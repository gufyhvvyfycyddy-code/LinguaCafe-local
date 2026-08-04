<?php

namespace Tests\Unit;

use App\Services\BackupSchedule;
use Tests\TestCase;

class BackupScheduleTest extends TestCase
{
    public function test_valid_enabled_schedule_is_returned(): void
    {
        config([
            'backup.enabled' => true,
            'backup.schedule' => '0 2 * * *',
        ]);

        $this->assertSame('0 2 * * *', app(BackupSchedule::class)->expression());
    }

    public function test_disabled_schedule_fails_closed(): void
    {
        config([
            'backup.enabled' => false,
            'backup.schedule' => '0 2 * * *',
        ]);

        $this->assertNull(app(BackupSchedule::class)->expression());
    }

    public function test_invalid_schedule_fails_closed(): void
    {
        config([
            'backup.enabled' => true,
            'backup.schedule' => 'not a cron expression',
        ]);

        $this->assertNull(app(BackupSchedule::class)->expression());
    }
}
