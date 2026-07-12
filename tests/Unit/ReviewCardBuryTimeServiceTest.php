<?php

namespace Tests\Unit;

use App\Services\ReviewCardBuryTimeService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class ReviewCardBuryTimeServiceTest extends TestCase
{
    private ReviewCardBuryTimeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReviewCardBuryTimeService();
    }

    // ─── Normal date ───

    public function test_next_local_day_boundary_normal_date(): void
    {
        // 2026-07-12 14:30 UTC = 2026-07-12 22:30 Asia/Shanghai
        // Next local midnight: 2026-07-13 00:00 Shanghai = 2026-07-12 16:00 UTC
        $now = Carbon::create(2026, 7, 12, 14, 30, 0, 'UTC');
        $result = $this->service->nextLocalDayBoundary('Asia/Shanghai', $now);
        $this->assertSame('2026-07-12 16:00:00', $result->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $result->tzName);
    }

    public function test_next_local_day_boundary_utc_same_day(): void
    {
        $now = Carbon::create(2026, 7, 12, 14, 30, 0, 'UTC');
        $result = $this->service->nextLocalDayBoundary('UTC', $now);
        $this->assertSame('2026-07-13 00:00:00', $result->format('Y-m-d H:i:s'));
    }

    // ─── Month-end ───

    public function test_next_local_day_boundary_month_end(): void
    {
        // 2026-01-31 14:00 UTC = 2026-01-31 22:00 Shanghai
        // Next local midnight: 2026-02-01 00:00 Shanghai = 2026-01-31 16:00 UTC
        $now = Carbon::create(2026, 1, 31, 14, 0, 0, 'UTC');
        $result = $this->service->nextLocalDayBoundary('Asia/Shanghai', $now);
        $this->assertSame('2026-01-31 16:00:00', $result->format('Y-m-d H:i:s'));
    }

    // ─── Year-end ───

    public function test_next_local_day_boundary_year_end(): void
    {
        // 2026-12-31 14:00 UTC = 2026-12-31 22:00 Shanghai
        // Next local midnight: 2027-01-01 00:00 Shanghai = 2026-12-31 16:00 UTC
        $now = Carbon::create(2026, 12, 31, 14, 0, 0, 'UTC');
        $result = $this->service->nextLocalDayBoundary('Asia/Shanghai', $now);
        $this->assertSame('2026-12-31 16:00:00', $result->format('Y-m-d H:i:s'));
    }

    // ─── Negative timezone offset ───

    public function test_next_local_day_boundary_negative_offset(): void
    {
        // 2026-07-12 14:30 UTC = 2026-07-12 09:30 America/New_York (UTC-5 in DST)
        // Next local midnight: 2026-07-13 00:00 EDT = 2026-07-13 04:00 UTC
        $now = Carbon::create(2026, 7, 12, 14, 30, 0, 'UTC');
        $result = $this->service->nextLocalDayBoundary('America/New_York', $now);
        $this->assertSame('2026-07-13 04:00:00', $result->format('Y-m-d H:i:s'));
    }

    // ─── Already at midnight ───

    public function test_next_local_day_boundary_at_midnight_goes_to_next_day(): void
    {
        // 2026-07-12 00:00 UTC = 2026-07-12 08:00 Shanghai (not midnight in Shanghai)
        // Let's test with UTC midnight instead
        $now = Carbon::create(2026, 7, 12, 0, 0, 0, 'UTC');
        $result = $this->service->nextLocalDayBoundary('UTC', $now);
        // At midnight, should go to NEXT midnight (not today's midnight)
        $this->assertSame('2026-07-13 00:00:00', $result->format('Y-m-d H:i:s'));
    }

    // ─── Invalid timezone ───

    public function test_invalid_timezone_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->nextLocalDayBoundary('Invalid/Timezone', Carbon::now('UTC'));
    }

    public function test_empty_timezone_is_valid(): void
    {
        // Empty string is treated as UTC
        $now = Carbon::create(2026, 7, 12, 14, 30, 0, 'UTC');
        $result = $this->service->nextLocalDayBoundary('', $now);
        $this->assertSame('2026-07-13 00:00:00', $result->format('Y-m-d H:i:s'));
    }

    // ─── isValidTimezone ───

    public function test_is_valid_timezone_utc(): void
    {
        $this->assertTrue($this->service->isValidTimezone('UTC'));
    }

    public function test_is_valid_timezone_shanghai(): void
    {
        $this->assertTrue($this->service->isValidTimezone('Asia/Shanghai'));
    }

    public function test_is_valid_timezone_new_york(): void
    {
        $this->assertTrue($this->service->isValidTimezone('America/New_York'));
    }

    public function test_is_valid_timezone_invalid(): void
    {
        $this->assertFalse($this->service->isValidTimezone('Mars/Olympus'));
    }

    // ─── buryUntil convenience ───

    public function test_bury_until_defaults_to_now(): void
    {
        $result = $this->service->buryUntil('UTC');
        // Should be the next UTC midnight
        $this->assertSame('UTC', $result->tzName);
        $this->assertSame(0, (int) $result->format('H'));
        $this->assertSame(0, (int) $result->format('i'));
        $this->assertSame(0, (int) $result->format('s'));
    }

    public function test_bury_until_with_explicit_now(): void
    {
        $now = Carbon::create(2026, 7, 12, 14, 30, 0, 'UTC');
        $result = $this->service->buryUntil('UTC', $now);
        $this->assertSame('2026-07-13 00:00:00', $result->format('Y-m-d H:i:s'));
    }

    // ─── DST forward (spring ahead) ───

    public function test_dst_forward_spring_ahead_2026(): void
    {
        // US DST spring ahead: 2026-03-08 02:00 EST → 03:00 EDT
        // 2026-03-07 20:00 UTC = 2026-03-07 15:00 EST (UTC-5)
        // Next local midnight: 2026-03-08 00:00 EST = 2026-03-08 05:00 UTC
        // (After spring ahead, 2026-03-08 00:00 is still EST since change is at 02:00)
        $now = Carbon::create(2026, 3, 7, 20, 0, 0, 'UTC');
        $result = $this->service->nextLocalDayBoundary('America/New_York', $now);
        // 2026-03-08 00:00 local = 2026-03-08 05:00 UTC (EST is UTC-5)
        $this->assertSame('2026-03-08 05:00:00', $result->format('Y-m-d H:i:s'));
    }

    // ─── Server timezone differs from user timezone ───

    public function test_user_timezone_different_from_server(): void
    {
        // Server in UTC, user in Tokyo (UTC+9)
        // 2026-07-12 10:00 UTC = 2026-07-12 19:00 Tokyo
        // Next local midnight: 2026-07-13 00:00 Tokyo = 2026-07-12 15:00 UTC
        $now = Carbon::create(2026, 7, 12, 10, 0, 0, 'UTC');
        $result = $this->service->nextLocalDayBoundary('Asia/Tokyo', $now);
        $this->assertSame('2026-07-12 15:00:00', $result->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $result->tzName);
    }
}
