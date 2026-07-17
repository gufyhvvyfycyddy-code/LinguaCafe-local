<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\WordSenseOccurrence;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
 *  - Apply missing-field conditions through the canonical missing predicate owner.
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

    private const FSRS_PROPERTY_COLUMNS = [
        'lapses' => 'review_cards.fsrs_lapses',
        'reps' => 'review_cards.fsrs_reps',
        'stability' => 'review_cards.fsrs_stability',
        'difficulty' => 'review_cards.fsrs_difficulty',
    ];

    public function __construct(
        private ReviewCardMissingFieldQueryApplier $missingFieldApplier,
        private SenseReviewReportPeriodService $periodService,
    ) {
    }

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

        // 5. Recent formal-review windows. Each condition is an independent
        // existence predicate, preserving the Browser grammar's global AND semantics.
        foreach ($criteria->recentReviewConditions as $condition) {
            $days = (int) ($condition['days'] ?? 0);
            $rating = $condition['rating'] ?? null;
            if ($days < 1 || $days > SenseReviewReportPeriodService::MAX_DAYS
                || ($rating !== null && !isset(self::REVIEW_LOG_RATINGS[$rating]))) {
                $query->whereRaw('1 = 0');
                continue;
            }

            $period = $this->periodService->rollingDays(
                $days,
                config('app.timezone', 'UTC'),
            );

            $query->whereExists(function ($subQuery) use ($userId, $language, $rating, $period) {
                $subQuery->select(DB::raw(1))
                    ->from('review_logs')
                    ->whereColumn('review_logs.review_card_id', 'review_cards.id')
                    ->where('review_logs.user_id', $userId)
                    ->where('review_logs.language_id', $language)
                    ->where('review_logs.source', 'sense_review')
                    ->whereIn('review_logs.rating', array_values(self::REVIEW_LOG_RATINGS))
                    ->whereNull('review_logs.undone_at')
                    ->where('review_logs.reviewed_at', '>=', $period['start'])
                    ->where('review_logs.reviewed_at', '<', $period['end']);

                if ($rating !== null) {
                    $subQuery->where('review_logs.rating', $rating);
                }
            });
        }

        // 6. Property conditions on direct ReviewCard FSRS columns.
        foreach ($criteria->propertyConditions as $cond) {
            $column = self::FSRS_PROPERTY_COLUMNS[$cond['field']] ?? null;
            if ($column === null) {
                $query->whereRaw('1 = 0');
                continue;
            }
            $query->where($column, $cond['operator'], $cond['value']);
        }

        // 6. FSRS state conditions (state:new/learning/review/relearning)
        // Max one distinct value — AND-combined with others.
        foreach ($criteria->fsrsStates as $fsrsState) {
            $query->where('review_cards.fsrs_state', $fsrsState);
        }

        // 7. Exact calendar-day due condition.
        if ($criteria->hasDueDate()) {
            $today = Carbon::today();
            $start = match ($criteria->dueDate) {
                'yesterday' => $today->copy()->subDay(),
                'today' => $today->copy(),
                'tomorrow' => $today->copy()->addDay(),
                default => Carbon::createFromFormat('Y-m-d', $criteria->dueDate)->startOfDay(),
            };
            $query->where('review_cards.fsrs_due_at', '>=', $start)
                ->where('review_cards.fsrs_due_at', '<', $start->copy()->addDay());
        }

        // 8. Real source provenance conditions. Each target is applied as an
        // independent existence predicate, so distinct source tokens use the
        // Browser grammar's global AND semantics without duplicating cards.
        foreach ($criteria->sourceTargets as $sourceTarget) {
            $this->applySourceTarget($query, $sourceTarget, $userId, $language);
        }

        // 9. Missing-field conditions. The same predicate owner is also used
        // by the top-level missing_* filter buttons in ReviewCardManageQueryService.
        foreach ($criteria->missingFields as $missingField) {
            $this->missingFieldApplier->apply($query, $missingField, $userId, $language);
        }
    }

    /**
     * @param array{kind: string, id: int} $sourceTarget
     */
    private function applySourceTarget(
        Builder $query,
        array $sourceTarget,
        int $userId,
        string $language,
    ): void {
        $kind = $sourceTarget['kind'] ?? null;
        $sourceId = $sourceTarget['id'] ?? null;

        if (!in_array($kind, ['chapter', 'book'], true) || !is_int($sourceId) || $sourceId <= 0) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereHas('sense', function ($senseQuery) use ($kind, $sourceId, $userId, $language) {
            $senseQuery->where(function ($provenanceQuery) use ($kind, $sourceId, $userId, $language) {
                $this->applyDirectSourceExists(
                    $provenanceQuery,
                    $kind,
                    $sourceId,
                    $userId,
                    $language,
                );

                $provenanceQuery->orWhereExists(function ($occurrenceQuery) use ($kind, $sourceId, $userId, $language) {
                    $occurrenceQuery->select(DB::raw(1))
                        ->from('word_sense_occurrences as source_occurrences')
                        ->join('chapters as source_occurrence_chapters', 'source_occurrence_chapters.id', '=', 'source_occurrences.chapter_id')
                        ->whereColumn('source_occurrences.word_sense_id', 'word_senses.id')
                        ->where('source_occurrences.user_id', $userId)
                        ->where('source_occurrences.language_id', $language)
                        ->where('source_occurrences.status', WordSenseOccurrence::STATUS_BOUND)
                        ->where('source_occurrence_chapters.user_id', $userId)
                        ->where('source_occurrence_chapters.language', $language);

                    if ($kind === 'chapter') {
                        $occurrenceQuery->where('source_occurrence_chapters.id', $sourceId);
                        return;
                    }

                    $occurrenceQuery
                        ->join('books as source_occurrence_books', 'source_occurrence_books.id', '=', 'source_occurrence_chapters.book_id')
                        ->where('source_occurrence_books.id', $sourceId)
                        ->where('source_occurrence_books.user_id', $userId)
                        ->where('source_occurrence_books.language', $language);
                });
            });
        });
    }

    private function applyDirectSourceExists(
        Builder $query,
        string $kind,
        int $sourceId,
        int $userId,
        string $language,
    ): void {
        $query->whereExists(function ($directQuery) use ($kind, $sourceId, $userId, $language) {
            $directQuery->select(DB::raw(1))
                ->from('chapters as source_direct_chapters')
                ->whereColumn('source_direct_chapters.id', 'word_senses.source_chapter_id')
                ->where('source_direct_chapters.user_id', $userId)
                ->where('source_direct_chapters.language', $language);

            if ($kind === 'chapter') {
                $directQuery->where('source_direct_chapters.id', $sourceId);
                return;
            }

            $directQuery
                ->join('books as source_direct_books', 'source_direct_books.id', '=', 'source_direct_chapters.book_id')
                ->where('source_direct_books.id', $sourceId)
                ->where('source_direct_books.user_id', $userId)
                ->where('source_direct_books.language', $language);
        });
    }
}
