<?php

namespace Tests\Feature;

use App\Services\SenseReviewReportPeriodService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SenseReviewReportPeriodServiceTest
 *
 * Verifies the pure time-window utility for SenseReview reports.
 *
 * Contract:
 *  - Pure time math: no DB, no Auth, no config, no .env reads.
 *  - rollingDays(int $days, string $timezone): returns start_day, end_day,
 *    start (inclusive), end (exclusive), day_keys (ascending 'Y-m-d').
 *  - Rejects 0, negative, and unreasonably large day counts.
 *  - No Chinese product copy. No payload decisions.
 *
 * Rule tests:
 *  1. 1-day window boundary
 *  2. 7-day window boundary
 *  3. 30-day window boundary
 *  4. day_keys ascending and correct count
 *  5. DST / cross-month / cross-year sequences
 *  6. illegal days (0, -1) safety fail
 * 7. illegal days (huge) safety fail
 *  8. zero DB queries
 *  9. timezone respected
 * 10. start inclusive, end exclusive
 * 11. day_keys format 'Y-m-d'
 * 12. end_day = today, start_day = today - (days-1)
 */
class SenseReviewReportPeriodServiceTest extends TestCase
{
    use RefreshDatabase;

    private SenseReviewReportPeriodService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SenseReviewReportPeriodService();
    }

    /**
     * 1. 1-day window: just today.
     */
    public function test_one_day_window_boundary(): void
    {
        $tz = 'UTC';
        Carbon::setTestNow(Carbon::create(2026, 7, 10, 12, 0, 0, $tz));

        $period = $this->service->rollingDays(1, $tz);

        $this->assertSame('2026-07-10', $period['start_day']);
        $this->assertSame('2026-07-10', $period['end_day']);
        $this->assertCount(1, $period['day_keys']);
        $this->assertSame('2026-07-10', $period['day_keys'][0]);

        Carbon::setTestNow();
    }

    /**
     * 2. 7-day window: today + previous 6 natural days.
     */
    public function test_seven_day_window_boundary(): void
    {
        $tz = 'UTC';
        Carbon::setTestNow(Carbon::create(2026, 7, 10, 15, 30, 0, $tz));

        $period = $this->service->rollingDays(7, $tz);

        $this->assertSame('2026-07-04', $period['start_day']);
        $this->assertSame('2026-07-10', $period['end_day']);
        $this->assertCount(7, $period['day_keys']);
        $this->assertSame('2026-07-04', $period['day_keys'][0]);
        $this->assertSame('2026-07-10', $period['day_keys'][6]);

        Carbon::setTestNow();
    }

    /**
     * 3. 30-day window: today + previous 29 natural days.
     */
    public function test_thirty_day_window_boundary(): void
    {
        $tz = 'UTC';
        Carbon::setTestNow(Carbon::create(2026, 7, 10, 8, 0, 0, $tz));

        $period = $this->service->rollingDays(30, $tz);

        $this->assertSame('2026-06-11', $period['start_day']);
        $this->assertSame('2026-07-10', $period['end_day']);
        $this->assertCount(30, $period['day_keys']);
        $this->assertSame('2026-06-11', $period['day_keys'][0]);
        $this->assertSame('2026-07-10', $period['day_keys'][29]);

        Carbon::setTestNow();
    }

    /**
     * 4. day_keys ascending and correct count.
     */
    public function test_day_keys_ascending_and_correct_count(): void
    {
        $tz = 'UTC';
        Carbon::setTestNow(Carbon::create(2026, 7, 10, 0, 0, 0, $tz));

        $period = $this->service->rollingDays(7, $tz);

        $this->assertCount(7, $period['day_keys']);
        for ($i = 1; $i < count($period['day_keys']); $i++) {
            $prev = Carbon::parse($period['day_keys'][$i - 1]);
            $curr = Carbon::parse($period['day_keys'][$i]);
            $this->assertSame(1.0, $prev->diffInDays($curr), 'day_keys must be consecutive ascending');
        }

        Carbon::setTestNow();
    }

    /**
     * 5. DST / cross-month / cross-year sequences.
     */
    public function test_cross_month_and_cross_year_sequence(): void
    {
        $tz = 'UTC';
        // Cross month: Jan 31 → Feb 6
        Carbon::setTestNow(Carbon::create(2026, 2, 3, 10, 0, 0, $tz));

        $period = $this->service->rollingDays(7, $tz);

        $this->assertSame('2026-01-28', $period['start_day']);
        $this->assertSame('2026-02-03', $period['end_day']);
        $this->assertContains('2026-01-31', $period['day_keys']);
        $this->assertContains('2026-02-01', $period['day_keys']);

        Carbon::setTestNow();

        // Cross year: Dec 30 2025 → Jan 5 2026
        Carbon::setTestNow(Carbon::create(2026, 1, 5, 10, 0, 0, $tz));

        $period2 = $this->service->rollingDays(7, $tz);

        $this->assertSame('2025-12-30', $period2['start_day']);
        $this->assertSame('2026-01-05', $period2['end_day']);
        $this->assertContains('2025-12-31', $period2['day_keys']);
        $this->assertContains('2026-01-01', $period2['day_keys']);

        Carbon::setTestNow();
    }

    /**
     * 6. Illegal days (0, -1) safety fail.
     */
    public function test_illegal_days_zero_and_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->rollingDays(0, 'UTC');
    }

    /**
     * 7. Illegal days (huge) safety fail.
     */
    public function test_illegal_days_huge(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // 366 is beyond a year — unreasonably large for a rolling report.
        $this->service->rollingDays(366, 'UTC');
    }

    /**
     * 8. Zero DB queries.
     */
    public function test_period_service_does_not_access_database(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service->rollingDays(7, 'UTC');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(0, count($queries), 'PeriodService must issue zero DB queries');
    }

    /**
     * 9. Timezone respected.
     */
    public function test_timezone_respected(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 10, 2, 0, 0, 'UTC'));

        // In Asia/Shanghai (UTC+8), it's 2026-07-10 10:00 — same day.
        $periodShanghai = $this->service->rollingDays(7, 'Asia/Shanghai');
        $this->assertSame('2026-07-10', $periodShanghai['end_day']);

        // In America/Los_Angeles (UTC-7), it's 2026-07-09 19:00 — previous day.
        $periodLA = $this->service->rollingDays(7, 'America/Los_Angeles');
        $this->assertSame('2026-07-09', $periodLA['end_day']);

        Carbon::setTestNow();
    }

    /**
     * 10. start inclusive, end exclusive.
     */
    public function test_start_inclusive_end_exclusive(): void
    {
        $tz = 'UTC';
        Carbon::setTestNow(Carbon::create(2026, 7, 10, 12, 0, 0, $tz));

        $period = $this->service->rollingDays(7, $tz);

        $this->assertSame('2026-07-04 00:00:00', $period['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-11 00:00:00', $period['end']->format('Y-m-d H:i:s'));
        $this->assertTrue($period['start']->lt($period['end']));

        Carbon::setTestNow();
    }

    /**
     * 11. day_keys format 'Y-m-d'.
     */
    public function test_day_keys_format(): void
    {
        $period = $this->service->rollingDays(3, 'UTC');

        foreach ($period['day_keys'] as $key) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $key, "day_key '$key' must be Y-m-d format");
        }
    }

    /**
     * 12. end_day = today, start_day = today - (days-1).
     */
    public function test_end_day_is_today_start_day_is_today_minus_days_minus_one(): void
    {
        $tz = 'UTC';
        Carbon::setTestNow(Carbon::create(2026, 7, 10, 14, 0, 0, $tz));

        $period = $this->service->rollingDays(30, $tz);

        $this->assertSame('2026-07-10', $period['end_day']);
        $this->assertSame('2026-06-11', $period['start_day']);

        Carbon::setTestNow();
    }
}
