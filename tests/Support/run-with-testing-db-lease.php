<?php

use Tests\Support\TestingDatabaseLease;
use Tests\Support\TestingDatabaseLeaseException;

require_once __DIR__.'/TestingDatabaseLease.php';

/** @return never */
function leaseRunnerExit(int $code, string $machineCode): void
{
    fwrite(STDERR, "[testing-db-lease] {$machineCode}\n");
    exit($code);
}

/** @param list<string> $command */
function leaseRunnerContainsArtisanServe(array $command): bool
{
    $executable = $command[0] ?? null;
    $artisanArgument = $command[1] ?? null;
    if (! is_string($executable) || ! is_string($artisanArgument)) {
        return false;
    }

    $executableName = strtolower(basename(str_replace('\\', '/', $executable)));
    $currentPhpName = strtolower(basename(str_replace('\\', '/', PHP_BINARY)));
    if ($executable !== PHP_BINARY
        && ! in_array($executableName, ['php', 'php.exe', $currentPhpName], true)
    ) {
        return false;
    }
    if (basename(str_replace('\\', '/', $artisanArgument)) !== 'artisan') {
        return false;
    }

    return ($command[2] ?? null) === 'serve'
        || (($command[2] ?? null) === '--env=testing' && ($command[3] ?? null) === 'serve');
}

/** @return array{status: bool, label: string, wait_ms: int, command: list<string>} */
function parseLeaseRunnerArguments(array $arguments): array
{
    array_shift($arguments);
    $status = false;
    $label = 'testing-runner';
    $waitMs = 0;
    $command = [];
    $afterSeparator = false;

    foreach ($arguments as $argument) {
        if ($afterSeparator) {
            $command[] = $argument;

            continue;
        }
        if ($argument === '--') {
            $afterSeparator = true;

            continue;
        }
        if ($argument === '--status') {
            $status = true;

            continue;
        }
        if (str_starts_with($argument, '--label=')) {
            $label = substr($argument, strlen('--label='));

            continue;
        }
        if (str_starts_with($argument, '--wait-ms=')) {
            $value = substr($argument, strlen('--wait-ms='));
            if ($value === ''
                || ! ctype_digit($value)
                || strlen($value) > 7
                || (int) $value > 3_600_000
            ) {
                leaseRunnerExit(TestingDatabaseLease::EXIT_USAGE, 'LEASE_RUNNER_WAIT_INVALID');
            }
            $waitMs = (int) $value;

            continue;
        }

        leaseRunnerExit(TestingDatabaseLease::EXIT_USAGE, 'LEASE_RUNNER_ARGUMENT_INVALID');
    }

    if ($status && $command !== []) {
        leaseRunnerExit(TestingDatabaseLease::EXIT_USAGE, 'LEASE_RUNNER_STATUS_COMMAND_CONFLICT');
    }
    if (! $status && $command === []) {
        leaseRunnerExit(TestingDatabaseLease::EXIT_USAGE, 'LEASE_RUNNER_COMMAND_REQUIRED');
    }

    return compact('status', 'label', 'waitMs', 'command');
}

$options = parseLeaseRunnerArguments($argv);
$projectRoot = dirname(__DIR__, 2);
$leaseBaseDirectory = trim((string) (getenv(TestingDatabaseLease::BASE_DIRECTORY_ENV) ?: ''));
$leaseBaseDirectory = $leaseBaseDirectory !== '' ? $leaseBaseDirectory : null;

if (! $options['status'] && leaseRunnerContainsArtisanServe($options['command'])) {
    leaseRunnerExit(TestingDatabaseLease::EXIT_USAGE, 'LEASE_RUNNER_ARTISAN_SERVE_REQUIRES_PAB');
}

try {
    if ($options['status']) {
        $status = TestingDatabaseLease::statusForProject(
            $projectRoot,
            leaseBaseDirectory: $leaseBaseDirectory,
        );
        fwrite(STDOUT, json_encode($status, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        exit(0);
    }

    if (strtolower((string) (getenv('APP_ENV') ?: '')) !== 'testing') {
        throw new TestingDatabaseLeaseException('LEASE_ENV_NOT_TESTING');
    }

    $lease = TestingDatabaseLease::acquireOrInheritForProject(
        $projectRoot,
        label: $options['label'],
        waitMs: $options['waitMs'],
        leaseBaseDirectory: $leaseBaseDirectory,
    );
} catch (TestingDatabaseLeaseException $error) {
    leaseRunnerExit(TestingDatabaseLease::exitCodeFor($error), $error->machineCode);
} catch (Throwable) {
    leaseRunnerExit(TestingDatabaseLease::EXIT_UNAVAILABLE, 'LEASE_RUNNER_INITIALIZATION_FAILED');
}

$proofEnvironment = $lease->createInheritanceProof();
$currentEnvironment = getenv();
if (! is_array($currentEnvironment)) {
    $currentEnvironment = [];
}
$childEnvironment = array_merge($currentEnvironment, $proofEnvironment);

$descriptors = [
    0 => ['file', 'php://stdin', 'r'],
    1 => ['file', 'php://stdout', 'w'],
    2 => ['file', 'php://stderr', 'w'],
];
$child = @proc_open(
    $options['command'],
    $descriptors,
    $pipes,
    $projectRoot,
    $childEnvironment,
    ['bypass_shell' => true],
);

if (! is_resource($child)) {
    $lease->release();
    leaseRunnerExit(TestingDatabaseLease::EXIT_SPAWN_FAILED, 'LEASE_RUNNER_CHILD_START_FAILED');
}

$terminationRequestedAt = null;
$terminationAttempts = 0;
$forcedTerminationSent = false;
$terminateChild = static function () use (
    &$child,
    &$terminationRequestedAt,
    &$terminationAttempts,
    &$forcedTerminationSent,
): void {
    if (! is_resource($child)) {
        return;
    }
    $status = @proc_get_status($child);
    if (! is_array($status) || ! ($status['running'] ?? false)) {
        return;
    }

    $terminationAttempts++;
    $terminationRequestedAt ??= hrtime(true);
    if ($terminationAttempts === 1) {
        @proc_terminate($child);

        return;
    }

    $forcedTerminationSent = true;
    @proc_terminate($child, 9);
};

register_shutdown_function(static function () use (&$child, $terminateChild, $lease): void {
    $terminateChild();
    $deadline = hrtime(true) + 2_000_000_000;
    while (is_resource($child) && hrtime(true) < $deadline) {
        $status = @proc_get_status($child);
        if (! is_array($status) || ! ($status['running'] ?? false)) {
            break;
        }
        usleep(50_000);
    }
    if (is_resource($child)) {
        $status = @proc_get_status($child);
        if (is_array($status) && ($status['running'] ?? false)) {
            @proc_terminate($child, 9);
        }
        @proc_close($child);
    }
    $lease->release();
});

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach (array_filter([
        defined('SIGINT') ? SIGINT : null,
        defined('SIGTERM') ? SIGTERM : null,
        defined('SIGHUP') ? SIGHUP : null,
        defined('SIGQUIT') ? SIGQUIT : null,
    ]) as $signal) {
        pcntl_signal($signal, static function () use ($terminateChild): void {
            $terminateChild();
        });
    }
}

if (function_exists('sapi_windows_set_ctrl_handler')) {
    sapi_windows_set_ctrl_handler(static function (int $event) use ($terminateChild): bool {
        if (defined('PHP_WINDOWS_EVENT_CTRL_C') && $event === PHP_WINDOWS_EVENT_CTRL_C) {
            $terminateChild();

            return true;
        }
        if (defined('PHP_WINDOWS_EVENT_CTRL_BREAK') && $event === PHP_WINDOWS_EVENT_CTRL_BREAK) {
            $terminateChild();

            return true;
        }

        return false;
    });
}

$lastStatus = null;
do {
    $lastStatus = @proc_get_status($child);
    if (! is_array($lastStatus) || ! ($lastStatus['running'] ?? false)) {
        break;
    }
    if ($terminationRequestedAt !== null
        && ! $forcedTerminationSent
        && hrtime(true) - $terminationRequestedAt >= 2_000_000_000
    ) {
        $forcedTerminationSent = true;
        @proc_terminate($child, 9);
    }
    usleep(50_000);
} while (true);

$closeCode = proc_close($child);
$child = null;
$lease->release();

$exitCode = $closeCode;
if ($exitCode === -1 && is_array($lastStatus) && is_int($lastStatus['exitcode'] ?? null)) {
    $exitCode = $lastStatus['exitcode'];
}
if (! is_int($exitCode) || $exitCode < 0) {
    leaseRunnerExit(TestingDatabaseLease::EXIT_SPAWN_FAILED, 'LEASE_RUNNER_CHILD_EXIT_UNKNOWN');
}

exit($exitCode);
