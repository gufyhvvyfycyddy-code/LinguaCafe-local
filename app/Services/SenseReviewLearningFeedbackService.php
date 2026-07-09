<?php

namespace App\Services;

use App\Models\ReviewLog;

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
 *  - Aggregate non-reset ReviewLog rows for a single review card.
 *  - Compute total / again / hard / good / easy counts.
 *  - Build the latest-5 recent_reviews list (newest first).
 *  - Compute forgetting_pattern: forget_rate, last_forget_date, trend.
 *  - Map ratings to Chinese labels (RATING_LABELS).
 *
 * Invariants:
 *  - READ-ONLY: never writes ReviewLog, never touches any FSRS field.
 *  - Multi-user isolation via review_card_id scoping (a card belongs to
 *    exactly one user).
 *  - Reset-type logs (rating='reset' OR source='reset') are excluded,
 *    matching SenseReviewQueryService::nonResetSenseReviewLogQuery.
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

    /**
     * Build the read-only learning feedback aggregate for one review card.
     *
     * Pulls only from the ReviewLog table; never writes. Excludes reset-type
     * logs (rating='reset' OR source='reset') so the aggregate reflects real
     * review attempts only, matching nonResetSenseReviewLogQuery.
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
        $baseQuery = ReviewLog::query()
            ->where('review_card_id', $reviewCardId)
            ->where('rating', '!=', 'reset')
            ->where('source', '!=', 'reset');

        $total = (clone $baseQuery)->count();
        $forgetCount = (clone $baseQuery)->where('rating', 'again')->count();
        $hardCount = (clone $baseQuery)->where('rating', 'hard')->count();
        $goodCount = (clone $baseQuery)->where('rating', 'good')->count();
        $easyCount = (clone $baseQuery)->where('rating', 'easy')->count();

        $recent = (clone $baseQuery)
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['rating', 'reviewed_at']);

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

        // Most recent 'again' log date (Y-m-d), null when never forgotten.
        $lastAgain = (clone $baseQuery)
            ->where('rating', 'again')
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->first();
        $lastForgetDate = $lastAgain?->reviewed_at?->format('Y-m-d');

        // Trend: take the latest 6 non-reset logs (newest first), reverse to
        // old→new, then split into early/late halves and compare 'again'
        // counts. <4 logs → 'insufficient' (not enough to compare halves).
        $trendLogs = (clone $baseQuery)
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get(['rating'])
            ->reverse()
            ->values();
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
