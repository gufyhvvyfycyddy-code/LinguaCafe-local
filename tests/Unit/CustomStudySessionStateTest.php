<?php

namespace Tests\Unit;

use App\Exceptions\CustomStudySessionStateException;
use App\Services\CustomStudy\CustomStudyCriteria;
use App\Services\CustomStudy\CustomStudySessionState;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for CustomStudySessionState immutable value object.
 *
 * Pure unit tests — no Laravel container, no DB, no Auth, no Request, no Crypt.
 *
 * Verifies:
 * - createInitial() factory behavior (non-empty + empty candidates).
 * - fromArray() / toArray() round-trip.
 * - Immutability (no setter, input/getter/toArray isolation).
 * - Five-state union + mutual exclusion invariants (current / ready / delayed /
 *   completed / skipped_ineligible).
 * - completed_count === count(completed_ids).
 * - total_count === count(ordered_candidate_ids).
 * - Time fields as UTC Unix seconds.
 * - session_id strict UUID v4.
 * - step non-negative integer.
 * - preview_delay_config validation.
 * - 500 candidates accepted, 501 rejected.
 * - Pure value object guards (no DB / Auth / Request / Crypt / ReviewLog / FSRS / AI).
 * - No answer / rate / resume / nextCard / transition / rotate methods.
 *
 * Task 2000-19 — Custom Study 1A Phase 3A.
 */
class CustomStudySessionStateTest extends TestCase
{
    // ---------- helpers ----------

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

    // ---------- 1. Non-empty initial state ----------

    public function test_create_initial_with_non_empty_candidates(): void
    {
        $state = CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11, 12, 13],
            $this->validDelayConfig()
        );

        $this->assertSame(1, $state->version());
        $this->assertSame(42, $state->userId());
        $this->assertSame('en', $state->language());
        $this->assertSame('today_forgotten', $state->mode());
        $this->assertSame([], $state->parameters());
        $this->assertSame($this->validUuidV4(), $state->sessionId());
        $this->assertSame($this->validIssuedAt(), $state->issuedAt());
        $this->assertSame($this->validExpiresAt(), $state->expiresAt());
    }

    // ---------- 2. Empty initial state ----------

    public function test_create_initial_with_empty_candidates(): void
    {
        $state = CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [],
            $this->validDelayConfig()
        );

        $this->assertSame(null, $state->currentCardId());
        $this->assertSame([], $state->readyQueue());
        $this->assertSame([], $state->delayedRepeatQueue());
        $this->assertSame([], $state->completedIds());
        $this->assertSame([], $state->skippedIneligibleIds());
        $this->assertSame(0, $state->completedCount());
        $this->assertSame(0, $state->totalCount());
        $this->assertSame([], $state->orderedCandidateIds());
    }

    // ---------- 3. First card popped as current ----------

    public function test_create_initial_pops_first_card_as_current(): void
    {
        $state = CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11, 12, 13],
            $this->validDelayConfig()
        );

        $this->assertSame(11, $state->currentCardId());
    }

    // ---------- 4. Ready queue preserves remaining order ----------

    public function test_create_initial_ready_queue_preserves_remaining_order(): void
    {
        $state = CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11, 12, 13],
            $this->validDelayConfig()
        );

        $this->assertSame([12, 13], $state->readyQueue());
    }

    // ---------- 5. completed/skipped initially empty ----------

    public function test_create_initial_completed_and_skipped_are_empty(): void
    {
        $state = CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11, 12, 13],
            $this->validDelayConfig()
        );

        $this->assertSame([], $state->completedIds());
        $this->assertSame([], $state->skippedIneligibleIds());
    }

    // ---------- 6. total_count ----------

    public function test_total_count_matches_ordered_candidate_count(): void
    {
        $state = CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11, 12, 13],
            $this->validDelayConfig()
        );

        $this->assertSame(3, $state->totalCount());
    }

    // ---------- 7. completed_count ----------

    public function test_completed_count_is_zero_on_initial_state(): void
    {
        $state = CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11, 12, 13],
            $this->validDelayConfig()
        );

        $this->assertSame(0, $state->completedCount());
    }

    // ---------- 8. Valid round-trip ----------

    public function test_from_array_to_array_round_trip(): void
    {
        $original = CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11, 12, 13],
            $this->validDelayConfig()
        );

        $payload = $original->toArray();
        $restored = CustomStudySessionState::fromArray($payload);

        $this->assertSame($original->toArray(), $restored->toArray());
    }

    public function test_round_trip_preserves_all_fields(): void
    {
        $original = CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11, 12, 13],
            $this->validDelayConfig()
        );

        $payload = $original->toArray();

        $this->assertSame(1, $payload['version']);
        $this->assertSame(42, $payload['user_id']);
        $this->assertSame('en', $payload['language']);
        $this->assertSame('today_forgotten', $payload['mode']);
        $this->assertSame([], $payload['parameters']);
        $this->assertSame($this->validUuidV4(), $payload['session_id']);
        $this->assertSame($this->validIssuedAt(), $payload['issued_at']);
        $this->assertSame($this->validExpiresAt(), $payload['expires_at']);
        $this->assertSame([11, 12, 13], $payload['ordered_candidate_ids']);
        $this->assertSame(11, $payload['current_card_id']);
        $this->assertSame([12, 13], $payload['ready_queue']);
        $this->assertSame([], $payload['delayed_repeat_queue']);
        $this->assertSame([], $payload['completed_ids']);
        $this->assertSame([], $payload['skipped_ineligible_ids']);
        $this->assertSame(0, $payload['completed_count']);
        $this->assertSame(3, $payload['total_count']);
        $this->assertSame(0, $payload['step']);
        $this->assertSame($this->validDelayConfig(), $payload['preview_delay_config']);
    }

    // ---------- 9. Immutable input ----------

    public function test_modifying_input_array_after_create_initial_does_not_change_state(): void
    {
        $candidates = [11, 12, 13];
        $delayConfig = $this->validDelayConfig();

        $state = CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            $candidates,
            $delayConfig
        );

        // Mutate the original arrays
        $candidates[] = 999;
        $delayConfig['again_secs'] = 9999;

        $this->assertSame([11, 12, 13], $state->orderedCandidateIds());
        $this->assertSame(60, $state->previewDelayConfig()['again_secs']);
    }

    public function test_modifying_input_array_after_from_array_does_not_change_state(): void
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
        ];

        $state = CustomStudySessionState::fromArray($payload);

        // Mutate the original payload
        $payload['ordered_candidate_ids'][] = 999;
        $payload['completed_count'] = 999;

        $this->assertSame([11, 12, 13], $state->orderedCandidateIds());
        $this->assertSame(0, $state->completedCount());
    }

    // ---------- 10. Immutable getter ----------

    public function test_modifying_getter_return_array_does_not_change_state(): void
    {
        $state = CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11, 12, 13],
            $this->validDelayConfig()
        );

        $ready = $state->readyQueue();
        $ready[] = 999;

        $this->assertSame([12, 13], $state->readyQueue());

        $ordered = $state->orderedCandidateIds();
        $ordered[] = 888;

        $this->assertSame([11, 12, 13], $state->orderedCandidateIds());
    }

    // ---------- 11. Immutable toArray ----------

    public function test_modifying_to_array_return_does_not_change_state(): void
    {
        $state = CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11, 12, 13],
            $this->validDelayConfig()
        );

        $payload = $state->toArray();
        $payload['completed_count'] = 999;
        $payload['ordered_candidate_ids'][] = 888;

        $this->assertSame(0, $state->completedCount());
        $this->assertSame([11, 12, 13], $state->orderedCandidateIds());
    }

    // ---------- 12. Invalid version ----------

    public function test_rejects_zero_version(): void
    {
        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::createInitial(
            0,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11],
            $this->validDelayConfig()
        );
    }

    public function test_rejects_negative_version(): void
    {
        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::createInitial(
            -1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11],
            $this->validDelayConfig()
        );
    }

    // ---------- 13. Invalid user ----------

    public function test_rejects_zero_user_id(): void
    {
        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::createInitial(
            1,
            0,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11],
            $this->validDelayConfig()
        );
    }

    public function test_rejects_negative_user_id(): void
    {
        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::createInitial(
            1,
            -5,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11],
            $this->validDelayConfig()
        );
    }

    // ---------- 14. Empty language ----------

    public function test_rejects_empty_language(): void
    {
        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::createInitial(
            1,
            42,
            '',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11],
            $this->validDelayConfig()
        );
    }

    public function test_rejects_whitespace_only_language(): void
    {
        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::createInitial(
            1,
            42,
            '   ',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11],
            $this->validDelayConfig()
        );
    }

    // ---------- 15. Unknown mode ----------

    public function test_rejects_unknown_mode_in_from_array(): void
    {
        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::fromArray([
            'version' => 1,
            'user_id' => 42,
            'language' => 'en',
            'mode' => 'unknown_marker_mode',
            'parameters' => [],
            'session_id' => $this->validUuidV4(),
            'issued_at' => $this->validIssuedAt(),
            'expires_at' => $this->validExpiresAt(),
            'ordered_candidate_ids' => [11],
            'ready_queue' => [],
            'delayed_repeat_queue' => [],
            'completed_ids' => [],
            'skipped_ineligible_ids' => [],
            'completed_count' => 0,
            'total_count' => 1,
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);
    }

    // ---------- 16. Invalid criteria parameters ----------

    public function test_rejects_source_chapter_with_missing_chapter_id_in_from_array(): void
    {
        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::fromArray([
            'version' => 1,
            'user_id' => 42,
            'language' => 'en',
            'mode' => 'source_chapter',
            'parameters' => [],
            'session_id' => $this->validUuidV4(),
            'issued_at' => $this->validIssuedAt(),
            'expires_at' => $this->validExpiresAt(),
            'ordered_candidate_ids' => [11],
            'ready_queue' => [],
            'delayed_repeat_queue' => [],
            'completed_ids' => [],
            'skipped_ineligible_ids' => [],
            'completed_count' => 0,
            'total_count' => 1,
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);
    }

    public function test_rejects_leech_attention_with_invalid_sub_mode_in_from_array(): void
    {
        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::fromArray([
            'version' => 1,
            'user_id' => 42,
            'language' => 'en',
            'mode' => 'leech_attention',
            'parameters' => ['sub_mode' => 'all'],
            'session_id' => $this->validUuidV4(),
            'issued_at' => $this->validIssuedAt(),
            'expires_at' => $this->validExpiresAt(),
            'ordered_candidate_ids' => [11],
            'ready_queue' => [],
            'delayed_repeat_queue' => [],
            'completed_ids' => [],
            'skipped_ineligible_ids' => [],
            'completed_count' => 0,
            'total_count' => 1,
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);
    }

    // ---------- 17. Invalid UUID ----------

    public function test_rejects_non_uuid_session_id(): void
    {
        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            'not-a-uuid',
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11],
            $this->validDelayConfig()
        );
    }

    public function test_rejects_malformed_uuid_session_id(): void
    {
        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            '550e8400-e29b-41d4-a716',
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11],
            $this->validDelayConfig()
        );
    }

    // ---------- 18. Non-v4 UUID ----------

    public function test_rejects_uuid_v1_session_id(): void
    {
        // UUID v1: third group starts with '1'
        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            '550e8400-e29b-11d4-a716-446655440000',
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11],
            $this->validDelayConfig()
        );
    }

    public function test_rejects_uuid_v3_session_id(): void
    {
        // UUID v3: third group starts with '3'
        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            '550e8400-e29b-31d4-a716-446655440000',
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11],
            $this->validDelayConfig()
        );
    }

    public function test_rejects_uuid_v5_session_id(): void
    {
        // UUID v5: third group starts with '5'
        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            '550e8400-e29b-51d4-a716-446655440000',
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11],
            $this->validDelayConfig()
        );
    }

    // ---------- 19. Invalid issued/expires ----------

    public function test_rejects_zero_issued_at(): void
    {
        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            0,
            $this->validExpiresAt(),
            [11],
            $this->validDelayConfig()
        );
    }

    public function test_rejects_negative_issued_at(): void
    {
        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            -1,
            $this->validExpiresAt(),
            [11],
            $this->validDelayConfig()
        );
    }

    public function test_rejects_zero_expires_at(): void
    {
        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            0,
            [11],
            $this->validDelayConfig()
        );
    }

    // ---------- 20. expires <= issued ----------

    public function test_rejects_expires_equal_to_issued(): void
    {
        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            1720000000,
            1720000000,
            [11],
            $this->validDelayConfig()
        );
    }

    public function test_rejects_expires_before_issued(): void
    {
        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            1720000000,
            1719999999,
            [11],
            $this->validDelayConfig()
        );
    }

    // ---------- 21. 500 candidates accepted ----------

    public function test_accepts_500_candidates(): void
    {
        $candidates = range(1, 500);

        $state = CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            $candidates,
            $this->validDelayConfig()
        );

        $this->assertSame(500, $state->totalCount());
        $this->assertSame(1, $state->currentCardId());
        $this->assertSame(range(2, 500), $state->readyQueue());
    }

    // ---------- 22. 501 candidates rejected ----------

    public function test_rejects_501_candidates(): void
    {
        $candidates = range(1, 501);

        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            $candidates,
            $this->validDelayConfig()
        );
    }

    // ---------- 23. Negative ID rejected ----------

    public function test_rejects_negative_candidate_id(): void
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
            [11, -12, 13],
            $this->validDelayConfig()
        );
    }

    public function test_rejects_zero_candidate_id(): void
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
            [11, 0, 13],
            $this->validDelayConfig()
        );
    }

    public function test_rejects_non_integer_candidate_id(): void
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
            [11, '12', 13],
            $this->validDelayConfig()
        );
    }

    // ---------- 24. Duplicate ordered ID rejected ----------

    public function test_rejects_duplicate_ordered_candidate_id(): void
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
            [11, 12, 11],
            $this->validDelayConfig()
        );
    }

    // ---------- 25. Current overlap rejected ----------

    public function test_rejects_current_card_in_ready_queue(): void
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
            'ready_queue' => [11, 12, 13],
            'delayed_repeat_queue' => [],
            'completed_ids' => [],
            'skipped_ineligible_ids' => [],
            'completed_count' => 0,
            'total_count' => 3,
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);
    }

    public function test_rejects_current_card_in_completed_ids(): void
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
            'completed_ids' => [11],
            'skipped_ineligible_ids' => [],
            'completed_count' => 1,
            'total_count' => 3,
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);
    }

    // ---------- 26. Ready overlap rejected ----------

    public function test_rejects_ready_queue_overlap_with_delayed(): void
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
            'delayed_repeat_queue' => [
                ['card_id' => 12, 'available_at' => $this->validIssuedAt() + 60],
            ],
            'completed_ids' => [],
            'skipped_ineligible_ids' => [],
            'completed_count' => 0,
            'total_count' => 3,
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);
    }

    // ---------- 27. Delayed overlap rejected ----------

    public function test_rejects_delayed_overlap_with_completed(): void
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
            'ready_queue' => [13],
            'delayed_repeat_queue' => [
                ['card_id' => 12, 'available_at' => $this->validIssuedAt() + 60],
            ],
            'completed_ids' => [12],
            'skipped_ineligible_ids' => [],
            'completed_count' => 1,
            'total_count' => 3,
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);
    }

    // ---------- 28. Completed overlap rejected ----------

    public function test_rejects_completed_overlap_with_skipped(): void
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
            'ready_queue' => [13],
            'delayed_repeat_queue' => [],
            'completed_ids' => [12],
            'skipped_ineligible_ids' => [12],
            'completed_count' => 1,
            'total_count' => 3,
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);
    }

    // ---------- 29. Skipped overlap rejected ----------

    public function test_rejects_skipped_overlap_with_ready(): void
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
            'skipped_ineligible_ids' => [13],
            'completed_count' => 0,
            'total_count' => 3,
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);
    }

    // ---------- 30. Unknown state ID rejected ----------

    public function test_rejects_unknown_id_in_ready_queue(): void
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
            'ready_queue' => [12, 99],
            'delayed_repeat_queue' => [],
            'completed_ids' => [],
            'skipped_ineligible_ids' => [],
            'completed_count' => 0,
            'total_count' => 3,
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);
    }

    public function test_rejects_unknown_id_in_completed_ids(): void
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
            'completed_ids' => [99],
            'skipped_ineligible_ids' => [],
            'completed_count' => 1,
            'total_count' => 3,
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);
    }

    // ---------- 31. Lost ordered ID rejected ----------

    public function test_rejects_lost_ordered_id_not_in_any_state(): void
    {
        // Card 13 is in ordered but not in any of the five states
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
            'ready_queue' => [12],
            'delayed_repeat_queue' => [],
            'completed_ids' => [],
            'skipped_ineligible_ids' => [],
            'completed_count' => 0,
            'total_count' => 3,
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);
    }

    // ---------- 32. Delayed malformed rejected ----------

    public function test_rejects_delayed_item_missing_card_id(): void
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
            'ready_queue' => [13],
            'delayed_repeat_queue' => [
                ['available_at' => $this->validIssuedAt() + 60],
            ],
            'completed_ids' => [],
            'skipped_ineligible_ids' => [],
            'completed_count' => 0,
            'total_count' => 3,
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);
    }

    public function test_rejects_delayed_item_missing_available_at(): void
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
            'ready_queue' => [13],
            'delayed_repeat_queue' => [
                ['card_id' => 12],
            ],
            'completed_ids' => [],
            'skipped_ineligible_ids' => [],
            'completed_count' => 0,
            'total_count' => 3,
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);
    }

    public function test_rejects_delayed_item_with_duplicate_card_id(): void
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
            'ready_queue' => [13],
            'delayed_repeat_queue' => [
                ['card_id' => 12, 'available_at' => $this->validIssuedAt() + 60],
                ['card_id' => 12, 'available_at' => $this->validIssuedAt() + 120],
            ],
            'completed_ids' => [],
            'skipped_ineligible_ids' => [],
            'completed_count' => 0,
            'total_count' => 3,
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);
    }

    public function test_rejects_delayed_item_with_non_positive_available_at(): void
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
            'ready_queue' => [13],
            'delayed_repeat_queue' => [
                ['card_id' => 12, 'available_at' => 0],
            ],
            'completed_ids' => [],
            'skipped_ineligible_ids' => [],
            'completed_count' => 0,
            'total_count' => 3,
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);
    }

    // ---------- 33. completed_count mismatch rejected ----------

    public function test_rejects_completed_count_mismatch(): void
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
            'ready_queue' => [13],
            'delayed_repeat_queue' => [],
            'completed_ids' => [12],
            'skipped_ineligible_ids' => [],
            'completed_count' => 5, // mismatch: should be 1
            'total_count' => 3,
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);
    }

    // ---------- 34. total_count mismatch rejected ----------

    public function test_rejects_total_count_mismatch(): void
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
            'total_count' => 99, // mismatch: should be 3
            'current_card_id' => 11,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);
    }

    // ---------- 35. Negative step rejected ----------

    public function test_rejects_negative_step(): void
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
            'step' => -1,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);
    }

    public function test_rejects_non_integer_step(): void
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
            'step' => '0',
            'preview_delay_config' => $this->validDelayConfig(),
        ]);
    }

    // ---------- 36. Invalid delay config rejected ----------

    public function test_rejects_delay_config_missing_again_secs(): void
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
            [11],
            ['hard_secs' => 600, 'good_secs' => 0, 'easy_secs' => 0]
        );
    }

    public function test_rejects_delay_config_with_negative_value(): void
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
            [11],
            ['again_secs' => -60, 'hard_secs' => 600, 'good_secs' => 0, 'easy_secs' => 0]
        );
    }

    public function test_rejects_delay_config_with_non_integer_value(): void
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
            [11],
            ['again_secs' => '60', 'hard_secs' => 600, 'good_secs' => 0, 'easy_secs' => 0]
        );
    }

    // ---------- 37. Pure / no DB / Auth / Request / Crypt ----------

    public function test_state_class_does_not_use_db_facade(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Services/CustomStudy/CustomStudySessionState.php');
        $this->assertStringNotContainsString('Illuminate\Support\Facades\DB', $source);
        $this->assertStringNotContainsString('Illuminate\Database', $source);
    }

    public function test_state_class_does_not_use_auth_facade(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Services/CustomStudy/CustomStudySessionState.php');
        $this->assertStringNotContainsString('Illuminate\Support\Facades\Auth', $source);
        $this->assertStringNotContainsString('Auth::', $source);
    }

    public function test_state_class_does_not_use_request_facade(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Services/CustomStudy/CustomStudySessionState.php');
        $this->assertStringNotContainsString('Illuminate\Http\Request', $source);
        $this->assertStringNotContainsString('Request::', $source);
    }

    public function test_state_class_does_not_use_crypt_facade(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Services/CustomStudy/CustomStudySessionState.php');
        $this->assertStringNotContainsString('Illuminate\Support\Facades\Crypt', $source);
        $this->assertStringNotContainsString('Crypt::', $source);
        $this->assertStringNotContainsString('Encrypter', $source);
    }

    public function test_state_class_does_not_use_review_log(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Services/CustomStudy/CustomStudySessionState.php');
        $this->assertStringNotContainsString('ReviewLog', $source);
    }

    public function test_state_class_does_not_use_fsrs(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Services/CustomStudy/CustomStudySessionState.php');
        $this->assertStringNotContainsString('FSRS', $source);
        $this->assertStringNotContainsString('Fsrs', $source);
    }

    public function test_state_class_does_not_use_ai(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Services/CustomStudy/CustomStudySessionState.php');
        $this->assertStringNotContainsString('OpenAI', $source);
        $this->assertStringNotContainsString('openai', $source);
    }

    // ---------- No forbidden methods (answer/rate/resume/nextCard/transition/rotate) ----------

    public function test_state_class_does_not_have_answer_method(): void
    {
        $this->assertFalse(
            method_exists(CustomStudySessionState::class, 'answer'),
            'CustomStudySessionState must not have answer() — belongs to Phase 4 PreviewPolicy.'
        );
    }

    public function test_state_class_does_not_have_rate_method(): void
    {
        $this->assertFalse(
            method_exists(CustomStudySessionState::class, 'rate'),
            'CustomStudySessionState must not have rate() — belongs to Phase 4 PreviewPolicy.'
        );
    }

    public function test_state_class_does_not_have_apply_rating_method(): void
    {
        $this->assertFalse(
            method_exists(CustomStudySessionState::class, 'applyRating'),
            'CustomStudySessionState must not have applyRating() — belongs to Phase 4 PreviewPolicy.'
        );
    }

    public function test_state_class_does_not_have_resume_method(): void
    {
        $this->assertFalse(
            method_exists(CustomStudySessionState::class, 'resume'),
            'CustomStudySessionState must not have resume() — belongs to Phase 4 SessionService.'
        );
    }

    public function test_state_class_does_not_have_next_card_method(): void
    {
        $this->assertFalse(
            method_exists(CustomStudySessionState::class, 'nextCard'),
            'CustomStudySessionState must not have nextCard() — belongs to Phase 4 SessionService.'
        );
    }

    public function test_state_class_does_not_have_transition_method(): void
    {
        $this->assertFalse(
            method_exists(CustomStudySessionState::class, 'transition'),
            'CustomStudySessionState must not have transition() — belongs to Phase 4 PreviewPolicy.'
        );
    }

    public function test_state_class_does_not_have_rotate_method(): void
    {
        $this->assertFalse(
            method_exists(CustomStudySessionState::class, 'rotate'),
            'CustomStudySessionState must not have rotate() — belongs to Phase 4 SessionService.'
        );
    }

    // ---------- No setter methods ----------

    public function test_state_class_has_no_public_setter_methods(): void
    {
        $reflection = new ReflectionClass(CustomStudySessionState::class);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        $setters = array_filter($methods, function ($method) {
            return strpos($method->getName(), 'set') === 0;
        });

        $this->assertSame([], $setters, 'CustomStudySessionState must not have any public setter methods.');
    }

    // ---------- All four criteria modes work via createInitial ----------

    public function test_create_initial_with_source_chapter_criteria(): void
    {
        $criteria = CustomStudyCriteria::fromArray([
            'mode' => 'source_chapter',
            'parameters' => ['chapter_id' => 42],
        ]);

        $state = CustomStudySessionState::createInitial(
            1,
            1,
            'en',
            $criteria,
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11, 12],
            $this->validDelayConfig()
        );

        $this->assertSame('source_chapter', $state->mode());
        $this->assertSame(['chapter_id' => 42], $state->parameters());
    }

    public function test_create_initial_with_overdue_criteria(): void
    {
        $criteria = CustomStudyCriteria::fromArray([
            'mode' => 'overdue',
            'parameters' => [],
        ]);

        $state = CustomStudySessionState::createInitial(
            1,
            1,
            'en',
            $criteria,
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11],
            $this->validDelayConfig()
        );

        $this->assertSame('overdue', $state->mode());
    }

    public function test_create_initial_with_leech_attention_criteria(): void
    {
        $criteria = CustomStudyCriteria::fromArray([
            'mode' => 'leech_attention',
            'parameters' => ['sub_mode' => 'leech_only'],
        ]);

        $state = CustomStudySessionState::createInitial(
            1,
            1,
            'en',
            $criteria,
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11],
            $this->validDelayConfig()
        );

        $this->assertSame('leech_attention', $state->mode());
        $this->assertSame(['sub_mode' => 'leech_only'], $state->parameters());
    }

    // ---------- Step is 0 on initial state ----------

    public function test_initial_step_is_zero(): void
    {
        $state = CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11, 12, 13],
            $this->validDelayConfig()
        );

        $this->assertSame(0, $state->step());
    }

    // ---------- Valid state with delayed + completed + skipped ----------

    public function test_valid_state_with_all_five_states_populated(): void
    {
        // current=11, ready=[12], delayed=[{13, t+60}], completed=[14], skipped=[15]
        // ordered = [11,12,13,14,15]
        $state = CustomStudySessionState::fromArray([
            'version' => 1,
            'user_id' => 42,
            'language' => 'en',
            'mode' => 'today_forgotten',
            'parameters' => [],
            'session_id' => $this->validUuidV4(),
            'issued_at' => $this->validIssuedAt(),
            'expires_at' => $this->validExpiresAt(),
            'ordered_candidate_ids' => [11, 12, 13, 14, 15],
            'ready_queue' => [12],
            'delayed_repeat_queue' => [
                ['card_id' => 13, 'available_at' => $this->validIssuedAt() + 60],
            ],
            'completed_ids' => [14],
            'skipped_ineligible_ids' => [15],
            'completed_count' => 1,
            'total_count' => 5,
            'current_card_id' => 11,
            'step' => 3,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);

        $this->assertSame(11, $state->currentCardId());
        $this->assertSame([12], $state->readyQueue());
        $this->assertSame([['card_id' => 13, 'available_at' => $this->validIssuedAt() + 60]], $state->delayedRepeatQueue());
        $this->assertSame([14], $state->completedIds());
        $this->assertSame([15], $state->skippedIneligibleIds());
        $this->assertSame(1, $state->completedCount());
        $this->assertSame(5, $state->totalCount());
        $this->assertSame(3, $state->step());
    }

    // ---------- Null current_card_id with non-empty ready_queue (between cards) ----------

    public function test_valid_state_with_null_current_and_non_empty_ready_queue(): void
    {
        $state = CustomStudySessionState::fromArray([
            'version' => 1,
            'user_id' => 42,
            'language' => 'en',
            'mode' => 'today_forgotten',
            'parameters' => [],
            'session_id' => $this->validUuidV4(),
            'issued_at' => $this->validIssuedAt(),
            'expires_at' => $this->validExpiresAt(),
            'ordered_candidate_ids' => [11, 12, 13],
            'ready_queue' => [11, 12, 13],
            'delayed_repeat_queue' => [],
            'completed_ids' => [],
            'skipped_ineligible_ids' => [],
            'completed_count' => 0,
            'total_count' => 3,
            'current_card_id' => null,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
        ]);

        $this->assertSame(null, $state->currentCardId());
        $this->assertSame([11, 12, 13], $state->readyQueue());
    }

    // ---------- Exception carries reason code ----------

    public function test_exception_carries_reason_code(): void
    {
        try {
            CustomStudySessionState::createInitial(
                0, // invalid version
                42,
                'en',
                $this->validCriteria(),
                $this->validUuidV4(),
                $this->validIssuedAt(),
                $this->validExpiresAt(),
                [11],
                $this->validDelayConfig()
            );
            $this->fail('Expected CustomStudySessionStateException was not thrown');
        } catch (CustomStudySessionStateException $e) {
            $this->assertNotSame('', $e->getReason());
        }
    }
}
