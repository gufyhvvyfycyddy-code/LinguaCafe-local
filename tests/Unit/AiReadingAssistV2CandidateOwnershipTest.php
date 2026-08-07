<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * PAB R2 V2 candidate-ownership contract.
 *
 * PENDING_INTEGRATION_RUN: the R1 architecture report freezes the ownership
 * semantics but does not freeze a callable candidate-validation method
 * signature. The Lane 3 baseline has no AiReadingAssistV2ContractService.
 * Lane 4 must bind these named tests to the concrete Backend Core seam after
 * merge; inventing a test-local validator here would create a false GREEN.
 */
class AiReadingAssistV2CandidateOwnershipTest extends TestCase
{
    public function test_matched_existing_accepts_owned_confirmed_candidate(): void
    {
        $this->pendingCandidateSeam('Accept only an ID in this occurrence server-issued candidate set that still belongs to the current user + language and has confirmed status.');
    }

    public function test_matched_existing_rejects_null_id(): void
    {
        $this->pendingCandidateSeam('matched_existing with matched_word_sense_id=null must fail closed.');
    }

    public function test_matched_existing_rejects_candidate_outside_server_set(): void
    {
        $this->pendingCandidateSeam('An otherwise owned confirmed WordSense that was not in this occurrence server-issued candidate set must be rejected.');
    }

    public function test_matched_existing_rejects_other_user_candidate(): void
    {
        $this->pendingCandidateSeam('A WordSense owned by another user must be rejected even if its numeric ID appears in the AI payload.');
    }

    public function test_matched_existing_rejects_other_language_candidate(): void
    {
        $this->pendingCandidateSeam('A WordSense from another language must be rejected.');
    }

    public function test_matched_existing_rejects_non_confirmed_candidate(): void
    {
        $this->pendingCandidateSeam('Rejected/ai_suggested/non-confirmed WordSense status must be rejected at preview/confirm time.');
    }

    public function test_matched_existing_rejects_new_sense_payload(): void
    {
        $this->pendingCandidateSeam('matched_existing must have new_sense=null.');
    }

    public function test_new_sense_rejects_matched_id(): void
    {
        $this->pendingCandidateSeam('new_sense must have matched_word_sense_id=null and a complete new_sense object.');
    }

    public function test_ambiguous_rejects_matched_id(): void
    {
        $this->pendingCandidateSeam('ambiguous must have matched_word_sense_id=null and new_sense=null.');
    }

    public function test_phrase_rejects_word_sense_resolution_for_passive_path(): void
    {
        $this->pendingCandidateSeam('Phrase results must never carry WordSense resolution or become passive-Good evidence.');
    }

    private function pendingCandidateSeam(string $assertion): never
    {
        $this->markTestIncomplete(
            'PENDING_INTEGRATION_RUN: Backend Core candidate/manifest validation seam is absent from the Lane 3 baseline. Required assertion: ' . $assertion
        );
    }
}
