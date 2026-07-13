<?php

namespace App\Services;

/**
 * Pure queue ordering policy.
 *
 * Input: pre-categorized queue items with sort keys + validated Options.
 * Output: stable ordered array.
 *
 * Does NOT: query DB, read Settings, read Auth, judge lifecycle eligibility,
 * write any DB records, or compute FSRS.
 *
 * Categories (assigned by ReviewQueueOrderService, not this policy):
 *   - intraday: learning/relearning cards due today (same local date as last_reviewed_at)
 *   - interday: learning/relearning cards due today (cross local date)
 *   - review:   review cards due now
 *   - new:      new cards due now
 *
 * Ordering rules (ADR-0015):
 *   1. intraday always first (Anki: same-day learning steps take priority)
 *   2. interday + review combined per interday_learning_review_order
 *   3. new + (interday+review) combined per new_review_order
 *   4. intra-category order preserved (each item has a pre-computed sort_key)
 *   5. mix is deterministic uniform interleaving (NOT random shuffle)
 */
class ReviewQueueOrderPolicy
{
    /**
     * Order queue items according to the given options.
     *
     * @param array $items Each item must have:
     *   - 'category': string ('intraday' | 'interday' | 'review' | 'new')
     *   - 'sort_key': float (pre-computed; lower = earlier within category)
     *   - 'card_id': int (for stable tie-breaking, not used by mix)
     *   - 'card': mixed (the actual card object, passed through untouched)
     * @param ReviewQueueOrderOptions $options
     * @return array Ordered items (same structure, new order)
     */
    public function order(array $items, ReviewQueueOrderOptions $options): array
    {
        if (empty($items)) {
            return [];
        }

        // 1. Split by category
        $intraday = [];
        $interday = [];
        $review = [];
        $new = [];
        foreach ($items as $item) {
            switch ($item['category']) {
                case 'intraday':
                    $intraday[] = $item;
                    break;
                case 'interday':
                    $interday[] = $item;
                    break;
                case 'review':
                    $review[] = $item;
                    break;
                case 'new':
                    $new[] = $item;
                    break;
            }
        }

        // 2. Sort each category by sort_key (stable for equal keys via card_id tie-breaker)
        $this->sortStable($intraday);
        $this->sortStable($interday);
        $this->sortStable($review);
        $this->sortStable($new);

        // 3. Combine interday + review per interday_learning_review_order
        $nonNew = match ($options->interdayLearningReviewOrder) {
            ReviewQueueOrderOptions::INTERDAY_BEFORE => array_merge($interday, $review),
            ReviewQueueOrderOptions::INTERDAY_AFTER => array_merge($review, $interday),
            ReviewQueueOrderOptions::INTERDAY_MIX => $this->mix($interday, $review),
            default => array_merge($interday, $review),
        };

        // 4. Combine non-new + new per new_review_order
        $nonIntraday = match ($options->newReviewOrder) {
            ReviewQueueOrderOptions::NEW_BEFORE => array_merge($new, $nonNew),
            ReviewQueueOrderOptions::NEW_AFTER => array_merge($nonNew, $new),
            ReviewQueueOrderOptions::NEW_MIX => $this->mix($nonNew, $new),
            default => array_merge($nonNew, $new),
        };

        // 5. Prepend intraday
        return array_merge($intraday, $nonIntraday);
    }

    /**
     * Deterministic uniform interleaving.
     *
     * Given two ordered sequences A (main) and B (to interleave), distributes B
     * evenly across A. Preserves internal order of both A and B.
     * Deterministic: same input always produces same output.
     *
     * @param array $a Main sequence
     * @param array $b Sequence to interleave
     * @return array Interleaved result
     */
    public function mix(array $a, array $b): array
    {
        $aCount = count($a);
        $bCount = count($b);
        $total = $aCount + $bCount;

        if ($total === 0) {
            return [];
        }
        if ($aCount === 0) {
            return $b;
        }
        if ($bCount === 0) {
            return $a;
        }

        $result = [];
        $ai = 0;
        $bi = 0;

        for ($i = 0; $i < $total; $i++) {
            // Determine if position i should hold a B element
            // Using round() for more even distribution (floor puts B too late)
            $bTarget = (int) round(($i + 1) * $bCount / $total);
            $bAlready = $bi;
            if ($bi < $bTarget && $bi < $bCount) {
                $result[] = $b[$bi++];
            } elseif ($ai < $aCount) {
                $result[] = $a[$ai++];
            } else {
                // A exhausted, place remaining B
                $result[] = $b[$bi++];
            }
        }

        return $result;
    }

    /**
     * Stable sort by sort_key, then by card_id as tie-breaker.
     */
    private function sortStable(array &$items): void
    {
        usort($items, function (array $a, array $b): int {
            $cmp = $a['sort_key'] <=> $b['sort_key'];
            if ($cmp !== 0) {
                return $cmp;
            }
            return ($a['card_id'] ?? 0) <=> ($b['card_id'] ?? 0);
        });
    }
}
