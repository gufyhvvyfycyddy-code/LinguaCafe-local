<?php

namespace App\Services\CustomStudy;

use App\Models\ReviewCard;
use App\Services\ReviewQueueOrderOptions;
use App\Services\ReviewQueueOrderService;
use App\Services\ReviewStudyTimezoneService;
use App\Services\SenseReviewLeechQueryService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CustomStudySessionOrder — Custom Study 1A Phase 4A (Task 2000-21).
 *
 * Session-internal ordering service. Takes UNORDERED candidate ReviewCard IDs
 * (from CustomStudyQueryService::candidateIds()) and produces a stable,
 * per-mode ordered list of IDs ready for SessionState::createInitial().
 *
 * Data flow (frozen by ADR-0016):
 *   CriteriaValidator
 *   → QueryService::candidateIds()       (unordered list<int>)
 *   → CustomStudySessionOrder::order()   ← THIS SERVICE
 *   → [future] apply card_limit
 *   → SessionState::createInitial()
 *   → TokenService::issue()
 *
 * Per-mode ordering (ADR-0016 §19):
 *   - source_chapter:   canonical Queue Order (no extra sort).
 *   - overdue:          retrievability ASC; tie → canonical fallback ASC.
 *   - today_forgotten:  latest valid today-again DESC; tie → canonical fallback ASC.
 *   - leech_attention:  severity level DESC (leech=2, struggling=1, stable=0);
 *                       tie → canonical fallback ASC.
 *   - unknown mode:     canonical fallback (defensive — criteria is pre-validated).
 *
 * Hard rules:
 *   - READ-ONLY: never writes any table.
 *   - Does NOT create SessionState or token.
 *   - Does NOT apply card_limit.
 *   - Does NOT re-run Criteria Queries or call QueryService.
 *   - Does NOT modify Queue Order settings.
 *   - Batch-loads ReviewCard once (user + language + target_type=sense filter).
 *   - Batch-queries ReviewLog once for today_forgotten (no N+1).
 *   - Calls describeForCards once with pre-loaded cards for leech_attention.
 *   - Reuses ReviewQueueOrderService for canonical order + computeRetrievability.
 *   - Does NOT copy Queue Order, FSRS, or Leech Policy algorithms.
 *   - Does NOT access Auth/Request/Session.
 *   - Does NOT call AI.
 *
 * Task 2000-21 — Custom Study 1A Phase 4A.
 */
class CustomStudySessionOrder
{
    public function __construct(
        private readonly ReviewQueueOrderService $orderService,
        private readonly ReviewStudyTimezoneService $timezoneService,
        private readonly SenseReviewLeechQueryService $leechQueryService,
    ) {
    }

    /**
     * Order candidate ReviewCard IDs for a Custom Study session.
     *
     * @param  list<int>               $candidateIds  Raw candidate IDs from QueryService.
     * @param  CustomStudyCriteria     $criteria      Validated criteria (mode + parameters).
     * @param  int                     $userId        Trusted current user id.
     * @param  string                  $language      Trusted current language.
     * @param  Carbon                  $now           Current instant.
     * @param  ReviewQueueOrderOptions $queueOptions  Queue Order settings (read-only).
     * @return list<int>  Ordered candidate IDs (subset of input, all valid sense cards).
     */
    public function order(
        array $candidateIds,
        CustomStudyCriteria $criteria,
        int $userId,
        string $language,
        Carbon $now,
        ReviewQueueOrderOptions $queueOptions
    ): array {
        // 1. Sanitize input: dedupe + filter positive integers.
        $uniqueIds = array_values(array_unique(array_filter(
            array_map('intval', $candidateIds),
            fn ($id) => $id > 0
        )));

        if (empty($uniqueIds)) {
            return [];
        }

        // 2. Batch-load ReviewCards with strict user/language/sense isolation.
        //    Input IDs that don't exist, belong to another user/language, or are
        //    legacy word cards are silently dropped.
        $cards = ReviewCard::whereIn('id', $uniqueIds)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->get();

        if ($cards->isEmpty()) {
            return [];
        }

        // 3. Compute canonical fallback order ONCE.
        //    All modes use this as the tie-break. source_chapter uses it directly.
        $timezone = $this->timezoneService->getStudyTimezone();
        $canonicalOrdered = $this->orderService->order(
            $cards,
            $userId,
            $language,
            $timezone,
            $now,
            $queueOptions
        );

        $fallbackRank = [];
        $index = 0;
        foreach ($canonicalOrdered as $card) {
            $fallbackRank[$card->id] = $index++;
        }

        // 4. Dispatch by mode.
        $mode = $criteria->mode();
        switch ($mode) {
            case CustomStudyCriteria::MODE_OVERDUE:
                return $this->orderByRetrievability($canonicalOrdered, $now, $fallbackRank);

            case CustomStudyCriteria::MODE_TODAY_FORGOTTEN:
                return $this->orderByTodayForgotten(
                    $canonicalOrdered,
                    $userId,
                    $language,
                    $now,
                    $fallbackRank
                );

            case CustomStudyCriteria::MODE_LEECH_ATTENTION:
                return $this->orderByLeech(
                    $canonicalOrdered,
                    $now,
                    $timezone,
                    $fallbackRank
                );

            case CustomStudyCriteria::MODE_SOURCE_CHAPTER:
            case CustomStudyCriteria::MODE_MARKED:
            default:
                // source_chapter and unknown modes: pure canonical fallback.
                return $canonicalOrdered->map->id->values()->all();
        }
    }

    /**
     * overdue: retrievability ASC; tie → canonical fallback ASC.
     *
     * Reuses ReviewQueueOrderService::computeRetrievability() — does NOT
     * copy the FSRS-5 formula. null/zero/negative stability semantics are
     * inherited from the canonical service (returns 0.0 = most forgotten).
     */
    private function orderByRetrievability(
        Collection $canonicalOrdered,
        Carbon $now,
        array $fallbackRank
    ): array {
        $items = [];
        foreach ($canonicalOrdered as $card) {
            $r = $this->orderService->computeRetrievability($card, $now);
            $items[] = [
                'card_id' => $card->id,
                'retrievability' => $r,
                'fallback_rank' => $fallbackRank[$card->id] ?? PHP_INT_MAX,
            ];
        }

        usort($items, function ($a, $b) {
            if ($a['retrievability'] !== $b['retrievability']) {
                return $a['retrievability'] <=> $b['retrievability'];
            }
            return $a['fallback_rank'] <=> $b['fallback_rank'];
        });

        return array_map(fn ($item) => $item['card_id'], $items);
    }

    /**
     * today_forgotten: latest valid today-again DESC; tie → canonical fallback ASC.
     *
     * Cards without a valid today-again log are placed AFTER all cards that
     * have one (sorted by canonical fallback among themselves).
     *
     * Uses ONE batch ReviewLog query (no N+1). Reuses
     * ReviewStudyTimezoneService::dayStart() for the local day boundary
     * (NOT Carbon::today()). Filters match TodayForgottenQuery:
     *   - source = 'sense_review'
     *   - rating = 'again'
     *   - undone_at IS NULL
     *   - reviewed_at in [dayStart, nextDayStart)
     */
    private function orderByTodayForgotten(
        Collection $canonicalOrdered,
        int $userId,
        string $language,
        Carbon $now,
        array $fallbackRank
    ): array {
        $dayStart = $this->timezoneService->dayStart($now);
        $nextDayStart = $dayStart->copy()->addDay();

        $candidateIdList = $canonicalOrdered->map->id->all();

        // Single batch query: latest valid again per card within the learning day.
        $rows = DB::table('review_logs')
            ->select('review_card_id', DB::raw('MAX(reviewed_at) as latest_again'))
            ->whereIn('review_card_id', $candidateIdList)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('source', 'sense_review')
            ->where('rating', 'again')
            ->whereNull('undone_at')
            ->where('reviewed_at', '>=', $dayStart)
            ->where('reviewed_at', '<', $nextDayStart)
            ->groupBy('review_card_id')
            ->get();

        $againTimes = [];
        foreach ($rows as $row) {
            $againTimes[$row->review_card_id] = $row->latest_again;
        }

        $items = [];
        foreach ($canonicalOrdered as $card) {
            $hasAgain = isset($againTimes[$card->id]);
            $items[] = [
                'card_id' => $card->id,
                'has_again' => $hasAgain ? 1 : 0,
                'again_time' => $hasAgain ? $againTimes[$card->id] : '',
                'fallback_rank' => $fallbackRank[$card->id] ?? PHP_INT_MAX,
            ];
        }

        usort($items, function ($a, $b) {
            // Cards with a valid again come first (DESC on has_again).
            if ($a['has_again'] !== $b['has_again']) {
                return $b['has_again'] <=> $a['has_again'];
            }
            // Both have valid again: later time first (DESC by string compare —
            // Y-m-d H:i:s format sorts chronologically).
            if ($a['has_again'] === 1) {
                $cmp = strcmp($b['again_time'], $a['again_time']);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            // Tie: canonical fallback (ASC).
            return $a['fallback_rank'] <=> $b['fallback_rank'];
        });

        return array_map(fn ($item) => $item['card_id'], $items);
    }

    /**
     * leech_attention: severity level DESC (leech=2, struggling=1, stable=0);
     * tie → canonical fallback ASC.
     *
     * Calls SenseReviewLeechQueryService::describeForCards() ONCE with
     * pre-loaded cards (avoids a second ReviewCard query). Does NOT call
     * describeForCard() (per-card) or summary() (unscoped). Does NOT copy
     * SenseReviewLeechPolicy — reuses the canonical classification.
     */
    private function orderByLeech(
        Collection $canonicalOrdered,
        Carbon $now,
        string $timezone,
        array $fallbackRank
    ): array {
        $candidateIdList = $canonicalOrdered->map->id->all();

        $descriptors = $this->leechQueryService->describeForCards(
            $candidateIdList,
            $canonicalOrdered,
            $now,
            $timezone
        );

        $items = [];
        foreach ($canonicalOrdered as $card) {
            $desc = $descriptors[$card->id] ?? [];
            $status = $desc['status'] ?? 'stable';
            $level = $this->severityLevel($status);
            $items[] = [
                'card_id' => $card->id,
                'level' => $level,
                'fallback_rank' => $fallbackRank[$card->id] ?? PHP_INT_MAX,
            ];
        }

        usort($items, function ($a, $b) {
            if ($a['level'] !== $b['level']) {
                return $b['level'] <=> $a['level'];
            }
            return $a['fallback_rank'] <=> $b['fallback_rank'];
        });

        return array_map(fn ($item) => $item['card_id'], $items);
    }

    /**
     * Map a leech status string to a severity level for sorting.
     *
     * leech = 2, struggling = 1, stable/unknown = 0.
     */
    private function severityLevel(string $status): int
    {
        return match ($status) {
            'leech' => 2,
            'struggling' => 1,
            default => 0,
        };
    }
}
