<?php

declare(strict_types=1);

require_once __DIR__.'/run-h02-environment-gate.php';
require_once __DIR__.'/run-h01-load-observability.php';

final class H02DockerLadderFailure extends RuntimeException
{
    public function __construct(public readonly string $machineCode, ?Throwable $previous = null)
    {
        parent::__construct($machineCode, 0, $previous);
    }
}

function h02LadderEnsureTempDirectory(string $directory): void
{
    if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
        throw new H02DockerLadderFailure('H02_LADDER_TEMP_DIRECTORY_FAILED');
    }
}

/** @param list<string> $command */
function h02LadderRunCommand(array $command, int $timeoutMs = 120_000): string
{
    $environment = getenv();
    if (! is_array($environment)) {
        throw new H02DockerLadderFailure('H02_LADDER_ENVIRONMENT_UNAVAILABLE');
    }

    $label = 'h02-ladder-command-'.bin2hex(random_bytes(6));
    $child = h01StartProcess(
        $command,
        dirname(__DIR__, 2),
        $environment,
        sys_get_temp_dir(),
        $label,
    );

    try {
        $exitCode = h02LadderWaitChild($child, $timeoutMs);
        $stdout = is_file($child['stdout']) ? (string) file_get_contents($child['stdout']) : '';
        $stderr = is_file($child['stderr']) ? (string) file_get_contents($child['stderr']) : '';
        if ($exitCode !== 0) {
            $tail = trim($stderr !== '' ? $stderr : $stdout);
            if ($tail !== '') {
                fwrite(STDERR, "[h02-docker-ladder] command_tail: ".substr($tail, -3000)."\n");
            }
            throw new H02DockerLadderFailure('H02_LADDER_COMMAND_FAILED');
        }

        return trim($stdout);
    } finally {
        if (is_resource($child['process'] ?? null)) {
            h01StopProcess($child['process']);
        }
        @unlink($child['stdout']);
        @unlink($child['stderr']);
    }
}

/** @param list<string> $command */
function h02LadderRunJsonCommand(array $command, int $timeoutMs = 120_000): array
{
    $stdout = h02LadderRunCommand($command, $timeoutMs);
    try {
        $decoded = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        throw new H02DockerLadderFailure('H02_LADDER_JSON_INVALID', $error);
    }
    if (! is_array($decoded) || ($decoded['schema_version'] ?? null) !== 1) {
        throw new H02DockerLadderFailure('H02_LADDER_JSON_INVALID');
    }

    return $decoded;
}

function h02LadderWaitForLogin(string $url): void
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 2.0,
            'ignore_errors' => true,
        ],
    ]);
    $deadline = microtime(true) + 15.0;
    do {
        $body = @file_get_contents($url, false, $context);
        $status = $http_response_header[0] ?? '';
        if (is_string($body) && preg_match('/\s200\s/', $status) === 1) {
            return;
        }
        usleep(100_000);
    } while (microtime(true) < $deadline);

    throw new H02DockerLadderFailure('H02_LADDER_WEB_NOT_READY');
}

function h02LadderWaitChild(array &$child, int $timeoutMs): int
{
    $deadline = microtime(true) + ($timeoutMs / 1000);
    $terminalExitCode = null;
    do {
        $status = @proc_get_status($child['process']);
        if (! is_array($status)) {
            throw new H02DockerLadderFailure('H02_LADDER_CHILD_STATUS_FAILED');
        }
        if (! ($status['running'] ?? false)) {
            $candidate = $status['exitcode'] ?? null;
            if (is_int($candidate) && $candidate >= 0) {
                $terminalExitCode = $candidate;
            }
            break;
        }
        if (microtime(true) >= $deadline) {
            h01StopProcess($child['process']);
            $child['process'] = null;
            throw new H02DockerLadderFailure('H02_LADDER_CHILD_TIMEOUT');
        }
        usleep(50_000);
    } while (true);

    $closeCode = proc_close($child['process']);
    $child['process'] = null;
    if ($closeCode >= 0) {
        return $closeCode;
    }
    if (is_int($terminalExitCode)) {
        return $terminalExitCode;
    }

    throw new H02DockerLadderFailure('H02_LADDER_CHILD_EXIT_UNKNOWN');
}

function h02LadderNewLaravelErrors(string $logs): int
{
    return preg_match_all('/\btesting\.(?:ERROR|CRITICAL|ALERT|EMERGENCY):/', $logs) ?: 0;
}

function h02LadderEmitWebDiagnostic(string $compose, int $vus): void
{
    try {
        $logs = h02LadderRunCommand(
            ['docker.exe', 'compose', '-f', $compose, 'logs', '--no-color', 'web'],
            30_000,
        );
        $tail = trim(substr($logs, -12_000));
        if ($tail !== '') {
            fwrite(STDERR, "[h02-docker-ladder] web_{$vus}_log_tail:\n{$tail}\n");
        }

        $latestErrorOffset = false;
        foreach (['testing.ERROR:', 'testing.CRITICAL:', 'testing.ALERT:', 'testing.EMERGENCY:'] as $marker) {
            $offset = strrpos($logs, $marker);
            if ($offset !== false && ($latestErrorOffset === false || $offset > $latestErrorOffset)) {
                $latestErrorOffset = $offset;
            }
        }
        if ($latestErrorOffset !== false) {
            $errorExcerpt = trim(substr($logs, $latestErrorOffset, 24_000));
            fwrite(STDERR, "[h02-docker-ladder] web_{$vus}_latest_error:\n{$errorExcerpt}\n");
        }

        $laravelLog = h02LadderRunCommand([
            'docker.exe', 'compose', '-f', $compose, 'exec', '-T', 'web',
            'sh', '-lc', 'tail -n 500 storage/logs/laravel.log 2>/dev/null || true',
        ], 30_000);
        $laravelTail = trim(substr($laravelLog, -40_000));
        if ($laravelTail !== '') {
            fwrite(STDERR, "[h02-docker-ladder] laravel_{$vus}_log_tail:\n{$laravelTail}\n");
        }

        $innodbStatus = h02LadderRunCommand([
            'docker.exe', 'compose', '-f', $compose, 'exec', '-T', 'mysql',
            'sh', '-lc', 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SHOW ENGINE INNODB STATUS\\G"',
        ], 30_000);
        $innodbTail = trim(substr($innodbStatus, -30_000));
        if ($innodbTail !== '') {
            fwrite(STDERR, "[h02-docker-ladder] innodb_{$vus}_status_tail:\n{$innodbTail}\n");
        }
    } catch (Throwable $error) {
        fwrite(STDERR, "[h02-docker-ladder] web_{$vus}_diagnostic_unavailable: ".$error->getMessage()."\n");
    }
}

function h02LadderExpectedRated(int $vus): int
{
    return intdiv($vus, 3);
}

/** @return array<string,mixed> */
function h02LadderRunThreshold(
    int $vus,
    string $k6Executable,
    string $k6Version,
    string $gitHead,
    string $tempDirectory,
): array {
    h02LadderEnsureTempDirectory($tempDirectory);
    $projectRoot = dirname(__DIR__, 2);
    $compose = $projectRoot.'/docker-compose.h02-testing.yml';
    $helper = 'tests/Support/run-h02-container-runtime.php';
    $baseUrl = 'http://127.0.0.1:8892';
    $scenario = $projectRoot.'/tests/load/h02-representative-workloads.js';
    $startedAt = microtime(true);

    h02LadderRunCommand(['docker.exe', 'compose', '-f', $compose, 'down'], 60_000);
    h02LadderRunCommand(['docker.exe', 'compose', '-f', $compose, 'up', '-d', '--no-build'], 120_000);
    h02LadderRunCommand(['docker.exe', 'compose', '-f', $compose, 'exec', '-T', 'web', 'php', 'artisan', 'migrate'], 120_000);
    h02LadderWaitForLogin($baseUrl.'/login');

    $runtimeEnvelope = h02LadderRunJsonCommand([
        'docker.exe', 'compose', '-f', $compose, 'exec', '-T', 'web', 'php', $helper, '--runtime',
    ]);
    $runtime = $runtimeEnvelope['runtime'] ?? null;
    if (! is_array($runtime)
        || ($runtime['server_profile'] ?? null) !== 'docker_apache_testing'
        || ($runtime['capacity_representative'] ?? null) !== true
        || (int) ($runtime['apache_processes'] ?? 0) < 2
    ) {
        throw new H02DockerLadderFailure('H02_LADDER_APACHE_CONCURRENCY_UNPROVEN');
    }

    $provisionEnvelope = h02LadderRunJsonCommand([
        'docker.exe', 'compose', '-f', $compose, 'exec', '-T', 'web', 'php', $helper, '--provision', '--vus='.$vus,
    ]);
    $rows = $provisionEnvelope['rows'] ?? null;
    if (! is_array($rows) || count($rows) !== $vus) {
        throw new H02DockerLadderFailure('H02_LADDER_FIXTURE_PROVISION_FAILED');
    }

    $fixturePath = $tempDirectory.DIRECTORY_SEPARATOR."fixtures-{$vus}.json";
    $summaryPath = $tempDirectory.DIRECTORY_SEPARATOR."k6-summary-{$vus}.json";
    $fixtureJson = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($fixturePath, $fixtureJson, LOCK_EX) !== strlen($fixtureJson)) {
        throw new H02DockerLadderFailure('H02_LADDER_FIXTURE_FILE_FAILED');
    }

    $environment = getenv();
    if (! is_array($environment)) {
        throw new H02DockerLadderFailure('H02_LADDER_ENVIRONMENT_UNAVAILABLE');
    }
    $k6Environment = array_merge($environment, [
        'H02_BASE_URL' => $baseUrl,
        'H02_VUS' => (string) $vus,
        'H02_FIXTURES_PATH' => str_replace('\\', '/', $fixturePath),
        'H02_K6_SUMMARY_PATH' => str_replace('\\', '/', $summaryPath),
    ]);

    $sampler = h01StartProcess([
        'docker.exe', 'compose', '-f', $compose, 'exec', '-T', 'web', 'php', $helper,
        '--sample', '--sample-count=60', '--sample-ms=100',
    ], $projectRoot, $environment, $tempDirectory, "sampler-{$vus}");
    usleep(100_000);
    $k6 = h01StartProcess([
        $k6Executable, 'run', '--quiet', $scenario,
    ], $projectRoot, $k6Environment, $tempDirectory, "k6-{$vus}");

    try {
        $k6Exit = h02LadderWaitChild($k6, 60_000);
        $samplerExit = h02LadderWaitChild($sampler, 30_000);
    } finally {
        if (is_resource($k6['process'] ?? null)) {
            h01StopProcess($k6['process']);
        }
        if (is_resource($sampler['process'] ?? null)) {
            h01StopProcess($sampler['process']);
        }
    }

    if ($k6Exit !== 0) {
        $tail = h01DiagnosticTail($k6['stderr']);
        if ($tail !== '') {
            fwrite(STDERR, "[h02-docker-ladder] k6_{$vus}_stderr_tail:\n{$tail}\n");
        }
        h02LadderEmitWebDiagnostic($compose, $vus);
        throw new H02DockerLadderFailure('H02_LADDER_K6_FAILED');
    }
    if ($samplerExit !== 0) {
        h02LadderEmitWebDiagnostic($compose, $vus);
        throw new H02DockerLadderFailure('H02_LADDER_SAMPLER_FAILED');
    }
    if (! is_file($summaryPath)) {
        throw new H02DockerLadderFailure('H02_LADDER_K6_SUMMARY_MISSING');
    }

    $k6Summary = json_decode((string) file_get_contents($summaryPath), true, flags: JSON_THROW_ON_ERROR);
    $sampleEnvelope = json_decode((string) file_get_contents($sampler['stdout']), true, flags: JSON_THROW_ON_ERROR);
    $samples = $sampleEnvelope['samples'] ?? null;
    if (! is_array($k6Summary) || ! is_array($samples) || count($samples) < 2) {
        throw new H02DockerLadderFailure('H02_LADDER_MEASUREMENT_INVALID');
    }

    $expectedRated = h02LadderExpectedRated($vus);
    $verificationEnvelope = h02LadderRunJsonCommand([
        'docker.exe', 'compose', '-f', $compose, 'exec', '-T', 'web', 'php', $helper,
        '--verify', '--vus='.$vus, '--expected-rated='.$expectedRated,
    ]);
    $verification = $verificationEnvelope['verification'] ?? null;
    if (! is_array($verification) || ($verification['ok'] ?? null) !== true) {
        throw new H02DockerLadderFailure('H02_LADDER_FORMAL_RATING_INVALID');
    }

    $logs = h02LadderRunCommand(['docker.exe', 'compose', '-f', $compose, 'logs', '--no-color', 'web'], 30_000);
    $laravelErrors = h02LadderNewLaravelErrors($logs);
    if ($laravelErrors !== 0) {
        throw new H02DockerLadderFailure('H02_LADDER_LARAVEL_ERRORS');
    }

    $measurementRuntime = array_merge($runtime, [
        'git_head' => $gitHead,
        'scenario' => 'h02_representative_reading_lookup_sense_review',
        'k6' => $k6Version,
        'base_url' => $baseUrl,
        'vus' => $vus,
        'duration' => 'per-vu-iterations',
        'sample_ms' => 100,
    ]);
    $measurement = H01LoadObservabilityHarness::buildFinalSummary(
        $k6Summary,
        $samples,
        $measurementRuntime,
        $laravelErrors,
        microtime(true) - $startedAt,
    );

    if (($measurement['http']['failed_rate'] ?? null) !== 0
        || ($measurement['http']['checks_rate'] ?? null) !== 1
    ) {
        throw new H02DockerLadderFailure('H02_LADDER_HTTP_GATE_FAILED');
    }

    return [
        'vus' => $vus,
        'expected_rated' => $expectedRated,
        'measurement' => $measurement,
        'verification' => $verification,
    ];
}

function h02LadderAcquireLock()
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'linguacafe-h02-docker-ladder.lock';
    $handle = @fopen($path, 'c+');
    if (! is_resource($handle)) {
        throw new H02DockerLadderFailure('H02_LADDER_LOCK_UNAVAILABLE');
    }
    if (! @flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        throw new H02DockerLadderFailure('H02_LADDER_ALREADY_RUNNING');
    }

    return $handle;
}

function runH02DockerLadderCli(array $arguments): int
{
    unset($arguments);
    $projectRoot = dirname(__DIR__, 2);
    $compose = $projectRoot.'/docker-compose.h02-testing.yml';
    $tempDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'linguacafe-h02-ladder-'.bin2hex(random_bytes(8));
    $results = [];
    $lock = null;

    try {
        $lock = h02LadderAcquireLock();
        h02LadderEnsureTempDirectory($tempDirectory);

        h02LadderRunCommand(['docker.exe', 'compose', '-f', $compose, 'down'], 60_000);
        $environmentGate = h02RunEnvironmentGate(projectRoot: $projectRoot);
        if (($environmentGate['ready'] ?? null) !== true) {
            fwrite(STDERR, json_encode($environmentGate, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            throw new H02DockerLadderFailure('H02_LADDER_ENVIRONMENT_NOT_READY');
        }

        $k6Executable = H01LoadObservabilityHarness::resolveExecutable('k6');
        if ($k6Executable === null) {
            throw new H02DockerLadderFailure('H02_LADDER_K6_NOT_FOUND');
        }
        $environment = getenv();
        if (! is_array($environment)) {
            throw new H02DockerLadderFailure('H02_LADDER_ENVIRONMENT_UNAVAILABLE');
        }
        $k6Version = h01K6Version($k6Executable, $projectRoot, $environment, $tempDirectory);
        $gitHead = h02LadderRunCommand(['git', 'rev-parse', 'HEAD'], 30_000);
        if (preg_match('/\A[0-9a-f]{40}\z/i', $gitHead) !== 1) {
            throw new H02DockerLadderFailure('H02_LADDER_GIT_HEAD_INVALID');
        }

        h02LadderRunCommand(['docker.exe', 'compose', '-f', $compose, 'build', '--quiet', 'web'], 600_000);

        foreach ([1, 10, 25, 50, 100] as $vus) {
            $results[] = h02LadderRunThreshold($vus, $k6Executable, $k6Version, $gitHead, $tempDirectory);
        }

        fwrite(STDOUT, json_encode([
            'schema_version' => 1,
            'tool' => 'linguacafe-h02-docker-ladder',
            'git_head' => $gitHead,
            'thresholds' => $results,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        return 0;
    } catch (H02DockerLadderFailure $error) {
        fwrite(STDERR, '[h02-docker-ladder] '.$error->machineCode."\n");

        return 78;
    } catch (Throwable $error) {
        fwrite(STDERR, '[h02-docker-ladder] H02_LADDER_UNEXPECTED_FAILURE: '.$error->getMessage()."\n");

        return 78;
    } finally {
        if (is_resource($lock)) {
            try {
                h02LadderRunCommand(['docker.exe', 'compose', '-f', $compose, 'down'], 60_000);
            } catch (Throwable) {
                fwrite(STDERR, "[h02-docker-ladder] H02_LADDER_CLEANUP_FAILED\n");
            }
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
        if (is_dir($tempDirectory)) {
            h01RemoveTempDirectory($tempDirectory);
        }
    }
}

$scriptPath = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($scriptPath)
    && realpath($scriptPath) !== false
    && realpath($scriptPath) === realpath(__FILE__)
) {
    exit(runH02DockerLadderCli($argv));
}
