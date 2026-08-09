<?php

use Tests\Support\TestingDatabaseLease;
use Tests\Support\TestingDatabaseLeaseException;

require_once __DIR__.'/TestingDatabaseLease.php';

final class PabR3BrowserAcceptanceFailure extends RuntimeException
{
    public function __construct(
        public readonly string $machineCode,
        public readonly int $exitCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct($machineCode, 0, $previous);
    }
}

final class PabR3BrowserAcceptanceHarness
{
    public const SENTINEL_PREFIX = '__testing_acceptance_sentinel_';

    public const EXIT_USAGE = TestingDatabaseLease::EXIT_USAGE;

    public const EXIT_UNAVAILABLE = TestingDatabaseLease::EXIT_UNAVAILABLE;

    public const EXIT_CHILD_START_FAILED = TestingDatabaseLease::EXIT_SPAWN_FAILED;

    public const EXIT_NOT_TESTING = TestingDatabaseLease::EXIT_NOT_TESTING;

    public const EXIT_CLEANUP_FAILED = 79;

    public const EXIT_CANCELLED = 130;

    private Closure $acquireLease;

    private Closure $createSentinel;

    private Closure $cleanupSentinel;

    private Closure $runChild;

    private Closure $randomBytes;

    private Closure $environmentProvider;

    private Closure $evidenceWriter;

    private Closure $cancellationRequested;

    public function __construct(
        callable $acquireLease,
        callable $createSentinel,
        callable $cleanupSentinel,
        callable $runChild,
        ?callable $randomBytes = null,
        ?callable $environmentProvider = null,
        ?callable $evidenceWriter = null,
        ?callable $cancellationRequested = null,
    ) {
        $this->acquireLease = Closure::fromCallable($acquireLease);
        $this->createSentinel = Closure::fromCallable($createSentinel);
        $this->cleanupSentinel = Closure::fromCallable($cleanupSentinel);
        $this->runChild = Closure::fromCallable($runChild);
        $this->randomBytes = Closure::fromCallable($randomBytes ?? static fn (int $length): string => random_bytes($length));
        $this->environmentProvider = Closure::fromCallable($environmentProvider ?? static function (): array {
            $environment = getenv();

            return is_array($environment) ? $environment : [];
        });
        $this->evidenceWriter = Closure::fromCallable($evidenceWriter ?? static function (string $stream, string $line): void {
            fwrite($stream === 'stderr' ? STDERR : STDOUT, $line."\n");
        });
        $this->cancellationRequested = Closure::fromCallable(
            $cancellationRequested ?? static fn (): bool => false,
        );
    }

    public function run(string $appEnvironment, array $command): int
    {
        if (strtolower(trim($appEnvironment)) !== 'testing') {
            $this->emitFailure('PAB_R3_ENV_NOT_TESTING');

            return self::EXIT_NOT_TESTING;
        }
        if ($command === [] || array_filter($command, 'is_string') !== $command) {
            $this->emitFailure('PAB_R3_COMMAND_REQUIRED');

            return self::EXIT_USAGE;
        }
        try {
            self::assertSentinelSafeCommand($command);
        } catch (PabR3BrowserAcceptanceFailure $error) {
            $this->emitFailure($error->machineCode);

            return $error->exitCode;
        }

        if (($this->cancellationRequested)()) {
            $this->emitFailure('PAB_R3_CANCELLED');

            return self::EXIT_CANCELLED;
        }

        $lease = null;
        $sentinel = null;
        $sentinelOwned = false;
        $failure = null;
        $childExitCode = null;

        try {
            $lease = ($this->acquireLease)();
            if (! is_object($lease)
                || ! method_exists($lease, 'createInheritanceProof')
                || ! method_exists($lease, 'release')
                || ! method_exists($lease, 'metadata')
                || ! method_exists($lease, 'isInherited')
            ) {
                throw new PabR3BrowserAcceptanceFailure('PAB_R3_LEASE_CONTRACT_INVALID', self::EXIT_UNAVAILABLE);
            }

            $metadata = $lease->metadata();
            $this->emitEvidence([
                'event' => 'lease_acquired',
                'mode' => is_array($metadata) && is_string($metadata['mode'] ?? null) ? $metadata['mode'] : 'unknown',
                'label' => is_array($metadata) && is_string($metadata['label'] ?? null) ? $metadata['label'] : 'unknown',
                'inherited' => $lease->isInherited(),
            ]);
            $this->throwIfCancelled();

            $sentinel = self::generateSentinel($this->randomBytes);
            if (($this->createSentinel)($sentinel) !== true) {
                throw new PabR3BrowserAcceptanceFailure('PAB_R3_SENTINEL_CREATE_UNPROVEN', self::EXIT_UNAVAILABLE);
            }
            $sentinelOwned = true;
            $this->throwIfCancelled();

            $this->emitEvidence([
                'event' => 'sentinel_created',
                'sentinel_sha256' => hash('sha256', $sentinel),
            ]);

            $proofEnvironment = $lease->createInheritanceProof();
            if (! is_array($proofEnvironment)) {
                throw new PabR3BrowserAcceptanceFailure('PAB_R3_LEASE_PROOF_INVALID', self::EXIT_UNAVAILABLE);
            }
            foreach ($proofEnvironment as $name => $value) {
                if (! is_string($name) || ! is_string($value) || $name === '') {
                    throw new PabR3BrowserAcceptanceFailure('PAB_R3_LEASE_PROOF_INVALID', self::EXIT_UNAVAILABLE);
                }
            }
            $this->throwIfCancelled();

            $currentEnvironment = ($this->environmentProvider)();
            if (! is_array($currentEnvironment)) {
                throw new PabR3BrowserAcceptanceFailure('PAB_R3_ENVIRONMENT_UNAVAILABLE', self::EXIT_UNAVAILABLE);
            }
            $childEnvironment = array_merge(
                $currentEnvironment,
                $proofEnvironment,
                ['LINGUACAFE_TEST_SENTINEL' => $sentinel],
            );
            $this->throwIfCancelled();

            $childExitCode = ($this->runChild)($command, $childEnvironment);
            if (! is_int($childExitCode) || $childExitCode < 0 || $childExitCode > 255) {
                throw new PabR3BrowserAcceptanceFailure('PAB_R3_CHILD_EXIT_UNKNOWN', self::EXIT_CHILD_START_FAILED);
            }
            $this->emitEvidence([
                'event' => 'child_exit',
                'exit_code' => $childExitCode,
            ]);
        } catch (PabR3BrowserAcceptanceFailure $error) {
            $failure = $error;
        } catch (TestingDatabaseLeaseException $error) {
            $failure = new PabR3BrowserAcceptanceFailure(
                $error->machineCode,
                TestingDatabaseLease::exitCodeFor($error),
                $error,
            );
        } catch (Throwable $error) {
            $failure = new PabR3BrowserAcceptanceFailure(
                'PAB_R3_HELPER_FAILED',
                self::EXIT_UNAVAILABLE,
                $error,
            );
        }

        $cleanupOk = true;
        if ($sentinelOwned && is_string($sentinel)) {
            try {
                $cleanupOk = ($this->cleanupSentinel)($sentinel) === true;
            } catch (Throwable) {
                $cleanupOk = false;
            }
            $this->emitEvidence([
                'event' => 'sentinel_cleanup',
                'status' => $cleanupOk ? 'ok' : 'failed',
                'sentinel_sha256' => hash('sha256', $sentinel),
            ]);
            if (! $cleanupOk) {
                $this->emitFailure('PAB_R3_SENTINEL_CLEANUP_FAILED');
            }
        }

        $releaseOk = true;
        if (is_object($lease)) {
            try {
                $lease->release();
            } catch (Throwable) {
                $releaseOk = false;
                $this->emitFailure('PAB_R3_LEASE_RELEASE_FAILED');
            }
        }

        if (! $cleanupOk) {
            return self::EXIT_CLEANUP_FAILED;
        }
        if (! $releaseOk) {
            return self::EXIT_UNAVAILABLE;
        }
        if ($failure instanceof PabR3BrowserAcceptanceFailure) {
            $this->emitFailure($failure->machineCode);

            return $failure->exitCode;
        }

        return $childExitCode ?? self::EXIT_UNAVAILABLE;
    }

    public static function generateSentinel(?callable $randomBytes = null): string
    {
        $source = $randomBytes ?? static fn (int $length): string => random_bytes($length);
        $bytes = $source(32);
        if (! is_string($bytes) || strlen($bytes) !== 32) {
            throw new PabR3BrowserAcceptanceFailure('PAB_R3_RANDOM_SOURCE_INVALID', self::EXIT_UNAVAILABLE);
        }

        $sentinel = self::SENTINEL_PREFIX.bin2hex($bytes);
        self::assertSentinel($sentinel);

        return $sentinel;
    }

    public static function createExactSentinel(
        string $sentinel,
        callable $existsByExactValue,
        callable $insertExactValue,
        callable $deleteExactValue,
    ): bool {
        self::assertSentinel($sentinel);
        if ($existsByExactValue($sentinel)) {
            throw new PabR3BrowserAcceptanceFailure('PAB_R3_SENTINEL_COLLISION', self::EXIT_UNAVAILABLE);
        }

        $insertAttempted = false;
        try {
            $insertAttempted = true;
            if ($insertExactValue($sentinel) !== true || ! $existsByExactValue($sentinel)) {
                throw new PabR3BrowserAcceptanceFailure('PAB_R3_SENTINEL_CREATE_UNPROVEN', self::EXIT_UNAVAILABLE);
            }

            return true;
        } catch (Throwable $error) {
            if ($insertAttempted) {
                try {
                    if ($existsByExactValue($sentinel)) {
                        $deleteExactValue($sentinel);
                    }
                    if ($existsByExactValue($sentinel)) {
                        throw new RuntimeException('Exact sentinel remained after creation failure cleanup.');
                    }
                } catch (Throwable $cleanupError) {
                    throw new PabR3BrowserAcceptanceFailure(
                        'PAB_R3_SENTINEL_CLEANUP_FAILED',
                        self::EXIT_CLEANUP_FAILED,
                        $cleanupError,
                    );
                }
            }

            if ($error instanceof PabR3BrowserAcceptanceFailure) {
                throw $error;
            }

            throw new PabR3BrowserAcceptanceFailure(
                'PAB_R3_SENTINEL_CREATE_FAILED',
                self::EXIT_UNAVAILABLE,
                $error,
            );
        }
    }

    public static function cleanupExactSentinel(
        string $sentinel,
        callable $deleteExactValue,
        callable $existsByExactValue,
    ): bool {
        self::assertSentinel($sentinel);
        $deleteExactValue($sentinel);

        return ! $existsByExactValue($sentinel);
    }

    private static function assertSentinel(string $sentinel): void
    {
        if (preg_match('/^'.preg_quote(self::SENTINEL_PREFIX, '/').'[a-f0-9]{64}$/D', $sentinel) !== 1) {
            throw new PabR3BrowserAcceptanceFailure('PAB_R3_SENTINEL_INVALID', self::EXIT_UNAVAILABLE);
        }
    }

    /** @param list<string> $command */
    private static function assertSentinelSafeCommand(array $command): void
    {
        $artisanArgument = $command[1] ?? null;
        if (! is_string($artisanArgument)
            || basename(str_replace('\\', '/', $artisanArgument)) !== 'artisan'
        ) {
            return;
        }
        $artisanIndex = 1;

        $isServeCommand = false;
        for ($index = $artisanIndex + 1, $count = count($command); $index < $count; $index++) {
            if ($command[$index] === 'serve') {
                $isServeCommand = true;
                break;
            }
        }
        if ($isServeCommand && ! in_array('--no-reload', $command, true)) {
            throw new PabR3BrowserAcceptanceFailure(
                'PAB_R3_ARTISAN_SERVE_NO_RELOAD_REQUIRED',
                self::EXIT_USAGE,
            );
        }
    }

    private function throwIfCancelled(): void
    {
        if (($this->cancellationRequested)()) {
            throw new PabR3BrowserAcceptanceFailure('PAB_R3_CANCELLED', self::EXIT_CANCELLED);
        }
    }

    private function emitEvidence(array $payload): void
    {
        try {
            ($this->evidenceWriter)(
                'stdout',
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            );
        } catch (Throwable) {
            // Operational evidence must never expose or alter lifecycle resources.
        }
    }

    private function emitFailure(string $machineCode): void
    {
        try {
            ($this->evidenceWriter)('stderr', '[pab-r3-browser-acceptance] '.$machineCode);
        } catch (Throwable) {
            // The caller still receives the nonzero exit status even if stderr is unavailable.
        }
    }
}

/** @return array{label: string, wait_ms: int, command: list<string>} */
function parsePabR3BrowserAcceptanceArguments(array $arguments): array
{
    array_shift($arguments);
    $label = 'pab-r3-browser-acceptance';
    $waitMs = 0;
    $command = [];
    $afterSeparator = false;

    foreach ($arguments as $argument) {
        if (! is_string($argument)) {
            throw new PabR3BrowserAcceptanceFailure('PAB_R3_ARGUMENT_INVALID', PabR3BrowserAcceptanceHarness::EXIT_USAGE);
        }
        if ($afterSeparator) {
            $command[] = $argument;

            continue;
        }
        if ($argument === '--') {
            $afterSeparator = true;

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
                throw new PabR3BrowserAcceptanceFailure('PAB_R3_WAIT_INVALID', PabR3BrowserAcceptanceHarness::EXIT_USAGE);
            }
            $waitMs = (int) $value;

            continue;
        }

        throw new PabR3BrowserAcceptanceFailure('PAB_R3_ARGUMENT_INVALID', PabR3BrowserAcceptanceHarness::EXIT_USAGE);
    }

    if (! $afterSeparator || $command === []) {
        throw new PabR3BrowserAcceptanceFailure('PAB_R3_COMMAND_REQUIRED', PabR3BrowserAcceptanceHarness::EXIT_USAGE);
    }

    return [
        'label' => $label,
        'wait_ms' => $waitMs,
        'command' => $command,
    ];
}

/**
 * @param  list<string>  $command
 * @return array{command: list<string>, working_directory: string}
 */
function preparePabR3BrowserAcceptanceChild(array $command, string $projectRoot): array
{
    $artisanArgument = $command[1] ?? null;
    if (! is_string($artisanArgument)
        || basename(str_replace('\\', '/', $artisanArgument)) !== 'artisan'
    ) {
        return ['command' => $command, 'working_directory' => $projectRoot];
    }

    $serveIndex = null;
    for ($index = 2, $count = count($command); $index < $count; $index++) {
        if ($command[$index] === 'serve') {
            $serveIndex = $index;
            break;
        }
    }

    if ($serveIndex === null) {
        return ['command' => $command, 'working_directory' => $projectRoot];
    }

    $host = '127.0.0.1';
    $port = '8000';
    for ($index = $serveIndex + 1, $count = count($command); $index < $count; $index++) {
        $argument = $command[$index];
        if ($argument === '--no-reload') {
            continue;
        }
        if (str_starts_with($argument, '--host=')) {
            $host = substr($argument, strlen('--host='));
            continue;
        }
        if (str_starts_with($argument, '--port=')) {
            $port = substr($argument, strlen('--port='));
            continue;
        }
        if (($argument === '--host' || $argument === '--port') && isset($command[$index + 1])) {
            if ($argument === '--host') {
                $host = $command[++$index];
            } else {
                $port = $command[++$index];
            }
            continue;
        }

        throw new PabR3BrowserAcceptanceFailure(
            'PAB_R3_ARTISAN_SERVE_ARGUMENT_UNSUPPORTED',
            PabR3BrowserAcceptanceHarness::EXIT_USAGE,
        );
    }

    if ($host !== '127.0.0.1'
        || ! ctype_digit($port)
        || (int) $port < 1
        || (int) $port > 65535
    ) {
        throw new PabR3BrowserAcceptanceFailure(
            'PAB_R3_ARTISAN_SERVE_BIND_INVALID',
            PabR3BrowserAcceptanceHarness::EXIT_USAGE,
        );
    }

    $publicDirectory = $projectRoot.'/public';
    $router = $projectRoot.'/tests/Support/pab-r3-browser-server.php';
    if (! is_dir($publicDirectory) || ! is_file($router)) {
        throw new PabR3BrowserAcceptanceFailure(
            'PAB_R3_SERVER_ENTRY_MISSING',
            PabR3BrowserAcceptanceHarness::EXIT_UNAVAILABLE,
        );
    }

    return [
        'command' => [PHP_BINARY, '-S', $host.':'.$port, $router],
        'working_directory' => $publicDirectory,
    ];
}

/** @param list<string> $command @param array<string, string> $environment */
function runPabR3BrowserAcceptanceChild(
    array $command,
    array $environment,
    string $projectRoot,
    ?callable $cancellationRequested = null,
): int
{
    $prepared = preparePabR3BrowserAcceptanceChild($command, $projectRoot);
    $cancellationRequested ??= static fn (): bool => false;
    if (($prepared['command'][0] ?? null) === PHP_BINARY
        && ($prepared['command'][1] ?? null) === '-S'
    ) {
        unset($environment['PHP_CLI_SERVER_WORKERS']);
    }
    $descriptors = [
        0 => ['file', 'php://stdin', 'r'],
        1 => ['file', 'php://stdout', 'w'],
        2 => ['file', 'php://stderr', 'w'],
    ];
    $child = @proc_open(
        $prepared['command'],
        $descriptors,
        $pipes,
        $prepared['working_directory'],
        $environment,
        ['bypass_shell' => true],
    );
    if (! is_resource($child)) {
        throw new PabR3BrowserAcceptanceFailure(
            'PAB_R3_CHILD_START_FAILED',
            PabR3BrowserAcceptanceHarness::EXIT_CHILD_START_FAILED,
        );
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

    register_shutdown_function(static function () use (&$child, $terminateChild): void {
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
            $child = null;
        }
    });

    $lastStatus = null;
    do {
        if ($terminationRequestedAt === null && $cancellationRequested()) {
            $terminateChild();
        }
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
    $exitCode = $closeCode;
    if ($exitCode === -1 && is_array($lastStatus) && is_int($lastStatus['exitcode'] ?? null)) {
        $exitCode = $lastStatus['exitcode'];
    }
    if ($terminationRequestedAt !== null) {
        throw new PabR3BrowserAcceptanceFailure(
            'PAB_R3_CANCELLED',
            PabR3BrowserAcceptanceHarness::EXIT_CANCELLED,
        );
    }
    if (! is_int($exitCode) || $exitCode < 0 || $exitCode > 255) {
        throw new PabR3BrowserAcceptanceFailure(
            'PAB_R3_CHILD_EXIT_UNKNOWN',
            PabR3BrowserAcceptanceHarness::EXIT_CHILD_START_FAILED,
        );
    }

    return $exitCode;
}

/** @return Closure(): bool */
function installPabR3BrowserAcceptanceCancellationProbe(): Closure
{
    $cancelled = false;

    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        foreach (array_filter([
            defined('SIGINT') ? SIGINT : null,
            defined('SIGTERM') ? SIGTERM : null,
            defined('SIGHUP') ? SIGHUP : null,
            defined('SIGQUIT') ? SIGQUIT : null,
        ]) as $signal) {
            pcntl_signal($signal, static function () use (&$cancelled): void {
                $cancelled = true;
            });
        }
    }

    if (function_exists('sapi_windows_set_ctrl_handler')) {
        sapi_windows_set_ctrl_handler(static function (int $event) use (&$cancelled): bool {
            if ((defined('PHP_WINDOWS_EVENT_CTRL_C') && $event === PHP_WINDOWS_EVENT_CTRL_C)
                || (defined('PHP_WINDOWS_EVENT_CTRL_BREAK') && $event === PHP_WINDOWS_EVENT_CTRL_BREAK)
            ) {
                $cancelled = true;

                return true;
            }

            return false;
        });
    }

    return static function () use (&$cancelled): bool {
        return $cancelled;
    };
}

function runPabR3BrowserAcceptanceCli(array $arguments): int
{
    try {
        $options = parsePabR3BrowserAcceptanceArguments($arguments);
    } catch (PabR3BrowserAcceptanceFailure $error) {
        fwrite(STDERR, '[pab-r3-browser-acceptance] '.$error->machineCode."\n");

        return $error->exitCode;
    }

    $appEnvironment = (string) (getenv('APP_ENV') ?: '');
    $cancellationRequested = installPabR3BrowserAcceptanceCancellationProbe();
    $projectRoot = dirname(__DIR__, 2);
    $application = null;
    $connection = null;

    $ensureTestingConnection = static function () use ($projectRoot, &$application, &$connection) {
        if ($connection !== null) {
            return $connection;
        }

        $autoload = $projectRoot.'/vendor/autoload.php';
        $bootstrap = $projectRoot.'/bootstrap/app.php';
        if (! is_file($autoload) || ! is_file($bootstrap)) {
            throw new PabR3BrowserAcceptanceFailure(
                'PAB_R3_APPLICATION_BOOTSTRAP_MISSING',
                PabR3BrowserAcceptanceHarness::EXIT_UNAVAILABLE,
            );
        }

        require_once $autoload;
        $application = require $bootstrap;
        $kernel = $application->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
        if (! $application->environment('testing')) {
            throw new PabR3BrowserAcceptanceFailure(
                'PAB_R3_APPLICATION_NOT_TESTING',
                PabR3BrowserAcceptanceHarness::EXIT_NOT_TESTING,
            );
        }

        $connection = $application->make('db')->connection();
        $databaseName = $connection->getDatabaseName();
        if (! is_string($databaseName)
            || ! str_contains(strtolower($databaseName), 'test')
        ) {
            throw new PabR3BrowserAcceptanceFailure(
                'PAB_R3_DATABASE_NOT_TESTING',
                PabR3BrowserAcceptanceHarness::EXIT_NOT_TESTING,
            );
        }

        return $connection;
    };

    $harness = new PabR3BrowserAcceptanceHarness(
        acquireLease: static fn () => TestingDatabaseLease::acquireOrInheritForProject(
            $projectRoot,
            label: $options['label'],
            waitMs: $options['wait_ms'],
        ),
        createSentinel: static function (string $sentinel) use ($ensureTestingConnection): bool {
            $database = $ensureTestingConnection();
            return PabR3BrowserAcceptanceHarness::createExactSentinel(
                $sentinel,
                static fn (string $value): bool => $database->table('migrations')->where('migration', $value)->exists(),
                static fn (string $value): bool => $database->table('migrations')->insert([
                    'migration' => $value,
                    'batch' => 0,
                ]),
                static fn (string $value): int => $database->table('migrations')->where('migration', $value)->delete(),
            );
        },
        cleanupSentinel: static function (string $sentinel) use ($ensureTestingConnection): bool {
            $database = $ensureTestingConnection();

            return PabR3BrowserAcceptanceHarness::cleanupExactSentinel(
                $sentinel,
                static fn (string $value): int => $database->table('migrations')->where('migration', $value)->delete(),
                static fn (string $value): bool => $database->table('migrations')->where('migration', $value)->exists(),
            );
        },
        runChild: static fn (array $command, array $environment): int => runPabR3BrowserAcceptanceChild(
            $command,
            $environment,
            $projectRoot,
            $cancellationRequested,
        ),
        cancellationRequested: $cancellationRequested,
    );

    return $harness->run($appEnvironment, $options['command']);
}

$scriptPath = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($scriptPath)
    && realpath($scriptPath) !== false
    && realpath($scriptPath) === realpath(__FILE__)
) {
    exit(runPabR3BrowserAcceptanceCli($argv));
}
