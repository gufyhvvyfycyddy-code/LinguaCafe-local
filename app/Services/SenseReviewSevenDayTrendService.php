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
 *  - Delegate window computation to SenseReviewReportPeriodService.
 *  - Delegate ReviewLog querying to SenseReviewAnalyticsQueryService so
 *    the shared sense-only / user-isolated / language-isolated /
 *    reset-excluded rules live in one place. ONE query for the whole
 *    7-day window — constant query budget regardless of sense count.
 *  - Delegate daily series zero-fill to SenseReviewDailySeriesBuilder
 *    (which reuses SenseReviewReportMetricsService).
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
 *   Period Layer   → rollingDays(7, tz) gives start/end/day_keys
 *   Query Layer    → reviewsForPeriod (1 query for whole window)
 *   Series Layer   → build(logs, day_keys) zero-fills
 *   Metrics Layer  → groupByDay, ratingDistribution, etc. (via Series)
 *   Product Layer  → this service (summary block, payload shape)
 */
class SenseReviewSevenDayTrendService
{
    public function __construct(
        private SenseReviewAnalyticsQueryService $analytics,
        private SenseReviewReportMetricsService $metrics,
        private SenseReviewReportPeriodService $periodService,
        private SenseReviewDailySeriesBuilder $seriesBuilder,
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

        // Period Layer: compute the fixed 7-day window.
        $period = $this->periodService->rollingDays(7, $timezone);

        // SINGLE query for the whole 7-day window via the centralized Query Layer.
        $logs = $this->analytics->reviewsForPeriod($userId, $language, $period['start'], $period['end']);

        // Series Layer: zero-fill to exactly 7 ascending day entries.
        $days = $this->seriesBuilder->build($logs, $period['day_keys']);

        $summary = $this->buildSummary($logs, $days);

        return [
            'timezone' => $timezone,
            'start_day' => $period['start_day'],
            'end_day' => $period['end_day'],
            'summary' => $summary,
            'days' => $days,
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
