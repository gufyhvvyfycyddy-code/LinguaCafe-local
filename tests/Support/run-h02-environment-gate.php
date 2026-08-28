<?php

declare(strict_types=1);

final class H02EnvironmentGateFailure extends RuntimeException
{
    public function __construct(public readonly string $machineCode)
    {
        parent::__construct($machineCode);
    }
}

const H02_ENVIRONMENT_GATE_TIMEOUT_MS = 10_000;
const H02_ENVIRONMENT_GATE_OUTPUT_TAIL_BYTES = 2048;
const H02_ENVIRONMENT_GATE_BLOCKED_EXIT_CODE = 78;

/**
 * @return array{started:bool,timed_out:bool,exit_code:int,stdout:string,stderr:string}
 */
function h02EnvironmentGateRunProcess(array $command, string $workingDirectory, int $timeoutMs): array
{
    $process = @proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $workingDirectory,
        h02EnvironmentGateLaunchEnvironment(),
        ['bypass_shell' => true],
    );
    if (! is_resource($process)) {
        return [
            'started' => false,
            'timed_out' => false,
            'exit_code' => -1,
            'stdout' => '',
            'stderr' => '',
        ];
    }

    if (is_resource($pipes[0] ?? null)) {
        fclose($pipes[0]);
    }
    $stdout = '';
    $stderr = '';
    $timedOut = false;
    $processExited = false;
    $lastStatus = null;
    $deadline = microtime(true) + ($timeoutMs / 1000);

    while (true) {
        $lastStatus = @proc_get_status($process);
        if (! is_array($lastStatus)) {
            $timedOut = true;
            break;
        }
        if (($lastStatus['running'] ?? false) !== true) {
            $processExited = true;
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            break;
        }

        usleep(20_000);
    }

    if ($timedOut) {
        // Probes are direct executable arrays with bypass_shell enabled, so
        // retain the owned process resource and force-terminate that process
        // after a bounded grace period instead of targeting a bare PID tree.
        @proc_terminate($process);
        $terminationDeadline = microtime(true) + 1.0;
        do {
            $lastStatus = @proc_get_status($process);
            if (! is_array($lastStatus) || ! ($lastStatus['running'] ?? false)) {
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $terminationDeadline);

        if (is_array($lastStatus) && ($lastStatus['running'] ?? false)) {
            @proc_terminate($process, 9);
        }
        $lastStatus = @proc_get_status($process);
        $processExited = is_array($lastStatus) && ! ($lastStatus['running'] ?? false);
    }

    // Windows proc_open pipes can block despite stream_set_blocking(false).
    // Drain only after a non-timeout process has exited; timeout cleanup must
    // never wait on a pipe that a leaked descendant could still hold open.
    if (! $timedOut && $processExited) {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            h02EnvironmentGateDrainPipe($pipes[1] ?? null, $stdout);
            h02EnvironmentGateDrainPipe($pipes[2] ?? null, $stderr);
            if (is_resource($pipes[1] ?? null) && feof($pipes[1])) {
                if (is_resource($pipes[2] ?? null) && feof($pipes[2])) {
                    break;
                }
            }
            usleep(10_000);
        }
    }

    foreach ([1, 2] as $pipeIndex) {
        if (is_resource($pipes[$pipeIndex] ?? null)) {
            fclose($pipes[$pipeIndex]);
        }
    }

    $exitCode = @proc_close($process);
    if ($exitCode === -1 && is_array($lastStatus) && is_int($lastStatus['exitcode'] ?? null)) {
        $exitCode = $lastStatus['exitcode'];
    }

    return [
        'started' => true,
        'timed_out' => $timedOut,
        'exit_code' => is_int($exitCode) ? $exitCode : -1,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

function h02EnvironmentGateDrainPipe(mixed $pipe, string &$buffer): void
{
    if (! is_resource($pipe)) {
        return;
    }

    do {
        $chunk = @stream_get_contents($pipe, 4096);
        if (! is_string($chunk) || $chunk === '') {
            return;
        }
        $buffer .= h02EnvironmentGateRedactText($chunk);
        if (strlen($buffer) > H02_ENVIRONMENT_GATE_OUTPUT_TAIL_BYTES) {
            $buffer = substr($buffer, -H02_ENVIRONMENT_GATE_OUTPUT_TAIL_BYTES);
        }
    } while (strlen($chunk) === 4096);
}

/** @return array<string, string> */
function h02EnvironmentGateLaunchEnvironment(): array
{
    $environment = [];
    foreach (['PATH', 'SystemRoot'] as $name) {
        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            $environment[$name] = $value;
        }
    }

    return $environment;
}

function h02EnvironmentGateRedactText(string $text): string
{
    $text = preg_replace(
        '/(?i)("?\b(password|passwd|token|secret|api[_-]?key|authorization|cookie|docker[_-]?host|app[_-]?key|systemroot|userprofile|home)\b"?\s*:\s*)("[^"\r\n]*"|[^,\r\n}\]]*)/',
        '$1"[REDACTED]"',
        $text,
    ) ?? '';
    $text = preg_replace(
        '/(?i)("path"\s*:\s*)("[^"\r\n]*"|[^,\r\n}\]]*)/',
        '$1"[REDACTED]"',
        $text,
    ) ?? '';
    $text = preg_replace(
        '/(?i)\b(password|passwd|token|secret|api[_-]?key|authorization|cookie|docker[_-]?host|app[_-]?key|systemroot|userprofile|home)\b(\s*[=:]\s*)(?!["\'])[^\r\n]*/',
        '$1$2[REDACTED]',
        $text,
    ) ?? '';
    $text = preg_replace(
        '/(?i)(\bpath\b\s*=\s*)(?!["\'])[^\r\n]*/',
        '$1[REDACTED]',
        $text,
    ) ?? '';
    $text = preg_replace(
        '/(?im)(^|[\r\n])([ \t]*path\b(\s*:\s*))(?!["\'])[^\r\n]*/',
        '$1$2[REDACTED]',
        $text,
    ) ?? '';
    $text = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $text) ?? '';

    return $text;
}

function h02EnvironmentGateSanitizeText(string $text): string
{
    $text = h02EnvironmentGateRedactText($text);

    return trim(substr($text, -H02_ENVIRONMENT_GATE_OUTPUT_TAIL_BYTES));
}

/**
 * @return array{started:bool,timed_out:bool,exit_code:int,stdout:string,stderr:string}
 */
function h02EnvironmentGateNormalizeProcessResult(mixed $result): array
{
    if (! is_array($result)) {
        throw new H02EnvironmentGateFailure('H02_ENV_PROBE_FAILED');
    }

    $started = $result['started'] ?? null;
    $timedOut = $result['timed_out'] ?? null;
    $exitCode = $result['exit_code'] ?? null;
    $stdout = $result['stdout'] ?? null;
    $stderr = $result['stderr'] ?? null;
    if (! is_bool($started)
        || ! is_bool($timedOut)
        || ! is_int($exitCode)
        || ! is_string($stdout)
        || ! is_string($stderr)
    ) {
        throw new H02EnvironmentGateFailure('H02_ENV_PROBE_FAILED');
    }

    return [
        'started' => $started,
        'timed_out' => $timedOut,
        'exit_code' => $exitCode,
        'stdout' => h02EnvironmentGateSanitizeText($stdout),
        'stderr' => h02EnvironmentGateSanitizeText($stderr),
    ];
}

/** @return array{started:bool,timed_out:bool,exit_code:int,stdout:string,stderr:string} */
function h02EnvironmentGateProbeDetails(array $result): array
{
    return [
        'started' => $result['started'],
        'timed_out' => $result['timed_out'],
        'exit_code' => $result['exit_code'],
        'stdout' => h02EnvironmentGateSanitizeText($result['stdout']),
        'stderr' => h02EnvironmentGateSanitizeText($result['stderr']),
    ];
}

/** @return array<string, mixed> */
function h02EnvironmentGateInitialDetails(): array
{
    return [
        'machine_code' => null,
        'failed_check' => null,
        'message' => null,
        'wsl_status' => null,
        'wsl_version' => null,
        'docker_version' => null,
        'docker_compose_version' => null,
        'docker_info' => null,
        'compose_config' => null,
        'port_8892' => null,
    ];
}

/** @return array<string, bool> */
function h02EnvironmentGateInitialChecks(): array
{
    return [
        'platform_windows' => false,
        'wsl_status' => false,
        'wsl_version' => false,
        'docker_client' => false,
        'docker_server' => false,
        'docker_compose' => false,
        'docker_linux' => false,
        'compose_config' => false,
        'port_8892_free' => false,
    ];
}

/** @param array<string, bool> $checks @param array<string, mixed> $details @return array<string, mixed> */
function h02EnvironmentGateBuildResult(
    string $platform,
    array $checks,
    array $details,
    string $machineCode,
    ?string $failedCheck,
    string $message,
): array {
    $details['machine_code'] = $machineCode;
    $details['failed_check'] = $failedCheck;
    $details['message'] = $message;

    return [
        'schema_version' => 1,
        'ready' => $machineCode === 'H02_ENV_READY' && ! in_array(false, $checks, true),
        'platform' => $platform,
        'checks' => $checks,
        'details' => $details,
    ];
}

function h02EnvironmentGateDecodeObject(string $text): ?array
{
    try {
        $decoded = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }

    return is_array($decoded) ? $decoded : null;
}

/**
 * Run the read-only post-reboot H-02 environment gate.
 *
 * The command runner receives an argument array, repository-root working
 * directory, and a 10-second timeout. It returns started, timed_out,
 * exit_code, stdout, and stderr fields. The default runner never inherits
 * repository or user environment values beyond PATH and SystemRoot.
 *
 * @return array{schema_version:int,ready:bool,platform:string,checks:array<string,bool>,details:array<string,mixed>}
 */
function h02RunEnvironmentGate(
    ?callable $commandRunner = null,
    ?string $platform = null,
    ?string $projectRoot = null,
): array {
    $platform ??= defined('PHP_OS_FAMILY') ? PHP_OS_FAMILY : PHP_OS;
    $checks = h02EnvironmentGateInitialChecks();
    $details = h02EnvironmentGateInitialDetails();
    $fail = static function (string $machineCode, string $failedCheck, string $message) use (&$checks, &$details, $platform): array {
        return h02EnvironmentGateBuildResult(
            $platform,
            $checks,
            $details,
            $machineCode,
            $failedCheck,
            $message,
        );
    };

    if ($platform !== 'Windows') {
        return $fail(
            'H02_ENV_PLATFORM_UNSUPPORTED',
            'platform_windows',
            'The H-02 environment gate supports Windows only.',
        );
    }

    $checks['platform_windows'] = true;
    $projectRoot ??= dirname(__DIR__, 2);
    $commandRunner ??= static function (array $command, string $workingDirectory, int $timeoutMs): array {
        return h02EnvironmentGateRunProcess($command, $workingDirectory, $timeoutMs);
    };
    $run = static function (array $command) use ($commandRunner, $projectRoot): array {
        try {
            return h02EnvironmentGateNormalizeProcessResult(
                $commandRunner($command, $projectRoot, H02_ENVIRONMENT_GATE_TIMEOUT_MS),
            );
        } catch (H02EnvironmentGateFailure $error) {
            throw $error;
        } catch (Throwable) {
            throw new H02EnvironmentGateFailure('H02_ENV_PROBE_FAILED');
        }
    };

    try {
        $wslStatus = $run(['wsl.exe', '--status']);
        $details['wsl_status'] = h02EnvironmentGateProbeDetails($wslStatus);
        if ($wslStatus['timed_out']) {
            return $fail('H02_ENV_PROBE_TIMEOUT', 'wsl_status', 'The WSL status probe timed out.');
        }
        if (! $wslStatus['started'] || $wslStatus['exit_code'] !== 0 || trim($wslStatus['stdout']) === '') {
            return $fail('H02_ENV_WSL_UNAVAILABLE', 'wsl_status', 'WSL status is unavailable.');
        }
        $checks['wsl_status'] = true;

        $wslVersion = $run(['wsl.exe', '--version']);
        $details['wsl_version'] = h02EnvironmentGateProbeDetails($wslVersion);
        if ($wslVersion['timed_out']) {
            return $fail('H02_ENV_PROBE_TIMEOUT', 'wsl_version', 'The WSL version probe timed out.');
        }
        if (! $wslVersion['started'] || $wslVersion['exit_code'] !== 0 || trim($wslVersion['stdout']) === '') {
            return $fail('H02_ENV_WSL_UNAVAILABLE', 'wsl_version', 'WSL version is unavailable.');
        }
        $checks['wsl_version'] = true;

        $dockerVersion = $run(['docker.exe', 'version', '--format', '{{json .}}']);
        $details['docker_version'] = h02EnvironmentGateProbeDetails($dockerVersion);
        if ($dockerVersion['timed_out']) {
            return $fail('H02_ENV_PROBE_TIMEOUT', 'docker_version', 'The Docker version probe timed out.');
        }
        if (! $dockerVersion['started']) {
            return $fail('H02_ENV_DOCKER_UNAVAILABLE', 'docker_client', 'The Docker CLI is unavailable.');
        }
        if ($dockerVersion['exit_code'] !== 0) {
            return $fail('H02_ENV_DOCKER_SERVER_UNAVAILABLE', 'docker_server', 'The Docker server is unavailable.');
        }
        $dockerVersionObject = h02EnvironmentGateDecodeObject($dockerVersion['stdout']);
        $clientVersion = $dockerVersionObject['Client']['Version'] ?? null;
        $serverVersion = $dockerVersionObject['Server']['Version'] ?? null;
        if (! is_string($clientVersion) || trim($clientVersion) === '') {
            return $fail('H02_ENV_DOCKER_UNAVAILABLE', 'docker_client', 'Docker client version was not returned.');
        }
        $checks['docker_client'] = true;
        if (! is_string($serverVersion) || trim($serverVersion) === '') {
            return $fail('H02_ENV_DOCKER_SERVER_UNAVAILABLE', 'docker_server', 'Docker server version was not returned.');
        }
        $checks['docker_server'] = true;
        $details['docker_version']['client_version'] = h02EnvironmentGateSanitizeText($clientVersion);
        $details['docker_version']['server_version'] = h02EnvironmentGateSanitizeText($serverVersion);

        $dockerComposeVersion = $run(['docker.exe', 'compose', 'version']);
        $details['docker_compose_version'] = h02EnvironmentGateProbeDetails($dockerComposeVersion);
        if ($dockerComposeVersion['timed_out']) {
            return $fail('H02_ENV_PROBE_TIMEOUT', 'docker_compose', 'The Docker Compose version probe timed out.');
        }
        if (! $dockerComposeVersion['started']
            || $dockerComposeVersion['exit_code'] !== 0
            || trim($dockerComposeVersion['stdout']) === ''
        ) {
            return $fail('H02_ENV_COMPOSE_UNAVAILABLE', 'docker_compose', 'Docker Compose is unavailable.');
        }
        $checks['docker_compose'] = true;

        $dockerInfo = $run(['docker.exe', 'info', '--format', '{{json .}}']);
        $details['docker_info'] = h02EnvironmentGateProbeDetails($dockerInfo);
        if ($dockerInfo['timed_out']) {
            return $fail('H02_ENV_PROBE_TIMEOUT', 'docker_linux', 'The Docker info probe timed out.');
        }
        if (! $dockerInfo['started'] || $dockerInfo['exit_code'] !== 0) {
            return $fail('H02_ENV_DOCKER_SERVER_UNAVAILABLE', 'docker_linux', 'Docker server information is unavailable.');
        }
        $dockerInfoObject = h02EnvironmentGateDecodeObject($dockerInfo['stdout']);
        $osType = $dockerInfoObject['OSType'] ?? null;
        if (! is_string($osType) || trim($osType) === '') {
            return $fail('H02_ENV_DOCKER_SERVER_UNAVAILABLE', 'docker_linux', 'Docker server OSType was not returned.');
        }
        $details['docker_info']['ostype'] = h02EnvironmentGateSanitizeText($osType);
        if (strtolower(trim($osType)) !== 'linux') {
            return $fail('H02_ENV_DOCKER_NOT_LINUX', 'docker_linux', 'Docker server OSType is not linux.');
        }
        $checks['docker_linux'] = true;

        $composeConfig = $run([
            'docker.exe',
            'compose',
            '-f',
            'docker-compose.h02-testing.yml',
            'config',
            '--quiet',
        ]);
        $details['compose_config'] = h02EnvironmentGateProbeDetails($composeConfig);
        if ($composeConfig['timed_out']) {
            return $fail('H02_ENV_PROBE_TIMEOUT', 'compose_config', 'The Docker Compose config probe timed out.');
        }
        if (! $composeConfig['started'] || $composeConfig['exit_code'] !== 0) {
            return $fail('H02_ENV_COMPOSE_INVALID', 'compose_config', 'The H-02 Docker Compose file is invalid.');
        }
        $checks['compose_config'] = true;

        $portProbe = $run([
            'powershell.exe',
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-Command',
            '$ErrorActionPreference = "Stop"; $listeners = @(Get-NetTCPConnection -LocalPort 8892 -State Listen -ErrorAction Stop); [Console]::Out.WriteLine($listeners.Count)',
        ]);
        $details['port_8892'] = h02EnvironmentGateProbeDetails($portProbe);
        if ($portProbe['timed_out']) {
            return $fail('H02_ENV_PROBE_TIMEOUT', 'port_8892_free', 'The localhost port probe timed out.');
        }
        if (! $portProbe['started'] || $portProbe['exit_code'] !== 0) {
            return $fail('H02_ENV_PORT_PROBE_UNAVAILABLE', 'port_8892_free', 'The localhost port probe is unavailable.');
        }
        $listenerCount = trim($portProbe['stdout']);
        if (preg_match('/\A\d+\z/', $listenerCount) !== 1) {
            return $fail('H02_ENV_PORT_PROBE_UNAVAILABLE', 'port_8892_free', 'The localhost port probe returned an invalid result.');
        }
        $details['port_8892']['listening'] = (int) $listenerCount > 0;
        if ((int) $listenerCount > 0) {
            return $fail('H02_ENV_PORT_8892_BUSY', 'port_8892_free', 'Localhost port 8892 is already listening.');
        }
        $checks['port_8892_free'] = true;

        return h02EnvironmentGateBuildResult(
            $platform,
            $checks,
            $details,
            'H02_ENV_READY',
            null,
            'All H-02 environment checks passed.',
        );
    } catch (H02EnvironmentGateFailure $error) {
        return $fail($error->machineCode, 'probe', 'An H-02 environment probe failed.');
    } catch (Throwable) {
        return $fail('H02_ENV_PROBE_FAILED', 'probe', 'An H-02 environment probe failed.');
    }
}

function runH02EnvironmentGateCli(
    array $arguments,
    ?callable $commandRunner = null,
    ?string $platform = null,
    ?string $projectRoot = null,
): int {
    unset($arguments);
    try {
        $result = h02RunEnvironmentGate($commandRunner, $platform, $projectRoot);
        $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $result = h02EnvironmentGateBuildResult(
            $platform ?? (defined('PHP_OS_FAMILY') ? PHP_OS_FAMILY : PHP_OS),
            h02EnvironmentGateInitialChecks(),
            h02EnvironmentGateInitialDetails(),
            'H02_ENV_PROBE_FAILED',
            'probe',
            'An H-02 environment probe failed.',
        );
        $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    fwrite(STDOUT, $encoded."\n");

    return $result['ready'] === true ? 0 : H02_ENVIRONMENT_GATE_BLOCKED_EXIT_CODE;
}

$scriptPath = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($scriptPath)
    && realpath($scriptPath) !== false
    && realpath($scriptPath) === realpath(__FILE__)
) {
    exit(runH02EnvironmentGateCli($argv));
}
