<?php

namespace Tests\Unit;

use App\Exceptions\CustomStudySessionStateException;
use App\Services\CustomStudy\CustomStudyCriteria;
use App\Services\CustomStudy\CustomStudySessionState;
use PHPUnit\Framework\TestCase;

/**
 * Task 2000-22 — Phase 4B SessionState eligibility extensions.
 *
 * Verifies the new available_candidate_count field (invariants 16/17) and
 * the withEligibilityResolution() same-step immutable copy boundary
 * (invariant 18).
 *
 * These tests are RED until DEV-STATE-1 lands the implementation.
 */
class CustomStudySessionStateEligibilityTest extends TestCase
{
    private function validUuidV4(): string
    {
        return '550e8400-e29b-41d4-a716-446655440000';
    }

    private function validDelayConfig(): array
    {
        return [
            'again_secs' => 60,
            'hard_secs' => 600,
            'good_secs' => 0,
            'easy_secs' => 0,
        ];
    }

    private function validCriteria(): CustomStudyCriteria
    {
        return CustomStudyCriteria::fromArray([
            'mode' => 'today_forgotten',
            'parameters' => [],
        ]);
    }

    private function validIssuedAt(): int
    {
        return 1720000000;
    }

    private function validExpiresAt(): int
    {
        return 1720000000 + 14400;
    }

    /**
     * Creates an initial state with three candidates [11, 12, 13] and
     * available_candidate_count = 5 (larger than total_count to simulate
     * card_limit truncation downstream).
     */
    private function initialStateWithThreeCandidatesAndFiveAvailable(): CustomStudySessionState
    {
        return CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11, 12, 13],
            $this->validDelayConfig(),
            5
        );
    }

    // ---------- 1. createInitial accepts available_candidate_count (10th param) ----------

    public function test_create_initial_accepts_available_candidate_count_parameter(): void
    {
        $state = $this->initialStateWithThreeCandidatesAndFiveAvailable();

        $this->assertInstanceOf(CustomStudySessionState::class, $state);
    }

    // ---------- 2. createInitial rejects negative available_candidate_count ----------

    public function test_create_initial_rejects_negative_available_candidate_count(): void
    {
        $this->expectException(CustomStudySessionStateException::class);

        CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11, 12, 13],
            $this->validDelayConfig(),
            -1
        );
    }

    // ---------- 3. createInitial rejects available_candidate_count < total_count ----------

    public function test_create_initial_rejects_available_candidate_count_less_than_total_count(): void
    {
        $this->expectException(CustomStudySessionStateException::class);

        // total_count = 3 (three candidates), available_candidate_count = 2 < 3 → invariant 17 violation
        CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11, 12, 13],
            $this->validDelayConfig(),
            2
        );
    }

    // ---------- 4. createInitial stores available_candidate_count ----------

    public function test_create_initial_stores_available_candidate_count(): void
    {
        $state = $this->initialStateWithThreeCandidatesAndFiveAvailable();

        $this->assertSame(5, $state->availableCandidateCount());
    }

    // ---------- 5. toArray() includes available_candidate_count ----------

    public function test_to_array_includes_available_candidate_count(): void
    {
        $state = $this->initialStateWithThreeCandidatesAndFiveAvailable();
        $array = $state->toArray();

        $this->assertArrayHasKey('available_candidate_count', $array);
        $this->assertSame(5, $array['available_candidate_count']);
    }

    // ---------- 6. fromArray() defaults available_candidate_count to total_count when absent ----------

    public function test_from_array_defaults_available_candidate_count_to_total_count_when_absent(): void
    {
        $payload = [
            'version' => 1,
            'user_id' => 42,
            'language' => 'en',
            'mode' => 'today_forgotten',
            'parameters' => [],
            'session_id' => $this->validUuidV4(),
            'issued_at' => $this->validIssuedAt(),
            'expires_at' => $this->validExpiresAt(),
            'ordered_candidate_ids' => [11, 12, 13],
            'ready_queue' => [12, 13],
            'delayed_repeat_queue' => [],
            'completed_ids' => [],
            'skipped_ineligible_ids' => [],
            'completed_count' => 0,
            'total_count' => 3,
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
            // NOTE: available_candidate_count intentionally OMITTED
        ];

        $state = CustomStudySessionState::fromArray($payload);

        // When the key is absent, fromArray() defaults to total_count (backward compat
        // for tokens issued before the field was added — old tokens are client-obsolete,
        // not server-revoked).
        $this->assertSame(3, $state->availableCandidateCount());
    }

    // ---------- 7. fromArray() rejects negative available_candidate_count ----------

    public function test_from_array_rejects_negative_available_candidate_count(): void
    {
        $this->expectException(CustomStudySessionStateException::class);

        CustomStudySessionState::fromArray([
            'version' => 1,
            'user_id' => 42,
            'language' => 'en',
            'mode' => 'today_forgotten',
            'parameters' => [],
            'session_id' => $this->validUuidV4(),
            'issued_at' => $this->validIssuedAt(),
            'expires_at' => $this->validExpiresAt(),
            'ordered_candidate_ids' => [11, 12, 13],
            'ready_queue' => [12, 13],
            'delayed_repeat_queue' => [],
            'completed_ids' => [],
            'skipped_ineligible_ids' => [],
            'completed_count' => 0,
            'total_count' => 3,
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
            'available_candidate_count' => -1,
        ]);
    }

    // ---------- 8. fromArray() rejects available_candidate_count < total_count ----------

    public function test_from_array_rejects_available_candidate_count_less_than_total_count(): void
    {
        $this->expectException(CustomStudySessionStateException::class);

        CustomStudySessionState::fromArray([
            'version' => 1,
            'user_id' => 42,
            'language' => 'en',
            'mode' => 'today_forgotten',
            'parameters' => [],
            'session_id' => $this->validUuidV4(),
            'issued_at' => $this->validIssuedAt(),
            'expires_at' => $this->validExpiresAt(),
            'ordered_candidate_ids' => [11, 12, 13],
            'ready_queue' => [12, 13],
            'delayed_repeat_queue' => [],
            'completed_ids' => [],
            'skipped_ineligible_ids' => [],
            'completed_count' => 0,
            'total_count' => 3,
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
            'available_candidate_count' => 2, // < total_count (3) → invariant 17 violation
        ]);
    }

    // ---------- 9. fromArray() round-trips available_candidate_count ----------

    public function test_from_array_round_trips_available_candidate_count(): void
    {
        $original = $this->initialStateWithThreeCandidatesAndFiveAvailable();
        $array = $original->toArray();
        $reconstructed = CustomStudySessionState::fromArray($array);

        $this->assertSame(5, $reconstructed->availableCandidateCount());
    }

    // ---------- 10. availableCandidateCount() getter returns the stored value ----------

    public function test_available_candidate_count_getter_returns_stored_value(): void
    {
        $state = $this->initialStateWithThreeCandidatesAndFiveAvailable();

        $this->assertSame(5, $state->availableCandidateCount());
    }

    // ---------- 11. withProgress() preserves available_candidate_count (invariant 16) ----------

    public function test_with_progress_preserves_available_candidate_count(): void
    {
        $state = $this->initialStateWithThreeCandidatesAndFiveAvailable();
        $next = $state->withProgress(12, [13], [], [11], []);

        $this->assertSame(5, $next->availableCandidateCount());
    }

    // ---------- 12. withEligibilityResolution() method exists ----------

    public function test_with_eligibility_resolution_method_exists(): void
    {
        $state = $this->initialStateWithThreeCandidatesAndFiveAvailable();

        $this->assertTrue(
            method_exists($state, 'withEligibilityResolution'),
            'CustomStudySessionState must expose withEligibilityResolution() method.'
        );
    }

    // ---------- 13. withEligibilityResolution() does NOT increment step (invariant 18) ----------

    public function test_with_eligibility_resolution_does_not_increment_step(): void
    {
        $state = $this->initialStateWithThreeCandidatesAndFiveAvailable();
        $this->assertSame(0, $state->step());

        // Move card 12 from ready to skipped_ineligible (simulating eligibility failure).
        $next = $state->withEligibilityResolution(
            11,           // current_card_id unchanged
            [13],         // ready_queue: 12 removed
            [],           // delayed_repeat_queue unchanged
            [],           // completed_ids unchanged
            [12]          // skipped_ineligible_ids: 12 added
        );

        $this->assertSame(0, $next->step(), 'withEligibilityResolution must NOT increment step.');
    }

    // ---------- 14. withEligibilityResolution() preserves available_candidate_count (invariant 18) ----------

    public function test_with_eligibility_resolution_preserves_available_candidate_count(): void
    {
        $state = $this->initialStateWithThreeCandidatesAndFiveAvailable();
        $this->assertSame(5, $state->availableCandidateCount());

        $next = $state->withEligibilityResolution(
            11,
            [13],
            [],
            [],
            [12]
        );

        $this->assertSame(
            5,
            $next->availableCandidateCount(),
            'withEligibilityResolution must preserve available_candidate_count.'
        );
    }

    // ---------- 15. withEligibilityResolution() preserves identity fields (invariant 18) ----------

    public function test_with_eligibility_resolution_preserves_identity_fields(): void
    {
        $state = $this->initialStateWithThreeCandidatesAndFiveAvailable();

        $next = $state->withEligibilityResolution(
            11,
            [13],
            [],
            [],
            [12]
        );

        // Identity fields that MUST be preserved
        $this->assertSame($state->version(), $next->version());
        $this->assertSame($state->userId(), $next->userId());
        $this->assertSame($state->language(), $next->language());
        $this->assertSame($state->mode(), $next->mode());
        $this->assertSame($state->parameters(), $next->parameters());
        $this->assertSame($state->sessionId(), $next->sessionId());
        $this->assertSame($state->issuedAt(), $next->issuedAt());
        $this->assertSame($state->expiresAt(), $next->expiresAt());
        $this->assertSame($state->orderedCandidateIds(), $next->orderedCandidateIds());
        $this->assertSame($state->previewDelayConfig(), $next->previewDelayConfig());
        $this->assertSame($state->step(), $next->step());
        $this->assertSame($state->availableCandidateCount(), $next->availableCandidateCount());

        // The new state has the updated five-state
        $this->assertSame([12], $next->skippedIneligibleIds());
        $this->assertSame([13], $next->readyQueue());
    }
}
