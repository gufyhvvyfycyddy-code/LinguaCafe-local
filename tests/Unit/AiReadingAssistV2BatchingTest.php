<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * PAB R2 V2 50-target batching / atomic merge contract.
 *
 * PENDING_INTEGRATION_RUN: R1 freezes the behavior but not the callable
 * package/merge method signature, and the Lane 3 baseline has no V2 contract
 * service. These scenarios must be wired to Backend Core by Lane 4. A
 * test-local batching implementation is intentionally forbidden because it
 * would only prove the test helper itself.
 */
class AiReadingAssistV2BatchingTest extends TestCase
{
    public function test_20_targets_build_one_part(): void
    {
        $this->pendingBatchSeam('20 total deduplicated targets => exactly 1 part.');
    }

    public function test_49_targets_build_one_part(): void
    {
        $this->pendingBatchSeam('49 total deduplicated targets => exactly 1 part.');
    }

    public function test_50_targets_build_one_part(): void
    {
        $this->pendingBatchSeam('50 total deduplicated targets => exactly 1 part.');
    }

    public function test_51_targets_build_two_parts(): void
    {
        $this->pendingBatchSeam('51 targets => exactly 2 parts with max 50 targets in each part.');
    }

    public function test_100_targets_build_two_parts(): void
    {
        $this->pendingBatchSeam('100 targets => exactly 2 parts.');
    }

    public function test_101_targets_build_three_parts(): void
    {
        $this->pendingBatchSeam('101 targets => exactly 3 parts.');
    }

    public function test_missing_part_rejects_whole_import(): void
    {
        $this->pendingBatchSeam('A missing part_index in the expected 1..part_count set rejects the whole import with zero save/evidence delta.');
    }

    public function test_duplicate_part_rejects_whole_import(): void
    {
        $this->pendingBatchSeam('Duplicate part_index/package submission rejects the whole import rather than choosing one copy.');
    }

    public function test_duplicate_target_across_parts_is_rejected(): void
    {
        $this->pendingBatchSeam('The same occurrence_id appearing in two parts is rejected; merged target sets must be disjoint and complete.');
    }

    public function test_part_two_and_later_reject_sentence_translations(): void
    {
        $this->pendingBatchSeam('part_index >= 2 requires sentence_translations === []; only part 1 owns the full chapter translation set.');
    }

    public function test_mixed_source_revision_or_package_metadata_is_rejected(): void
    {
        $this->pendingBatchSeam('All parts must validate their authenticated package metadata and share the expected current source_revision; mixed/stale metadata fails closed.');
    }

    public function test_complete_part_set_merges_all_targets_for_one_atomic_chapter_save(): void
    {
        $this->pendingBatchSeam('After all parts parse and validate, merge in memory to the exact target set and perform one atomic chapter-assist confirm/save; no per-part partial save.');
    }

    private function pendingBatchSeam(string $assertion): never
    {
        $this->markTestIncomplete(
            'PENDING_INTEGRATION_RUN: Backend Core package/merge seam is absent from the Lane 3 baseline. Required assertion: ' . $assertion
        );
    }
}
