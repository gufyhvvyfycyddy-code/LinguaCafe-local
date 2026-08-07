<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * PAB R2 Phase B finish-settlement / precedence contract.
 *
 * PENDING_INTEGRATION_RUN: the Lane 3 baseline has no Backend Core reading
 * settlement public seam or reading-session persistence seam. Each method is
 * a named integration acceptance scenario required by the R2 dispatch. Lane 4
 * must bind these scenarios to the actual Backend Core service/endpoint after
 * merging Lane 1; the stated semantics must not be weakened.
 */
class ReadingReviewSettlementContractTest extends TestCase
{
    public function test_reliable_single_sense_passive_good_is_written_exactly_once(): void
    {
        $this->pendingSettlement('One passive-eligible occurrence bound to one confirmed sense settles Good exactly once with source=reading_passive.');
    }

    public function test_user_confirmed_binding_passive_good_is_written_exactly_once(): void
    {
        $this->pendingSettlement('User binding evidence is eligible for one passive Good in the reading session, subject to opened/explicit/new-unknown suppression.');
    }

    public function test_trust_high_matched_existing_passive_good_is_written_exactly_once(): void
    {
        $this->pendingSettlement('Persisted trust_ai high matched_existing evidence may yield one passive Good; current browser LocalStorage must not be consulted as authority.');
    }

    public function test_ambiguous_new_sense_excluded_medium_and_low_create_zero_passive_ratings(): void
    {
        $this->pendingSettlement('ambiguous, new_sense, excluded, medium and low evidence produce zero passive ReviewLog rows and zero FSRS mutation.');
    }

    public function test_opened_or_helped_occurrence_creates_zero_passive_rating(): void
    {
        $this->pendingSettlement('An occurrence opened/inspected/helped during the session is suppressed from passive Good; ordinary lookup is never proof of knowledge.');
    }

    public function test_explicit_again_hard_good_easy_each_create_exactly_one_reading_explicit_log(): void
    {
        $this->pendingSettlement('Explicit inline Sense Review for Again/Hard/Good/Easy each creates exactly one formal log through ReviewCardService with source=reading_explicit.');
    }

    public function test_explicit_rating_wins_over_passive_settlement_for_same_sense_session(): void
    {
        $this->pendingSettlement('If a sense receives an explicit reading rating in the session, finish settlement must not add a passive Good for that same sense/session.');
    }

    public function test_multiple_occurrences_of_same_sense_create_one_passive_log_per_session(): void
    {
        $this->pendingSettlement('Multiple passive-eligible occurrences resolving to the same ReviewCard are deduplicated to one Good per reading session/card.');
    }

    public function test_finish_retry_is_idempotent_and_keeps_one_passive_log(): void
    {
        $this->pendingSettlement('Retrying the same finish settlement/session/request identity returns the prior result and leaves exactly one passive ReviewLog per eligible card.');
    }

    public function test_new_reading_session_can_rate_same_sense_again(): void
    {
        $this->pendingSettlement('Session idempotency is scoped to one reading session; a later distinct reading session may legitimately rate the same sense again.');
    }

    public function test_marked_unknown_or_new_sense_in_same_session_has_zero_passive_good(): void
    {
        $this->pendingSettlement('A target explicitly marked unknown, including a newly created sense from that mark, remains ineligible for passive Good in the same reading session.');
    }

    public function test_cross_user_language_or_session_replay_is_rejected_without_rating(): void
    {
        $this->pendingSettlement('Replaying another user, language, chapter/session or stale settlement identity is rejected fail-closed with zero ReviewLog/FSRS delta.');
    }

    private function pendingSettlement(string $scenario): never
    {
        $this->markTestIncomplete(
            'PENDING_INTEGRATION_RUN: Backend Core Phase B settlement seam is not present in the Lane 3 baseline. Lane 4 must execute: ' . $scenario
        );
    }
}
