<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReviewCardManageQueryService
{
    public function __construct(
        private SenseReviewLeechQueryService $leechQueryService,
        private ReviewCardBrowserSearchParser $searchParser,
        private ReviewCardBrowserSearchQueryApplier $searchApplier,
    ) {
    }

    /**
     * Whitelist of sortable columns.
     * Maps query-param keys to fully-qualified column expressions.
     * Only review_cards direct fields are supported — no word_senses join.
     */
    private const SORTABLE_COLUMNS = [
        'id'                    => 'review_cards.id',
        'fsrs_state'            => 'review_cards.fsrs_state',
        'fsrs_due_at'           => 'review_cards.fsrs_due_at',
        'fsrs_stability'        => 'review_cards.fsrs_stability',
        'fsrs_difficulty'       => 'review_cards.fsrs_difficulty',
        'fsrs_reps'             => 'review_cards.fsrs_reps',
        'fsrs_lapses'           => 'review_cards.fsrs_lapses',
        'fsrs_last_reviewed_at' => 'review_cards.fsrs_last_reviewed_at',
    ];

    /**
     * Build the shared base query with all security constraints, search, filters,
     * advanced filters, and sort applied. Used by both data() and export().
     *
     * ADR-0013: This is now a THIN WRAPPER for backward compatibility. The
     * Controller's main path is buildFromCriteria() which receives an
     * already-parsed ReviewCardBrowserSearchCriteria (parsed exactly once per
     * request). This wrapper re-parses q from the Request — it exists only for
     * callers that have not migrated to the single-parse contract.
     *
     * @throws InvalidBrowserSearchException When the search grammar is invalid.
     */
    public function build(Request $request, int $userId, string $language): \Illuminate\Database\Eloquent\Builder
    {
        $state = ReviewCardManageFilterState::fromRequest($request);
        $criteria = $this->parseCriteriaForState($state);
        return $this->buildFromFilterState($state, $criteria, $userId, $language);
    }

    /**
     * Build the shared base query from an ALREADY-PARSED criteria.
     *
     * ADR-0013: This is the single execution entry point for the browser
     * search pipeline. The Controller parses q once, catches
     * InvalidBrowserSearchException for the 422 guard, and passes the
     * resulting criteria here. This method does NOT re-read q and does NOT
     * call the parser.
     *
     * Responsibilities:
     *  - Create the security-scoped base query (user/language/sense-confirmed).
     *  - Pre-compute governance matching IDs (single batch, no N+1).
     *  - Delegate to ReviewCardBrowserSearchQueryApplier (text/lifecycle/
     *    governance/rated/prop).
     *  - Apply standard filter buttons (reusing pre-computed governance IDs).
     *  - Apply advanced filter panel.
     *  - Apply sort.
     */
    public function buildFromCriteria(
        Request $request,
        ReviewCardBrowserSearchCriteria $criteria,
        int $userId,
        string $language,
    ): \Illuminate\Database\Eloquent\Builder {
        return $this->buildFromFilterState(
            ReviewCardManageFilterState::fromRequest($request),
            $criteria,
            $userId,
            $language,
        );
    }

    public function buildFromFilterState(
        ReviewCardManageFilterState $state,
        ReviewCardBrowserSearchCriteria $criteria,
        int $userId,
        string $language,
    ): \Illuminate\Database\Eloquent\Builder {
        $filter = $state->get('filter');

        // Base query — all security constraints baked in via whereHas
        $query = ReviewCard::query()
            ->where('review_cards.user_id', $userId)
            ->where('review_cards.language_id', $language)
            ->where('review_cards.target_type', ReviewCard::TARGET_SENSE)
            ->whereHas('sense', function ($subQuery) use ($userId, $language) {
                $subQuery->where('user_id', $userId)
                    ->where('language_id', $language)
                    ->where('status', WordSense::STATUS_CONFIRMED);
            })
            ->with('sense');

        // ADR-0012: Pre-compute governance matching IDs if needed.
        // If filter=leech|struggling AND is:leech|is:struggling token both
        // request the SAME status, compute once and reuse. If they request
        // DIFFERENT statuses (e.g. filter=leech is:struggling), both must
        // be satisfied (AND) — compute both. If only one source requests
        // governance, compute only that one.
        $governanceMatchingIds = $this->resolveGovernanceMatchingIds(
            $filter, $criteria, $userId, $language
        );

        // ADR-0012: Apply browser search criteria (text, lifecycle, governance,
        // rated, prop) via the applier. Plain text is applied here instead of
        // the old inline whereHas — same 5 fields, same LIKE %text% behavior.
        $this->searchApplier->apply(
            $query,
            $criteria,
            $userId,
            $language,
            $governanceMatchingIds['criteria_ids'] ?? null,
            null, // resolver not needed — ids pre-computed above
        );

        // Apply standard filters (filter buttons).
        // NOTE: leech/struggling filter case uses the pre-computed IDs to
        // avoid duplicate classification.
        $this->applyFilters($query, $filter, $userId, $language, $governanceMatchingIds['filter_ids'] ?? null);

        // Advanced filters — all within security scope (user_id/language_id/sense confirmed)
        $this->applyAdvancedFilters($query, $state);

        // Sort — whitelist only, no raw user input in orderBy
        $this->applySort($query, $state);

        return $query;
    }

    /**
     * Get the parsed criteria for a request, for the controller to expose
     * as search_meta in the response.
     *
     * ADR-0013: The Controller calls this once per request for the 422 guard
     * and search_meta, then passes the returned criteria to buildFromCriteria().
     * buildFromCriteria() does NOT re-parse.
     *
     * @throws InvalidBrowserSearchException
     */
    public function parseCriteria(Request $request): ReviewCardBrowserSearchCriteria
    {
        return $this->parseCriteriaForState(ReviewCardManageFilterState::fromRequest($request));
    }

    public function parseCriteriaForState(ReviewCardManageFilterState $state): ReviewCardBrowserSearchCriteria
    {
        return $this->searchParser->parse($state->get('q'));
    }

    /**
     * Pre-compute governance (leech/struggling) matching card IDs.
     *
     * ADR-0012: Avoids duplicate Policy classification when both the filter
     * button and the is: token request governance. Returns IDs keyed by
     * use-case so applyFilters and the applier can reuse them.
     *
     * @return array{filter_ids: array<int>|null, criteria_ids: array<int>|null}
     */
    private function resolveGovernanceMatchingIds(
        string $filter,
        ReviewCardBrowserSearchCriteria $criteria,
        int $userId,
        string $language,
    ): array {
        $filterNeedsGovernance = in_array($filter, ['leech', 'struggling'], true);
        $criteriaNeedsGovernance = $criteria->hasGovernanceStatus();

        if (!$filterNeedsGovernance && !$criteriaNeedsGovernance) {
            return ['filter_ids' => null, 'criteria_ids' => null];
        }

        $result = ['filter_ids' => null, 'criteria_ids' => null];

        // If filter requests governance, compute once.
        if ($filterNeedsGovernance) {
            $filterIds = $this->getLeechFilteredCardIds($userId, $language, $filter);
            $result['filter_ids'] = $filterIds;

            // If criteria requests the SAME status, reuse.
            if ($criteriaNeedsGovernance && $criteria->governanceStatus === $filter) {
                $result['criteria_ids'] = $filterIds;
            }
        }

        // If criteria requests a DIFFERENT status (or filter didn't request
        // governance), compute separately.
        if ($criteriaNeedsGovernance && $result['criteria_ids'] === null) {
            $result['criteria_ids'] = $this->getLeechFilteredCardIds(
                $userId, $language, $criteria->governanceStatus
            );
        }

        return $result;
    }

    /**
     * Apply standard filter to a query already scoped to current user/language/sense.
     *
     * ADR-0010: The old 'enabled'/'disabled' filters are kept as deprecated
     * aliases. New lifecycle-aware filters:
     *   - 'active'    → lifecycle_state = active
     *   - 'buried'    → lifecycle_state = buried
     *   - 'suspended' → lifecycle_state = suspended
     *   - 'archived'  → lifecycle_state = archived
     *   - 'learning'  → active + buried + suspended (default visible set;
     *                   excludes archived per spec 2.4 "默认管理列表隐藏")
     */
    private function applyFilters($query, string $filter, int $userId, string $language, ?array $precomputedGovernanceIds = null): void
    {
        $now = Carbon::now();
        switch ($filter) {
            case 'due':
                $query->where('review_cards.fsrs_due_at', '<=', $now);
                break;
            case 'future':
                $query->where('review_cards.fsrs_due_at', '>', $now);
                break;
            case 'enabled':
                // Deprecated: active + buried (fsrs_enabled mirror = true)
                $query->whereIn('review_cards.lifecycle_state', [
                    ReviewCard::LIFECYCLE_ACTIVE,
                    ReviewCard::LIFECYCLE_BURIED,
                ]);
                break;
            case 'disabled':
                // Deprecated: suspended + archived (fsrs_enabled mirror = false)
                $query->whereIn('review_cards.lifecycle_state', [
                    ReviewCard::LIFECYCLE_SUSPENDED,
                    ReviewCard::LIFECYCLE_ARCHIVED,
                ]);
                break;
            case 'active':
                $query->where('review_cards.lifecycle_state', ReviewCard::LIFECYCLE_ACTIVE);
                break;
            case 'buried':
                $query->where('review_cards.lifecycle_state', ReviewCard::LIFECYCLE_BURIED);
                break;
            case 'suspended':
                $query->where('review_cards.lifecycle_state', ReviewCard::LIFECYCLE_SUSPENDED);
                break;
            case 'archived':
                $query->where('review_cards.lifecycle_state', ReviewCard::LIFECYCLE_ARCHIVED);
                break;
            case 'learning':
                // Default visible set: everything except archived
                $query->whereNotIn('review_cards.lifecycle_state', [
                    ReviewCard::LIFECYCLE_ARCHIVED,
                ]);
                break;
            case 'leech':
            case 'struggling':
                // ADR-0011: Use REAL Policy classification (not SQL proxy).
                // Method A: Pre-compute matching card IDs via batch classification,
                // then apply as whereIn. This ensures the filter, pagination total,
                // and in-row badges all use the same classification source.
                //
                // The classification considers ALL sense cards for this user/language
                // (regardless of lifecycle state), so suspended/archived leech cards
                // are still findable via this filter.
                //
                // ADR-0012: If the caller pre-computed IDs via resolveGovernanceMatchingIds()
                // (e.g. when is:leech token also requested governance), reuse them to
                // avoid running Policy classification a second time.
                $matchingIds = $precomputedGovernanceIds ?? $this->getLeechFilteredCardIds($userId, $language, $filter);
                $query->whereIn('review_cards.id', $matchingIds);
                break;
            case 'missing_definition':
                $query->whereHas('sense', function ($subQuery) {
                    $subQuery->where(function ($q) {
                        $q->whereNull('sense_zh')->orWhere('sense_zh', '');
                    })->where(function ($q) {
                        $q->whereNull('sense_en')->orWhere('sense_en', '');
                    });
                });
                break;
            case 'missing_example':
                $query->whereHas('sense', function ($subQuery) {
                    $subQuery->where(function ($q) {
                        $q->whereNull('example_sentence_en')->orWhere('example_sentence_en', '');
                    });
                });
                break;
            case 'missing_source':
                $query->whereHas('sense', function ($subQuery) {
                    $subQuery->whereNull('source_chapter_id');
                })->whereNotExists(function ($subQuery) use ($userId, $language) {
                    $subQuery->select(DB::raw(1))
                        ->from('word_sense_occurrences')
                        ->whereColumn('word_sense_occurrences.word_sense_id', 'review_cards.target_id')
                        ->where('word_sense_occurrences.user_id', $userId)
                        ->where('word_sense_occurrences.language_id', $language)
                        ->where('word_sense_occurrences.status', WordSenseOccurrence::STATUS_BOUND)
                        ->whereNotNull('word_sense_occurrences.chapter_id');
                });
                break;
        }
    }

    /**
     * Get card IDs matching the real leech/struggling Policy classification.
     *
     * This is the SINGLE source of truth for leech/struggling filtering on
     * the management page. It uses SenseReviewLeechQueryService (which
     * delegates to SenseReviewLeechPolicy) to classify ALL sense cards for
     * the user/language, then returns only the IDs matching the requested
     * status.
     *
     * Query budget: 1 ReviewLog batch query (via buildForCards) + 1 card ID
     * query, regardless of card count. Classification is in-memory.
     *
     * The classification considers ALL lifecycle states (active, suspended,
     * archived, buried) so that suspended/archived leech cards remain
     * findable in the management filter.
     *
     * @param  int    $userId
     * @param  string $language
     * @param  string $filter  'leech' | 'struggling'
     * @return list<int>  Card IDs matching the filter. Empty list if none.
     */
    private function getLeechFilteredCardIds(int $userId, string $language, string $filter): array
    {
        // Get ALL sense card IDs for this user/language, regardless of lifecycle.
        // Base constraints: user/language/target_type=sense/sense confirmed.
        $allIds = ReviewCard::query()
            ->where('review_cards.user_id', $userId)
            ->where('review_cards.language_id', $language)
            ->where('review_cards.target_type', ReviewCard::TARGET_SENSE)
            ->whereHas('sense', function ($subQuery) use ($userId, $language) {
                $subQuery->where('user_id', $userId)
                    ->where('language_id', $language)
                    ->where('status', WordSense::STATUS_CONFIRMED);
            })
            ->pluck('review_cards.id')
            ->all();

        if (empty($allIds)) {
            return [];
        }

        return $this->leechQueryService->filterCardIdsByLeechStatus($allIds, $filter);
    }

    /**
     * Apply advanced filter query parameters within the already-scoped query.
     * All filters use whitelist/enum/int-safe parsing — no raw user input in SQL.
     */
    private function applyAdvancedFilters($query, ReviewCardManageFilterState $state): void
    {
        // fsrs_states[] — whitelist each value
        $fsrsStates = $state->get('fsrs_states');
        if (!empty($fsrsStates)) {
            $query->whereIn('review_cards.fsrs_state', $fsrsStates);
        }

        // due_range — whitelist via switch
        $dueRange = $state->get('due_range');
        switch ($dueRange) {
            case 'overdue':
                $query->where('review_cards.fsrs_due_at', '<', Carbon::today());
                break;
            case 'today':
                $query->whereBetween('review_cards.fsrs_due_at', [Carbon::today(), Carbon::tomorrow()]);
                break;
            case 'next7':
                $query->whereBetween('review_cards.fsrs_due_at', [Carbon::now(), Carbon::now()->addDays(7)]);
                break;
            case 'future':
                $query->where('review_cards.fsrs_due_at', '>', Carbon::now());
                break;
            case 'none':
                $query->whereNull('review_cards.fsrs_due_at');
                break;
            case 'all':
            default:
                break; // no filter
        }

        // reps_min — non-negative int, ctype_digit guard
        $repsMin = $state->get('reps_min');
        if ($repsMin !== null) {
            $query->where('review_cards.fsrs_reps', '>=', $repsMin);
        }

        // lapses_min — non-negative int, ctype_digit guard
        $lapsesMin = $state->get('lapses_min');
        if ($lapsesMin !== null) {
            $query->where('review_cards.fsrs_lapses', '>=', $lapsesMin);
        }
    }

    /**
     * Apply sort to a query — whitelist only, no raw user input in orderBy.
     */
    private function applySort($query, ReviewCardManageFilterState $state): void
    {
        $sortBy = $state->get('sort_by');
        $sortDir = $state->get('sort_dir');

        $sortColumn = self::SORTABLE_COLUMNS[$sortBy];
        $query->orderBy($sortColumn, $sortDir);

        // Tie-breaker for stable pagination
        if ($sortBy !== 'id') {
            $query->orderBy('review_cards.id', 'desc');
        }
    }
}
