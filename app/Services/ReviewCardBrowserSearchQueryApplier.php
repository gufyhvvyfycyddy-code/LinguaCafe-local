<?php

namespace App\Services;

use App\Models\ReviewCard;
use Illuminate\Database\Eloquent\Builder;

/**
 * ReviewCardBrowserSearchQueryApplier
 *
 * ADR-0012: Applies a parsed ReviewCardBrowserSearchCriteria to an
 * already-security-scoped ReviewCard query builder.
 *
 * Responsibilities:
 *  - Apply plain-text LIKE search (textQuery) to word_senses fields.
 *  - Apply lifecycle condition (is:active/buried/suspended/archived).
 *  - Apply governance condition (is:leech/is:struggling) via pre-computed
 *    matching card IDs (delegated to caller to avoid duplicate classification).
 *  - Apply rated conditions (rated:again/rated:hard/rated:good/rated:easy) via whereExists.
 *  - Apply property conditions (prop:lapses<op><n>) via direct column WHERE.
 *
 * Hard rules:
 *  - Does NOT create the base query (caller does that with security scope).
 *  - Does NOT handle pagination.
 *  - Does NOT handle serialization.
 *  - Does NOT handle export.
 *  - Does NOT write to the database.
 *  - Does NOT modify FSRS / lifecycle / ReviewLog.
 *
 * Governance caching:
 *  The caller (ReviewCardManageQueryService::build) may pre-compute the
 *  leech/struggling matching IDs (e.g. because filter=leech was also set)
 *  and pass them via $governanceMatchingIds to avoid double-classification.
 *  If $governanceMatchingIds is null AND the criteria has a governance
 *  condition, the applier will call $governanceResolver to compute them.
 */
class ReviewCardBrowserSearchQueryApplier
{
    private const REVIEW_LOG_RATINGS = [
        'again' => 'again',
        'hard' => 'hard',
        'good' => 'good',
        'easy' => 'easy',
    ];

    /**
     * Apply parsed criteria to a security-scoped query.
     *
     * @param  Builder $query  Already scoped to user/language/sense-confirmed.
     * @param  ReviewCardBrowserSearchCriteria $criteria
     * @param  int      $userId
     * @param  string   $language
     * @param  array<int>|null $governanceMatchingIds  Pre-computed leech/struggling card IDs (null = not pre-computed).
     * @param  callable|null $governanceResolver  fn(string $status): array<int> — called when governance is needed and not pre-computed.
     * @return void
     */
    public function apply(
        Builder $query,
        ReviewCardBrowserSearchCriteria $criteria,
        int $userId,
        string $language,
        ?array $governanceMatchingIds = null,
        ?callable $governanceResolver = null,
    ): void {
        // 1. Plain text LIKE search (same 5 fields as existing q behavior)
        if ($criteria->hasTextQuery()) {
            $textQuery = $criteria->textQuery;
            $query->whereHas('sense', function ($subQuery) use ($textQuery) {
                $subQuery->where(function ($inner) use ($textQuery) {
                    $inner->where('lemma', 'like', "%{$textQuery}%")
                        ->orWhere('surface_form', 'like', "%{$textQuery}%")
                        ->orWhere('sense_zh', 'like', "%{$textQuery}%")
                        ->orWhere('sense_en', 'like', "%{$textQuery}%")
                        ->orWhere('example_sentence_en', 'like', "%{$textQuery}%");
                });
            });
        }

        // 2. Lifecycle condition (is:active/buried/suspended/archived)
        if ($criteria->hasLifecycleStatus()) {
            $query->where('review_cards.lifecycle_state', $criteria->lifecycleStatus);
        }

        if ($criteria->hasMarker()) {
            $query->where('review_cards.marker', $criteria->marker);
        }

        // 3. Governance condition (is:leech/is:struggling)
        if ($criteria->hasGovernanceStatus()) {
            $matchingIds = $governanceMatchingIds;
            if ($matchingIds === null && $governanceResolver !== null) {
                $matchingIds = $governanceResolver($criteria->governanceStatus);
            }
            $matchingIds = $matchingIds ?? [];
            // whereIn with empty array would produce no rows on some DBs;
            // use a always-false guard to be safe.
            if (empty($matchingIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('review_cards.id', $matchingIds);
            }
        }

        // 4. Rated conditions (rated:again / rated:hard / rated:good / rated:easy)
        // Each rating adds a whereExists subquery: the card must have at
        // least one matching ReviewLog. Multiple ratings are AND-combined
        // (card must have at least one of EACH requested rating).
        foreach ($criteria->ratings as $rating) {
            $reviewLogRating = self::REVIEW_LOG_RATINGS[$rating] ?? null;
            if ($reviewLogRating === null) {
                $query->whereRaw('1 = 0');
                continue;
            }

            $query->whereExists(function ($subQuery) use ($userId, $language, $reviewLogRating) {
                $subQuery->select(\Illuminate\Support\Facades\DB::raw(1))
                    ->from('review_logs')
                    ->whereColumn('review_logs.review_card_id', 'review_cards.id')
                    ->where('review_logs.user_id', $userId)
                    ->where('review_logs.language_id', $language)
                    ->where('review_logs.source', '=', 'sense_review')
                    ->where('review_logs.rating', $reviewLogRating)
                    ->whereNull('review_logs.undone_at');
            });
        }

        // 5. Property conditions (prop:lapses<op><n>)
        foreach ($criteria->propertyConditions as $cond) {
            if ($cond['field'] === 'lapses') {
                $query->where('review_cards.fsrs_lapses', $cond['operator'], $cond['value']);
            }
        }

        // 6. FSRS state conditions (state:new/learning/review/relearning)
        // Max one distinct value — AND-combined with others.
        foreach ($criteria->fsrsStates as $fsrsState) {
            $query->where('review_cards.fsrs_state', $fsrsState);
        }
    }
}
