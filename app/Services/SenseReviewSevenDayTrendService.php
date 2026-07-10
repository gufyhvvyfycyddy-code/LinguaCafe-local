<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * SenseReviewSevenDayTrendService
 *
 * SenseReview-SevenDayTrend-1000-1
 *
 * Read-only "近 7 天学习趋势" service. Fixed rolling 7-day window:
 * today + previous 6 natural days (NOT a natural week). Source of truth:
 * ReviewLog. Sense-review only, reset excluded, legacy word excluded.
 *
 * Responsibilities:
 *  - Compute the fixed 7-day window boundary in the Laravel app timezone.
 *  - Delegate ReviewLog querying to SenseReviewAnalyticsQueryService so
 *    the shared sense-only / user-isolated / language-isolated /
 *    reset-excluded rules live in one place. ONE query for the whole
 *    7-day window — constant query budget regardless of sense count.
 *  - Delegate pure metric computation to SenseReviewReportMetricsService
 *    (rating distribution, forget rate, stability rate, distinct senses,
 *    per-day grouping).
 *  - Zero-fill missing days so the days array always has exactly 7
 *    entries (Product Service responsibility — Metrics does not know
 *    about window sizes).
 *  - Build summary block (total_reviews, active_days, distinct_senses,
 *    average_per_active_day, distribution, forget_rate, stability_rate).
 *
 * Invariants:
 *  - READ-ONLY: never writes ReviewLog, never touches FSRS, never
 *    creates WordSense or ReviewCard.
 *  - Strict user + language isolation (enforced by Query Layer).
 *  - Reset exclusion + sense-only filtering centralized in Query Layer.
 *  - No new database table, no migration, no FSRS change.
 *  - Empty days → 0 counts, null rates (NOT misleading "0%").
 *
 * Layering:
 *   Query Layer   → reviewsForPeriod (1 query for whole window)
 *   Metrics Layer → groupByDay, periodMetrics, ratingDistribution, etc.
 *   Product Layer → this service (window sizing, zero-fill, summary)
 */
class SenseReviewSevenDayTrendService
{
    public function __construct(
        private SenseReviewAnalyticsQueryService $analytics,
        private SenseReviewReportMetricsService $metrics,
    ) {
    }

    /**
     * Build the read-only 7-day trend payload.
     *
     * @param  int     $userId
     * @param  string  $language  The user's selected_language (language_id).
     * @return array{
     *   timezone: string,
     *   start_day: string,
     *   end_day: string,
     *   summary: array{
     *     total_reviews: int,
     *     active_days: int,
     *     distinct_senses: int,
     *     average_per_active_day: float|null,
     *     distribution: array{again: int, hard: int, good: int, easy: int},
     *     forget_rate: float|null,
     *     stability_rate: float|null,
     *   },
     *   days: list<array{
     *     day: string,
     *     total_reviews: int,
     *     distinct_senses: int,
     *     distribution: array{again: int, hard: int, good: int, easy: int},
     *     forget_rate: float|null,
     *     stability_rate: float|null,
     *   }>,
     * }
     */
    public function build(int $userId, string $language): array
    {
        $timezone = config('app.timezone', 'UTC');
        $endDay = Carbon::today($timezone);          // today 00:00:00
        $startDay = $endDay->copy()->subDays(6);     // 6 days before today 00:00:00

        // Window: [startDay 00:00:00, tomorrow 00:00:00) — covers today fully.
        $start = $startDay->copy();
        $end = $endDay->copy()->addDay(); // exclusive upper bound

        // SINGLE query for the whole 7-day window via the centralized Query Layer.
        $logs = $this->analytics->reviewsForPeriod($userId, $language, $start, $end);

        // Group by day via the pure Metrics Layer (only days with data appear).
        $byDay = $this->metrics->groupByDay($logs);

        // Build the fixed 7-day array with zero-fill (Product responsibility).
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = $endDay->copy()->subDays($i)->format('Y-m-d');
            $dayLogs = $byDay[$day] ?? collect();
            $days[] = $this->buildDayEntry($day, $dayLogs);
        }

        $summary = $this->buildSummary($logs, $days);

        return [
            'timezone' => $timezone,
            'start_day' => $startDay->format('Y-m-d'),
            'end_day' => $endDay->format('Y-m-d'),
            'summary' => $summary,
            'days' => $days,
        ];
    }

    /**
     * Build a single day entry. Empty days get 0 counts and null rates
     * (never a misleading "0%").
     *
     * @param  string      $day  'Y-m-d'
     * @param  Collection  $logs Logs for this day (may be empty).
     * @return array{
     *   day: string,
     *   total_reviews: int,
     *   distinct_senses: int,
     *   distribution: array{again: int, hard: int, good: int, easy: int},
     *   forget_rate: float|null,
     *   stability_rate: float|null,
     * }
     */
    private function buildDayEntry(string $day, Collection $logs): array
    {
        return [
            'day' => $day,
            'total_reviews' => $logs->count(),
            'distinct_senses' => $this->metrics->distinctSenseCount($logs),
            'distribution' => $this->metrics->ratingDistribution($logs),
            'forget_rate' => $this->metrics->forgetRate($logs),
            'stability_rate' => $this->metrics->stabilityRate($logs),
        ];
    }

    /**
     * Build the summary block for the whole 7-day window.
     *
     * average_per_active_day = total_reviews / active_days, null when
     * active_days = 0 (not a fake 0).
     *
     * @param  Collection  $logs  All logs in the 7-day window.
     * @param  array       $days  The 7 zero-filled day entries.
     * @return array{
     *   total_reviews: int,
     *   active_days: int,
     *   distinct_senses: int,
     *   average_per_active_day: float|null,
     *   distribution: array{again: int, hard: int, good: int, easy: int},
     *   forget_rate: float|null,
     *   stability_rate: float|null,
     * }
     */
    private function buildSummary(Collection $logs, array $days): array
    {
        $totalReviews = $logs->count();
        $activeDays = 0;
        foreach ($days as $day) {
            if ($day['total_reviews'] > 0) {
                $activeDays++;
            }
        }

        $averagePerActiveDay = $activeDays > 0
            ? round($totalReviews / $activeDays, 2)
            : null;

        return [
            'total_reviews' => $totalReviews,
            'active_days' => $activeDays,
            'distinct_senses' => $this->metrics->distinctSenseCount($logs),
            'average_per_active_day' => $averagePerActiveDay,
            'distribution' => $this->metrics->ratingDistribution($logs),
            'forget_rate' => $this->metrics->forgetRate($logs),
            'stability_rate' => $this->metrics->stabilityRate($logs),
        ];
    }
}
