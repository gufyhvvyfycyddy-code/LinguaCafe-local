<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * PAB R2 Phase A DB write-boundary acceptance contract.
 *
 * PENDING_INTEGRATION_RUN by design. The Safety Harness lane is forbidden to
 * run shared-testing-DB Feature tests, and its baseline predates the Backend
 * Core V2 manifest/evidence public seam. These named scenarios must be wired
 * to the concrete Backend Core seam by Lane 4 without weakening the stated
 * assertions. Marking them incomplete is deliberate and visible; they are not
 * reported as GREEN.
 */
class AiReadingAssistV2WriteBoundaryTest extends TestCase
{
    public function test_v2_preview_has_zero_business_writes(): void
    {
        $this->pendingBackendCoreSeam(
            'Capture exact before/after counts for ChapterAiReadingAssist, V2 evidence, WordSense, ReviewCard, ReviewLog and EncounteredWord around a valid V2 preview; every delta must be zero.'
        );
    }

    public function test_v2_invalid_confirm_has_zero_partial_writes(): void
    {
        $this->pendingBackendCoreSeam(
            'Submit an invalid/stale/mismatched V2 part set to confirm; assert zero partial assist/evidence/WordSense/ReviewCard/ReviewLog/EncounteredWord writes.'
        );
    }

    public function test_trust_ai_high_matched_existing_writes_evidence_only(): void
    {
        $this->pendingBackendCoreSeam(
            'For a server-issued high-confidence matched_existing result, assert exactly one trust_ai binding evidence delta, ReviewLog delta=0 and existing ReviewCard FSRS snapshot unchanged.'
        );
    }

    public function test_medium_low_ambiguous_and_new_sense_do_not_auto_bind_or_rate(): void
    {
        $this->pendingBackendCoreSeam(
            'Exercise medium/low matched_existing plus high ambiguous/new_sense; assert no automatic binding evidence and no ReviewLog/FSRS rating.'
        );
    }

    public function test_user_evidence_takes_precedence_over_trust_ai(): void
    {
        $this->pendingBackendCoreSeam(
            'Create trust_ai evidence, then a valid user correction; assert resolution_source becomes user and the user-selected owned confirmed sense is authoritative.'
        );
    }

    public function test_reimport_cannot_overwrite_existing_user_evidence(): void
    {
        $this->pendingBackendCoreSeam(
            'After user evidence exists, re-import a conflicting valid high AI match; assert user evidence remains unchanged and no rating is created.'
        );
    }

    public function test_phase_a_preserves_full_existing_review_card_fsrs_snapshot_and_review_log_count(): void
    {
        $this->pendingBackendCoreSeam(
            'Capture ReviewCardFsrsSnapshotService::capture() and ReviewLog count before trust/manual confirmation; assert the complete scheduling snapshot and ReviewLog count are byte-for-byte/equivalent unchanged afterward.'
        );
    }

    public function test_v2_never_reuses_legacy_numeric_confidence_auto_fsrs_path(): void
    {
        $this->pendingBackendCoreSeam(
            'Seed legacy WordSenseOccurrence auto_fsrs_allowed/numeric confidence conditions, then run V2 trust flow; assert bulkConfirmHighConfidence/legacy threshold behavior creates no card activation, ReviewLog or FSRS mutation.'
        );
    }

    private function pendingBackendCoreSeam(string $scenario): never
    {
        $this->markTestIncomplete(
            'PENDING_INTEGRATION_RUN: Backend Core V2 manifest/evidence seam is not present in the Lane 3 baseline. Lane 4 must bind this scenario to the concrete seam. Required assertion: ' . $scenario
        );
    }
}
