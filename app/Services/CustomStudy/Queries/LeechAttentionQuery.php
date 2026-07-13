<?php

namespace App\Services\CustomStudy\Queries;

use App\Models\ReviewCard;
use App\Services\ReviewStudyTimezoneService;
use App\Services\SenseReviewLeechQueryService;
use App\Services\SenseReviewLeechPolicy;
use App\Services\SenseReviewQueryService;
use Illuminate\Support\Carbon;

/**
 * Custom Study Phase 2B — CS-6: leech_attention candidate ID resolver.
 *
 * Policy-derived boundary (frozen by Task 2000-18 §9):
 *   - Unlike the three SQL-native queries (today_forgotten / overdue /
 *     source_chapter) which return a composable Eloquent Builder, this
 *     query returns `list<int>` because leech / struggling status depends
 *     on PHP-side aggregation via SenseReviewLearningFeedbackService and
 *     classification via SenseReviewLeechPolicy. The Leech Policy is a
 *     pure PHP function — it cannot be translated to a single SQL filter
 *     without duplicating thresholds.
 *
 * Hard rules:
 *   - Does NOT duplicate SenseReviewLeechPolicy thresholds.
 *   - Does NOT create a second leech classifier.
 *   - Does NOT modify SenseReviewLeechPolicy / QueryService / Feedback.
 *   - Reuses SenseReviewQueryService::confirmedSenseCardQuery() for
 *     user/language/target_type/confirmed-sense isolation.
 *   - Reuses ReviewCard::scopeSenseReviewEligible() for lifecycle +
 *     fsrs_enabled isolation. Eligible cards are filtered FIRST, before
 *     leech classification — so suspended / archived leech cards remain
 *     diagnosable via the management page but never enter a Custom Study
 *     session.
 *   - Reuses SenseReviewLeechQueryService::describeForCards() with
 *     preloaded cards — no second ReviewCard query, no N+1.
 *   - Does NOT write ReviewLog / ReviewCard / WordSense / lifecycle.
 *   - Does NOT modify FSRS.
 *
 * Query budget (frozen by Task 2000-18 §6.3):
 *   1. One eligible ReviewCard query (confirmedSenseCardQuery + eligible
 *      scope + get()).
 *   2. One batched ReviewLog / feedback query (inside
 *      SenseReviewLearningFeedbackService::buildForCards()).
 *   No re-query of ReviewCard. No N+1.
 *
 * Task CS-6 of Custom Study 1A Phase 2B (Task 2000-18).
 */
class LeechAttentionQuery
{
    public function __construct(
        private readonly SenseReviewQueryService $senseReviewQueryService,
        private readonly SenseReviewLeechQueryService $leechQueryService,
        private readonly ReviewStudyTimezoneService $timezoneService
    ) {
    }

    /**
     * Resolve the candidate ReviewCard IDs for leech_attention mode.
     *
     * @param int    $userId   Trusted current user id.
     * @param string $language Trusted current language.
     * @param string $subMode  One of:
     *                          - 'leech_only'            → only status=leech
     *                          - 'leech_plus_struggling' → leech + struggling
     * @param Carbon $now      Current instant (injectable for tests).
     * @return list<int>  Unique positive ReviewCard IDs. Empty when no
     *                    eligible card matches the requested sub-mode.
     */
    public function candidateIds(int $userId, string $language, string $subMode, Carbon $now): array
    {
        if ($subMode !== 'leech_only' && $subMode !== 'leech_plus_struggling') {
            return [];
        }

        // 1. Build the eligible-card query (1 SQL when terminated).
        //    confirmedSenseCardQuery enforces user + language + target_type=sense
        //    + WordSense.status=confirmed + fsrs_enabled=true (via join).
        //    senseReviewEligible enforces lifecycle (active / expired buried)
        //    + excludes suspended / archived / future-buried + fsrs_enabled.
        $builder = $this->senseReviewQueryService
            ->confirmedSenseCardQuery($userId, $language)
            ->senseReviewEligible($userId, $language, $now);

        // 2. Single-load eligible cards with explicit select to avoid
        //    accidental column collision from the word_senses join.
        $cards = $builder->select('review_cards.*')->get();

        if ($cards->isEmpty()) {
            return [];
        }

        $cardIds = $cards->pluck('id')->all();

        // 3. Classify via SenseReviewLeechQueryService with preloaded cards
        //    — internally triggers exactly ONE batch ReviewLog query via
        //    SenseReviewLearningFeedbackService::buildForCards(); the cards
        //    we pass here are reused, so describeForCards does NOT re-query
        //    ReviewCard.
        $studyTimezone = $this->timezoneService->getStudyTimezone();
        $descriptors = $this->leechQueryService->describeForCards(
            $cardIds,
            $cards,
            $now,
            $studyTimezone
        );

        // 4. Filter by sub-mode.
        $allowedStatuses = $subMode === 'leech_only'
            ? [SenseReviewLeechPolicy::STATUS_LEECH]
            : [SenseReviewLeechPolicy::STATUS_LEECH, SenseReviewLeechPolicy::STATUS_STRUGGLING];

        $result = [];
        foreach ($descriptors as $cardId => $descriptor) {
            $status = $descriptor['status'] ?? SenseReviewLeechPolicy::STATUS_STABLE;
            if (in_array($status, $allowedStatuses, true)) {
                $result[] = (int) $cardId;
            }
        }

        // 5. De-duplicate + keep only positive integers. Order is not
        //    guaranteed to be stable by the underlying query — the caller
        //    (CustomStudyQueryService) does NOT rely on order here; session
        //    ordering is a Phase 3+ concern.
        $result = array_values(array_unique(array_filter($result, fn ($id) => $id > 0)));

        return $result;
    }
}
