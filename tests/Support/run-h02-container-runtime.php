<?php

declare(strict_types=1);

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/H01ObservabilitySampleSupport.php';
require_once __DIR__.'/H02RepresentativeFixtureSupport.php';

final class H02ContainerRuntimeFailure extends RuntimeException
{
    public function __construct(public readonly string $machineCode, ?Throwable $previous = null)
    {
        parent::__construct($machineCode, 0, $previous);
    }
}

/** @return array{database:string,queue_connection:string,queue_driver:string,queue_name:string} */
function h02ContainerBootstrap(): array
{
    $projectRoot = dirname(__DIR__, 2);
    $application = require $projectRoot.'/bootstrap/app.php';
    $kernel = $application->make(Kernel::class);
    $kernel->bootstrap();

    if (! $application->environment('testing')) {
        throw new H02ContainerRuntimeFailure('H02_CONTAINER_ENV_NOT_TESTING');
    }

    $database = DB::connection()->getDatabaseName();
    if (! is_string($database) || ! str_contains(strtolower($database), 'test')) {
        throw new H02ContainerRuntimeFailure('H02_CONTAINER_DATABASE_NOT_TESTING');
    }

    $queueConnection = (string) config('queue.default');
    $queueDriver = (string) config("queue.connections.{$queueConnection}.driver", 'unknown');
    $queueName = (string) (config("queue.connections.{$queueConnection}.queue") ?: 'default');

    return [
        'database' => $database,
        'queue_connection' => $queueConnection,
        'queue_driver' => $queueDriver,
        'queue_name' => $queueName,
    ];
}

function h02ContainerOption(array $arguments, string $name, ?string $default = null): ?string
{
    $prefix = '--'.$name.'=';
    foreach ($arguments as $argument) {
        if (is_string($argument) && str_starts_with($argument, $prefix)) {
            return substr($argument, strlen($prefix));
        }
    }

    return $default;
}

function h02ContainerPositiveInt(array $arguments, string $name, int $min, int $max, ?int $default = null): int
{
    $raw = h02ContainerOption($arguments, $name, $default === null ? null : (string) $default);
    if (! is_string($raw) || preg_match('/^\d+$/', $raw) !== 1) {
        throw new H02ContainerRuntimeFailure('H02_CONTAINER_ARGUMENT_INVALID');
    }
    $value = (int) $raw;
    if ($value < $min || $value > $max) {
        throw new H02ContainerRuntimeFailure('H02_CONTAINER_ARGUMENT_INVALID');
    }

    return $value;
}

/** @return list<array<string,mixed>> */
function h02ContainerProvision(int $vus): array
{
    h02ContainerBootstrap();
    $rows = H02RepresentativeFixtureSupport::prepareRows($vus);
    $state = H02RepresentativeFixtureSupport::provision($rows);
    $runtimeRows = $state['rows'] ?? null;
    if (! is_array($runtimeRows) || count($runtimeRows) !== $vus) {
        throw new H02ContainerRuntimeFailure('H02_CONTAINER_FIXTURE_PROVISION_FAILED');
    }

    return array_values($runtimeRows);
}

function h02ContainerApacheProcessCount(): int
{
    $count = 0;
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
        $contents = @file_get_contents($path);
        if (! is_string($contents)) {
            continue;
        }
        $command = str_replace("\0", ' ', $contents);
        if (str_contains($command, 'apache2 -DFOREGROUND')) {
            $count++;
        }
    }

    return $count;
}

/** @return array<string,mixed> */
function h02ContainerRuntimeProof(): array
{
    $runtime = h02ContainerBootstrap();
    $apacheProcesses = h02ContainerApacheProcessCount();

    return array_merge($runtime, [
        'php' => PHP_VERSION,
        'os_family' => PHP_OS_FAMILY,
        'server_profile' => 'docker_apache_testing',
        'apache_processes' => $apacheProcesses,
        'capacity_representative' => PHP_OS_FAMILY === 'Linux' && $apacheProcesses >= 2,
    ]);
}

/** @return list<array{threads_connected:int,threads_running:int,queue_backlog:int,timestamp_ms:int}> */
function h02ContainerSample(int $sampleCount, int $sampleMs): array
{
    $runtime = h02ContainerBootstrap();
    $samples = [];
    for ($index = 0; $index < $sampleCount; $index++) {
        try {
            $samples[] = H01ObservabilitySampleSupport::collect($runtime['queue_connection'], $runtime['queue_name']);
        } catch (RuntimeException $error) {
            throw new H02ContainerRuntimeFailure($error->getMessage(), $error);
        }
        if ($index + 1 < $sampleCount) {
            usleep($sampleMs * 1000);
        }
    }

    return $samples;
}

/** @return array<string,mixed> */
function h02ContainerVerify(int $vus, int $expectedRated): array
{
    h02ContainerBootstrap();
    if ($expectedRated < 0 || $expectedRated > $vus) {
        throw new H02ContainerRuntimeFailure('H02_CONTAINER_EXPECTED_RATED_INVALID');
    }

    $users = User::query()
        ->where('email', 'like', 'h02-vu-%@example.test')
        ->orderBy('id')
        ->get();
    $userIds = $users->pluck('id')->map(static fn ($value): int => (int) $value)->all();

    $cards = ReviewCard::query()
        ->whereIn('user_id', $userIds)
        ->where('target_type', ReviewCard::TARGET_SENSE)
        ->orderBy('id')
        ->get();
    $cardIds = $cards->pluck('id')->map(static fn ($value): int => (int) $value)->all();

    $logs = ReviewLog::query()
        ->whereIn('review_card_id', $cardIds)
        ->orderBy('id')
        ->get();

    $logsPerCard = [];
    foreach ($logs as $log) {
        $cardId = (int) $log->review_card_id;
        $logsPerCard[$cardId] = ($logsPerCard[$cardId] ?? 0) + 1;
    }

    $duplicateLogCards = array_values(array_filter(
        $logsPerCard,
        static fn (int $count): bool => $count > 1,
    ));
    $invalidLogs = $logs->filter(static fn (ReviewLog $log): bool =>
        $log->rating !== 'good'
        || $log->source !== ReviewLog::SOURCE_SENSE_REVIEW
        || $log->undone_at !== null
    )->count();

    $ratedCards = 0;
    $unratedCards = 0;
    $invalidFsrsCards = 0;
    foreach ($cards as $card) {
        $logCount = $logsPerCard[(int) $card->id] ?? 0;
        $reps = (int) ($card->fsrs_reps ?? 0);
        if ($logCount === 1) {
            $ratedCards++;
            if ($reps !== 1 || $card->fsrs_last_reviewed_at === null) {
                $invalidFsrsCards++;
            }
        } elseif ($logCount === 0) {
            $unratedCards++;
            if ($reps !== 0 || $card->fsrs_last_reviewed_at !== null) {
                $invalidFsrsCards++;
            }
        } else {
            $invalidFsrsCards++;
        }
    }

    $result = [
        'vus' => $vus,
        'expected_rated' => $expectedRated,
        'users' => count($userIds),
        'cards' => count($cardIds),
        'review_logs' => $logs->count(),
        'rated_cards' => $ratedCards,
        'unrated_cards' => $unratedCards,
        'duplicate_log_cards' => count($duplicateLogCards),
        'invalid_logs' => $invalidLogs,
        'invalid_fsrs_cards' => $invalidFsrsCards,
    ];

    $ok = $result['users'] === $vus
        && $result['cards'] === $vus
        && $result['review_logs'] === $expectedRated
        && $result['rated_cards'] === $expectedRated
        && $result['unrated_cards'] === $vus - $expectedRated
        && $result['duplicate_log_cards'] === 0
        && $result['invalid_logs'] === 0
        && $result['invalid_fsrs_cards'] === 0;

    $result['ok'] = $ok;
    if (! $ok) {
        throw new H02ContainerRuntimeFailure('H02_CONTAINER_FORMAL_RATING_INVARIANT_FAILED');
    }

    return $result;
}

function runH02ContainerRuntimeCli(array $arguments): int
{
    $mode = $arguments[1] ?? null;
    try {
        if ($mode === '--provision') {
            $vus = h02ContainerPositiveInt($arguments, 'vus', 1, 1000);
            fwrite(STDOUT, json_encode([
                'schema_version' => 1,
                'rows' => h02ContainerProvision($vus),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

            return 0;
        }

        if ($mode === '--runtime') {
            fwrite(STDOUT, json_encode([
                'schema_version' => 1,
                'runtime' => h02ContainerRuntimeProof(),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

            return 0;
        }

        if ($mode === '--sample') {
            $sampleCount = h02ContainerPositiveInt($arguments, 'sample-count', 2, 240, 20);
            $sampleMs = h02ContainerPositiveInt($arguments, 'sample-ms', 100, 5000, 250);
            fwrite(STDOUT, json_encode([
                'schema_version' => 1,
                'samples' => h02ContainerSample($sampleCount, $sampleMs),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

            return 0;
        }

        if ($mode === '--verify') {
            $vus = h02ContainerPositiveInt($arguments, 'vus', 1, 1000);
            $expectedRated = h02ContainerPositiveInt($arguments, 'expected-rated', 0, $vus, 0);
            fwrite(STDOUT, json_encode([
                'schema_version' => 1,
                'verification' => h02ContainerVerify($vus, $expectedRated),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

            return 0;
        }

        throw new H02ContainerRuntimeFailure('H02_CONTAINER_MODE_REQUIRED');
    } catch (H02ContainerRuntimeFailure $error) {
        fwrite(STDERR, '[h02-container-runtime] '.$error->machineCode."\n");

        return 78;
    } catch (Throwable $error) {
        fwrite(STDERR, '[h02-container-runtime] H02_CONTAINER_UNEXPECTED_FAILURE: '.$error->getMessage()."\n");

        return 78;
    }
}

$scriptPath = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($scriptPath)
    && realpath($scriptPath) !== false
    && realpath($scriptPath) === realpath(__FILE__)
) {
    exit(runH02ContainerRuntimeCli($argv));
}
