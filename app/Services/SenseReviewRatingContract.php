<?php

namespace App\Services;

/**
 * SenseReviewRatingContract
 *
 * SenseReview-RatingContract-1000-1
 *
 * Single source of truth for sense-review rating metadata: the allowed
 * rating values, their Chinese display labels, and their numeric scores.
 *
 * This is a pure value object. It never accesses the database, Auth,
 * config, or any mutable state. It contains no product report copy and
 * no auto-correction — invalid ratings fail closed (return null / false)
 * and are never silently coerced to 'good'.
 *
 * Allowed ratings (stable order):
 *   again → label 忘了, score 1
 *   hard  → label 勉强记得, score 2
 *   good  → label 记得, score 3
 *   easy  → label 很熟, score 4
 *
 * 'reset' is intentionally NOT an allowed rating here — reset logs are
 * excluded from all report aggregates before they reach this contract.
 *
 * Boundary:
 *  - Reads: nothing.
 *  - Writes: nothing.
 *  - Dependencies: none.
 */
class SenseReviewRatingContract
{
    /**
     * Allowed rating values in stable display order.
     */
    private const ALLOWED_RATINGS = [
        'again',
        'hard',
        'good',
        'easy',
    ];

    /**
     * Rating value → Chinese display label.
     * Kept in sync with the frontend score-button labels (SenseReview.vue).
     */
    private const LABELS = [
        'again' => '忘了',
        'hard'  => '勉强记得',
        'good'  => '记得',
        'easy'  => '很熟',
    ];

    /**
     * Rating value → numeric score for average-rating computation.
     * again=1 (worst), easy=4 (best).
     */
    private const SCORES = [
        'again' => 1,
        'hard'  => 2,
        'good'  => 3,
        'easy'  => 4,
    ];

    /**
     * Return the allowed rating values in stable order.
     *
     * @return list<string>
     */
    public function allowedRatings(): array
    {
        return self::ALLOWED_RATINGS;
    }

    /**
     * Whether the given rating is one of the four allowed values.
     *
     * Strict comparison only — 'Again', 'GOOD', null, '' are NOT allowed.
     *
     * @param  mixed  $rating
     */
    public function isAllowed($rating): bool
    {
        return is_string($rating) && isset(self::LABELS[$rating]);
    }

    /**
     * Chinese display label for a rating, or null when invalid.
     *
     * Fail-closed: invalid ratings return null, never 'good' / '记得'.
     *
     * @param  mixed  $rating
     */
    public function labelFor($rating): ?string
    {
        return self::LABELS[$rating] ?? null;
    }

    /**
     * Numeric score for a rating, or null when invalid.
     *
     * Fail-closed: invalid ratings return null, never 3.
     *
     * @param  mixed  $rating
     */
    public function scoreFor($rating): ?int
    {
        return self::SCORES[$rating] ?? null;
    }
}
