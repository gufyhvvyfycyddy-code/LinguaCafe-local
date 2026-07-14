<?php

namespace Tests\Unit;

use App\Exceptions\CustomStudySessionStateException;
use App\Services\CustomStudy\CustomStudyCriteria;
use App\Services\CustomStudy\CustomStudySessionState;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for CustomStudySessionState::withProgress() + waitUntil() + isCompleted().
 *
 * Pure unit tests — no Laravel container, no DB, no Auth, no Request, no Crypt.
 *
 * Verifies the immutable copy boundary that Phase 3B freezes for the future
 * CustomStudyPreviewPolicy: PreviewPolicy MUST go through withProgress() rather
 * than mutating toArray()/fromArray() string-keyed payloads.
 *
 * Task 2000-20 — Custom Study 1A Phase 3B.
 */
class CustomStudySessionStateProgressTest extends TestCase
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

    /**
     * Creates an initial state with three candidates [11, 12, 13]:
     *   current = 11, ready = [12, 13], delayed = [], completed = [], skipped = [].
     */
    private function initialStateWithThreeCandidates(): CustomStudySessionState
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
            $this->validDelayConfig()
        );
    }

    /**
     * Reconstructs a state from a payload so tests can drive edge cases that
     * createInitial() cannot (e.g. step = PHP_INT_MAX, populated delayed queue).
     *
     * @param array<string, mixed> $overrides
     */
    private function stateFromPayload(array $overrides = []): CustomStudySessionState
    {
        $base = [
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
        return CustomStudySessionState::fromArray(array_merge($base, $overrides));
    }

    // ---------- 1. withProgress returns a new object ----------

    public function test_with_progress_returns_new_instance(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $next = $state->withProgress(12, [13], [], [11], []);

        $this->assertNotSame($state, $next);
        $this->assertInstanceOf(CustomStudySessionState::class, $next);
    }

    // ---------- 2. Original state is not mutated ----------

    public function test_with_progress_does_not_modify_original_state(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $originalCurrent = $state->currentCardId();
        $originalReady = $state->readyQueue();
        $originalDelayed = $state->delayedRepeatQueue();
        $originalCompleted = $state->completedIds();
        $originalSkipped = $state->skippedIneligibleIds();
        $originalStep = $state->step();
        $originalCompletedCount = $state->completedCount();
        $originalTotalCount = $state->totalCount();

        $state->withProgress(12, [13], [], [11], []);

        $this->assertSame($originalCurrent, $state->currentCardId());
        $this->assertSame($originalReady, $state->readyQueue());
        $this->assertSame($originalDelayed, $state->delayedRepeatQueue());
        $this->assertSame($originalCompleted, $state->completedIds());
        $this->assertSame($originalSkipped, $state->skippedIneligibleIds());
        $this->assertSame($originalStep, $state->step());
        $this->assertSame($originalCompletedCount, $state->completedCount());
        $this->assertSame($originalTotalCount, $state->totalCount());
    }

    // ---------- 3. Identity fields preserved (version/userId/language) ----------

    public function test_with_progress_preserves_version_user_id_language(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $next = $state->withProgress(12, [13], [], [11], []);

        $this->assertSame($state->version(), $next->version());
        $this->assertSame($state->userId(), $next->userId());
        $this->assertSame($state->language(), $next->language());
    }

    // ---------- 4. Criteria mode + parameters preserved ----------

    public function test_with_progress_preserves_criteria_mode_and_parameters(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $next = $state->withProgress(12, [13], [], [11], []);

        $this->assertSame($state->mode(), $next->mode());
        $this->assertSame($state->parameters(), $next->parameters());
        $this->assertSame($state->criteria()->mode(), $next->criteria()->mode());
    }

    // ---------- 5. session_id preserved ----------

    public function test_with_progress_preserves_session_id(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $next = $state->withProgress(12, [13], [], [11], []);

        $this->assertSame($state->sessionId(), $next->sessionId());
    }

    // ---------- 6. issued_at / expires_at preserved ----------

    public function test_with_progress_preserves_timestamps(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $next = $state->withProgress(12, [13], [], [11], []);

        $this->assertSame($state->issuedAt(), $next->issuedAt());
        $this->assertSame($state->expiresAt(), $next->expiresAt());
    }

    // ---------- 7. ordered_candidate_ids preserved ----------

    public function test_with_progress_preserves_ordered_candidate_ids(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $next = $state->withProgress(12, [13], [], [11], []);

        $this->assertSame($state->orderedCandidateIds(), $next->orderedCandidateIds());
    }

    // ---------- 8. preview_delay_config preserved ----------

    public function test_with_progress_preserves_preview_delay_config(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $next = $state->withProgress(12, [13], [], [11], []);

        $this->assertSame($state->previewDelayConfig(), $next->previewDelayConfig());
    }

    // ---------- 9. current_card_id updated ----------

    public function test_with_progress_updates_current_card_id(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $next = $state->withProgress(12, [13], [], [11], []);

        $this->assertSame(12, $next->currentCardId());
    }

    public function test_with_progress_accepts_null_current_card_id(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $next = $state->withProgress(null, [], [], [11, 12, 13], []);

        $this->assertNull($next->currentCardId());
    }

    // ---------- 10. ready_queue updated ----------

    public function test_with_progress_updates_ready_queue(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $next = $state->withProgress(12, [13], [], [11], []);

        $this->assertSame([13], $next->readyQueue());
    }

    // ---------- 11. delayed_repeat_queue updated ----------

    public function test_with_progress_updates_delayed_repeat_queue(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $next = $state->withProgress(
            12,
            [13],
            [['card_id' => 11, 'available_at' => 1720000060]],
            [],
            []
        );

        $this->assertSame(
            [['card_id' => 11, 'available_at' => 1720000060]],
            $next->delayedRepeatQueue()
        );
    }

    // ---------- 12. completed_ids updated ----------

    public function test_with_progress_updates_completed_ids(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $next = $state->withProgress(12, [13], [], [11], []);

        $this->assertSame([11], $next->completedIds());
    }

    // ---------- 13. skipped_ineligible_ids updated ----------

    public function test_with_progress_updates_skipped_ineligible_ids(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $next = $state->withProgress(13, [], [], [11], [12]);

        $this->assertSame([12], $next->skippedIneligibleIds());
    }

    // ---------- 14. completed_count auto-recomputed ----------

    public function test_with_progress_auto_recomputes_completed_count(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $next = $state->withProgress(12, [13], [], [11], []);

        $this->assertSame(1, $next->completedCount());
    }

    public function test_with_progress_completed_count_equals_count_of_completed_ids(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $next = $state->withProgress(null, [], [], [11, 12], [13]);

        $this->assertSame(count($next->completedIds()), $next->completedCount());
    }

    // ---------- 15. total_count auto-recomputed ----------

    public function test_with_progress_auto_recomputes_total_count(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $next = $state->withProgress(12, [13], [], [11], []);

        $this->assertSame(3, $next->totalCount());
    }

    public function test_with_progress_total_count_equals_count_of_ordered_candidate_ids(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $next = $state->withProgress(null, [], [], [11, 12], [13]);

        $this->assertSame(count($next->orderedCandidateIds()), $next->totalCount());
    }

    // ---------- 16. step auto-incremented by 1 ----------

    public function test_with_progress_auto_increments_step_by_one(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $this->assertSame(0, $state->step());

        $next = $state->withProgress(12, [13], [], [11], []);
        $this->assertSame(1, $next->step());

        $next2 = $next->withProgress(13, [], [], [11, 12], []);
        $this->assertSame(2, $next2->step());
    }

    // ---------- 17. step cannot be set by caller ----------

    public function test_with_progress_signature_does_not_accept_step_parameter(): void
    {
        // The withProgress method signature must be exactly:
        //   withProgress(?int $currentCardId, array $readyQueue,
        //                 array $delayedRepeatQueue, array $completedIds,
        //                 array $skippedIneligibleIds): self
        // No step / completed_count / total_count parameter is allowed.
        $reflection = new ReflectionClass(CustomStudySessionState::class);
        $method = $reflection->getMethod('withProgress');
        $params = $method->getParameters();

        $this->assertCount(5, $params);
        $expectedNames = [
            'currentCardId',
            'readyQueue',
            'delayedRepeatQueue',
            'completedIds',
            'skippedIneligibleIds',
        ];
        $actualNames = array_map(fn ($p) => $p->getName(), $params);
        $this->assertSame($expectedNames, $actualNames);
    }

    // ---------- 18. step overflow rejected (PHP_INT_MAX) ----------

    public function test_with_progress_rejects_step_overflow_at_php_int_max(): void
    {
        $state = $this->stateFromPayload(['step' => PHP_INT_MAX]);

        try {
            $state->withProgress(12, [13], [], [11], []);
            $this->fail('Expected CustomStudySessionStateException for step overflow');
        } catch (CustomStudySessionStateException $e) {
            $this->assertSame('step_overflow', $e->getReason());
        }
    }

    // ---------- 19. Five-state overlap rejected ----------

    public function test_with_progress_rejects_overlap_current_in_ready(): void
    {
        $state = $this->initialStateWithThreeCandidates();

        try {
            // current=11 also appears in ready — overlap
            $state->withProgress(11, [11, 12], [], [13], []);
            $this->fail('Expected state_overlap');
        } catch (CustomStudySessionStateException $e) {
            $this->assertSame('state_overlap', $e->getReason());
        }
    }

    public function test_with_progress_rejects_overlap_current_in_completed(): void
    {
        $state = $this->initialStateWithThreeCandidates();

        try {
            // current=11 also in completed — overlap
            $state->withProgress(11, [12, 13], [], [11], []);
            $this->fail('Expected state_overlap');
        } catch (CustomStudySessionStateException $e) {
            $this->assertSame('state_overlap', $e->getReason());
        }
    }

    public function test_with_progress_rejects_overlap_ready_in_delayed(): void
    {
        $state = $this->initialStateWithThreeCandidates();

        try {
            // 12 in both ready and delayed
            $state->withProgress(
                11,
                [12, 13],
                [['card_id' => 12, 'available_at' => 1720000060]],
                [],
                []
            );
            $this->fail('Expected state_overlap');
        } catch (CustomStudySessionStateException $e) {
            $this->assertSame('state_overlap', $e->getReason());
        }
    }

    // ---------- 20. Lost ordered ID rejected ----------

    public function test_with_progress_rejects_lost_ordered_id(): void
    {
        $state = $this->initialStateWithThreeCandidates();

        try {
            // 13 is in ordered but missing from all five states
            $state->withProgress(11, [12], [], [], []);
            $this->fail('Expected lost_ordered_id');
        } catch (CustomStudySessionStateException $e) {
            $this->assertSame('lost_ordered_id', $e->getReason());
        }
    }

    // ---------- 21. Unknown ID rejected ----------

    public function test_with_progress_rejects_unknown_id_in_ready(): void
    {
        $state = $this->initialStateWithThreeCandidates();

        try {
            // 99 is not in ordered_candidate_ids
            $state->withProgress(11, [12, 13, 99], [], [], []);
            $this->fail('Expected unknown_state_id');
        } catch (CustomStudySessionStateException $e) {
            $this->assertSame('unknown_state_id', $e->getReason());
        }
    }

    public function test_with_progress_rejects_unknown_id_in_completed(): void
    {
        $state = $this->initialStateWithThreeCandidates();

        try {
            $state->withProgress(11, [12, 13], [], [99], []);
            $this->fail('Expected unknown_state_id');
        } catch (CustomStudySessionStateException $e) {
            $this->assertSame('unknown_state_id', $e->getReason());
        }
    }

    public function test_with_progress_rejects_unknown_id_in_delayed(): void
    {
        $state = $this->initialStateWithThreeCandidates();

        try {
            $state->withProgress(
                11,
                [12, 13],
                [['card_id' => 99, 'available_at' => 1720000060]],
                [],
                []
            );
            $this->fail('Expected unknown_state_id');
        } catch (CustomStudySessionStateException $e) {
            $this->assertSame('unknown_state_id', $e->getReason());
        }
    }

    // ---------- 22. waitUntil returns earliest available_at ----------

    public function test_wait_until_returns_earliest_available_at(): void
    {
        $state = $this->stateFromPayload([
            'ready_queue' => [],
            'delayed_repeat_queue' => [
                ['card_id' => 12, 'available_at' => 1720000200],
                ['card_id' => 13, 'available_at' => 1720000060],
                ['card_id' => 11, 'available_at' => 1720000120],
            ],
            'completed_ids' => [],
            'completed_count' => 0,
            'current_card_id' => null,
            'step' => 5,
        ]);

        $this->assertSame(1720000060, $state->waitUntil());
    }

    // ---------- 23. waitUntil returns null for empty delayed ----------

    public function test_wait_until_returns_null_for_empty_delayed_queue(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $this->assertSame([], $state->delayedRepeatQueue());
        $this->assertNull($state->waitUntil());
    }

    // ---------- 24. isCompleted true when all active queues empty ----------

    public function test_is_completed_returns_true_when_no_active_queues(): void
    {
        $state = $this->stateFromPayload([
            'ordered_candidate_ids' => [11, 12],
            'ready_queue' => [],
            'delayed_repeat_queue' => [],
            'completed_ids' => [11],
            'skipped_ineligible_ids' => [12],
            'completed_count' => 1,
            'total_count' => 2,
            'current_card_id' => null,
            'step' => 5,
        ]);

        $this->assertTrue($state->isCompleted());
    }

    // ---------- 25. isCompleted false when current exists ----------

    public function test_is_completed_returns_false_when_current_card_id_exists(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $this->assertNotNull($state->currentCardId());
        $this->assertFalse($state->isCompleted());
    }

    // ---------- 26. isCompleted false when ready non-empty ----------

    public function test_is_completed_returns_false_when_ready_queue_non_empty(): void
    {
        $state = $this->stateFromPayload([
            'ordered_candidate_ids' => [11, 12],
            'ready_queue' => [12],
            'delayed_repeat_queue' => [],
            'completed_ids' => [11],
            'skipped_ineligible_ids' => [],
            'completed_count' => 1,
            'total_count' => 2,
            'current_card_id' => null,
            'step' => 5,
        ]);

        $this->assertFalse($state->isCompleted());
    }

    // ---------- 27. isCompleted false when delayed non-empty ----------

    public function test_is_completed_returns_false_when_delayed_queue_non_empty(): void
    {
        $state = $this->stateFromPayload([
            'ordered_candidate_ids' => [11, 12],
            'ready_queue' => [],
            'delayed_repeat_queue' => [['card_id' => 12, 'available_at' => 1720000060]],
            'completed_ids' => [11],
            'skipped_ineligible_ids' => [],
            'completed_count' => 1,
            'total_count' => 2,
            'current_card_id' => null,
            'step' => 5,
        ]);

        $this->assertFalse($state->isCompleted());
    }

    // ---------- 28. isCompleted true with non-empty completed/skipped ----------

    public function test_is_completed_true_with_non_empty_completed_and_skipped(): void
    {
        $state = $this->stateFromPayload([
            'ordered_candidate_ids' => [11, 12, 13, 14],
            'ready_queue' => [],
            'delayed_repeat_queue' => [],
            'completed_ids' => [11, 12],
            'skipped_ineligible_ids' => [13, 14],
            'completed_count' => 2,
            'total_count' => 4,
            'current_card_id' => null,
            'step' => 10,
        ]);

        $this->assertTrue($state->isCompleted());
    }

    // ---------- 29. No new setters added ----------

    public function test_state_class_does_not_add_setters_for_progress_fields(): void
    {
        $reflection = new ReflectionClass(CustomStudySessionState::class);
        $methods = array_map(fn ($m) => $m->getName(), $reflection->getMethods());

        $forbidden = [
            'setCurrentCardId',
            'setReadyQueue',
            'setDelayedRepeatQueue',
            'setCompletedIds',
            'setSkippedIneligibleIds',
            'setStep',
            'setCompletedCount',
            'setTotalCount',
        ];
        foreach ($forbidden as $name) {
            $this->assertNotContains(
                $name,
                $methods,
                "State must not add setter: $name"
            );
        }
    }

    // ---------- 30. No DB / Auth / Request / Crypt dependencies ----------

    public function test_state_class_does_not_use_db_auth_request_crypt(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../app/Services/CustomStudy/CustomStudySessionState.php'
        );

        $forbiddenUsages = [
            'use Illuminate\Support\Facades\DB;',
            'use Illuminate\Support\Facades\Auth;',
            'use Illuminate\Http\Request;',
            'use Illuminate\Support\Facades\Crypt;',
            'use Illuminate\Contracts\Encryption\Encrypter;',
        ];
        foreach ($forbiddenUsages as $usage) {
            $this->assertStringNotContainsString(
                $usage,
                $source,
                "State must not import: $usage"
            );
        }

        $forbiddenStaticCalls = ['DB::', 'Auth::', 'Crypt::'];
        foreach ($forbiddenStaticCalls as $call) {
            $this->assertStringNotContainsString(
                $call,
                $source,
                "State must not call static facade: $call"
            );
        }
    }

    // ---------- 31. No rating/answer/resume branching in State ----------

    public function test_state_class_does_not_contain_rating_answer_resume_logic(): void
    {
        $reflection = new ReflectionClass(CustomStudySessionState::class);
        $methods = array_map(fn ($m) => $m->getName(), $reflection->getMethods());

        $forbiddenMethods = [
            'applyRating',
            'answer',
            'resume',
            'rate',
            'nextCard',
            'transition',
            'rotate',
        ];
        foreach ($forbiddenMethods as $name) {
            $this->assertNotContains(
                $name,
                $methods,
                "State must not have method: $name"
            );
        }

        $source = file_get_contents(
            __DIR__ . '/../../app/Services/CustomStudy/CustomStudySessionState.php'
        );
        // State must not branch on rating literals.
        $this->assertStringNotContainsString("'again'", $source);
        $this->assertStringNotContainsString("'hard'", $source);
        $this->assertStringNotContainsString("'good'", $source);
        $this->assertStringNotContainsString("'easy'", $source);
    }

    // ---------- Additional: withProgress on empty initial state ----------

    public function test_with_progress_on_empty_initial_state(): void
    {
        $emptyState = CustomStudySessionState::createInitial(
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

        $next = $emptyState->withProgress(null, [], [], [], []);

        $this->assertTrue($next->isCompleted());
        $this->assertSame(1, $next->step());
        $this->assertNull($next->waitUntil());
    }
}
