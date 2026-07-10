<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * SenseReviewDailySeriesBuilder
 *
 * SenseReview-DailySeries-1000-1
 *
 * Shared Product-layer helper that turns a ReviewLog Collection + fixed
 * day keys into a zero-filled daily series. Sits beside the Product
 * Services (not a new database layer) and reuses
 * SenseReviewReportMetricsService for all metric math.
 *
 * Single responsibility: given already-queried logs and an ascending list
 * of day keys, produce one entry per day key with zero-fill for empty days.
 *
 * Invariants:
 *  - Pure computation: zero DB queries, no Auth, no config, no .env.
 *  - No Chinese product copy.
 *  - Empty days: total_reviews=0, distinct_senses=0, distribution all 0,
 *    forget_rate=null, stability_rate=null (NOT misleading "0%").
 *  - Day entries are in the same order as day_keys (ascending).
 *  - Logs whose reviewed_at falls outside day_keys are silently ignored.
 *  - Reuses SenseReviewReportMetricsService — never re-implements formulas.
 *  - Never writes ReviewLog. Never touches FSRS.
 */
class SenseReviewDailySeriesBuilder
{
    public function __construct(
        private SenseReviewReportMetricsService $metrics,
    ) {
    }

    /**
     * Build a zero-filled daily series.
     *
     * @param  Collection    $logs     Already-queried ReviewLog rows (each must
     *                                 have rating, reviewed_at, word_sense_id).
     * @param  list<string>  $dayKeys  Ascending 'Y-m-d' keys covering the window.
     * @return list<array{
     *   day: string,
     *   total_reviews: int,
     *   distinct_senses: int,
     *   distribution: array{again: int, hard: int, good: int, easy: int},
     *   forget_rate: float|null,
     *   stability_rate: float|null,
     * }>
     */
    public function build(Collection $logs, array $dayKeys): array
    {
        // Group logs by day (only days with data appear here).
        $byDay = $this->metrics->groupByDay($logs);

        $series = [];
        foreach ($dayKeys as $day) {
            $dayLogs = $byDay[$day] ?? collect();
            $series[] = $this->buildDayEntry($day, $dayLogs);
        }

        return $series;
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
}
