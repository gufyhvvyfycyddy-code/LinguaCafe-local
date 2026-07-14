<?php

namespace App\Services\CustomStudy;

use App\Services\SenseReviewQueryService;
use Illuminate\Support\Carbon;

/**
 * Batch eligibility recheck service for Custom Study preview sessions.
 *
 * Reuses SenseReviewQueryService::confirmedSenseCardQuery +
 * ReviewCard::senseReviewEligible scope to find which session cards
 * are NO LONGER eligible for review (e.g. suspended, archived,
 * unconfirmed, fsrs disabled, or buried with unexpired bury).
 *
 * READ-ONLY: does NOT write to DB, does NOT write review logs,
 * does NOT issue/verify token, does NOT call the preview policy.
 *
 * Task 2000-22 — Phase 4B.
 */
class CustomStudySessionEligibilityService
{
    public function __construct(
        private SenseReviewQueryService $senseReviewQueryService
    ) {
    }

    /**
     * Finds card IDs in the session's active queues (current / ready /
     * delayed) that are no longer eligible for sense review.
     *
     * Cards already in completed_ids or skipped_ineligible_ids are NOT
     * re-checked — by the five-state mutual-exclusion invariant they
     * are not present in any active queue.
     *
     * @return list<int>
     */
    public function findIneligibleCardIds(
        CustomStudySessionState $state,
        Carbon $now
    ): array {
        // 1. Collect candidate IDs from active queues only.
        $candidateIds = $this->collectActiveCardIds($state);

        if (empty($candidateIds)) {
            return [];
        }

        // 2. Query which of these candidates are STILL eligible.
        $eligibleIds = $this->senseReviewQueryService
            ->confirmedSenseCardQuery($state->userId(), $state->language())
            ->senseReviewEligible($state->userId(), $state->language(), $now)
            ->whereIn('review_cards.id', $candidateIds)
            ->pluck('review_cards.id')
            ->all();

        // 3. Ineligible = candidates - eligible.
        return array_values(array_diff($candidateIds, $eligibleIds));
    }

    /**
     * Collects card IDs from current_card_id, ready_queue, and
     * delayed_repeat_queue. Does NOT include completed_ids or
     * skipped_ineligible_ids (they are not in active queues by the
     * five-state mutual-exclusion invariant).
     *
     * @return list<int>
     */
    private function collectActiveCardIds(CustomStudySessionState $state): array
    {
        $ids = [];

        $currentCardId = $state->currentCardId();
        if ($currentCardId !== null) {
            $ids[] = $currentCardId;
        }

        foreach ($state->readyQueue() as $cardId) {
            $ids[] = $cardId;
        }

        foreach ($state->delayedRepeatQueue() as $entry) {
            $ids[] = $entry['card_id'];
        }

        return $ids;
    }
}
