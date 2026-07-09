<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * SenseReviewLearningFeedbackService
 *
 * SenseReview-FeedbackService-1000-1
 *
 * Single source of truth for the read-only learning feedback aggregate
 * shown on the SenseReview page. Extracted from SenseReviewCardSerializerService
 * so the serializer no longer directly queries ReviewLog.
 *
 * Responsibilities:
 *  - Aggregate non-reset ReviewLog rows for one or many review cards.
 *  - Compute total / again / hard / good / easy counts.
 *  - Build the latest-5 recent_reviews list (newest first).
 *  - Compute forgetting_pattern: forget_rate, last_forget_date, trend.
 *  - Map ratings to Chinese labels (RATING_LABELS).
 *
 * Invariants:
 *  - READ-ONLY: never writes ReviewLog, never touches any FSRS field.
 *  - Multi-user isolation via review_card_id scoping (a card belongs to
 *    exactly one user).
 *  - Reset-type logs (rating='reset' OR source='reset') are excluded via
 *    SenseReviewAnalyticsQueryService::reviewsForCards(), which delegates
 *    to SenseReviewQueryService::nonResetCardReviewLogQuery().
 *  - buildForCard() and buildForCards() share ONE algorithm via
 *    buildFeedbackFromLogs() — no duplicated aggregation logic.
 *  - Rating counting delegates to
 *    SenseReviewAnalyticsQueryService::ratingDistribution() so the
 *    counting logic is shared with TodaySummary / DailyReport.
 */
class SenseReviewLearningFeedbackService
{
    /**
     * Rating value → Chinese label shown in the learning feedback block.
     * Keep in sync with the frontend score-button labels (SenseReview.vue).
     * 'reset' is intentionally absent — reset logs are excluded from the
     * feedback aggregate, so they never need a label here.
     */
    public const RATING_LABELS = [
        'again' => '忘了',
        'hard'  => '勉强',
        'good'  => '记得',
        'easy'  => '很熟',
    ];

    public function __construct(
        private SenseReviewAnalyticsQueryService $analytics,
    ) {
    }

    /**
     * Build the read-only learning feedback aggregate for one review card.
     *
     * Delegates to buildForCards() so the aggregation algorithm lives in
     * exactly one place (single source of truth). This keeps the per-card
     * path backward-compatible while eliminating the old 7-query-per-card
     * pattern: a single-card call now issues exactly 1 ReviewLog query.
     *
     * Shape:
     *   total_reviews:        int   — count of non-reset logs for this card
     *   forget_count:         int   — count where rating='again'
     *   hard_count:           int   — count where rating='hard'
     *   good_count:           int   — count where rating='good'
     *   easy_count:           int   — count where rating='easy'
     *   recent_reviews:       list  — latest 5 non-reset logs, newest first;
     *                                 each {rating, rating_label, date(Y-m-d)}
     *   recent_forget_count:  int   — count of 'again' among recent_reviews
     *   forgetting_pattern:   array — {total_forget, recent_forget_count,
     *                                 forget_rate, last_forget_date, trend}
     *
     * @param  int  $reviewCardId  The card whose logs to aggregate.
     * @return array{total_reviews: int, forget_count: int, hard_count: int,
     *               good_count: int, easy_count: int, recent_reviews: list,
     *               recent_forget_count: int, forgetting_pattern: array}
     */
    public function buildForCard(int $reviewCardId): array
    {
        $map = $this->buildForCards([$reviewCardId]);

        return $map[$reviewCardId] ?? $this->emptyFeedback();
    }

    /**
     * Build the read-only learning feedback aggregate for many review cards
     * in a SINGLE batch ReviewLog query, eliminating the per-card N+1 that
     * occurred when serializing the review queue.
     *
     * SenseReview-BatchFeedback-1000-1
     *
     * One query loads all non-reset ReviewLog rows for the target cards
     * (newest-first). The rows are then grouped by review_card_id in memory
     * and each card's feedback is computed by the shared
     * buildFeedbackFromLogs() helper — the same algorithm used by
     * buildForCard(). Cards with no logs receive the stable empty structure.
     *
     * Query count: exactly 1 ReviewLog query regardless of how many card
     * ids are passed (0 when the list is empty).
     *
     * @param  array<int>  $reviewCardIds  Card ids to aggregate. Duplicates
     *                                      are de-duplicated; each id appears
     *                                      exactly once in the result map.
     * @return array<int, array>  Map of review_card_id => feedback payload.
     *                            Empty array when $reviewCardIds is empty.
     */
    public function buildForCards(array $reviewCardIds): array
    {
        // Normalize + de-duplicate ids (preserve int keys in the result map).
        $ids = [];
        foreach ($reviewCardIds as $id) {
            $id = (int) $id;
            if ($id > 0 && !isset($ids[$id])) {
                $ids[$id] = $id;
            }
        }

        if (empty($ids)) {
            return [];
        }

        // Single batch query via the centralized analytics layer. Reset
        // exclusion is delegated to
        // SenseReviewQueryService::nonResetCardReviewLogQuery(). Exactly 1
        // ReviewLog query regardless of how many card ids are passed.
        $logs = $this->analytics->reviewsForCards(array_values($ids));

        // Group by card id; groupBy preserves the within-group order from
        // the query (newest-first), which is what buildFeedbackFromLogs
        // expects.
        $logsByCard = $logs->groupBy('review_card_id');

        $result = [];
        foreach ($ids as $cardId) {
            $cardLogs = $logsByCard->get($cardId, collect());
            $result[$cardId] = $this->buildFeedbackFromLogs($cardLogs);
        }

        return $result;
    }

    /**
     * Shared aggregation algorithm used by both buildForCard() and
     * buildForCards(). This is the SINGLE source of truth for the feedback
     * payload shape, rating counts, recent_reviews slicing, forget rate,
     * last_forget_date, and trend computation.
     *
     * @param  Collection  $logs  Non-reset logs for one card, ordered
     *                            newest-first (reviewed_at desc, id desc).
     * @return array{total_reviews: int, forget_count: int, hard_count: int,
     *               good_count: int, easy_count: int, recent_reviews: list,
     *               recent_forget_count: int, forgetting_pattern: array}
     */
    private function buildFeedbackFromLogs(Collection $logs): array
    {
        $total = $logs->count();

        // Rating counting delegates to the centralized analytics layer so
        // the again/hard/good/easy counting logic is shared with
        // TodaySummary and DailyReport.
        $dist = $this->analytics->ratingDistribution($logs);
        $forgetCount = $dist['again'];
        $hardCount = $dist['hard'];
        $goodCount = $dist['good'];
        $easyCount = $dist['easy'];

        // recent_reviews: latest 5, newest-first (logs are already ordered).
        $recent = $logs->take(5);
        $recentReviews = [];
        $recentForgetCount = 0;
        foreach ($recent as $log) {
            $rating = $log->rating;
            if ($rating === 'again') {
                $recentForgetCount++;
            }
            $recentReviews[] = [
                'rating' => $rating,
                'rating_label' => self::RATING_LABELS[$rating] ?? $rating,
                'date' => $log->reviewed_at?->format('Y-m-d'),
            ];
        }

        // forget_rate = again_count / total_reviews (0.0 when no reviews).
        $forgetRate = $total > 0 ? round($forgetCount / $total, 4) : 0.0;

        // Most recent 'again' log date (logs are newest-first, so the first
        // 'again' row is the last forget date).
        $lastForgetDate = null;
        foreach ($logs as $log) {
            if ($log->rating === 'again') {
                $lastForgetDate = $log->reviewed_at?->format('Y-m-d');
                break;
            }
        }

        // Trend: take the latest 6 non-reset logs (newest-first), reverse to
        // old→new, then split into early/late halves and compare 'again'
        // counts. <4 logs → 'insufficient' (not enough to compare halves).
        $trendLogs = $logs->take(6)->reverse()->values();
        $trend = $this->computeForgettingTrend($trendLogs);

        return [
            'total_reviews' => $total,
            'forget_count' => $forgetCount,
            'hard_count' => $hardCount,
            'good_count' => $goodCount,
            'easy_count' => $easyCount,
            'recent_reviews' => $recentReviews,
            'recent_forget_count' => $recentForgetCount,
            'forgetting_pattern' => [
                'total_forget' => $forgetCount,
                'recent_forget_count' => $recentForgetCount,
                'forget_rate' => $forgetRate,
                'last_forget_date' => $lastForgetDate,
                'trend' => $trend,
            ],
        ];
    }

    /**
     * Stable empty feedback structure returned when a card has no non-reset
     * ReviewLog rows. Matches the shape produced by buildFeedbackFromLogs()
     * with an empty collection.
     */
    private function emptyFeedback(): array
    {
        return [
            'total_reviews' => 0,
            'forget_count' => 0,
            'hard_count' => 0,
            'good_count' => 0,
            'easy_count' => 0,
            'recent_reviews' => [],
            'recent_forget_count' => 0,
            'forgetting_pattern' => [
                'total_forget' => 0,
                'recent_forget_count' => 0,
                'forget_rate' => 0.0,
                'last_forget_date' => null,
                'trend' => 'insufficient',
            ],
        ];
    }

    /**
     * Compute the forgetting trend from a collection of recent non-reset
     * ReviewLog ratings ordered old→new.
     *
     * The collection is split into two halves:
     *   - early half: the older reviews (first floor(n/2) items)
     *   - late half:  the newer reviews (remaining items)
     * 'again' counts in each half are compared:
     *   - late < early → 'improving' (forgetting less over time)
     *   - late > early → 'declining' (forgetting more over time)
     *   - equal        → 'stable'
     *
     * When there are fewer than 4 logs the trend is 'insufficient' because
     * the two halves would be too small to compare meaningfully. This is a
     * factual comparison only — no AI, no guessing causes.
     *
     * @param  \Illuminate\Support\Collection  $logs  Ratings ordered old→new.
     * @return string  'improving' | 'declining' | 'stable' | 'insufficient'
     */
    public function computeForgettingTrend($logs): string
    {
        $n = $logs->count();
        if ($n < 4) {
            return 'insufficient';
        }

        $half = intdiv($n, 2);
        $earlyForget = $logs->slice(0, $half)->where('rating', 'again')->count();
        $lateForget = $logs->slice($half)->where('rating', 'again')->count();

        if ($lateForget < $earlyForget) {
            return 'improving';
        }
        if ($lateForget > $earlyForget) {
            return 'declining';
        }
        return 'stable';
    }
}
