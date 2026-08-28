<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/H01ObservabilitySampleSupport.php';
require_once __DIR__.'/run-pab-r3-browser-acceptance.php';

final class H01LoadObservabilityFailure extends RuntimeException
{
    public function __construct(public readonly string $machineCode, ?Throwable $previous = null)
    {
        parent::__construct($machineCode, 0, $previous);
    }
}

final class H01LoadObservabilityHarness
{
    public const SCHEMA_VERSION = 1;

    public const SENTINEL_REQUEST_TIMEOUT_SECONDS = 5.0;

    public const SENTINEL_READY_DEADLINE_SECONDS = 15.0;

    /** @return array{port:int,vus:int,duration:string,sample_ms:int} */
    public static function parseArguments(array $arguments): array
    {
        array_shift($arguments);
        $options = [
            'port' => 8891,
            'vus' => 4,
            'duration' => '3s',
            'sample_ms' => 250,
        ];

        foreach ($arguments as $argument) {
            if (preg_match('/^--port=(\d+)$/', $argument, $matches) === 1) {
                $options['port'] = (int) $matches[1];
                continue;
            }
            if (preg_match('/^--vus=(\d+)$/', $argument, $matches) === 1) {
                $options['vus'] = (int) $matches[1];
                continue;
            }
            if (preg_match('/^--duration=(\d+)(ms|s|m)$/', $argument, $matches) === 1) {
                $durationMilliseconds = (int) $matches[1] * match ($matches[2]) {
                    'ms' => 1,
                    's' => 1000,
                    'm' => 60_000,
                };
                if ($durationMilliseconds < 1000 || $durationMilliseconds > 1_800_000) {
                    throw new H01LoadObservabilityFailure('H01_DURATION_INVALID');
                }
                $options['duration'] = $matches[1].$matches[2];
                continue;
            }
            if (preg_match('/^--sample-ms=(\d+)$/', $argument, $matches) === 1) {
                $options['sample_ms'] = (int) $matches[1];
                continue;
            }

            throw new H01LoadObservabilityFailure('H01_ARGUMENT_INVALID');
        }

        if ($options['port'] < 1024 || $options['port'] > 65535) {
            throw new H01LoadObservabilityFailure('H01_PORT_INVALID');
        }
        if ($options['vus'] < 1 || $options['vus'] > 1000) {
            throw new H01LoadObservabilityFailure('H01_VUS_INVALID');
        }
        if ($options['sample_ms'] < 100 || $options['sample_ms'] > 5000) {
            throw new H01LoadObservabilityFailure('H01_SAMPLE_INTERVAL_INVALID');
        }

        return $options;
    }

    public static function assertRuntimeBoundary(string $environment, string|false $sentinel): void
    {
        if (strtolower(trim($environment)) !== 'testing') {
            throw new H01LoadObservabilityFailure('H01_ENV_NOT_TESTING');
        }
        if (! is_string($sentinel)
            || ! str_starts_with($sentinel, PabR3BrowserAcceptanceHarness::SENTINEL_PREFIX)
        ) {
            throw new H01LoadObservabilityFailure('H01_PAB_SENTINEL_REQUIRED');
        }
    }

    public static function resolveExecutable(
        string $name,
        ?string $pathValue = null,
        ?string $osFamily = null,
    ): ?string {
        $pathValue ??= (string) (getenv('PATH') ?: '');
        $osFamily ??= PHP_OS_FAMILY;
        $extensions = $osFamily === 'Windows' ? ['', '.exe', '.cmd', '.bat', '.com'] : [''];

        foreach (array_filter(explode(PATH_SEPARATOR, $pathValue), static fn (string $part): bool => $part !== '') as $directory) {
            $directory = trim($directory, " \t\n\r\0\x0B\"");
            foreach ($extensions as $extension) {
                $candidate = rtrim($directory, '\\/').DIRECTORY_SEPARATOR.$name.$extension;
                if (is_file($candidate)) {
                    return realpath($candidate) ?: $candidate;
                }
            }
        }

        return null;
    }

    /** @param list<array<string,int|float>> $samples
     *  @return array{min:int|float,max:int|float,last:int|float}
     */
    public static function summarizeSeries(array $samples, string $key): array
    {
        $values = array_values(array_map(
            static fn (array $sample): int|float => $sample[$key],
            array_filter($samples, static fn (array $sample): bool => isset($sample[$key]) && is_numeric($sample[$key])),
        ));
        if ($values === []) {
            throw new H01LoadObservabilityFailure('H01_SAMPLE_SERIES_EMPTY');
        }

        return [
            'min' => min($values),
            'max' => max($values),
            'last' => $values[array_key_last($values)],
        ];
    }

    public static function k6MetricValue(array $summary, string $metric, string $value): int|float|null
    {
        $candidate = $summary['metrics'][$metric]['values'][$value] ?? null;

        return is_int($candidate) || is_float($candidate) ? $candidate : null;
    }

    /** @return array<string,int> */
    public static function captureLogOffsets(string $logDirectory): array
    {
        $offsets = [];
        foreach (glob(rtrim($logDirectory, '\\/').DIRECTORY_SEPARATOR.'*.log') ?: [] as $path) {
            $size = filesize($path);
            if (is_int($size) && $size >= 0) {
                $offsets[$path] = $size;
            }
        }

        return $offsets;
    }

    public static function countNewLaravelErrors(string $logDirectory, array $offsets): int
    {
        $count = 0;
        foreach (glob(rtrim($logDirectory, '\\/').DIRECTORY_SEPARATOR.'*.log') ?: [] as $path) {
            $handle = @fopen($path, 'rb');
            if (! is_resource($handle)) {
                continue;
            }
            try {
                $offset = (int) ($offsets[$path] ?? 0);
                if ($offset > 0) {
                    fseek($handle, $offset);
                }
                $newContent = stream_get_contents($handle);
                if (is_string($newContent) && $newContent !== '') {
                    $count += preg_match_all('/\b(?:ERROR|CRITICAL|ALERT|EMERGENCY):/', $newContent);
                }
            } finally {
                fclose($handle);
            }
        }

        return $count;
    }

    public static function buildFinalSummary(
        array $k6Summary,
        array $samples,
        array $runtime,
        int $laravelErrorEntries,
        float $elapsedSeconds,
    ): array {
        $requiredMetrics = [
            'requests' => self::k6MetricValue($k6Summary, 'http_reqs', 'count'),
            'failed_rate' => self::k6MetricValue($k6Summary, 'http_req_failed', 'rate'),
            'checks_rate' => self::k6MetricValue($k6Summary, 'checks', 'rate'),
            'avg' => self::k6MetricValue($k6Summary, 'http_req_duration', 'avg'),
            'p95' => self::k6MetricValue($k6Summary, 'http_req_duration', 'p(95)'),
            'p99' => self::k6MetricValue($k6Summary, 'http_req_duration', 'p(99)'),
            'max' => self::k6MetricValue($k6Summary, 'http_req_duration', 'max'),
        ];
        if (in_array(null, $requiredMetrics, true)) {
            throw new H01LoadObservabilityFailure('H01_K6_REQUIRED_METRIC_MISSING');
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'tool' => 'linguacafe-h01-load-observability',
            'scenario' => $runtime['scenario'] ?? 'h01_sentinel_smoke',
            'runtime' => $runtime,
            'elapsed_seconds' => round($elapsedSeconds, 3),
            'sample_count' => count($samples),
            'http' => [
                'requests' => $requiredMetrics['requests'],
                'failed_rate' => $requiredMetrics['failed_rate'],
                'checks_rate' => $requiredMetrics['checks_rate'],
                'duration_ms' => [
                    'avg' => $requiredMetrics['avg'],
                    'p95' => $requiredMetrics['p95'],
                    'p99' => $requiredMetrics['p99'],
                    'max' => $requiredMetrics['max'],
                ],
            ],
            'mysql' => [
                'threads_connected' => self::summarizeSeries($samples, 'threads_connected'),
                'threads_running' => self::summarizeSeries($samples, 'threads_running'),
            ],
            'queue' => [
                'connection' => $runtime['queue_connection'],
                'driver' => $runtime['queue_driver'],
                'name' => $runtime['queue_name'],
                'backlog' => self::summarizeSeries($samples, 'queue_backlog'),
            ],
            'errors' => [
                'http_failed_rate' => $requiredMetrics['failed_rate'],
                'laravel_error_entries' => $laravelErrorEntries,
            ],
        ];
    }
}

/** @return array{process:resource,stdout:string,stderr:string} */
function h01StartProcess(array $command, string $workingDirectory, array $environment, string $tempDirectory, string $label): array
{
    $stdout = $tempDirectory.DIRECTORY_SEPARATOR.$label.'.stdout.log';
    $stderr = $tempDirectory.DIRECTORY_SEPARATOR.$label.'.stderr.log';
    $descriptors = [
        0 => ['file', 'php://stdin', 'r'],
        1 => ['file', $stdout, 'a'],
        2 => ['file', $stderr, 'a'],
    ];
    $process = @proc_open(
        $command,
        $descriptors,
        $pipes,
        $workingDirectory,
        $environment,
        ['bypass_shell' => true],
    );
    if (! is_resource($process)) {
        throw new H01LoadObservabilityFailure('H01_PROCESS_START_FAILED');
    }

    return compact('process', 'stdout', 'stderr');
}

function h01DiagnosticTail(string $path, int $maxBytes = 4000): string
{
    if (! is_file($path)) {
        return '';
    }
    $size = filesize($path);
    if (! is_int($size) || $size <= 0) {
        return '';
    }
    $handle = @fopen($path, 'rb');
    if (! is_resource($handle)) {
        return '';
    }
    try {
        if ($size > $maxBytes) {
            fseek($handle, -$maxBytes, SEEK_END);
        }
        $content = stream_get_contents($handle);

        return is_string($content) ? trim($content) : '';
    } finally {
        fclose($handle);
    }
}

function h01StopProcess($process): bool
{
    if (! is_resource($process)) {
        return true;
    }
    $status = @proc_get_status($process);
    if (is_array($status) && ($status['running'] ?? false)) {
        @proc_terminate($process);
        $deadline = microtime(true) + 2.0;
        do {
            usleep(50_000);
            $status = @proc_get_status($process);
        } while (is_array($status) && ($status['running'] ?? false) && microtime(true) < $deadline);
    }
    $status = @proc_get_status($process);
    if (is_array($status) && ($status['running'] ?? false)) {
        @proc_terminate($process, 9);
        usleep(100_000);
        $status = @proc_get_status($process);
    }
    @proc_close($process);

    return ! (is_array($status) && ($status['running'] ?? false));
}

/** @return array<string,mixed> */
function h01ReadSentinel(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'timeout' => H01LoadObservabilityHarness::SENTINEL_REQUEST_TIMEOUT_SECONDS,
            'ignore_errors' => true,
        ],
    ]);
    $deadline = microtime(true) + H01LoadObservabilityHarness::SENTINEL_READY_DEADLINE_SECONDS;
    do {
        $body = @file_get_contents($url, false, $context);
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)
                && ($decoded['environment'] ?? null) === 'testing'
                && ($decoded['database_is_testing'] ?? null) === true
                && ($decoded['sentinel_present'] ?? null) === true
            ) {
                return $decoded;
            }
        }
        usleep(100_000);
    } while (microtime(true) < $deadline);

    throw new H01LoadObservabilityFailure('H01_SENTINEL_NOT_READY');
}

/** @return array{threads_connected:int,threads_running:int,queue_backlog:int,timestamp_ms:int} */
function h01CollectSample(string $queueConnection, string $queueName): array
{
    try {
        return H01ObservabilitySampleSupport::collect($queueConnection, $queueName);
    } catch (RuntimeException $error) {
        throw new H01LoadObservabilityFailure($error->getMessage(), $error);
    }
}

function h01RunK6AndSample(
    string $k6Executable,
    string $scenarioPath,
    string $baseUrl,
    string $duration,
    int $vus,
    int $sampleMs,
    string $projectRoot,
    string $tempDirectory,
    array $environment,
    string $queueConnection,
    string $queueName,
): array {
    $summaryFile = $tempDirectory.DIRECTORY_SEPARATOR.'k6-summary.json';
    $k6Environment = array_merge($environment, [
        'H01_BASE_URL' => $baseUrl,
        'H01_DURATION' => $duration,
        'H01_VUS' => (string) $vus,
        'H01_K6_SUMMARY_PATH' => str_replace('\\', '/', $summaryFile),
    ]);
    $child = h01StartProcess(
        [$k6Executable, 'run', '--quiet', $scenarioPath],
        $projectRoot,
        $k6Environment,
        $tempDirectory,
        'k6',
    );

    $samples = [];
    $lastStatus = null;
    try {
        do {
            $samples[] = h01CollectSample($queueConnection, $queueName);
            usleep($sampleMs * 1000);
            $lastStatus = @proc_get_status($child['process']);
        } while (is_array($lastStatus) && ($lastStatus['running'] ?? false));

        $closeCode = proc_close($child['process']);
        $exitCode = $closeCode;
        if ($exitCode === -1 && is_array($lastStatus) && is_int($lastStatus['exitcode'] ?? null)) {
            $exitCode = $lastStatus['exitcode'];
        }
        if ($exitCode !== 0) {
            $stderr = is_file($child['stderr']) ? (string) file_get_contents($child['stderr']) : '';
            throw new H01LoadObservabilityFailure('H01_K6_FAILED'.($stderr !== '' ? ': '.trim($stderr) : ''));
        }
        if (! is_file($summaryFile)) {
            throw new H01LoadObservabilityFailure('H01_K6_SUMMARY_MISSING');
        }
        $summary = json_decode((string) file_get_contents($summaryFile), true);
        if (! is_array($summary)) {
            throw new H01LoadObservabilityFailure('H01_K6_SUMMARY_INVALID');
        }

        return ['summary' => $summary, 'samples' => $samples];
    } finally {
        if (is_resource($child['process'])) {
            h01StopProcess($child['process']);
        }
    }
}

function h01K6Version(string $k6Executable, string $projectRoot, array $environment, string $tempDirectory): string
{
    $child = h01StartProcess([$k6Executable, 'version'], $projectRoot, $environment, $tempDirectory, 'k6-version');
    $exitCode = proc_close($child['process']);
    if ($exitCode !== 0) {
        throw new H01LoadObservabilityFailure('H01_K6_VERSION_FAILED');
    }

    return trim((string) file_get_contents($child['stdout']));
}

function h01RemoveTempDirectory(string $directory): void
{
    $deadline = microtime(true) + 1.0;
    do {
        foreach (glob(rtrim($directory, '\\/').DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        clearstatcache(true, $directory);
        if (! is_dir($directory) || @rmdir($directory)) {
            return;
        }
        usleep(50_000);
    } while (microtime(true) < $deadline);
}

function runH01LoadObservabilityCli(array $arguments): int
{
    $server = null;
    $tempDirectory = '';
    $startedAt = microtime(true);

    try {
        $options = H01LoadObservabilityHarness::parseArguments($arguments);
        H01LoadObservabilityHarness::assertRuntimeBoundary(
            (string) (getenv('APP_ENV') ?: ''),
            getenv('LINGUACAFE_TEST_SENTINEL'),
        );

        $projectRoot = dirname(__DIR__, 2);
        $k6Executable = H01LoadObservabilityHarness::resolveExecutable('k6');
        if ($k6Executable === null) {
            throw new H01LoadObservabilityFailure('H01_K6_NOT_FOUND');
        }
        $scenarioPath = $projectRoot.'/tests/load/h01-sentinel-smoke.js';
        if (! is_file($scenarioPath)) {
            throw new H01LoadObservabilityFailure('H01_SCENARIO_MISSING');
        }

        $environment = getenv();
        if (! is_array($environment)) {
            throw new H01LoadObservabilityFailure('H01_ENVIRONMENT_UNAVAILABLE');
        }

        $tempDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'linguacafe-h01-'.bin2hex(random_bytes(8));
        if (! @mkdir($tempDirectory, 0700, true) && ! is_dir($tempDirectory)) {
            throw new H01LoadObservabilityFailure('H01_TEMP_DIRECTORY_FAILED');
        }

        $app = require $projectRoot.'/bootstrap/app.php';
        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();
        if (! $app->environment('testing')) {
            throw new H01LoadObservabilityFailure('H01_APPLICATION_NOT_TESTING');
        }
        $databaseName = DB::connection()->getDatabaseName();
        if (! is_string($databaseName) || ! str_contains(strtolower($databaseName), 'test')) {
            throw new H01LoadObservabilityFailure('H01_DATABASE_NOT_TESTING');
        }

        $queueConnection = (string) config('queue.default');
        $queueDriver = (string) config("queue.connections.{$queueConnection}.driver", 'unknown');
        $queueName = (string) (config("queue.connections.{$queueConnection}.queue") ?: 'default');
        $baseUrl = 'http://127.0.0.1:'.$options['port'];
        $server = h01StartProcess(
            [PHP_BINARY, '-S', '127.0.0.1:'.$options['port'], $projectRoot.'/tests/Support/pab-r3-browser-server.php'],
            $projectRoot,
            $environment,
            $tempDirectory,
            'server',
        );
        h01ReadSentinel($baseUrl.'/__testing/acceptance-sentinel');

        $logDirectory = storage_path('logs');
        $logOffsets = H01LoadObservabilityHarness::captureLogOffsets($logDirectory);
        $result = h01RunK6AndSample(
            $k6Executable,
            $scenarioPath,
            $baseUrl,
            $options['duration'],
            $options['vus'],
            $options['sample_ms'],
            $projectRoot,
            $tempDirectory,
            $environment,
            $queueConnection,
            $queueName,
        );
        $laravelErrors = H01LoadObservabilityHarness::countNewLaravelErrors($logDirectory, $logOffsets);
        $runtime = [
            'php' => PHP_VERSION,
            'os_family' => PHP_OS_FAMILY,
            'server_profile' => PHP_OS_FAMILY === 'Windows'
                ? 'php_builtin_windows_single_worker'
                : 'php_builtin_development_server',
            'capacity_representative' => false,
            'k6' => h01K6Version($k6Executable, $projectRoot, $environment, $tempDirectory),
            'database' => $databaseName,
            'queue_connection' => $queueConnection,
            'queue_driver' => $queueDriver,
            'queue_name' => $queueName,
            'base_url' => $baseUrl,
            'vus' => $options['vus'],
            'duration' => $options['duration'],
            'sample_ms' => $options['sample_ms'],
        ];
        $summary = H01LoadObservabilityHarness::buildFinalSummary(
            $result['summary'],
            $result['samples'],
            $runtime,
            $laravelErrors,
            microtime(true) - $startedAt,
        );
        if (count($result['samples']) < 2) {
            throw new H01LoadObservabilityFailure('H01_INSUFFICIENT_SAMPLES');
        }

        fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        return 0;
    } catch (H01LoadObservabilityFailure $error) {
        fwrite(STDERR, '[h01-load-observability] '.$error->machineCode."\n");
        if (is_array($server)) {
            foreach (['stdout', 'stderr'] as $stream) {
                $tail = h01DiagnosticTail((string) ($server[$stream] ?? ''));
                if ($tail !== '') {
                    fwrite(STDERR, "[h01-load-observability] server_{$stream}_tail:\n{$tail}\n");
                }
            }
        }

        return 78;
    } catch (Throwable $error) {
        fwrite(STDERR, '[h01-load-observability] H01_UNEXPECTED_FAILURE: '.$error->getMessage()."\n");
        if (is_array($server)) {
            foreach (['stdout', 'stderr'] as $stream) {
                $tail = h01DiagnosticTail((string) ($server[$stream] ?? ''));
                if ($tail !== '') {
                    fwrite(STDERR, "[h01-load-observability] server_{$stream}_tail:\n{$tail}\n");
                }
            }
        }

        return 78;
    } finally {
        if (is_array($server)) {
            if (! h01StopProcess($server['process'])) {
                fwrite(STDERR, "[h01-load-observability] H01_SERVER_CLEANUP_FAILED\n");
            }
        }
        if ($tempDirectory !== '') {
            h01RemoveTempDirectory($tempDirectory);
        }
    }
}

$scriptPath = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($scriptPath)
    && realpath($scriptPath) !== false
    && realpath($scriptPath) === realpath(__FILE__)
) {
    require_once __DIR__.'/../bootstrap.php';
    exit(runH01LoadObservabilityCli($argv));
}
