<?php

namespace Tests\Feature;

use App\Jobs\ExecuteBackupRestore;
use App\Services\BackupRestoreService;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ExecuteBackupRestoreTest extends TestCase
{
    public function test_job_delegates_to_durable_operation_id(): void
    {
        $operationId = '11111111-1111-4111-8111-111111111111';
        $restore = Mockery::mock(BackupRestoreService::class);
        $restore->shouldReceive('runOperation')->once()->with($operationId);

        (new ExecuteBackupRestore($operationId))->handle($restore);
    }

    public function test_failed_job_marks_interrupted_operation_for_manual_recovery(): void
    {
        $operationId = '22222222-2222-4222-8222-222222222222';
        $restore = Mockery::mock(BackupRestoreService::class);
        $restore->shouldReceive('markInterrupted')->once()->with($operationId);
        $this->app->instance(BackupRestoreService::class, $restore);

        (new ExecuteBackupRestore($operationId))->failed(
            new RuntimeException('worker interrupted'),
        );
    }
}
