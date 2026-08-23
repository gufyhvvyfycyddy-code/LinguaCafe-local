<?php

use Illuminate\Support\Facades\Schedule;
use App\Services\BackupSchedule;

$backupSchedule = app(BackupSchedule::class)->expression();

if ($backupSchedule !== null) {
    Schedule::command('app:create-backup')
        ->cron($backupSchedule)
        ->withoutOverlapping();
}

Schedule::command('fsrs:optimize-due')
    ->dailyAt('03:00')
    ->withoutOverlapping();
