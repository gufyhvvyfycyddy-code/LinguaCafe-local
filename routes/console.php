<?php

use Illuminate\Support\Facades\Schedule;
use App\Services\BackupSchedule;

$backupSchedule = app(BackupSchedule::class)->expression();

if ($backupSchedule !== null) {
    Schedule::command('app:create-backup')
        ->cron($backupSchedule)
        ->withoutOverlapping();
}
