<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * SenseReviewDailyReportService
 *
 * SenseReview-DailyReport-1000-1 (consolidated in 1000-3)
 *
 * Read-only "今日学习日报" (daily learning report) service. This is now the
 * SINGLE formal today-report Product Service — the former
 * SenseReviewTodaySummaryService was merged into this service in task
 * GLM-SenseReview-DailyReportConsolidation-AndMergedProduct-1000-3.
 *
 * Produces a five-block report designed for daily learning reflection:
 *
 *   1. overview        — totals, distinct senses, first-vs-again, average rating.
 *   2. quality         — distribution, forget rate, stability rate.
 *   3. focus_senses    — max 10 senses needing attention, sorted by difficulty.
 *   4. progress_senses — senses that improved (again→good, hard→easy) today.
 *   5. recent_reviews  — max 10 newest reviews with rating_label (additive field
 *                        migrated from the old TodaySummary service).
 *
 * Responsibilities:
 *  - Compute the natural-day boundary via SenseReviewReportPeriodService::
 *    rollingDays(1, timezone) so date logic is centralized.
 *  - Delegate ReviewLog querying to SenseReviewAnalyticsQueryService so the
 *    shared sense-only / user-isolated / language-isolated / reset-excluded
 *    query rules live in one place.
 *  - Delegate rating distribution / forget rate / stability rate / average
 *    rating / distinct-sense count to the analytics+metrics layer so the
 *    formulas are shared with SevenDayTrend + LearningFeedback.
 *  - Delegate focus_senses / progress_senses / recent_reviews generation to
 *    SenseReviewDailyInsightBuilder so insight algorithms have ONE source of
 *    truth (previously duplicated in TodaySummary + DailyReport).
 *  - Determine first-review vs review-again senses via
 *    SenseReviewAnalyticsQueryService::sensesReviewedBefore().
 *  - Shape the final payload.
 *
 * Invariants:
 *  - READ-ONLY: never writes ReviewLog, never touches FSRS, never creates
 *    WordSense or ReviewCard.
 *  - Strict user + language isolation (enforced by analytics layer).
 *  - ReviewLog sense-only filtering + reset exclusion centralized in
 *    SenseReviewAnalyticsQueryService / SenseReviewQueryService.
 *  - No new database table, no migration, no FSRS change.
 *  - Insight algorithms (focus/progress/recent) are NOT re-implemented here —
 *    they live in SenseReviewDailyInsightBuilder.
 */
class SenseReviewDailyReportService
{
    public function __construct(
        private SenseReviewAnalyticsQueryService $analytics,
        private SenseReviewRatingContract $contract,
        private SenseReviewReportMetricsService $metrics,
        private SenseReviewReportPeriodService $periodService,
        private SenseReviewDailyInsightBuilder $insightBuilder,
    ) {
    }

    /**
     * Build the read-only daily learning report.
     *
     * @param  int     $userId
     * @param  string  $language  The user's selected_language (language_id).
     * @return array{
     *   timezone: string,
     *   day: string,
     *   day_start: string,
     *   day_end: string,
     *   overview: array{
     *     total_reviews: int,
     *     distinct_senses: int,
     *     first_review_senses: int,
     *     review_again_senses: int,
     *     average_rating: float|null,
     *   },
     *   quality: array{
     *     distribution: array{again: int, hard: int, good: int, easy: int},
     *     forget_rate: float|null,
     *     stability_rate: float|null,
     *   },
     *   focus_senses: list<array>,
     *   progress_senses: list<array>,
     *   recent_reviews: list<array>,
     * }
     */
    public function build(int $userId, string $language): array
    {
        $timezone = config('app.timezone', 'UTC');
        $window = $this->periodService->rollingDays(1, $timezone);
        $dayStart = $window['start'];
        $dayEnd = $window['end'];

        // Delegate log fetching to the centralized analytics layer.
        $todayLogs = $this->analytics->reviewsForPeriod($userId, $language, $dayStart, $dayEnd);

        // Distinct sense ids reviewed today.
        $todaySenseIds = $todayLogs->pluck('word_sense_id')->unique()->values()->all();

        // Sense ids reviewed before today (for first-review vs review-again).
        $beforeTodaySenseIds = [];
        if (!empty($todaySenseIds)) {
            $beforeTodaySenseIds = $this->analytics->sensesReviewedBefore($userId, $language, $dayStart);
        }
        $beforeTodaySet = array_flip($beforeTodaySenseIds);

        // Insight lists (focus / progress / recent) from the pure builder —
        // 0 additional DB queries, computed from the same in-memory logs.
        $insights = $this->insightBuilder->build($todayLogs);

        return [
            'timezone' => $timezone,
            'day' => $dayStart->format('Y-m-d'),
            'day_start' => $dayStart->toIso8601String(),
            'day_end' => $dayEnd->toIso8601String(),
            'overview' => $this->buildOverview($todayLogs, $todaySenseIds, $beforeTodaySet),
            'quality' => $this->buildQuality($todayLogs),
            'focus_senses' => $insights['focus_senses'],
            'progress_senses' => $insights['progress_senses'],
            'recent_reviews' => $insights['recent_reviews'],
        ];
    }

    /**
     * Build the overview block.
     *
     * @param  Collection  $logs           Today's non-reset logs (newest-first).
     * @param  array       $todaySenseIds  Distinct sense ids reviewed today.
     * @param  array       $beforeTodaySet Map of sense_id => true (reviewed before today).
     */
    private function buildOverview(Collection $logs, array $todaySenseIds, array $beforeTodaySet): array
    {
        $total = $logs->count();
        $distinct = count($todaySenseIds);

        $firstReview = 0;
        $reviewAgain = 0;
        foreach ($todaySenseIds as $sid) {
            if (isset($beforeTodaySet[$sid])) {
                $reviewAgain++;
            } else {
                $firstReview++;
            }
        }

        // average_rating: null when empty (frontend shows "暂无数据").
        // Score mapping via SenseReviewRatingContract (single source of truth).
        $averageRating = null;
        if ($total > 0) {
            $sum = 0;
            foreach ($logs as $log) {
                $sum += $this->contract->scoreFor($log->rating) ?? 0;
            }
            $averageRating = round($sum / $total, 2);
        }

        return [
            'total_reviews' => $total,
            'distinct_senses' => $distinct,
            'first_review_senses' => $firstReview,
            'review_again_senses' => $reviewAgain,
            'average_rating' => $averageRating,
        ];
    }

    /**
     * Build the quality block: distribution, forget_rate, stability_rate.
     *
     * Distribution + rates delegate to the metrics layer so the formulas
     * are shared with SevenDayTrend + LearningFeedback.
     *
     * forget_rate = again / total (null when empty).
     * stability_rate = (good + easy) / total (null when empty).
     */
    private function buildQuality(Collection $logs): array
    {
        return [
            'distribution' => $this->metrics->ratingDistribution($logs),
            'forget_rate' => $this->metrics->forgetRate($logs),
            'stability_rate' => $this->metrics->stabilityRate($logs),
        ];
    }
}
