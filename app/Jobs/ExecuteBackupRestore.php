<?php

namespace App\Jobs;

use App\Services\BackupRestoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ExecuteBackupRestore implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TIMEOUT_SECONDS = 21600;

    public int $tries = 200;

    public int $timeout = self::TIMEOUT_SECONDS;

    public bool $failOnTimeout = true;

    public int $backoff = 60;

    public function __construct(
        public readonly string $operationId,
    ) {}

    public function handle(BackupRestoreService $restore): void
    {
        $restore->runOperation($this->operationId);
    }

    public function failed(?Throwable $exception): void
    {
        app(BackupRestoreService::class)->markInterrupted($this->operationId);
    }
}
