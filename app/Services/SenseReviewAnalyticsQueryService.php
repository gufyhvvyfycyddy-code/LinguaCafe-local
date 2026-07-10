<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * SenseReviewAnalyticsQueryService
 *
 * SenseReview-AnalyticsQuery-1000-1
 *
 * Centralized read-only ReviewLog statistics query layer for the SenseReview
 * feature. This is the single entry point for sense-review analytics queries
 * so that future features (weekly reports, monthly reports, learning trends,
 * forgetting analysis, sense growth curves) share one consistent data path
 * instead of each re-implementing ReviewLog queries.
 *
 * Responsibilities (Query Layer only — DB reads + isolation):
 *  - reviewsForPeriod(): non-reset sense review logs in a [start, end) window,
 *    with sense metadata, newest-first. Used by TodaySummary + DailyReport +
 *    SevenDayTrend.
 *  - sensesReviewedBefore(): sense ids with any non-reset review before a time.
 *    Used by DailyReport first-review vs review-again detection.
 *  - reviewsForCards(): non-reset logs for given card ids, newest-first.
 *    Used by LearningFeedback. Single batch query regardless of card count.
 *
 * Pure computation (ratingDistribution, forgetRate, stabilityRate,
 * reviewsBySense, averageRating, etc.) lives in
 * SenseReviewReportMetricsService — NOT here. Rating labels and numeric
 * scores live in SenseReviewRatingContract — NOT here.
 *
 * Invariants:
 *  - READ-ONLY: never writes ReviewLog / ReviewCard / WordSense / FSRS.
 *  - Strict user + language isolation for sense-scoped queries (delegated to
 *    SenseReviewQueryService::nonResetSenseReviewLogQuery).
 *  - Reset exclusion (rating='reset' OR source='reset') centralized via
 *    SenseReviewQueryService for both sense-scoped and card-scoped paths.
 *  - Sense-only filtering (target_type='sense') for sense-scoped queries.
 *  - No product copy / sort / max-limit / rating-label / rating-score logic
 *    here — callers shape results.
 *  - No new database table, no migration, no FSRS change.
 *
 * Boundary:
 *  - Reads: ReviewLog, ReviewCard (join), WordSense (join).
 *  - Never writes: ReviewLog, ReviewCard, WordSense, FSRS.
 */
class SenseReviewAnalyticsQueryService
{
    public function __construct(
        private SenseReviewQueryService $senseReviewQueryService,
    ) {
    }

    /**
     * Fetch non-reset sense review logs in a [start, end) window.
     *
     * Returns a Collection of log rows with sense metadata, ordered
     * newest-first (reviewed_at DESC, id DESC). Each row has:
     *   id, review_card_id, rating, reviewed_at,
     *   word_sense_id (alias of review_cards.target_id),
     *   lemma, sense_zh.
     *
     * User / language isolation, sense-only filtering, and reset exclusion
     * are all delegated to SenseReviewQueryService::nonResetSenseReviewLogQuery.
     *
     * @param  int     $userId
     * @param  string  $language
     * @param  Carbon  $start  Inclusive lower bound.
     * @param  Carbon  $end    Exclusive upper bound.
     * @return Collection
     */
    public function reviewsForPeriod(int $userId, string $language, Carbon $start, Carbon $end): Collection
    {
        return $this->senseReviewQueryService
            ->nonResetSenseReviewLogQuery($userId, $language, $start)
            ->where('review_logs.reviewed_at', '<', $end)
            ->select([
                'review_logs.id',
                'review_logs.review_card_id',
                'review_logs.rating',
                'review_logs.reviewed_at',
                'review_cards.target_id as word_sense_id',
                'word_senses.lemma',
                'word_senses.sense_zh',
            ])
            ->orderByDesc('review_logs.reviewed_at')
            ->orderByDesc('review_logs.id')
            ->get();
    }

    /**
     * Return sense ids (review_cards.target_id) that had at least one
     * non-reset sense review BEFORE the given datetime.
     *
     * Used by DailyReport to distinguish first-review (new today) from
     * review-again (returning) senses.
     *
     * @param  int     $userId
     * @param  string  $language
     * @param  Carbon  $before  Exclusive upper bound.
     * @return array<int>  Distinct sense ids.
     */
    public function sensesReviewedBefore(int $userId, string $language, Carbon $before): array
    {
        $epoch = Carbon::create(1900, 1, 1, 0, 0, 0);

        return $this->senseReviewQueryService
            ->nonResetSenseReviewLogQuery($userId, $language, $epoch)
            ->where('review_logs.reviewed_at', '<', $before)
            ->pluck('review_cards.target_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Fetch non-reset ReviewLog rows for the given card ids, newest-first.
     *
     * Card-scoped path: user/language isolation is inherent (a ReviewCard
     * belongs to exactly one user/language). Reset exclusion is delegated
     * to SenseReviewQueryService::nonResetCardReviewLogQuery.
     *
     * Query count: exactly 1 ReviewLog query regardless of how many card
     * ids are passed (0 when the list is empty).
     *
     * @param  array<int>  $cardIds
     * @return Collection  Each row: id, review_card_id, rating, reviewed_at.
     */
    public function reviewsForCards(array $cardIds): Collection
    {
        $ids = [];
        foreach ($cardIds as $id) {
            $id = (int) $id;
            if ($id > 0 && !isset($ids[$id])) {
                $ids[$id] = $id;
            }
        }

        if (empty($ids)) {
            return collect();
        }

        return $this->senseReviewQueryService
            ->nonResetCardReviewLogQuery(array_values($ids))
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->get(['id', 'review_card_id', 'rating', 'reviewed_at']);
    }
}
