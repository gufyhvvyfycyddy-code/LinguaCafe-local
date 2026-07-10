<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * SenseReviewReportPeriodService
 *
 * SenseReview-ReportPeriod-1000-1
 *
 * Pure time-window utility for SenseReview reports. Does NOT access the
 * database, Auth, config, or .env. Timezone is passed in by the caller.
 *
 * Single responsibility: convert a rolling-day count into explicit date
 * boundaries and an ascending day-key sequence. No product copy, no
 * payload decisions, no metric computation.
 *
 * Layer: sits beside SenseReviewReportMetricsService as a pure helper
 * used by Product Services (SevenDayTrend, ThirtyDayCalendar, future
 * reports). It is NOT a new database layer.
 *
 * Invariants:
 *  - Zero DB queries.
 *  - No Auth, no config, no .env reads.
 *  - No Chinese product copy.
 *  - Rejects 0, negative, and unreasonably large (> 365) day counts.
 *  - day_keys are ascending 'Y-m-d' strings covering [start_day, end_day].
 *  - start is inclusive (00:00:00), end is exclusive (next day 00:00:00).
 */
class SenseReviewReportPeriodService
{
    /**
     * Maximum allowed rolling window. A year is the practical upper bound
     * for a SenseReview report; anything larger is almost certainly a bug.
     */
    public const MAX_DAYS = 365;

    /**
     * Build a fixed rolling-day window ending today (in the given timezone).
     *
     * @param  int     $days      Number of days in the window (1 = today only,
     *                            7 = today + previous 6, 30 = today + previous 29).
     * @param  string  $timezone  Caller-supplied timezone (e.g. config('app.timezone')).
     * @return array{
     *   start_day: string,
     *   end_day: string,
     *   start: Carbon,
     *   end: Carbon,
     *   day_keys: list<string>,
     * }
     *
     * @throws \InvalidArgumentException When $days <= 0 or $days > MAX_DAYS.
     */
    public function rollingDays(int $days, string $timezone): array
    {
        if ($days <= 0 || $days > self::MAX_DAYS) {
            throw new \InvalidArgumentException(
                "rollingDays expects 1.." . self::MAX_DAYS . ", got {$days}"
            );
        }

        $endDay = Carbon::today($timezone);          // today 00:00:00
        $startDay = $endDay->copy()->subDays($days - 1);  // (days-1) before today

        $start = $startDay->copy();
        $end = $endDay->copy()->addDay();            // exclusive upper bound

        $dayKeys = [];
        for ($i = 0; $i < $days; $i++) {
            $dayKeys[] = $startDay->copy()->addDays($i)->format('Y-m-d');
        }

        return [
            'start_day' => $startDay->format('Y-m-d'),
            'end_day' => $endDay->format('Y-m-d'),
            'start' => $start,
            'end' => $end,
            'day_keys' => $dayKeys,
        ];
    }
}
