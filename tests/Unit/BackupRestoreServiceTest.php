<?php

namespace Tests\Unit;

use App\Exceptions\BackupException;
use App\Jobs\ExecuteBackupRestore;
use App\Services\BackupRestoreService;
use App\Services\BackupService;
use App\Services\DatabaseDumpProcess;
use App\Services\DatabaseRestoreProcess;
use App\Services\SqlDumpInspector;
use App\Services\RestoreWriteFence;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class BackupRestoreServiceTest extends TestCase
{
    private string $dump = <<<'SQL'
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
CREATE TABLE `migrations` (`id` bigint);
CREATE TABLE `users` (`id` bigint);
SQL;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backup');
        config([
            'backup.disk' => 'backup',
            'backup.max_backups' => 14,
            'backup.lock_seconds' => 30,
            'backup.restore_coordination_store' => 'array',
            'backup.restore_preview_ttl_seconds' => 600,
            'backup.restore_operation_ttl_seconds' => 86400,
            'backup.restore_required_tables' => ['migrations', 'users'],
            'backup.restore_max_uncompressed_bytes' => 1024 * 1024,
            'backup.restore_disk_headroom_multiplier' => 1.0,
            'backup.restore_queue_connection' => 'redis-restore',
            'backup.restore_queue' => 'maintenance',
            'cache.default' => 'array',
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'linguacafe_testing',
        ]);
        Carbon::setTestNow('2026-07-28 10:00:00 UTC');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_confirmation_dispatches_one_idempotent_operation(): void
    {
        [$service, $backup] = $this->serviceAndBackup();

        $first = $service->confirm(
            $backup['backup_id'],
            7,
            'RESTORE',
        );
        $second = $service->confirm(
            $backup['backup_id'],
            7,
            'RESTORE',
        );

        $this->assertSame($first['operation_id'], $second['operation_id']);
        $this->assertSame('queued', $first['status']);
        Queue::assertPushed(
            ExecuteBackupRestore::class,
            1,
        );
        Queue::assertPushed(fn (ExecuteBackupRestore $job) => $job->operationId === $first['operation_id']
            && $job->connection === 'redis-restore'
            && $job->queue === 'maintenance');
    }

    public function test_confirmation_requires_exact_restore_capability_string(): void
    {
        [$service, $backup] = $this->serviceAndBackup();

        foreach (['', 'restore', 'RESTORE ', ' RESTORE', 'RESTOREX'] as $confirmation) {
            try {
                $service->confirm($backup['backup_id'], 7, $confirmation);
                $this->fail("Expected rejection for confirmation [{$confirmation}].");
            } catch (BackupException $exception) {
                $this->assertSame('BACKUP_RESTORE_CONFIRMATION_INVALID', $exception->errorCode);
            }
        }

        Queue::assertNothingPushed();
    }

    public function test_terminal_failure_allows_retry_with_fresh_operation(): void
    {
        [$service, $backup] = $this->serviceAndBackup();

        $first = $service->confirm($backup['backup_id'], 7, 'RESTORE');
        $store = app('cache')->store(config('backup.restore_coordination_store'));
        $key = 'backup:restore-operation:' . $first['operation_id'];
        $operation = $store->get($key);
        $operation['status'] = 'failed';
        $store->put($key, $operation, now()->addSeconds(
            (int) config('backup.restore_operation_ttl_seconds', 604800),
        ));

        $second = $service->confirm($backup['backup_id'], 7, 'RESTORE');

        $this->assertNotSame($first['operation_id'], $second['operation_id']);
        $this->assertSame('queued', $second['status']);
        Queue::assertPushed(ExecuteBackupRestore::class, 2);
    }

    public function test_running_operation_is_never_duplicated_by_reconfirmation(): void
    {
        [$service, $backup] = $this->serviceAndBackup();

        $first = $service->confirm($backup['backup_id'], 7, 'RESTORE');
        $store = app('cache')->store(config('backup.restore_coordination_store'));
        $key = 'backup:restore-operation:' . $first['operation_id'];
        $operation = $store->get($key);
        $operation['status'] = 'running';
        $store->put($key, $operation, now()->addSeconds(
            (int) config('backup.restore_operation_ttl_seconds', 604800),
        ));

        $second = $service->confirm($backup['backup_id'], 7, 'RESTORE');

        $this->assertSame($first['operation_id'], $second['operation_id']);
        $this->assertSame('running', $second['status']);
        Queue::assertPushed(ExecuteBackupRestore::class, 1);
    }

    public function test_confirm_and_status_are_scoped_to_the_owning_user(): void
    {
        [$service, $backup] = $this->serviceAndBackup();
        $operation = $service->confirm($backup['backup_id'], 7, 'RESTORE');

        $this->assertSame(
            $operation['operation_id'],
            $service->status($operation['operation_id'], 7)['operation_id'],
        );

        try {
            $service->status($operation['operation_id'], 8);
            $this->fail('Expected other-user status access to be hidden.');
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_RESTORE_OPERATION_NOT_FOUND', $exception->errorCode);
        }

        try {
            $service->confirm($backup['backup_id'], 8, 'RESTORE');
            $this->fail('Expected other-user confirmation of the same backup to conflict.');
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_RESTORE_CONFIRMATION_RUNNING', $exception->errorCode);
        }
    }

    public function test_confirmation_revalidates_payload_checksum_before_dispatch(): void
    {
        [$service, $backup] = $this->serviceAndBackup();
        Storage::disk('backup')->put($backup['payload_file'], gzencode('tampered'));

        try {
            $service->confirm(
                $backup['backup_id'],
                7,
                'RESTORE',
            );
            $this->fail('Expected checksum rejection.');
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_RESTORE_CHECKSUM_MISMATCH', $exception->errorCode);
        }

        Queue::assertNothingPushed();
    }

    public function test_database_backed_coordination_store_fails_closed(): void
    {
        [$service, $backup] = $this->serviceAndBackup();
        config(['backup.restore_coordination_store' => 'database']);

        try {
            $service->confirm($backup['backup_id'], 7, 'RESTORE');
            $this->fail('Expected database coordination rejection.');
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_CONFIGURATION_INVALID', $exception->errorCode);
        }
    }

    public function test_restore_queue_retry_window_must_exceed_job_timeout(): void
    {
        [$service, $backup] = $this->serviceAndBackup();
        config(['queue.connections.redis-restore.retry_after' => 90]);

        try {
            $service->confirm(
                $backup['backup_id'],
                7,
                'RESTORE',
            );
            $this->fail('Expected unsafe queue retry configuration rejection.');
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_CONFIGURATION_INVALID', $exception->errorCode);
        }

        Queue::assertNothingPushed();
    }

    public function test_operation_runs_isolated_validation_then_snapshot_and_active_restore(): void
    {
        $process = Mockery::mock(DatabaseRestoreProcess::class);
        $process->shouldReceive('validateIsolated')->once()->ordered();
        $process->shouldReceive('waitForQuiescence')->once()->ordered();
        $process->shouldReceive('replaceActive')->once()->ordered();
        $process->shouldReceive('assertActiveInventory')->once()->ordered();
        $maintenance = Mockery::mock(MaintenanceMode::class);
        $maintenance->shouldReceive('active')->once()->andReturnFalse();
        $maintenance->shouldReceive('activate')->once()->ordered();
        $maintenance->shouldReceive('deactivate')->once()->ordered();
        DB::shouldReceive('purge')->atLeast()->once();
        DB::shouldReceive('reconnect')->atLeast()->once();
        [$service, $operation] = $this->preparedOperation($process, $maintenance);

        $service->runOperation($operation['operation_id']);
        $status = $service->status($operation['operation_id'], 7);

        $this->assertSame('succeeded', $status['status']);
        $this->assertFalse(Storage::disk('backup')->directoryExists(
            ".restore/{$operation['operation_id']}",
        ));
    }

    public function test_active_restore_failure_rolls_back_before_releasing_maintenance(): void
    {
        $process = Mockery::mock(DatabaseRestoreProcess::class);
        $process->shouldReceive('validateIsolated')->once();
        $process->shouldReceive('waitForQuiescence')->once();
        $process->shouldReceive('replaceActive')
            ->once()
            ->andThrow(new BackupException('RESTORE_FAILED', 'failed'));
        $process->shouldReceive('replaceActive')->once();
        $process->shouldReceive('assertActiveInventory')->once();
        $maintenance = Mockery::mock(MaintenanceMode::class);
        $maintenance->shouldReceive('active')->once()->andReturnFalse();
        $maintenance->shouldReceive('activate')->once();
        $maintenance->shouldReceive('deactivate')->once();
        DB::shouldReceive('purge')->atLeast()->once();
        DB::shouldReceive('reconnect')->atLeast()->once();
        [$service, $operation] = $this->preparedOperation($process, $maintenance);

        $service->runOperation($operation['operation_id']);
        $status = $service->status($operation['operation_id'], 7);

        $this->assertSame('rolled_back', $status['status']);
        $this->assertSame('BACKUP_RESTORE_FAILED_ROLLED_BACK', $status['error']['code']);
    }

    public function test_rollback_failure_keeps_maintenance_mode_and_marks_manual_recovery(): void
    {
        $process = Mockery::mock(DatabaseRestoreProcess::class);
        $process->shouldReceive('validateIsolated')->once();
        $process->shouldReceive('waitForQuiescence')->once();
        $process->shouldReceive('replaceActive')
            ->twice()
            ->andThrow(new BackupException('RESTORE_FAILED', 'failed'));
        $process->shouldNotReceive('assertActiveInventory');
        $maintenance = Mockery::mock(MaintenanceMode::class);
        $maintenance->shouldReceive('active')->once()->andReturnFalse();
        $maintenance->shouldReceive('activate')->once();
        $maintenance->shouldNotReceive('deactivate');
        DB::shouldReceive('purge')->atLeast()->once();
        DB::shouldReceive('reconnect')->atLeast()->once();
        [$service, $operation] = $this->preparedOperation($process, $maintenance);

        $service->runOperation($operation['operation_id']);
        $status = $service->status($operation['operation_id'], 7);

        $this->assertSame('failed_manual_recovery', $status['status']);
        $this->assertSame('BACKUP_RESTORE_ROLLBACK_FAILED', $status['error']['code']);
        $this->assertTrue(Storage::disk('backup')->directoryExists(
            ".restore/{$operation['operation_id']}",
        ));
        $this->assertTrue(app(RestoreWriteFence::class)->active());
    }

    public function test_changed_pinned_safety_payload_is_detected_before_rollback_import(): void
    {
        $process = Mockery::mock(DatabaseRestoreProcess::class);
        $process->shouldReceive('validateIsolated')->once();
        $process->shouldReceive('waitForQuiescence')->once();
        $process->shouldReceive('replaceActive')
            ->once()
            ->andReturnUsing(function (string $targetPath) {
                $safetyPath = dirname($targetPath) . DIRECTORY_SEPARATOR . 'safety.sql.gz';
                @chmod($safetyPath, 0600);
                file_put_contents($safetyPath, 'replaced-after-validation');

                throw new BackupException('RESTORE_FAILED', 'failed');
            });
        $process->shouldNotReceive('assertActiveInventory');
        $maintenance = Mockery::mock(MaintenanceMode::class);
        $maintenance->shouldReceive('active')->once()->andReturnFalse();
        $maintenance->shouldReceive('activate')->once();
        $maintenance->shouldNotReceive('deactivate');
        DB::shouldReceive('purge')->atLeast()->once();
        DB::shouldReceive('reconnect')->atLeast()->once();
        [$service, $operation] = $this->preparedOperation($process, $maintenance);

        $service->runOperation($operation['operation_id']);
        $status = $service->status($operation['operation_id'], 7);

        $this->assertSame('failed_manual_recovery', $status['status']);
        $this->assertSame(
            'BACKUP_RESTORE_ROLLBACK_FAILED',
            $status['error']['code'],
        );
    }

    private function serviceAndBackup(): array
    {
        $runner = Mockery::mock(DatabaseDumpProcess::class);
        $runner->shouldReceive('dump')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $path) {
                file_put_contents($path, $this->dump);
            });
        $backups = new BackupService($runner);
        $backup = $backups->createBackup();
        $process = Mockery::mock(DatabaseRestoreProcess::class);
        $process->shouldNotReceive('replaceActive');
        $maintenance = Mockery::mock(MaintenanceMode::class);

        return [
            new BackupRestoreService(
                $backups,
                new SqlDumpInspector(),
                $process,
                $maintenance,
                new RestoreWriteFence(),
            ),
            $backup,
        ];
    }

    private function preparedOperation(
        DatabaseRestoreProcess $process,
        MaintenanceMode $maintenance,
    ): array {
        $runner = Mockery::mock(DatabaseDumpProcess::class);
        $runner->shouldReceive('dump')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $path) {
                file_put_contents($path, $this->dump);
            });
        $backups = new BackupService($runner);
        $backup = $backups->createBackup();
        $service = new BackupRestoreService(
            $backups,
            new SqlDumpInspector(),
            $process,
            $maintenance,
            new RestoreWriteFence(),
        );
        $operation = $service->confirm(
            $backup['backup_id'],
            7,
            'RESTORE',
        );

        return [$service, $operation];
    }
}
