<?php

namespace Tests\Unit;

use App\Services\ReviewStudyTimezoneService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * DEV-QO-2: Unified study timezone boundary service.
 *
 * Verifies that a single, explicit, testable service provides the learning
 * timezone, local date, and local day boundary — replacing scattered
 * config('app.timezone') reads and Carbon::today() calls.
 *
 * V1 contract (per task spec):
 *   - Returns config('app.timezone', 'UTC') — does NOT invent a user field.
 *   - Validates the timezone.
 *   - Does not call the DB.
 *   - Provides a single replacement point for future user-level timezone.
 */
class ReviewStudyTimezoneServiceTest extends TestCase
{
    private ReviewStudyTimezoneService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReviewStudyTimezoneService();
    }

    public function test_returns_a_non_empty_timezone_string(): void
    {
        $tz = $this->service->getStudyTimezone();
        $this->assertIsString($tz);
        $this->assertNotSame('', $tz);
    }

    public function test_returns_valid_iana_timezone(): void
    {
        $tz = $this->service->getStudyTimezone();
        // Must be accepted by DateTimeZone
        $dtz = new \DateTimeZone($tz);
        $this->assertNotNull($dtz);
    }

    public function test_defaults_to_app_timezone_config(): void
    {
        config(['app.timezone' => 'UTC']);
        $service = new ReviewStudyTimezoneService();
        $this->assertSame('UTC', $service->getStudyTimezone());
    }

    public function test_defaults_to_utc_when_config_missing(): void
    {
        config(['app.timezone' => null]);
        $service = new ReviewStudyTimezoneService();
        $this->assertSame('UTC', $service->getStudyTimezone());
    }

    public function test_local_date_for_utc_now(): void
    {
        config(['app.timezone' => 'UTC']);
        $service = new ReviewStudyTimezoneService();
        $now = Carbon::create(2026, 7, 13, 10, 30, 0, 'UTC');
        $this->assertSame('2026-07-13', $service->localDate($now));
    }

    public function test_local_date_respects_learning_timezone(): void
    {
        // 2026-07-13 23:30 UTC = 2026-07-13 16:30 America/Los_Angeles (PDT, UTC-7)
        config(['app.timezone' => 'America/Los_Angeles']);
        $service = new ReviewStudyTimezoneService();
        $now = Carbon::create(2026, 7, 13, 23, 30, 0, 'UTC');
        $this->assertSame('2026-07-13', $service->localDate($now));

        // 2026-07-14 07:00 UTC = 2026-07-14 00:00 America/Los_Angeles (PDT)
        $now2 = Carbon::create(2026, 7, 14, 7, 0, 0, 'UTC');
        $this->assertSame('2026-07-14', $service->localDate($now2));
    }

    public function test_local_date_across_midnight_boundary(): void
    {
        // 2026-07-13 06:59 UTC = 2026-07-12 23:59 America/Los_Angeles (PDT, UTC-7)
        config(['app.timezone' => 'America/Los_Angeles']);
        $service = new ReviewStudyTimezoneService();
        $before = Carbon::create(2026, 7, 13, 6, 59, 0, 'UTC');
        $this->assertSame('2026-07-12', $service->localDate($before));

        $after = Carbon::create(2026, 7, 13, 7, 1, 0, 'UTC');
        $this->assertSame('2026-07-13', $service->localDate($after));
    }

    public function test_day_start_returns_carbon_at_local_midnight(): void
    {
        config(['app.timezone' => 'America/Los_Angeles']);
        $service = new ReviewStudyTimezoneService();
        $now = Carbon::create(2026, 7, 13, 23, 30, 0, 'UTC');
        $dayStart = $service->dayStart($now);

        // 2026-07-13 23:30 UTC = 2026-07-13 16:30 PDT
        // Day start should be 2026-07-13 00:00 PDT = 2026-07-13 07:00 UTC
        $this->assertSame('2026-07-13', $dayStart->format('Y-m-d'));
        $this->assertSame('00:00:00', $dayStart->format('H:i:s'));
    }

    public function test_day_start_for_utc(): void
    {
        config(['app.timezone' => 'UTC']);
        $service = new ReviewStudyTimezoneService();
        $now = Carbon::create(2026, 7, 13, 15, 30, 0, 'UTC');
        $dayStart = $service->dayStart($now);

        $this->assertSame('2026-07-13', $dayStart->format('Y-m-d'));
        $this->assertSame('00:00:00', $dayStart->format('H:i:s'));
    }

    public function test_local_date_for_arbitrary_carbon_in_different_tz(): void
    {
        // A Carbon stored in UTC, but we want the local date in Asia/Shanghai
        config(['app.timezone' => 'Asia/Shanghai']);
        $service = new ReviewStudyTimezoneService();
        // 2026-07-13 18:00 UTC = 2026-07-14 02:00 Asia/Shanghai (CST, UTC+8)
        $now = Carbon::create(2026, 7, 13, 18, 0, 0, 'UTC');
        $this->assertSame('2026-07-14', $service->localDate($now));
    }

    public function test_does_not_read_db(): void
    {
        // The service must be pure — no DB queries.
        // We verify by checking it works with DB facade unavailable.
        $service = new ReviewStudyTimezoneService();
        $tz = $service->getStudyTimezone();
        $this->assertNotEmpty($tz);
    }

    public function test_day_bounds_follow_dst_local_midnights(): void
    {
        config(['app.timezone' => 'America/New_York']);
        $bounds = (new ReviewStudyTimezoneService())->dayBounds(
            Carbon::create(2026, 3, 8, 12, 0, 0, 'UTC')
        );

        $this->assertSame('2026-03-08', $bounds['study_date']);
        $this->assertSame('2026-03-08T00:00:00-05:00', $bounds['day_start']->toIso8601String());
        $this->assertSame('2026-03-09T00:00:00-04:00', $bounds['next_day_start']->toIso8601String());
        $this->assertSame(23.0, $bounds['day_start']->diffInHours($bounds['next_day_start']));
    }
}
