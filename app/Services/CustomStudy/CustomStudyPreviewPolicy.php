<?php

namespace App\Services\CustomStudy;

use App\Exceptions\CustomStudyPreviewPolicyException;
use Illuminate\Support\Carbon;

/**
 * Pure state-transition layer for Custom Study preview sessions.
 *
 * The Policy consumes an immutable CustomStudySessionState and returns a NEW
 * immutable CustomStudySessionState via State::withProgress(). It never mutates
 * the original state and never touches the payload string-keyed representation
 * (no toArray() / fromArray() calls).
 *
 * Allowed operations:
 * - Apply a rating (again/hard/good/easy) to the current card.
 * - Resume the session (pick the next card or keep the current one).
 *
 * Architecture constraints (enforced by source-level tests in
 * CustomStudyPreviewPolicyTest): this class is a pure function. It does not
 * touch the database, authentication, request, encryption, review logs,
 * spaced-repetition scheduling, card state machines, AI services, token
 * signing, candidate ID lookup, controllers, routes, Vue, or serializers. The
 * time is injected as a parameter — global/static time helpers are forbidden.
 *
 * Task 2000-20 — Custom Study 1A Phase 3B.
 */
class CustomStudyPreviewPolicy
{
    /** @var list<string> */
    private const ALLOWED_RATINGS = ['again', 'hard', 'good', 'easy'];

    /**
     * Applies a rating to the current card and returns a new state with the
     * next card selected.
     *
     * Rating behavior (frozen):
     * - again : current → delayed_repeat_queue (available_at = timestamp + again_secs)
     * - hard  : current → delayed_repeat_queue (available_at = timestamp + hard_secs)
     * - good  : current → completed_ids
     * - easy  : current → completed_ids
     *
     * Next-card selection (after the rating is applied):
     * 1. If ready_queue is non-empty, pop the first entry as the new current.
     * 2. Else, find mature delayed entries (available_at <= timestamp), pick
     *    the one with the earliest available_at. Ties keep the original queue
     *    order (stable selection).
     * 3. Else, current = null (session may be waiting or completed).
     *
     * @throws CustomStudyPreviewPolicyException If rating is invalid
     *     (reason=`invalid_rating`) or current_card_id is null
     *     (reason=`no_current_card`).
     */
    public function applyRating(
        CustomStudySessionState $state,
        string $rating,
        Carbon $now
    ): CustomStudySessionState {
        if (!in_array($rating, self::ALLOWED_RATINGS, true)) {
            throw new CustomStudyPreviewPolicyException(
                'invalid_rating',
                'Rating must be one of: again, hard, good, easy. Got: ' . $rating
            );
        }

        $currentCardId = $state->currentCardId();
        if ($currentCardId === null) {
            throw new CustomStudyPreviewPolicyException(
                'no_current_card',
                'Cannot apply a rating when current_card_id is null.'
            );
        }

        $readyQueue = $state->readyQueue();
        $delayedQueue = $state->delayedRepeatQueue();
        $completedIds = $state->completedIds();
        $skippedIds = $state->skippedIneligibleIds();
        $config = $state->previewDelayConfig();
        $timestamp = $now->getTimestamp();

        // Move the current card based on the rating.
        if ($rating === 'again' || $rating === 'hard') {
            $delaySeconds = ($rating === 'again')
                ? $config['again_secs']
                : $config['hard_secs'];
            $delayedQueue[] = [
                'card_id' => $currentCardId,
                'available_at' => $timestamp + $delaySeconds,
            ];
        } else { // 'good' or 'easy'
            $completedIds[] = $currentCardId;
        }

        // Pick the next current card.
        [$newCurrent, $newReady, $newDelayed] = $this->pickNext(
            $readyQueue,
            $delayedQueue,
            $timestamp
        );

        return $state->withProgress(
            $newCurrent,
            $newReady,
            $newDelayed,
            $completedIds,
            $skippedIds
        );
    }

    /**
     * Resumes the session by selecting the next card (or keeping the current
     * one if it already exists).
     *
     * Resume behavior:
     * 1. If current_card_id is non-null, keep it. Queues unchanged. Step +1.
     * 2. If current is null and ready_queue is non-empty, pop the first entry.
     * 3. If current is null and ready is empty, pick the earliest mature
     *    delayed entry (stable for ties).
     * 4. If no mature delayed exists, current stays null (waiting or completed).
     *
     * This method does NOT perform eligibility DB re-validation — that is the
     * future SessionService's responsibility.
     */
    public function resume(
        CustomStudySessionState $state,
        Carbon $now
    ): CustomStudySessionState {
        $currentCardId = $state->currentCardId();
        $readyQueue = $state->readyQueue();
        $delayedQueue = $state->delayedRepeatQueue();
        $completedIds = $state->completedIds();
        $skippedIds = $state->skippedIneligibleIds();
        $timestamp = $now->getTimestamp();

        if ($currentCardId !== null) {
            // Keep the same current card; queues unchanged; step +1 via withProgress.
            return $state->withProgress(
                $currentCardId,
                $readyQueue,
                $delayedQueue,
                $completedIds,
                $skippedIds
            );
        }

        [$newCurrent, $newReady, $newDelayed] = $this->pickNext(
            $readyQueue,
            $delayedQueue,
            $timestamp
        );

        return $state->withProgress(
            $newCurrent,
            $newReady,
            $newDelayed,
            $completedIds,
            $skippedIds
        );
    }

    /**
     * Picks the next current card from ready first, then from mature delayed.
     *
     * @param list<int> $readyQueue
     * @param list<array{card_id: int, available_at: int}> $delayedQueue
     * @return array{0: ?int, 1: list<int>, 2: list<array{card_id: int, available_at: int}>}
     */
    private function pickNext(array $readyQueue, array $delayedQueue, int $timestamp): array
    {
        // 1. Ready queue takes priority.
        if (!empty($readyQueue)) {
            $newCurrent = $readyQueue[0];
            $newReady = array_values(array_slice($readyQueue, 1));
            return [$newCurrent, $newReady, $delayedQueue];
        }

        // 2. Find the earliest mature delayed entry. Stable for ties: strict
        //    less-than means the first entry wins when available_at is equal.
        $bestIdx = -1;
        $bestAvailableAt = PHP_INT_MAX;
        $count = count($delayedQueue);
        for ($i = 0; $i < $count; $i++) {
            $entry = $delayedQueue[$i];
            if ($entry['available_at'] <= $timestamp
                && $entry['available_at'] < $bestAvailableAt
            ) {
                $bestIdx = $i;
                $bestAvailableAt = $entry['available_at'];
            }
        }

        if ($bestIdx === -1) {
            // No mature delayed entry — current stays null.
            return [null, $readyQueue, $delayedQueue];
        }

        // Remove the picked entry while preserving the relative order of the
        // remaining entries.
        $newCurrent = $delayedQueue[$bestIdx]['card_id'];
        $newDelayed = array_values(array_merge(
            array_slice($delayedQueue, 0, $bestIdx),
            array_slice($delayedQueue, $bestIdx + 1)
        ));

        return [$newCurrent, $readyQueue, $newDelayed];
    }

    /**
     * Resolves eligibility by moving ineligible cards from current/ready/delayed
     * to skipped_ineligible, WITHOUT incrementing step.
     *
     * This is a pure method — it does NOT query the DB. The caller
     * (CustomStudySessionEligibilityService) is responsible for determining
     * which card IDs are ineligible; this method only applies the state
     * transition.
     *
     * Behavior:
     * 1. Ineligible cards in ready_queue → moved to skipped_ineligible_ids.
     * 2. Ineligible cards in delayed_repeat_queue → moved to skipped_ineligible_ids.
     * 3. If current_card_id is ineligible → moved to skipped_ineligible_ids,
     *    and the next eligible card is popped from the (already-filtered)
     *    ready_queue. If ready is empty, current becomes null.
     * 4. Cards already in completed_ids or skipped_ineligible_ids are NOT
     *    affected (they are already resolved).
     * 5. step is NOT incremented (invariant 18 — same-step eligibility
     *    resolution boundary).
     *
     * Task 2000-22 — Phase 4B.
     *
     * @param list<int> $ineligibleCardIds Card IDs that are no longer eligible
     *     (determined by the EligibilityService via DB query).
     */
    public function resolveEligibility(
        CustomStudySessionState $state,
        array $ineligibleCardIds
    ): CustomStudySessionState {
        // Fast path: no ineligible cards — return an equivalent state through
        // withEligibilityResolution() to maintain the same-step boundary contract.
        if (empty($ineligibleCardIds)) {
            return $state->withEligibilityResolution(
                $state->currentCardId(),
                $state->readyQueue(),
                $state->delayedRepeatQueue(),
                $state->completedIds(),
                $state->skippedIneligibleIds()
            );
        }

        // Build a set for O(1) lookup.
        $ineligibleSet = array_flip($ineligibleCardIds);

        // 1. Partition ready_queue: ineligible → skipped, eligible → stays.
        $newReady = [];
        $newSkipped = $state->skippedIneligibleIds();
        foreach ($state->readyQueue() as $cardId) {
            if (isset($ineligibleSet[$cardId])) {
                $newSkipped[] = $cardId;
            } else {
                $newReady[] = $cardId;
            }
        }

        // 2. Partition delayed_repeat_queue: ineligible → skipped, eligible → stays.
        $newDelayed = [];
        foreach ($state->delayedRepeatQueue() as $entry) {
            if (isset($ineligibleSet[$entry['card_id']])) {
                $newSkipped[] = $entry['card_id'];
            } else {
                $newDelayed[] = $entry;
            }
        }

        // 3. Handle current_card_id: if ineligible, move to skipped and pick
        //    the next eligible card from the (already-filtered) ready_queue.
        $newCurrent = $state->currentCardId();
        if ($newCurrent !== null && isset($ineligibleSet[$newCurrent])) {
            $newSkipped[] = $newCurrent;
            if (!empty($newReady)) {
                $newCurrent = array_shift($newReady);
                $newReady = array_values($newReady);
            } else {
                $newCurrent = null;
            }
        }

        return $state->withEligibilityResolution(
            $newCurrent,
            $newReady,
            $newDelayed,
            $state->completedIds(),
            $newSkipped
        );
    }
}
