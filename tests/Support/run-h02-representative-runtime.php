<?php

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\ReviewCardService;
use App\Services\WordSenseService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/H02RepresentativeFixtureSupport.php';
require_once __DIR__.'/run-pab-r3-browser-acceptance.php';
require_once __DIR__.'/run-h01-load-observability.php';

final class H02RepresentativeRuntimeFailure extends RuntimeException
{
    public function __construct(public readonly string $machineCode, ?Throwable $previous = null)
    {
        parent::__construct($machineCode, 0, $previous);
    }
}

/**
 * Build the deterministic per-VU manifest consumed by the existing k6
 * workload. This slice owns the manifest contract; it does not claim that these records have been inserted into a database.
 *
 * @return list<array{email:string,password:string,lemma:string,language:string}>
 */
function h02PrepareFixtureRows(int $vus): array
{
    try {
        return H02RepresentativeFixtureSupport::prepareRows($vus);
    } catch (InvalidArgumentException $error) {
        throw new H02RepresentativeRuntimeFailure($error->getMessage(), $error);
    }
}

function h02CleanupFixtures(string $manifestPath): void
{
    if ($manifestPath === '' || ! is_file($manifestPath)) {
        return;
    }
    if (! @unlink($manifestPath) && is_file($manifestPath)) {
        throw new H02RepresentativeRuntimeFailure('H02_FIXTURE_CLEANUP_FAILED');
    }
}

function h02CreateFixtureManifest(array $fixtureRows): string
{
    $manifestPath = tempnam(sys_get_temp_dir(), 'linguacafe-h02-fixture-');
    if (! is_string($manifestPath)) {
        throw new H02RepresentativeRuntimeFailure('H02_FIXTURE_MANIFEST_CREATE_FAILED');
    }

    try {
        $encoded = json_encode(
            $fixtureRows,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $written = @file_put_contents($manifestPath, $encoded, LOCK_EX);
        if ($written !== strlen($encoded)) {
            throw new H02RepresentativeRuntimeFailure('H02_FIXTURE_MANIFEST_WRITE_FAILED');
        }
        @chmod($manifestPath, 0600);

        return $manifestPath;
    } catch (H02RepresentativeRuntimeFailure $error) {
        @unlink($manifestPath);
        throw $error;
    } catch (Throwable $error) {
        @unlink($manifestPath);
        throw new H02RepresentativeRuntimeFailure(
            'H02_FIXTURE_MANIFEST_WRITE_FAILED',
            $error,
        );
    }
}

/** @return list<array{email:string,password:string,lemma:string,language:string}> */
function h02ReadFixtureManifest(string $manifestPath, int $vus): array
{
    if (! is_file($manifestPath)) {
        throw new H02RepresentativeRuntimeFailure('H02_FIXTURE_MANIFEST_MISSING');
    }

    try {
        $decoded = json_decode(
            (string) file_get_contents($manifestPath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    } catch (Throwable $error) {
        throw new H02RepresentativeRuntimeFailure(
            'H02_FIXTURE_MANIFEST_INVALID',
            $error,
        );
    }

    if (! is_array($decoded)) {
        throw new H02RepresentativeRuntimeFailure('H02_FIXTURE_MANIFEST_INVALID');
    }
    if (count($decoded) < $vus) {
        throw new H02RepresentativeRuntimeFailure('H02_FIXTURE_ROWS_INSUFFICIENT');
    }

    $rows = array_slice($decoded, 0, $vus);
    $emails = [];
    foreach ($rows as $row) {
        if (! is_array($row)
            || ! is_string($row['email'] ?? null)
            || ! is_string($row['password'] ?? null)
            || ! is_string($row['lemma'] ?? null)
            || ($row['language'] ?? null) !== 'en'
        ) {
            throw new H02RepresentativeRuntimeFailure('H02_FIXTURE_ROW_INVALID');
        }

        if (isset($emails[$row['email']])) {
            throw new H02RepresentativeRuntimeFailure('H02_FIXTURE_IDENTITY_DUPLICATE');
        }
        $emails[$row['email']] = true;
    }

    return array_values($rows);
}

/**
 * Insert only the rows assigned to this measurement and return the actual
 * database identifiers consumed by k6.
 *
 * @param list<array<string,mixed>> $fixtureRows
 * @return array{
 *     rows:list<array<string,mixed>>,
 *     user_ids:list<int>,
 *     book_ids:list<int>,
 *     chapter_ids:list<int>,
 *     sense_ids:list<int>,
 *     review_card_ids:list<int>
 * }
 */
function h02ProvisionDatabaseFixtures(array $fixtureRows): array
{
    try {
        return H02RepresentativeFixtureSupport::provision($fixtureRows);
    } catch (RuntimeException $error) {
        throw new H02RepresentativeRuntimeFailure($error->getMessage(), $error);
    }
}

function h02CleanupDatabaseFixtures(array $fixtureState): void
{
    try {
        H02RepresentativeFixtureSupport::cleanup($fixtureState);
    } catch (RuntimeException $error) {
        throw new H02RepresentativeRuntimeFailure($error->getMessage(), $error);
    }
}

function h02ResolveSmokeRuntime(int $vus): array
{
    if ($vus >= 100) {
        throw new H02RepresentativeRuntimeFailure('H02_CAPACITY_REQUIRES_DOCKER_LADDER');
    }

    return [
        'server_profile' => 'external_apache_testing_runtime',
        'capacity_representative' => false,
    ];
}

function h02DurationMilliseconds(string $duration): int
{
    if (preg_match('/^(\d+)(ms|s|m)$/', $duration, $matches) !== 1) {
        throw new H02RepresentativeRuntimeFailure('H02_DURATION_INVALID');
    }

    $milliseconds = (int) $matches[1] * match ($matches[2]) {
        'ms' => 1,
        's' => 1000,
        'm' => 60_000,
    };
    if ($milliseconds < 1000 || $milliseconds > 1_800_000) {
        throw new H02RepresentativeRuntimeFailure('H02_DURATION_INVALID');
    }

    return $milliseconds;
}

/** @return array{port:int,vus:int,duration:string,sample_ms:int,wait_ms:int} */
function h02ParseRuntimeArguments(array $arguments): array
{
    array_shift($arguments);
    $options = [
        'port' => 8892,
        'vus' => 1,
        'duration' => '3s',
        'sample_ms' => 250,
        'wait_ms' => 0,
    ];

    foreach ($arguments as $argument) {
        if (! is_string($argument)) {
            throw new H02RepresentativeRuntimeFailure('H02_ARGUMENT_INVALID');
        }
        if (preg_match('/^--port=(\d+)$/', $argument, $matches) === 1) {
            $options['port'] = (int) $matches[1];
            continue;
        }
        if (preg_match('/^--vus=(\d+)$/', $argument, $matches) === 1) {
            $options['vus'] = (int) $matches[1];
            continue;
        }
        if (preg_match('/^--duration=(\d+(?:ms|s|m))$/', $argument, $matches) === 1) {
            h02DurationMilliseconds($matches[1]);
            $options['duration'] = $matches[1];
            continue;
        }
        if (preg_match('/^--sample-ms=(\d+)$/', $argument, $matches) === 1) {
            $options['sample_ms'] = (int) $matches[1];
            continue;
        }
        if (preg_match('/^--wait-ms=(\d+)$/', $argument, $matches) === 1) {
            $options['wait_ms'] = (int) $matches[1];
            continue;
        }

        throw new H02RepresentativeRuntimeFailure('H02_ARGUMENT_INVALID');
    }

    if ($options['port'] < 1024 || $options['port'] > 65535) {
        throw new H02RepresentativeRuntimeFailure('H02_PORT_INVALID');
    }
    if ($options['vus'] < 1 || $options['vus'] > 1000) {
        throw new H02RepresentativeRuntimeFailure('H02_VUS_INVALID');
    }
    if ($options['sample_ms'] < 100 || $options['sample_ms'] > 5000) {
        throw new H02RepresentativeRuntimeFailure('H02_SAMPLE_INTERVAL_INVALID');
    }
    if ($options['wait_ms'] > 3_600_000) {
        throw new H02RepresentativeRuntimeFailure('H02_WAIT_INVALID');
    }

    return $options;
}

/** @return array{port:int,vus:int,duration:string,sample_ms:int,wait_ms:int,fixture_path:string} */
function h02ParseMeasurementArguments(array $arguments): array
{
    if (($arguments[1] ?? null) !== '--measure') {
        throw new H02RepresentativeRuntimeFailure('H02_MEASUREMENT_MODE_REQUIRED');
    }

    $fixturePath = '';
    $runArguments = [$arguments[0] ?? 'h02-runner.php'];
    foreach (array_slice($arguments, 2) as $argument) {
        if (! is_string($argument)) {
            throw new H02RepresentativeRuntimeFailure('H02_ARGUMENT_INVALID');
        }
        if (str_starts_with($argument, '--fixture-file=')) {
            $fixturePath = substr($argument, strlen('--fixture-file='));
            continue;
        }
        $runArguments[] = $argument;
    }

    if ($fixturePath === '') {
        throw new H02RepresentativeRuntimeFailure('H02_FIXTURE_MANIFEST_REQUIRED');
    }

    return array_merge(
        h02ParseRuntimeArguments($runArguments),
        ['fixture_path' => $fixturePath],
    );
}

/**
 * @param list<array<string,mixed>> $fixtureRows
 */
function h02RunMeasurement(array $options, array $fixtureRows): int
{
    H01LoadObservabilityHarness::assertRuntimeBoundary(
        (string) (getenv('APP_ENV') ?: ''),
        getenv('LINGUACAFE_TEST_SENTINEL'),
    );
    if (count($fixtureRows) < $options['vus']) {
        throw new H02RepresentativeRuntimeFailure('H02_FIXTURE_ROWS_INSUFFICIENT');
    }

    $projectRoot = dirname(__DIR__, 2);
    $scenarioPath = $projectRoot.'/tests/load/h02-representative-workloads.js';
    if (! is_file($scenarioPath)) {
        throw new H02RepresentativeRuntimeFailure('H02_SCENARIO_MISSING');
    }

    $k6Executable = H01LoadObservabilityHarness::resolveExecutable('k6');
    if ($k6Executable === null) {
        throw new H02RepresentativeRuntimeFailure('H02_K6_NOT_FOUND');
    }

    $environment = getenv();
    if (! is_array($environment)) {
        throw new H02RepresentativeRuntimeFailure('H02_ENVIRONMENT_UNAVAILABLE');
    }

    $application = require $projectRoot.'/bootstrap/app.php';
    $kernel = $application->make(Kernel::class);
    $kernel->bootstrap();
    if (! $application->environment('testing')) {
        throw new H02RepresentativeRuntimeFailure('H02_APPLICATION_NOT_TESTING');
    }

    $databaseName = DB::connection()->getDatabaseName();
    if (! is_string($databaseName) || ! str_contains(strtolower($databaseName), 'test')) {
        throw new H02RepresentativeRuntimeFailure('H02_DATABASE_NOT_TESTING');
    }

    $queueConnection = (string) config('queue.default');
    $queueDriver = (string) config("queue.connections.{$queueConnection}.driver", 'unknown');
    $queueName = (string) (config("queue.connections.{$queueConnection}.queue") ?: 'default');
    $baseUrl = 'http://127.0.0.1:'.$options['port'];
    $capacityRuntime = h02ResolveSmokeRuntime($options['vus']);
    $startedAt = microtime(true);
    $fixtureState = null;
    $child = null;
    $tempDirectory = '';

    try {
        $fixtureState = h02ProvisionDatabaseFixtures(
            array_slice($fixtureRows, 0, $options['vus'])
        );
        if (! is_array($fixtureState) || ! is_array($fixtureState['rows'] ?? null)) {
            throw new H02RepresentativeRuntimeFailure('H02_FIXTURE_STATE_INVALID');
        }

        $fixtureJson = json_encode(
            array_slice($fixtureState['rows'], 0, $options['vus']),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $tempDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'linguacafe-h02-'.bin2hex(random_bytes(8));
        if (! @mkdir($tempDirectory, 0700, true) && ! is_dir($tempDirectory)) {
            throw new H02RepresentativeRuntimeFailure('H02_TEMP_DIRECTORY_FAILED');
        }

        $summaryFile = $tempDirectory.DIRECTORY_SEPARATOR.'k6-summary.json';
        $logDirectory = storage_path('logs');
        $logOffsets = H01LoadObservabilityHarness::captureLogOffsets($logDirectory);
        $k6Environment = array_merge($environment, [
            'H02_BASE_URL' => $baseUrl,
            'H02_VUS' => (string) $options['vus'],
            'H02_FIXTURES_JSON' => $fixtureJson,
        ]);
        $samples = [];
        $lastStatus = null;

        $child = h01StartProcess(
            [
                $k6Executable,
                'run',
                '--quiet',
                '--summary-export',
                $summaryFile,
                $scenarioPath,
            ],
            $projectRoot,
            $k6Environment,
            $tempDirectory,
            'k6',
        );

        do {
            $samples[] = h01CollectSample($queueConnection, $queueName);
            usleep($options['sample_ms'] * 1000);
            $lastStatus = @proc_get_status($child['process']);
        } while (is_array($lastStatus) && ($lastStatus['running'] ?? false));

        $closeCode = proc_close($child['process']);
        $child['process'] = null;
        $exitCode = $closeCode;
        if ($exitCode === -1 && is_array($lastStatus) && is_int($lastStatus['exitcode'] ?? null)) {
            $exitCode = $lastStatus['exitcode'];
        }
        if ($exitCode !== 0) {
            throw new H02RepresentativeRuntimeFailure('H02_K6_FAILED');
        }
        if (! is_file($summaryFile)) {
            throw new H02RepresentativeRuntimeFailure('H02_K6_SUMMARY_MISSING');
        }

        $k6Summary = json_decode(
            (string) file_get_contents($summaryFile),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (! is_array($k6Summary)) {
            throw new H02RepresentativeRuntimeFailure('H02_K6_SUMMARY_INVALID');
        }

        $laravelErrors = H01LoadObservabilityHarness::countNewLaravelErrors($logDirectory, $logOffsets);
        if (count($samples) < 2) {
            throw new H02RepresentativeRuntimeFailure('H02_INSUFFICIENT_SAMPLES');
        }

        $runtime = [
            'php' => PHP_VERSION,
            'os_family' => PHP_OS_FAMILY,
            'server_profile' => $capacityRuntime['server_profile'],
            'capacity_representative' => $capacityRuntime['capacity_representative'],
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
            $k6Summary,
            $samples,
            $runtime,
            $laravelErrors,
            microtime(true) - $startedAt,
        );

        fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        return 0;
    } finally {
        if (is_array($child) && is_resource($child['process'])) {
            h01StopProcess($child['process']);
        }
        if ($tempDirectory !== '') {
            h01RemoveTempDirectory($tempDirectory);
        }
        if (is_array($fixtureState)) {
            h02CleanupDatabaseFixtures($fixtureState);
        }
    }
}

function runH02RepresentativeRuntimeCli(array $arguments): int
{
    if (($arguments[1] ?? null) === '--measure') {
        try {
            $options = h02ParseMeasurementArguments($arguments);
            $fixtureRows = h02ReadFixtureManifest($options['fixture_path'], $options['vus']);

            return h02RunMeasurement($options, $fixtureRows);
        } catch (H02RepresentativeRuntimeFailure $error) {
            fwrite(STDERR, '[h02-representative-runtime] '.$error->machineCode."\n");

            return 78;
        } catch (Throwable $error) {
            fwrite(STDERR, '[h02-representative-runtime] H02_UNEXPECTED_FAILURE: '.$error->getMessage()."\n");

            return 78;
        }
    }

    try {
        $options = h02ParseRuntimeArguments($arguments);
        $fixtureRows = h02PrepareFixtureRows($options['vus']);
        if (count($fixtureRows) < $options['vus']) {
            throw new H02RepresentativeRuntimeFailure('H02_FIXTURE_ROWS_INSUFFICIENT');
        }
        $manifestPath = h02CreateFixtureManifest($fixtureRows);
    } catch (H02RepresentativeRuntimeFailure $error) {
        fwrite(STDERR, '[h02-representative-runtime] '.$error->machineCode."\n");

        return 78;
    } catch (Throwable $error) {
        fwrite(STDERR, '[h02-representative-runtime] H02_UNEXPECTED_FAILURE: '.$error->getMessage()."\n");

        return 78;
    }

    $cleaned = false;
    $cleanup = static function () use (&$cleaned, $manifestPath): void {
        if ($cleaned) {
            return;
        }
        try {
            $ownedManifestPath = $manifestPath;
        } finally {
            h02CleanupFixtures($ownedManifestPath);
            $cleaned = true;
        }
    };
    register_shutdown_function($cleanup);

    $pabArguments = [
        __FILE__,
        '--label=h02-representative-runtime',
        '--wait-ms='.$options['wait_ms'],
        '--',
        PHP_BINARY,
        __FILE__,
        '--measure',
        '--fixture-file='.$manifestPath,
        '--port='.$options['port'],
        '--vus='.$options['vus'],
        '--duration='.$options['duration'],
        '--sample-ms='.$options['sample_ms'],
    ];
    $exitCode = runPabR3BrowserAcceptanceCli($pabArguments);
    $cleanup();

    return $exitCode;
}

$scriptPath = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($scriptPath)
    && realpath($scriptPath) !== false
    && realpath($scriptPath) === realpath(__FILE__)
) {
    exit(runH02RepresentativeRuntimeCli($argv));
}
