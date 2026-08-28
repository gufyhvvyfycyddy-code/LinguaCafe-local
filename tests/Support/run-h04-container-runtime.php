<?php

declare(strict_types=1);

use App\Exceptions\BackupException;
use App\Models\ReviewCard;
use App\Models\User;
use App\Services\BackupRestoreService;
use App\Services\BackupService;
use App\Services\RestoreWriteFence;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/H02RepresentativeFixtureSupport.php';

final class H04ContainerRuntimeFailure extends RuntimeException
{
    public function __construct(public readonly string $machineCode, ?Throwable $previous = null)
    {
        parent::__construct($machineCode, 0, $previous);
    }
}

/** @return array{database:string} */
function h04ContainerBootstrap(bool $verifyConnection = true): array
{
    static $bootstrapped = false;
    if (! $bootstrapped) {
        $projectRoot = dirname(__DIR__, 2);
        $application = require $projectRoot.'/bootstrap/app.php';
        $kernel = $application->make(Kernel::class);
        $kernel->bootstrap();
        $bootstrapped = true;
    }

    if (! app()->environment('testing')) {
        throw new H04ContainerRuntimeFailure('H04_CONTAINER_ENV_NOT_TESTING');
    }

    $connection = (string) config('database.default');
    $configuredDatabase = config("database.connections.{$connection}.database");
    if (! is_string($configuredDatabase) || ! str_contains(strtolower($configuredDatabase), 'test')) {
        throw new H04ContainerRuntimeFailure('H04_CONTAINER_DATABASE_NOT_TESTING');
    }

    if ($verifyConnection) {
        $database = DB::connection()->getDatabaseName();
        if (! is_string($database)
            || ! hash_equals($configuredDatabase, $database)
            || ! str_contains(strtolower($database), 'test')) {
            throw new H04ContainerRuntimeFailure('H04_CONTAINER_DATABASE_NOT_TESTING');
        }
    }

    return ['database' => $configuredDatabase];
}

function h04ContainerOption(array $arguments, string $name): string
{
    $prefix = '--'.$name.'=';
    foreach ($arguments as $argument) {
        if (is_string($argument) && str_starts_with($argument, $prefix)) {
            $value = substr($argument, strlen($prefix));
            if ($value !== '') {
                return $value;
            }
        }
    }

    throw new H04ContainerRuntimeFailure('H04_CONTAINER_ARGUMENT_INVALID');
}

function h04ContainerPositiveInt(array $arguments, string $name): int
{
    $raw = h04ContainerOption($arguments, $name);
    if (preg_match('/^\d+$/', $raw) !== 1 || (int) $raw < 1) {
        throw new H04ContainerRuntimeFailure('H04_CONTAINER_ARGUMENT_INVALID');
    }

    return (int) $raw;
}

/** @return array<string,mixed> */
function h04ContainerPrepare(): array
{
    h04ContainerBootstrap();

    $admin = User::forceCreate([
        'name' => 'H04 before backup',
        'email' => 'h04-admin@example.test',
        'password' => 'H04-admin-testing-only!',
        'selected_language' => 'en',
        'is_admin' => true,
    ]);

    $fixture = H02RepresentativeFixtureSupport::provision([[
        'email' => 'h04-study@example.test',
        'password' => 'H04-study-testing-only!',
        'lemma' => 'h04-restore-marker',
        'language' => 'en',
    ]]);
    $studyUserId = (int) $fixture['user_ids'][0];
    $reviewCardId = (int) $fixture['review_card_ids'][0];
    $studyUser = User::query()->findOrFail($studyUserId);
    $reviewCard = ReviewCard::query()->findOrFail($reviewCardId);

    $backup = app(BackupService::class)->createBackup();

    $admin->forceFill(['name' => 'H04 after backup'])->save();
    $studyUser->forceFill(['name' => 'H04 study after backup'])->save();
    $reviewCard->forceFill(['fsrs_reps' => 7])->save();
    $extra = User::forceCreate([
        'name' => 'H04 created after backup',
        'email' => 'h04-after@example.test',
        'password' => 'H04-after-testing-only!',
        'selected_language' => 'en',
        'is_admin' => false,
    ]);

    return [
        'database' => DB::connection()->getDatabaseName(),
        'backup' => [
            'backup_id' => $backup['backup_id'],
            'sha256' => $backup['sha256'],
            'size_bytes' => $backup['size_bytes'],
        ],
        'baseline' => [
            'admin_id' => (int) $admin->id,
            'admin_name' => 'H04 before backup',
            'study_user_id' => $studyUserId,
            'study_user_name' => 'H02 h04-restore-marker',
            'review_card_id' => $reviewCardId,
            'review_card_fsrs_reps' => 0,
        ],
        'mutated' => [
            'admin_name' => 'H04 after backup',
            'study_user_name' => 'H04 study after backup',
            'review_card_fsrs_reps' => 7,
            'extra_user_id' => (int) $extra->id,
            'extra_user_email' => 'h04-after@example.test',
        ],
    ];
}

/** @return array<string,mixed> */
function h04ContainerConfirm(array $arguments): array
{
    h04ContainerBootstrap();
    $backupId = h04ContainerOption($arguments, 'backup-id');
    $userId = h04ContainerPositiveInt($arguments, 'user-id');

    return app(BackupRestoreService::class)->confirm($backupId, $userId, 'RESTORE');
}

/** @return array{active:bool} */
function h04ContainerFenceStatus(): array
{
    h04ContainerBootstrap(false);

    return ['active' => app(RestoreWriteFence::class)->active()];
}

/** @return array{blocked:bool,error_code:?string} */
function h04ContainerAttemptWrite(array $arguments): array
{
    h04ContainerBootstrap();
    $adminId = h04ContainerPositiveInt($arguments, 'admin-id');

    try {
        User::query()->whereKey($adminId)->update(['name' => 'H04 fence breach']);
    } catch (BackupException $exception) {
        return [
            'blocked' => $exception->errorCode === 'BACKUP_RESTORE_WRITE_FENCE_ACTIVE',
            'error_code' => $exception->errorCode,
        ];
    }

    return ['blocked' => false, 'error_code' => null];
}

/** @return array{tampered:bool} */
function h04ContainerTamperPinnedTarget(array $arguments): array
{
    h04ContainerBootstrap(false);
    $operationId = h04ContainerOption($arguments, 'operation-id');
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $operationId) !== 1) {
        throw new H04ContainerRuntimeFailure('H04_CONTAINER_ARGUMENT_INVALID');
    }

    $disk = Storage::disk((string) config('backup.disk', 'backup'));
    $path = $disk->path(".restore/{$operationId}/target.sql.gz");
    if (! is_file($path) || ! @chmod($path, 0600) || file_put_contents($path, 'H04_ROLLBACK_DRILL', FILE_APPEND) === false) {
        throw new H04ContainerRuntimeFailure('H04_CONTAINER_TAMPER_FAILED');
    }

    return ['tampered' => true];
}

/** @return array<string,mixed> */
function h04ContainerVerify(array $arguments, bool $rollbackExpected = false): array
{
    h04ContainerBootstrap();
    $operationId = h04ContainerOption($arguments, 'operation-id');
    $adminId = h04ContainerPositiveInt($arguments, 'admin-id');
    $studyUserId = h04ContainerPositiveInt($arguments, 'study-user-id');
    $reviewCardId = h04ContainerPositiveInt($arguments, 'review-card-id');

    $status = app(BackupRestoreService::class)->status($operationId, $adminId);
    $admin = User::query()->find($adminId);
    $studyUser = User::query()->find($studyUserId);
    $reviewCard = ReviewCard::query()->find($reviewCardId);
    $extraUserExists = User::query()->where('email', 'h04-after@example.test')->exists();
    $backups = app(BackupService::class)->listBackups();
    $fenceActive = app(RestoreWriteFence::class)->active();
    $maintenanceActive = app(MaintenanceMode::class)->active();

    $result = [
        'operation' => $status,
        'admin_name' => $admin?->name,
        'study_user_name' => $studyUser?->name,
        'review_card_fsrs_reps' => $reviewCard === null ? null : (int) $reviewCard->fsrs_reps,
        'extra_user_exists' => $extraUserExists,
        'backup_count' => count($backups),
        'backup_ids' => array_values(array_map(
            static fn (array $backup): string => (string) $backup['backup_id'],
            $backups,
        )),
        'fence_active' => $fenceActive,
        'maintenance_active' => $maintenanceActive,
    ];

    $result['ok'] = $rollbackExpected
        ? ($status['status'] ?? null) === 'rolled_back'
            && $result['admin_name'] === 'H04 after backup'
            && $result['study_user_name'] === 'H04 study after backup'
            && $result['review_card_fsrs_reps'] === 7
            && $extraUserExists === true
            && $result['backup_count'] === 2
            && $fenceActive === false
            && $maintenanceActive === false
        : ($status['status'] ?? null) === 'succeeded'
            && $result['admin_name'] === 'H04 before backup'
            && $result['study_user_name'] === 'H02 h04-restore-marker'
            && $result['review_card_fsrs_reps'] === 0
            && $extraUserExists === false
            && $result['backup_count'] === 2
            && $fenceActive === false
            && $maintenanceActive === false;

    if (! $result['ok']) {
        throw new H04ContainerRuntimeFailure('H04_CONTAINER_RESTORE_INVARIANT_FAILED');
    }

    return $result;
}

function runH04ContainerRuntimeCli(array $arguments): int
{
    $mode = $arguments[1] ?? null;

    try {
        $payload = match ($mode) {
            '--prepare' => ['prepared' => h04ContainerPrepare()],
            '--confirm' => ['operation' => h04ContainerConfirm($arguments)],
            '--fence-status' => ['fence' => h04ContainerFenceStatus()],
            '--attempt-write' => ['write_probe' => h04ContainerAttemptWrite($arguments)],
            '--tamper-pinned-target' => ['tamper' => h04ContainerTamperPinnedTarget($arguments)],
            '--verify' => ['verification' => h04ContainerVerify($arguments)],
            '--verify-rollback' => ['verification' => h04ContainerVerify($arguments, true)],
            default => throw new H04ContainerRuntimeFailure('H04_CONTAINER_MODE_REQUIRED'),
        };

        fwrite(STDOUT, json_encode([
            'schema_version' => 1,
            ...$payload,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        return 0;
    } catch (H04ContainerRuntimeFailure $error) {
        fwrite(STDERR, '[h04-container-runtime] '.$error->machineCode."\n");

        return 78;
    } catch (Throwable $error) {
        fwrite(STDERR, '[h04-container-runtime] H04_CONTAINER_UNEXPECTED_FAILURE: '.$error->getMessage()."\n");

        return 78;
    }
}

$scriptPath = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($scriptPath)
    && realpath($scriptPath) !== false
    && realpath($scriptPath) === realpath(__FILE__)
) {
    exit(runH04ContainerRuntimeCli($argv));
}
