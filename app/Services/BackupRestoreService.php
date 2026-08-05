<?php

namespace App\Services;

use App\Exceptions\BackupException;
use App\Jobs\ExecuteBackupRestore;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class BackupRestoreService
{
    public function __construct(
        private BackupService $backups,
        private SqlDumpInspector $inspector,
        private DatabaseRestoreProcess $restoreProcess,
        private MaintenanceMode $maintenanceMode,
        private RestoreWriteFence $writeFence,
    ) {}

    public function confirm(
        string $backupId,
        int $userId,
        string $confirmation,
    ): array {
        if ($confirmation !== 'RESTORE') {
            throw new BackupException(
                'BACKUP_RESTORE_CONFIRMATION_INVALID',
                'Type RESTORE to confirm this operation.',
                422,
            );
        }

        if (! Str::isUuid($backupId)) {
            throw new BackupException(
                'BACKUP_NOT_FOUND',
                'The requested backup was not found.',
                404,
            );
        }

        $store = $this->coordinationStore();
        $result = $store->lock(
            'backup:restore-confirm:' . hash('sha256', $backupId),
            30,
        )->get(fn () => $this->confirmLocked(
            $store,
            $backupId,
            $userId,
        ));

        if ($result === false) {
            throw new BackupException(
                'BACKUP_RESTORE_CONFIRMATION_RUNNING',
                'This restore confirmation is already being processed.',
                409,
            );
        }

        return $result;
    }

    private function confirmLocked(
        CacheRepository $store,
        string $backupId,
        int $userId,
    ): array {
        $mappingKey = $this->confirmationKey($backupId);
        $mapping = $store->get($mappingKey);

        if (is_array($mapping) && is_string($mapping['operation_id'] ?? null)) {
            $operation = $this->operation($mapping['operation_id']);
            if (($operation['user_id'] ?? null) !== $userId) {
                throw new BackupException(
                    'BACKUP_RESTORE_CONFIRMATION_RUNNING',
                    'Another user already started a restore for this backup.',
                    409,
                );
            }

            if (in_array($operation['status'], [
                'failed',
                'rolled_back',
                'failed_manual_recovery',
            ], true)) {
                // Terminal failure: drop the confirmation mapping so the same
                // backup can be confirmed again for a fresh retry.
                $store->forget($mappingKey);
            } else {
                if ($operation['status'] === 'dispatch_failed') {
                    $this->dispatchIfNeeded($operation);
                }

                return $this->publicOperation($operation);
            }
        }

        $target = $this->validateArchive($backupId);

        $operationId = (string) Str::uuid();
        $operation = [
            'operation_id' => $operationId,
            'user_id' => $userId,
            'backup_id' => $backupId,
            'checksum' => $target['manifest']['sha256'],
            'manifest_sha256' => $target['manifest_sha256'],
            'tables' => $target['inspection']['tables'],
            'status' => 'queued',
            'created_at' => Carbon::now('UTC')->toIso8601String(),
            'updated_at' => Carbon::now('UTC')->toIso8601String(),
            'safety_backup_id' => null,
            'rollback_performed' => false,
            'rollback_succeeded' => false,
            'error' => null,
        ];
        $ttl = $this->operationTtl();
        $store->put($this->operationKey($operationId), $operation, $ttl);
        $store->put($mappingKey, [
            'operation_id' => $operationId,
        ], $ttl);
        $this->dispatchIfNeeded($operation);

        return $this->publicOperation($operation);
    }

    public function status(string $operationId, int $userId): array
    {
        $operation = $this->operation($operationId, $userId);

        return $this->publicOperation($operation);
    }

    public function runOperation(string $operationId): void
    {
        $operation = $this->operation($operationId);
        if ($operation['status'] === 'running') {
            if (is_int($operation['lease_expires_at'] ?? null)
                && $operation['lease_expires_at'] > Carbon::now('UTC')->getTimestamp()) {
                throw new BackupException(
                    'BACKUP_RESTORE_ALREADY_RUNNING',
                    'The restore operation still owns an active execution lease.',
                    409,
                );
            }

            $this->updateOperation($operation, [
                'status' => 'failed_manual_recovery',
                'error' => [
                    'code' => 'BACKUP_RESTORE_LEASE_EXPIRED',
                    'message' => 'The restore execution lease expired; maintenance state requires inspection.',
                ],
            ]);

            return;
        }

        if (in_array($operation['status'], [
            'succeeded',
            'rolled_back',
            'failed',
            'failed_manual_recovery',
        ], true)) {
            return;
        }

        $lockSeconds = $this->restoreLockSeconds();
        $store = $this->coordinationStore();
        $result = $store->lock('backup:restore', $lockSeconds)->get(
            fn () => $this->runLockedOperation($operation),
        );

        if ($result === false) {
            throw new BackupException(
                'BACKUP_RESTORE_ALREADY_RUNNING',
                'Another restore is already running.',
                409,
            );
        }
    }

    public function markInterrupted(string $operationId): void
    {
        try {
            $operation = $this->operation($operationId);
            if ($operation['status'] === 'running') {
                $this->updateOperation($operation, [
                    'status' => 'failed_manual_recovery',
                    'error' => [
                        'code' => 'BACKUP_RESTORE_INTERRUPTED',
                        'message' => 'Restore execution was interrupted; maintenance mode remains active.',
                    ],
                ]);
            } elseif (in_array($operation['status'], ['queued', 'dispatch_failed'], true)) {
                $this->updateOperation($operation, [
                    'status' => 'failed',
                    'error' => [
                        'code' => 'BACKUP_RESTORE_JOB_FAILED',
                        'message' => 'The restore job stopped before maintenance mode was acquired.',
                    ],
                ]);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function runLockedOperation(array $operation): void
    {
        $operation = $this->updateOperation($operation, [
            'status' => 'running',
            'lease_owner' => $operation['operation_id'],
            'lease_expires_at' => Carbon::now('UTC')->addSeconds(
                $this->restoreLockSeconds(),
            )->getTimestamp(),
        ]);
        $maintenanceOwned = false;
        $fenceOwned = false;
        $releaseMaintenance = true;

        try {
            $target = $this->validateArchive($operation['backup_id']);
            if (! hash_equals($target['manifest']['sha256'], $operation['checksum'])
                || ! hash_equals($target['manifest_sha256'], $operation['manifest_sha256'])
                || $target['inspection']['tables'] !== $operation['tables']) {
                throw $this->backupChanged();
            }
            $pinnedTarget = $this->pinPayload(
                $target,
                $operation['operation_id'],
                'target',
            );

            $this->restoreProcess->validateIsolated(
                $pinnedTarget['payload_path'],
                $pinnedTarget['inspection']['tables'],
            );

            $this->writeFence->activate($operation['operation_id']);
            $fenceOwned = true;

            if ($this->maintenanceMode->active()) {
                throw new BackupException(
                    'BACKUP_RESTORE_MAINTENANCE_ACTIVE',
                    'Restore cannot start while maintenance mode is already active.',
                    409,
                );
            }

            $this->maintenanceMode->activate($this->maintenancePayload());
            $maintenanceOwned = true;
            $this->restoreProcess->waitForQuiescence();

            $this->backups->withExclusiveOperation(
                function (callable $createSnapshot) use (&$operation, $target, $pinnedTarget) {
                    $target = $this->validateArchive($target['manifest']['backup_id']);
                    $safety = $createSnapshot([$target['manifest']['backup_id']]);
                    $operation = $this->updateOperation($operation, [
                        'safety_backup_id' => $safety['backup_id'],
                    ]);
                    $safety = $this->validateArchive($safety['backup_id']);
                    $pinnedSafety = $this->pinPayload(
                        $safety,
                        $operation['operation_id'],
                        'safety',
                    );

                    try {
                        $this->assertPinnedPayload($pinnedTarget);
                        DB::purge();
                        $this->restoreProcess->replaceActive($pinnedTarget['payload_path']);
                        DB::reconnect();
                        $this->restoreProcess->assertActiveInventory(
                            $pinnedTarget['inspection']['tables'],
                        );
                    } catch (Throwable $restoreFailure) {
                        try {
                            $this->assertPinnedPayload($pinnedSafety);
                            DB::purge();
                            $this->restoreProcess->replaceActive($pinnedSafety['payload_path']);
                            DB::reconnect();
                            $this->restoreProcess->assertActiveInventory(
                                $pinnedSafety['inspection']['tables'],
                            );
                        } catch (Throwable $rollbackFailure) {
                            report($restoreFailure);
                            report($rollbackFailure);

                            throw new BackupException(
                                'BACKUP_RESTORE_ROLLBACK_FAILED',
                                'Restore failed and the automatic safety rollback also failed.',
                                500,
                                [
                                    'rollback_performed' => true,
                                    'rollback_succeeded' => false,
                                    'safety_backup_id' => $safety['manifest']['backup_id'],
                                ],
                            );
                        }

                        report($restoreFailure);

                        throw new BackupException(
                            'BACKUP_RESTORE_FAILED_ROLLED_BACK',
                            'Restore failed and the safety snapshot was restored.',
                            500,
                            [
                                'rollback_performed' => true,
                                'rollback_succeeded' => true,
                                'safety_backup_id' => $safety['manifest']['backup_id'],
                            ],
                        );
                    }
                },
                $this->restoreLockSeconds(),
            );

            $operation = $this->updateOperation($operation, [
                'status' => 'succeeded',
                'restored_at' => Carbon::now('UTC')->toIso8601String(),
            ]);
        } catch (BackupException $exception) {
            $rolledBack = ($exception->details['rollback_succeeded'] ?? null) === true;
            $manualRecovery = ($exception->details['rollback_performed'] ?? null) === true
                && ! $rolledBack;
            $releaseMaintenance = ! $manualRecovery;
            $operation = $this->updateOperation($operation, [
                'status' => $rolledBack
                    ? 'rolled_back'
                    : ($manualRecovery ? 'failed_manual_recovery' : 'failed'),
                'rollback_performed' => (bool) ($exception->details['rollback_performed'] ?? false),
                'rollback_succeeded' => (bool) ($exception->details['rollback_succeeded'] ?? false),
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ],
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $releaseMaintenance = ! $maintenanceOwned;
            $operation = $this->updateOperation($operation, [
                'status' => $maintenanceOwned ? 'failed_manual_recovery' : 'failed',
                'error' => [
                    'code' => 'BACKUP_RESTORE_FAILED',
                    'message' => $maintenanceOwned
                        ? 'Restore failed unexpectedly; maintenance mode remains active.'
                        : 'Restore failed before maintenance mode was acquired.',
                ],
            ]);
        } finally {
            try {
                DB::purge();
                DB::reconnect();
            } catch (Throwable $exception) {
                report($exception);
            }

            if ($maintenanceOwned && $releaseMaintenance) {
                try {
                    $this->maintenanceMode->deactivate();
                } catch (Throwable $exception) {
                    report($exception);
                    $releaseMaintenance = false;
                    $this->updateOperation($operation, [
                        'status' => 'failed_manual_recovery',
                        'error' => [
                            'code' => 'BACKUP_RESTORE_MAINTENANCE_RELEASE_FAILED',
                            'message' => 'Restore finished but maintenance mode could not be released.',
                        ],
                    ]);
                }
            }

            if ($fenceOwned && $releaseMaintenance) {
                $this->writeFence->deactivate($operation['operation_id']);
            }

            if ($releaseMaintenance) {
                $this->cleanupPinnedPayloads($operation['operation_id']);
            }
        }
    }

    private function pinPayload(
        array $backup,
        string $operationId,
        string $label,
    ): array {
        if (! Str::isUuid($operationId) || ! in_array($label, ['target', 'safety'], true)) {
            throw $this->configurationError('The restore payload pin is invalid.');
        }

        $disk = Storage::disk((string) config('backup.disk', 'backup'));
        $directory = ".restore/{$operationId}";
        $destination = "{$directory}/{$label}.sql.gz";
        $disk->makeDirectory($directory);
        $source = fopen($backup['payload_path'], 'rb');
        $target = fopen($disk->path($destination), 'xb');

        if ($source === false || $target === false) {
            is_resource($source) && fclose($source);
            is_resource($target) && fclose($target);
            throw new BackupException(
                'BACKUP_RESTORE_PIN_FAILED',
                'The restore payload could not be pinned for immutable execution.',
            );
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);
                if ($chunk === false
                    || ($chunk !== '' && fwrite($target, $chunk) !== strlen($chunk))) {
                    throw new BackupException(
                        'BACKUP_RESTORE_PIN_FAILED',
                        'The restore payload could not be pinned for immutable execution.',
                    );
                }
                hash_update($hash, $chunk);
                $bytes += strlen($chunk);
            }
            fflush($target);
        } finally {
            fclose($source);
            fclose($target);
        }

        $checksum = hash_final($hash);
        if ($bytes !== $backup['manifest']['size_bytes']
            || ! hash_equals($backup['manifest']['sha256'], $checksum)) {
            $disk->delete($destination);
            throw $this->backupChanged();
        }

        @chmod($disk->path($destination), 0400);
        $inspection = $this->inspector->inspect(
            $disk->path($destination),
            $this->requiredTables(),
        );
        if ($inspection['tables'] !== $backup['inspection']['tables']) {
            $disk->delete($destination);
            throw $this->backupChanged();
        }

        return [
            'payload_path' => $disk->path($destination),
            'checksum' => $checksum,
            'inspection' => $inspection,
        ];
    }

    private function assertPinnedPayload(array $pinned): void
    {
        $checksum = hash_file('sha256', $pinned['payload_path']);
        if (! is_string($checksum)
            || ! hash_equals((string) $pinned['checksum'], $checksum)) {
            throw new BackupException(
                'BACKUP_RESTORE_PIN_CHANGED',
                'A pinned restore payload changed before execution.',
            );
        }
    }

    private function cleanupPinnedPayloads(string $operationId): void
    {
        if (Str::isUuid($operationId)) {
            $disk = Storage::disk((string) config('backup.disk', 'backup'));
            $directory = ".restore/{$operationId}";
            foreach ($disk->files($directory) as $file) {
                @chmod($disk->path($file), 0600);
            }
            $disk->deleteDirectory($directory);
        }
    }

    private function dispatchIfNeeded(array $operation): void
    {
        if (! in_array($operation['status'], ['queued', 'dispatch_failed'], true)) {
            return;
        }

        $connection = (string) config('backup.restore_queue_connection', 'redis-restore');
        $queueConfig = config("queue.connections.{$connection}", []);
        if (($queueConfig['driver'] ?? null) !== 'redis'
            || (int) ($queueConfig['retry_after'] ?? 0) <= ExecuteBackupRestore::TIMEOUT_SECONDS + 300) {
            $this->updateOperation($operation, [
                'status' => 'dispatch_failed',
                'error' => [
                    'code' => 'BACKUP_CONFIGURATION_INVALID',
                    'message' => 'Restore execution requires a durable Redis retry window longer than the job timeout.',
                ],
            ]);
            throw $this->configurationError(
                'Restore execution requires a dedicated Redis queue connection.',
            );
        }

        try {
            ExecuteBackupRestore::dispatch($operation['operation_id'])
                ->onConnection($connection)
                ->onQueue((string) config('backup.restore_queue', 'maintenance'));
        } catch (Throwable $exception) {
            report($exception);
            $this->updateOperation($operation, [
                'status' => 'dispatch_failed',
                'error' => [
                    'code' => 'BACKUP_RESTORE_DISPATCH_FAILED',
                    'message' => 'The restore operation could not be queued.',
                ],
            ]);

            throw new BackupException(
                'BACKUP_RESTORE_DISPATCH_FAILED',
                'The restore operation could not be queued.',
                503,
            );
        }
    }

    private function operation(
        string $operationId,
        ?int $userId = null,
    ): array {
        if (! Str::isUuid($operationId)) {
            throw $this->operationNotFound();
        }

        $operation = $this->coordinationStore()->get($this->operationKey($operationId));
        if (! is_array($operation)
            || ($userId !== null
                && ($operation['user_id'] ?? null) !== $userId)) {
            throw $this->operationNotFound();
        }

        return $operation;
    }

    private function updateOperation(array $operation, array $changes): array
    {
        $operation = [
            ...$operation,
            ...$changes,
            'updated_at' => Carbon::now('UTC')->toIso8601String(),
        ];
        $this->coordinationStore()->put(
            $this->operationKey($operation['operation_id']),
            $operation,
            $this->operationTtl(),
        );

        return $operation;
    }

    private function publicOperation(array $operation): array
    {
        return array_intersect_key($operation, array_flip([
            'operation_id',
            'backup_id',
            'status',
            'created_at',
            'updated_at',
            'error',
        ]));
    }

    private function validateArchive(string $backupId): array
    {
        $backup = $this->backups->inspectBackup($backupId);
        $manifest = $backup['manifest'];
        $currentDatabase = (string) config(
            'database.connections.' . config('database.default') . '.database',
        );

        if (($manifest['format'] ?? null) !== 'linguacafe-backup'
            || ($manifest['format_version'] ?? null) !== 1
            || ($manifest['database_driver'] ?? null) !== 'mysql'
            || ! in_array('database', $manifest['included_scopes'] ?? [], true)
            || ! hash_equals(
                (string) ($manifest['database_name_fingerprint'] ?? ''),
                hash('sha256', $currentDatabase),
            )) {
            throw new BackupException(
                'BACKUP_RESTORE_INCOMPATIBLE',
                'The backup is not compatible with this application database.',
                422,
            );
        }

        $actualSize = filesize($backup['payload_path']);
        $actualChecksum = hash_file('sha256', $backup['payload_path']);
        if (! is_int($actualSize)
            || ! is_string($actualChecksum)
            || $actualSize !== ($manifest['size_bytes'] ?? null)
            || ! hash_equals((string) ($manifest['sha256'] ?? ''), $actualChecksum)) {
            throw new BackupException(
                'BACKUP_RESTORE_CHECKSUM_MISMATCH',
                'The backup payload no longer matches its manifest.',
                422,
            );
        }

        $inspection = $this->inspector->inspect(
            $backup['payload_path'],
            $this->requiredTables(),
        );
        $freeBytes = disk_free_space(dirname($backup['payload_path']));
        $multiplier = (float) config('backup.restore_disk_headroom_multiplier', 2.5);
        $requiredBytes = (int) ceil(
            ($inspection['uncompressed_size_bytes'] * $multiplier) + $actualSize,
        );

        if (! is_float($freeBytes) || $multiplier < 1.0 || $freeBytes < $requiredBytes) {
            throw new BackupException(
                'BACKUP_RESTORE_DISK_SPACE_INSUFFICIENT',
                'There is not enough free disk space to safely restore this backup.',
                422,
            );
        }

        return [...$backup, 'inspection' => $inspection];
    }

    private function requiredTables(): array
    {
        $tables = config('backup.restore_required_tables', []);
        if (! is_array($tables)
            || $tables === []
            || array_filter($tables, fn ($table) => ! is_string($table)
                || ! preg_match('/^[a-zA-Z0-9_]+$/', $table))) {
            throw $this->configurationError('The restore table requirements are invalid.');
        }

        $tables = array_values(array_unique($tables));
        sort($tables, SORT_STRING);

        return $tables;
    }

    private function coordinationStore(): CacheRepository
    {
        $store = (string) config('backup.restore_coordination_store', 'file');
        $driver = config("cache.stores.{$store}.driver");
        if (! is_string($driver)
            || $driver === 'database'
            || ($driver === 'array' && ! app()->environment('testing'))) {
            throw $this->configurationError(
                'The restore coordination store must be outside the application database.',
            );
        }

        return Cache::store($store);
    }

    private function restoreLockSeconds(): int
    {
        $processTimeout = (int) config('backup.process_timeout_seconds', 900);
        if ($processTimeout < 1 || $processTimeout > 1800) {
            throw $this->configurationError(
                'The restore process timeout is outside the supported range.',
            );
        }

        $seconds = (int) config('backup.restore_lock_seconds', 25200);
        $minimum = max(
            ($processTimeout * 10)
            + (int) config('backup.restore_quiesce_timeout_seconds', 30)
            + 300,
            ExecuteBackupRestore::TIMEOUT_SECONDS + 600,
        );

        if ($seconds < $minimum) {
            throw $this->configurationError(
                'The restore lock lifetime is too short for the configured process timeout.',
            );
        }

        return $seconds;
    }

    private function operationTtl(): Carbon
    {
        $seconds = (int) config('backup.restore_operation_ttl_seconds', 604800);
        if ($seconds < 86400 || $seconds > 2592000) {
            throw $this->configurationError('The restore operation lifetime is invalid.');
        }

        return Carbon::now('UTC')->addSeconds($seconds);
    }

    private function maintenancePayload(): array
    {
        return [
            'except' => ['backup-restores/*'],
            'redirect' => null,
            'retry' => 60,
            'refresh' => null,
            'secret' => null,
            'status' => 503,
            'template' => null,
        ];
    }

    private function confirmationKey(string $backupId): string
    {
        return 'backup:restore-confirmation:' . hash('sha256', $backupId);
    }

    private function operationKey(string $operationId): string
    {
        return "backup:restore-operation:{$operationId}";
    }

    private function backupChanged(): BackupException
    {
        return new BackupException(
            'BACKUP_RESTORE_BACKUP_CHANGED',
            'The backup changed after the restore was confirmed and can no longer be used.',
            409,
        );
    }

    private function operationNotFound(): BackupException
    {
        return new BackupException(
            'BACKUP_RESTORE_OPERATION_NOT_FOUND',
            'The requested restore operation was not found.',
            404,
        );
    }

    private function configurationError(string $message): BackupException
    {
        return new BackupException(
            'BACKUP_CONFIGURATION_INVALID',
            $message,
            503,
        );
    }
}
