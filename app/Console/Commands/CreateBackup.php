<?php

namespace App\Console\Commands;

use App\Exceptions\BackupException;
use App\Services\BackupService;
use Illuminate\Console\Command;

class CreateBackup extends Command
{
    protected $signature = 'app:create-backup';

    protected $description = 'Creates and publishes a verified database backup.';

    public function handle(BackupService $backups): int
    {
        try {
            $backup = $backups->createBackup();
        } catch (BackupException $exception) {
            $this->error("Backup failed: {$exception->errorCode}");

            return self::FAILURE;
        }

        $this->info("Backup created: {$backup['backup_id']}");

        return self::SUCCESS;
    }
}
