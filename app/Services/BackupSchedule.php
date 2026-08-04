<?php

namespace App\Services;

use Cron\CronExpression;

class BackupSchedule
{
    public function expression(): ?string
    {
        $expression = config('backup.schedule');

        if (! config('backup.enabled')
            || ! is_string($expression)
            || ! CronExpression::isValidExpression($expression)) {
            return null;
        }

        return $expression;
    }
}
