<?php

namespace App\Services;

use App\Models\ReviewCard;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The single canonical entry point for review queue ordering.
 *
 * Responsibilities:
 *   1. Read card fields (no per-card DB queries).
 *   2. Classify cards into intraday / interday / review / new.
 *   3. Compute or fall back to retrievability (FSRS-5 formula).
 *   4. Generate stable daily hash for due_random / random sort modes.
 *   5. Delegate to ReviewQueueOrderPolicy for final ordering.
 *
 * Does NOT:
 *   - Query DB per card (only reads already-loaded card fields)
 *   - Modify ReviewCard
 *   - Write ReviewLog
 *   - Change FSRS due_at
 *   - Read Auth/Request/Session (caller passes userId, language, timezone, now)
 *   - Judge lifecycle eligibility (caller pre-filters)
 */
class ReviewQueueOrderService
{
    // FSRS-5 retrievability constants (official formula)
    private const FACTOR = 19.0 / 81.0;
    private const DECAY = -0.5;

    private ReviewQueueOrderPolicy $policy;

    public function __construct(ReviewQueueOrderPolicy $policy = null)
    {
        $this->policy = $policy ?? new ReviewQueueOrderPolicy();
    }

    /**
     * Order a collection of due ReviewCards according to the given options.
     *
     * @param Collection<int, ReviewCard> $cards  Already filtered (eligible + due)
     * @param int $userId
     * @param string $language
     * @param string $timezone  IANA timezone (e.g. 'UTC', 'America/Los_Angeles')
     * @param Carbon $now  Current time (will be converted to $timezone)
     * @param ReviewQueueOrderOptions $options
     * @return Collection<int, ReviewCard>  Ordered cards
     */
    public function order(
        Collection $cards,
        int $userId,
        string $language,
        string $timezone,
        Carbon $now,
        ReviewQueueOrderOptions $options
    ): Collection {
        if ($cards->isEmpty()) {
            return $cards;
        }

        $nowInTz = $now->copy()->tz($timezone);
        $localDate = $nowInTz->format('Y-m-d');

        // Build queue items with category and sort_key
        $items = [];
        foreach ($cards as $card) {
            $category = $this->classify($card, $timezone, $nowInTz);
            $sortKey = $this->computeSortKey($card, $category, $options, $userId, $language, $localDate, $nowInTz);
            $items[] = [
                'category' => $category,
                'sort_key' => $sortKey,
                'card_id' => $card->id,
                'card' => $card,
            ];
        }

        // Delegate to policy
        $ordered = $this->policy->order($items, $options);

        // Extract cards in order
        return collect(array_map(fn ($item) => $item['card'], $ordered));
    }

    /**
     * Classify a card into intraday / interday / review / new.
     *
     * intraday: fsrs_state is learning/relearning, and last_reviewed_at & due_at
     *           fall on the same local date (in user timezone).
     * interday: fsrs_state is learning/relearning, but cross local date.
     * review:   fsrs_state is review.
     * new:      fsrs_state is new.
     */
    public function classify(ReviewCard $card, string $timezone, Carbon $nowInTz): string
    {
        $state = $card->fsrs_state;

        if ($state === 'new') {
            return 'new';
        }

        if ($state === 'review') {
            return 'review';
        }

        // learning or relearning — check intraday vs interday
        if ($state !== 'learning' && $state !== 'relearning') {
            // Unknown state — treat as review (conservative fallback)
            return 'review';
        }

        if ($card->fsrs_last_reviewed_at === null) {
            // No last reviewed_at — treat as interday (conservative)
            return 'interday';
        }

        $lastReviewedInTz = $card->fsrs_last_reviewed_at->copy()->tz($timezone);
        $dueInTz = $card->fsrs_due_at ? $card->fsrs_due_at->copy()->tz($timezone) : $nowInTz;

        if ($lastReviewedInTz->format('Y-m-d') === $dueInTz->format('Y-m-d')) {
            return 'intraday';
        }

        return 'interday';
    }

    /**
     * Compute the sort key for a card within its category.
     *
     * Lower sort_key = earlier in the queue.
     */
    public function computeSortKey(
        ReviewCard $card,
        string $category,
        ReviewQueueOrderOptions $options,
        int $userId,
        string $language,
        string $localDate,
        Carbon $nowInTz
    ): float {
        // intraday and interday always sort by due_at ASC
        if ($category === 'intraday' || $category === 'interday') {
            return $this->dueAtTimestamp($card);
        }

        if ($category === 'new') {
            return $this->computeNewSortKey($card, $options, $userId, $language, $localDate);
        }

        // review
        return $this->computeReviewSortKey($card, $options, $userId, $language, $localDate, $nowInTz);
    }

    /**
     * Compute retrievability using FSRS-5 official formula.
     *
     * R = (1 + FACTOR * elapsed / stability) ^ DECAY
     *
     * Fallback:
     *   - stability <= 0 or null: use due_at timestamp (earlier due = lower R proxy)
     *   - last_reviewed_at null: elapsed = 0, R = 1.0 (highest, lowest priority)
     *
     * @return float Retrievability in [0, 1]. Lower = more forgotten = higher priority.
     */
    public function computeRetrievability(ReviewCard $card, Carbon $now): float
    {
        $stability = $card->fsrs_stability;

        if ($stability === null || $stability <= 0) {
            // Fallback: use due_at as proxy (earlier due = lower retrievability)
            // Return negative timestamp so earlier cards sort first
            return 0.0; // Conservative: treat as fully forgotten
        }

        $elapsedDays = 0.0;
        if ($card->fsrs_last_reviewed_at !== null) {
            $elapsedSeconds = $card->fsrs_last_reviewed_at->diffInSeconds($now);
            $elapsedDays = max(0, $elapsedSeconds / 86400.0);
        }

        // R = (1 + FACTOR * elapsed / stability) ^ DECAY
        $inner = 1.0 + self::FACTOR * ($elapsedDays / $stability);
        if ($inner <= 0) {
            return 0.0;
        }

        return (float) pow($inner, self::DECAY);
    }

    /**
     * Generate a stable daily hash for a card.
     *
     * Same user + language + local date + card_id always produces the same value.
     * Different dates may produce different values.
     *
     * @return float Value in [0, 1)
     */
    public function dailyHash(int $userId, string $language, string $localDate, int $cardId): float
    {
        $key = implode('|', [$userId, $language, $localDate, $cardId]);
        $hash = md5($key);
        $int = hexdec(substr($hash, 0, 8));

        return $int / 4294967295.0; // 0xFFFFFFFF
    }

    private function computeReviewSortKey(
        ReviewCard $card,
        ReviewQueueOrderOptions $options,
        int $userId,
        string $language,
        string $localDate,
        Carbon $nowInTz
    ): float {
        switch ($options->reviewSortOrder) {
            case ReviewQueueOrderOptions::REVIEW_SORT_DUE_STABLE:
                return $this->dueAtTimestamp($card);

            case ReviewQueueOrderOptions::REVIEW_SORT_ASCENDING_RETRIEVABILITY:
                $r = $this->computeRetrievability($card, $nowInTz);
                // Lower R = higher priority = lower sort_key
                return $r;

            case ReviewQueueOrderOptions::REVIEW_SORT_RANDOM:
                return $this->dailyHash($userId, $language, $localDate, $card->id);

            case ReviewQueueOrderOptions::REVIEW_SORT_DUE_RANDOM:
            default:
                // Primary: due_at date (earlier date first)
                // Secondary: daily hash within same date
                $dueDate = $card->fsrs_due_at
                    ? $card->fsrs_due_at->format('Y-m-d')
                    : '1970-01-01';
                $dateScore = strtotime($dueDate);
                $hash = $this->dailyHash($userId, $language, $localDate, $card->id);
                // Combine: date as integer part + hash as fractional part
                return (float) $dateScore + $hash;
        }
    }

    private function computeNewSortKey(
        ReviewCard $card,
        ReviewQueueOrderOptions $options,
        int $userId,
        string $language,
        string $localDate
    ): float {
        switch ($options->newSortOrder) {
            case ReviewQueueOrderOptions::NEW_SORT_CREATED_DESC:
                // Use negative id so higher id = lower sort_key (earlier in queue)
                return (float) -$card->id;

            case ReviewQueueOrderOptions::NEW_SORT_RANDOM:
                return $this->dailyHash($userId, $language, $localDate, $card->id);

            case ReviewQueueOrderOptions::NEW_SORT_CREATED_ASC:
            default:
                return (float) $card->id;
        }
    }

    private function dueAtTimestamp(ReviewCard $card): float
    {
        return $card->fsrs_due_at
            ? (float) $card->fsrs_due_at->timestamp
            : 0.0;
    }
}
