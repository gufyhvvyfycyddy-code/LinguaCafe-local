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
     */
    public function build(Request $request, int $userId, string $language): \Illuminate\Database\Eloquent\Builder
    {
        $filter = $request->input('filter', 'enabled');
        $q = trim($request->input('q', ''));

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

        // Search — scoped inside whereHas to prevent escaping security constraints
        if (!empty($q)) {
            $query->whereHas('sense', function ($subQuery) use ($q) {
                $subQuery->where(function ($inner) use ($q) {
                    $inner->where('lemma', 'like', "%{$q}%")
                        ->orWhere('surface_form', 'like', "%{$q}%")
                        ->orWhere('sense_zh', 'like', "%{$q}%")
                        ->orWhere('sense_en', 'like', "%{$q}%")
                        ->orWhere('example_sentence_en', 'like', "%{$q}%");
                });
            });
        }

        // Apply standard filters
        $this->applyFilters($query, $filter, $userId, $language);

        // Advanced filters — all within security scope (user_id/language_id/sense confirmed)
        $this->applyAdvancedFilters($query, $request);

        // Sort — whitelist only, no raw user input in orderBy
        $this->applySort($query, $request);

        return $query;
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
    private function applyFilters($query, string $filter, int $userId, string $language): void
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
                $matchingIds = $this->getLeechFilteredCardIds($userId, $language, $filter);
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
    private function applyAdvancedFilters($query, Request $request): void
    {
        // fsrs_states[] — whitelist each value
        $allowedStates = ['new', 'learning', 'review', 'relearning'];
        $fsrsStates = $request->input('fsrs_states', []);
        if (is_array($fsrsStates) && !empty($fsrsStates)) {
            $validStates = array_values(array_intersect($fsrsStates, $allowedStates));
            if (!empty($validStates)) {
                $query->whereIn('review_cards.fsrs_state', $validStates);
            }
        }

        // due_range — whitelist via switch
        $dueRange = $request->input('due_range', 'all');
        $allowedRanges = ['all', 'overdue', 'today', 'next7', 'future', 'none'];
        if (!in_array($dueRange, $allowedRanges, true)) {
            $dueRange = 'all';
        }
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
        $repsMin = $request->input('reps_min');
        if ($repsMin !== null && $repsMin !== '' && ctype_digit((string) $repsMin)) {
            $repsMin = (int) $repsMin;
            $query->where('review_cards.fsrs_reps', '>=', $repsMin);
        }

        // lapses_min — non-negative int, ctype_digit guard
        $lapsesMin = $request->input('lapses_min');
        if ($lapsesMin !== null && $lapsesMin !== '' && ctype_digit((string) $lapsesMin)) {
            $lapsesMin = (int) $lapsesMin;
            $query->where('review_cards.fsrs_lapses', '>=', $lapsesMin);
        }
    }

    /**
     * Apply sort to a query — whitelist only, no raw user input in orderBy.
     */
    private function applySort($query, Request $request): void
    {
        $sortBy = $request->input('sort_by', 'id');
        $sortDir = strtolower($request->input('sort_dir', 'desc'));

        if (!array_key_exists($sortBy, self::SORTABLE_COLUMNS)) {
            $sortBy = 'id';
        }

        if (!in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = 'desc';
        }

        $sortColumn = self::SORTABLE_COLUMNS[$sortBy];
        $query->orderBy($sortColumn, $sortDir);

        // Tie-breaker for stable pagination
        if ($sortBy !== 'id') {
            $query->orderBy('review_cards.id', 'desc');
        }
    }
}
