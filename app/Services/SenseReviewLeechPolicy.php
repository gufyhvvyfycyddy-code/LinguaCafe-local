<?php

namespace App\Services;

use App\Models\ReviewCard;
use Illuminate\Support\Carbon;

/**
 * SenseReviewLeechPolicy
 *
 * ADR-0011
 *
 * Pure, side-effect-free classification policy for sense review card
 * "leech" governance. Given a ReviewCard, its learning feedback descriptor,
 * and a lifecycle descriptor, this policy determines:
 *
 *   - status:           stable | struggling | leech
 *   - severity:         0-100 (how badly the card is struggling)
 *   - reasons[]:        machine-readable reason codes
 *   - suggestions[]:    machine-readable suggested actions
 *   - blocked_actions[]: suggestions that are blocked by lifecycle state
 *
 * Hard rules (ADR-0011 Section 4):
 *  - Does NOT query the database.
 *  - Does NOT write the database.
 *  - Does NOT call AI.
 *  - Does NOT call lifecycle mutation.
 *  - Does NOT modify FSRS.
 *  - Does NOT read Request / Auth / session.
 *
 * The policy is a pure function of its inputs. All data aggregation
 * (ReviewLog queries, feedback building) is done by the caller — typically
 * SenseReviewLeechQueryService.
 *
 * Classification thresholds (ADR-0011 Section 3):
 *
 *   leech:
 *     - again_count >= 3 AND total_reviews >= 5
 *     - OR last 7 reviews: (again + hard) >= 4
 *
 *   struggling:
 *     - last 5 reviews: (again + hard) >= 3
 *     - OR fsrs_lapses >= 2 AND forgetting_pattern.trend = 'declining'
 *
 *   stable:
 *     - all other cards (including new cards with < 3 reviews)
 */
class SenseReviewLeechPolicy
{
    public const STATUS_STABLE = 'stable';
    public const STATUS_STRUGGLING = 'struggling';
    public const STATUS_LEECH = 'leech';

    public const REASON_RECENT_AGAIN_HIGH = 'recent_again_count_high';
    public const REASON_RECENT_HARD_HIGH = 'recent_hard_count_high';
    public const REASON_LAPSES_HIGH = 'lapses_high';
    public const REASON_STABILITY_DECLINING = 'stability_declining';
    public const REASON_LOW_SUCCESS_AFTER_MULTIPLE_REVIEWS = 'low_success_after_multiple_reviews';

    public const SUGGESTION_CONTINUE_REVIEW = 'continue_review';
    public const SUGGESTION_REWRITE_EXAMPLE = 'rewrite_example';
    public const SUGGESTION_EDIT_SENSE = 'edit_sense';
    public const SUGGESTION_SUSPEND_TEMPORARILY = 'suspend_temporarily';
    public const SUGGESTION_VIEW_HISTORY = 'view_history';

    /**
     * Classify a sense review card's leech status.
     *
     * @param  ReviewCard  $card               The card (must be target_type='sense').
     * @param  array       $feedback           Learning feedback descriptor from
     *                                         SenseReviewLearningFeedbackService.
     * @param  array       $lifecycleDescriptor Lifecycle descriptor from
     *                                          ReviewCardLifecyclePolicy::describe().
     * @param  Carbon|null $now                Current time (injectable for tests).
     * @return array{
     *     status: string,
     *     severity: int,
     *     reasons: list<string>,
     *     suggestions: list<string>,
     *     blocked_actions: list<string>,
     * }
     */
    public function classify(
        ReviewCard $card,
        array $feedback,
        array $lifecycleDescriptor,
        ?Carbon $now = null
    ): array {
        $now = $now ?? Carbon::now();

        // Extract metrics from the feedback descriptor.
        $totalReviews = $feedback['total_reviews'] ?? 0;
        $againCount = $feedback['forget_count'] ?? 0;
        $hardCount = $feedback['hard_count'] ?? 0;
        $recentReviews = $feedback['recent_reviews'] ?? [];
        $trend = $feedback['forgetting_pattern']['trend'] ?? 'insufficient';
        $fsrsLapses = (int) ($card->fsrs_lapses ?? 0);

        // Compute recent-window metrics.
        $last5 = array_slice($recentReviews, 0, 5);
        $last7 = array_slice($recentReviews, 0, 7);
        $last5AgainHard = $this->countAgainHard($last5);
        $last7AgainHard = $this->countAgainHard($last7);

        // Classify.
        $reasons = [];
        $status = self::STATUS_STABLE;

        // LEECH: again_count >= 3 AND total_reviews >= 5
        $isLeechByAgainCount = ($againCount >= 3 && $totalReviews >= 5);
        // LEECH: last 7 reviews: (again + hard) >= 4
        $isLeechByRecent = ($last7AgainHard >= 4 && count($last7) >= 4);

        if ($isLeechByAgainCount || $isLeechByRecent) {
            $status = self::STATUS_LEECH;
        }

        // STRUGGLING: last 5 reviews: (again + hard) >= 3
        $isStrugglingByRecent = ($last5AgainHard >= 3);
        // STRUGGLING: fsrs_lapses >= 2 AND trend = 'declining'
        $isStrugglingByLapses = ($fsrsLapses >= 2 && $trend === 'declining');

        if ($status !== self::STATUS_LEECH && ($isStrugglingByRecent || $isStrugglingByLapses)) {
            $status = self::STATUS_STRUGGLING;
        }

        // Build reasons based on the signals that triggered.
        if ($status !== self::STATUS_STABLE) {
            if ($againCount >= 3) {
                $reasons[] = self::REASON_RECENT_AGAIN_HIGH;
            }
            if ($hardCount >= 3) {
                $reasons[] = self::REASON_RECENT_HARD_HIGH;
            }
            if ($fsrsLapses >= 2) {
                $reasons[] = self::REASON_LAPSES_HIGH;
            }
            if ($trend === 'declining') {
                $reasons[] = self::REASON_STABILITY_DECLINING;
            }
            // Low success after multiple reviews: total >= 5 and success rate < 40%
            if ($totalReviews >= 5) {
                $successCount = ($feedback['good_count'] ?? 0) + ($feedback['easy_count'] ?? 0);
                if ($totalReviews > 0 && ($successCount / $totalReviews) < 0.4) {
                    $reasons[] = self::REASON_LOW_SUCCESS_AFTER_MULTIPLE_REVIEWS;
                }
            }
        }

        // Compute severity (0-100).
        $severity = $this->computeSeverity(
            $status,
            $againCount,
            $hardCount,
            $totalReviews,
            $fsrsLapses,
            $trend,
            $last5AgainHard
        );

        // Build suggestions.
        $suggestions = $this->buildSuggestions($status, $totalReviews);

        // Determine blocked actions based on lifecycle state.
        $effectiveState = $lifecycleDescriptor['effective_state'] ?? 'active';
        $blockedActions = $this->blockedActionsForState($effectiveState, $suggestions);

        return [
            'status' => $status,
            'severity' => $severity,
            'reasons' => array_values(array_unique($reasons)),
            'suggestions' => $suggestions,
            'blocked_actions' => $blockedActions,
        ];
    }

    /**
     * Count 'again' and 'hard' ratings in a recent_reviews slice.
     *
     * @param  array  $reviews  Each item has a 'rating' key.
     * @return int
     */
    private function countAgainHard(array $reviews): int
    {
        $count = 0;
        foreach ($reviews as $review) {
            $rating = $review['rating'] ?? '';
            if ($rating === 'again' || $rating === 'hard') {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Compute a 0-100 severity score.
     *
     * Weighted factors:
     *  - again count (30%)
     *  - recent again+hard density (25%)
     *  - fsrs_lapses (20%)
     *  - declining trend (15%)
     *  - low success rate (10%)
     *
     * @return int 0-100
     */
    private function computeSeverity(
        string $status,
        int $againCount,
        int $hardCount,
        int $totalReviews,
        int $fsrsLapses,
        string $trend,
        int $last5AgainHard
    ): int {
        if ($status === self::STATUS_STABLE) {
            return 0;
        }

        // Again factor: again_count / max(5, total) * 30
        $againFactor = $totalReviews > 0
            ? min(1.0, $againCount / max(5, $totalReviews)) * 30
            : 0;

        // Recent density: last5AgainHard / 5 * 25
        $recentFactor = min(1.0, $last5AgainHard / 5) * 25;

        // Lapses factor: lapses / 5 * 20
        $lapsesFactor = min(1.0, $fsrsLapses / 5) * 20;

        // Trend factor: declining = 15, stable = 5, improving = 0, insufficient = 0
        $trendFactor = $trend === 'declining' ? 15 : ($trend === 'stable' ? 5 : 0);

        // Low success factor: if total >= 5 and success < 40% → 10
        $lowSuccessFactor = 0;
        if ($totalReviews >= 5) {
            // We don't have good/easy counts here directly, but we can
            // approximate: if again+hard > 60% of total, it's low success.
            if ($totalReviews > 0 && (($againCount + $hardCount) / $totalReviews) > 0.6) {
                $lowSuccessFactor = 10;
            }
        }

        $raw = $againFactor + $recentFactor + $lapsesFactor + $trendFactor + $lowSuccessFactor;

        // Boost leech status by 1.15x (cap at 100), struggling stays as-is.
        if ($status === self::STATUS_LEECH) {
            $raw = $raw * 1.15;
        }

        return (int) round(min(100, max(0, $raw)));
    }

    /**
     * Build the suggestions list based on status.
     *
     * @param  string $status
     * @param  int    $totalReviews
     * @return list<string>
     */
    private function buildSuggestions(string $status, int $totalReviews): array
    {
        if ($status === self::STATUS_STABLE) {
            return [self::SUGGESTION_CONTINUE_REVIEW];
        }

        $suggestions = [self::SUGGESTION_VIEW_HISTORY];

        if ($status === self::STATUS_LEECH) {
            $suggestions[] = self::SUGGESTION_REWRITE_EXAMPLE;
            $suggestions[] = self::SUGGESTION_SUSPEND_TEMPORARILY;
            $suggestions[] = self::SUGGESTION_EDIT_SENSE;
        } elseif ($status === self::STATUS_STRUGGLING) {
            $suggestions[] = self::SUGGESTION_REWRITE_EXAMPLE;
            $suggestions[] = self::SUGGESTION_EDIT_SENSE;
        }

        return $suggestions;
    }

    /**
     * Determine which suggestions are blocked by the current lifecycle state.
     *
     * - suspended: suspend_temporarily is blocked (already suspended)
     * - archived: suspend_temporarily is blocked (archived is terminal,
     *             must restore first)
     * - buried: suspend_temporarily is blocked (must unbury first)
     *
     * @param  string $effectiveState
     * @param  array  $suggestions
     * @return list<string>
     */
    private function blockedActionsForState(string $effectiveState, array $suggestions): array
    {
        $blocked = [];

        if ($effectiveState !== 'active') {
            if (in_array(self::SUGGESTION_SUSPEND_TEMPORARILY, $suggestions)) {
                $blocked[] = self::SUGGESTION_SUSPEND_TEMPORARILY;
            }
        }

        return $blocked;
    }
}
