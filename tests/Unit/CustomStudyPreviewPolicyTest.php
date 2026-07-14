<?php

namespace Tests\Unit;

use App\Exceptions\CustomStudyPreviewPolicyException;
use App\Services\CustomStudy\CustomStudyCriteria;
use App\Services\CustomStudy\CustomStudyPreviewPolicy;
use App\Services\CustomStudy\CustomStudySessionState;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for CustomStudyPreviewPolicy — the pure state transition layer.
 *
 * Pure unit tests — no Laravel container, no DB, no Auth, no Request, no Crypt.
 *
 * Verifies (per Task 2000-20 Phase 3B spec):
 * - Rating: Again/Hard → delayed, Good/Easy → completed, four frozen ratings.
 * - Next-card selection: ready first, else earliest mature delayed, stable ties.
 * - Resume: keep current / pop ready / pop mature delayed / stay null.
 * - Architecture: pure function, only uses withProgress(), no DB/Auth/Request/
 *   Crypt/ReviewLog/FSRS/lifecycle/AI/SessionService/Controller/routes/Vue.
 *
 * Task 2000-20 — Custom Study 1A Phase 3B.
 */
class CustomStudyPreviewPolicyTest extends TestCase
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
     * Fixed "now" timestamp for deterministic tests: 1720000100.
     * again_secs=60  → available_at = 1720000160
     * hard_secs=600  → available_at = 1720000700
     */
    private function now(): Carbon
    {
        return Carbon::createFromTimestamp(1720000100);
    }

    private function policy(): CustomStudyPreviewPolicy
    {
        return new CustomStudyPreviewPolicy();
    }

    /**
     * Reconstructs a state from a payload so tests can drive any configuration.
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

    // ============================================================
    // Rating tests (1-20)
    // ============================================================

    // ---------- 1. Again moves current to delayed ----------

    public function test_again_moves_current_card_to_delayed_queue(): void
    {
        $state = $this->stateFromPayload();
        $next = $this->policy()->applyRating($state, 'again', $this->now());

        // 11 should be in delayed, NOT in completed
        $delayedIds = array_map(fn ($e) => $e['card_id'], $next->delayedRepeatQueue());
        $this->assertContains(11, $delayedIds);
        $this->assertNotContains(11, $next->completedIds());
    }

    // ---------- 2. Again uses again_secs ----------

    public function test_again_uses_again_secs_for_available_at(): void
    {
        $state = $this->stateFromPayload();
        $next = $this->policy()->applyRating($state, 'again', $this->now());

        // available_at = now + again_secs = 1720000100 + 60 = 1720000160
        $delayed = $next->delayedRepeatQueue();
        $this->assertCount(1, $delayed);
        $this->assertSame(11, $delayed[0]['card_id']);
        $this->assertSame(1720000160, $delayed[0]['available_at']);
    }

    // ---------- 3. Hard moves current to delayed ----------

    public function test_hard_moves_current_card_to_delayed_queue(): void
    {
        $state = $this->stateFromPayload();
        $next = $this->policy()->applyRating($state, 'hard', $this->now());

        $delayedIds = array_map(fn ($e) => $e['card_id'], $next->delayedRepeatQueue());
        $this->assertContains(11, $delayedIds);
        $this->assertNotContains(11, $next->completedIds());
    }

    // ---------- 4. Hard uses hard_secs ----------

    public function test_hard_uses_hard_secs_for_available_at(): void
    {
        $state = $this->stateFromPayload();
        $next = $this->policy()->applyRating($state, 'hard', $this->now());

        // available_at = now + hard_secs = 1720000100 + 600 = 1720000700
        $delayed = $next->delayedRepeatQueue();
        $this->assertCount(1, $delayed);
        $this->assertSame(11, $delayed[0]['card_id']);
        $this->assertSame(1720000700, $delayed[0]['available_at']);
    }

    // ---------- 5. Good moves current to completed ----------

    public function test_good_moves_current_card_to_completed_ids(): void
    {
        $state = $this->stateFromPayload();
        $next = $this->policy()->applyRating($state, 'good', $this->now());

        $this->assertContains(11, $next->completedIds());
        $delayedIds = array_map(fn ($e) => $e['card_id'], $next->delayedRepeatQueue());
        $this->assertNotContains(11, $delayedIds);
    }

    // ---------- 6. Easy moves current to completed ----------

    public function test_easy_moves_current_card_to_completed_ids(): void
    {
        $state = $this->stateFromPayload();
        $next = $this->policy()->applyRating($state, 'easy', $this->now());

        $this->assertContains(11, $next->completedIds());
        $delayedIds = array_map(fn ($e) => $e['card_id'], $next->delayedRepeatQueue());
        $this->assertNotContains(11, $delayedIds);
    }

    // ---------- 7. Good/Easy do not enter delayed ----------

    public function test_good_does_not_add_to_delayed_queue(): void
    {
        $state = $this->stateFromPayload();
        $next = $this->policy()->applyRating($state, 'good', $this->now());

        $this->assertSame([], $next->delayedRepeatQueue());
    }

    public function test_easy_does_not_add_to_delayed_queue(): void
    {
        $state = $this->stateFromPayload();
        $next = $this->policy()->applyRating($state, 'easy', $this->now());

        $this->assertSame([], $next->delayedRepeatQueue());
    }

    // ---------- 8. Again/Hard do not enter completed ----------

    public function test_again_does_not_add_to_completed_ids(): void
    {
        $state = $this->stateFromPayload();
        $next = $this->policy()->applyRating($state, 'again', $this->now());

        $this->assertNotContains(11, $next->completedIds());
    }

    public function test_hard_does_not_add_to_completed_ids(): void
    {
        $state = $this->stateFromPayload();
        $next = $this->policy()->applyRating($state, 'hard', $this->now());

        $this->assertNotContains(11, $next->completedIds());
    }

    // ---------- 9. After rating, current is picked from ready first ----------

    public function test_after_rating_current_is_picked_from_ready_queue_first(): void
    {
        $state = $this->stateFromPayload();
        $next = $this->policy()->applyRating($state, 'good', $this->now());

        // ready was [12, 13] → new current = 12, ready = [13]
        $this->assertSame(12, $next->currentCardId());
        $this->assertSame([13], $next->readyQueue());
    }

    // ---------- 10. Ready takes priority over mature delayed ----------

    public function test_ready_queue_takes_priority_over_mature_delayed(): void
    {
        $state = $this->stateFromPayload([
            'ordered_candidate_ids' => [11, 12, 13],
            'ready_queue' => [12],
            'delayed_repeat_queue' => [['card_id' => 13, 'available_at' => 1720000000]],
            'total_count' => 3,
        ]);
        $next = $this->policy()->applyRating($state, 'good', $this->now());

        // 12 from ready becomes current; 13 stays in delayed
        $this->assertSame(12, $next->currentCardId());
        $delayedIds = array_map(fn ($e) => $e['card_id'], $next->delayedRepeatQueue());
        $this->assertContains(13, $delayedIds);
    }

    // ---------- 11. When ready empty, pick earliest mature delayed ----------

    public function test_when_ready_empty_picks_earliest_mature_delayed(): void
    {
        $state = $this->stateFromPayload([
            'ready_queue' => [],
            'delayed_repeat_queue' => [
                ['card_id' => 12, 'available_at' => 1720000200], // immature (now+100)
                ['card_id' => 13, 'available_at' => 1720000050], // mature (now-50)
            ],
        ]);
        $next = $this->policy()->applyRating($state, 'good', $this->now());

        // 13 is the earliest mature delayed card
        $this->assertSame(13, $next->currentCardId());
        $delayedIds = array_map(fn ($e) => $e['card_id'], $next->delayedRepeatQueue());
        $this->assertNotContains(13, $delayedIds);
        $this->assertContains(12, $delayedIds);
    }

    // ---------- 12. Delayed tie is stable (original order preserved) ----------

    public function test_delayed_tie_keeps_original_queue_order(): void
    {
        $state = $this->stateFromPayload([
            'ready_queue' => [],
            'delayed_repeat_queue' => [
                ['card_id' => 12, 'available_at' => 1720000050], // mature, first
                ['card_id' => 13, 'available_at' => 1720000050], // mature, same time, second
            ],
        ]);
        $next = $this->policy()->applyRating($state, 'good', $this->now());

        // Both mature with same available_at → pick first (12)
        $this->assertSame(12, $next->currentCardId());
        $delayedIds = array_map(fn ($e) => $e['card_id'], $next->delayedRepeatQueue());
        $this->assertSame([13], $delayedIds);
    }

    // ---------- 13. Immature delayed is not shown ----------

    public function test_immature_delayed_is_not_picked_as_current(): void
    {
        $state = $this->stateFromPayload([
            'ordered_candidate_ids' => [11, 12],
            'ready_queue' => [],
            'delayed_repeat_queue' => [
                ['card_id' => 12, 'available_at' => 1720000200], // immature (now+100)
            ],
            'total_count' => 2,
        ]);
        $next = $this->policy()->applyRating($state, 'good', $this->now());

        // No ready, no mature delayed → current = null; 12 stays in delayed
        $this->assertNull($next->currentCardId());
        $this->assertFalse($next->isCompleted());
        $this->assertSame(1720000200, $next->waitUntil());
    }

    // ---------- 14. Selected delayed is removed from delayed queue ----------

    public function test_selected_delayed_card_is_removed_from_delayed_queue(): void
    {
        $state = $this->stateFromPayload([
            'ready_queue' => [],
            'delayed_repeat_queue' => [
                ['card_id' => 12, 'available_at' => 1720000200],
                ['card_id' => 13, 'available_at' => 1720000050],
            ],
        ]);
        $next = $this->policy()->applyRating($state, 'good', $this->now());

        $delayedIds = array_map(fn ($e) => $e['card_id'], $next->delayedRepeatQueue());
        $this->assertNotContains(13, $delayedIds);
        $this->assertContains(12, $delayedIds);
    }

    // ---------- 15. Other delayed order preserved ----------

    public function test_other_delayed_cards_keep_their_relative_order(): void
    {
        $state = $this->stateFromPayload([
            'ordered_candidate_ids' => [11, 12, 13, 14],
            'ready_queue' => [],
            'delayed_repeat_queue' => [
                ['card_id' => 12, 'available_at' => 1720000200],
                ['card_id' => 13, 'available_at' => 1720000050],
                ['card_id' => 14, 'available_at' => 1720000100],
            ],
            'total_count' => 4,
        ]);
        $next = $this->policy()->applyRating($state, 'good', $this->now());

        // 13 is picked (earliest mature). Remaining: 12, 14 (in original order).
        $delayedIds = array_map(fn ($e) => $e['card_id'], $next->delayedRepeatQueue());
        $this->assertSame([12, 14], $delayedIds);
    }

    // ---------- 16. step increments by exactly 1 ----------

    public function test_apply_rating_increments_step_by_exactly_one(): void
    {
        $state = $this->stateFromPayload(['step' => 5]);
        $next = $this->policy()->applyRating($state, 'good', $this->now());

        $this->assertSame(6, $next->step());
    }

    // ---------- 17. Original state unchanged ----------

    public function test_apply_rating_does_not_modify_original_state(): void
    {
        $state = $this->stateFromPayload();
        $originalCurrent = $state->currentCardId();
        $originalReady = $state->readyQueue();
        $originalDelayed = $state->delayedRepeatQueue();
        $originalCompleted = $state->completedIds();
        $originalStep = $state->step();

        $this->policy()->applyRating($state, 'again', $this->now());

        $this->assertSame($originalCurrent, $state->currentCardId());
        $this->assertSame($originalReady, $state->readyQueue());
        $this->assertSame($originalDelayed, $state->delayedRepeatQueue());
        $this->assertSame($originalCompleted, $state->completedIds());
        $this->assertSame($originalStep, $state->step());
    }

    // ---------- 18. Invalid rating rejected ----------

    public function test_invalid_rating_string_is_rejected(): void
    {
        $state = $this->stateFromPayload();

        try {
            $this->policy()->applyRating($state, 'medium', $this->now());
            $this->fail('Expected CustomStudyPreviewPolicyException for invalid_rating');
        } catch (CustomStudyPreviewPolicyException $e) {
            $this->assertSame('invalid_rating', $e->getReason());
        }
    }

    public function test_numeric_rating_is_rejected(): void
    {
        $state = $this->stateFromPayload();

        try {
            $this->policy()->applyRating($state, '3', $this->now());
            $this->fail('Expected CustomStudyPreviewPolicyException for invalid_rating');
        } catch (CustomStudyPreviewPolicyException $e) {
            $this->assertSame('invalid_rating', $e->getReason());
        }
    }

    public function test_empty_rating_is_rejected(): void
    {
        $state = $this->stateFromPayload();

        try {
            $this->policy()->applyRating($state, '', $this->now());
            $this->fail('Expected CustomStudyPreviewPolicyException for invalid_rating');
        } catch (CustomStudyPreviewPolicyException $e) {
            $this->assertSame('invalid_rating', $e->getReason());
        }
    }

    // ---------- 19. Uppercase rating rejected ----------

    public function test_uppercase_rating_is_rejected(): void
    {
        $state = $this->stateFromPayload();

        try {
            $this->policy()->applyRating($state, 'AGAIN', $this->now());
            $this->fail('Expected CustomStudyPreviewPolicyException for invalid_rating');
        } catch (CustomStudyPreviewPolicyException $e) {
            $this->assertSame('invalid_rating', $e->getReason());
        }
    }

    public function test_mixed_case_rating_is_rejected(): void
    {
        $state = $this->stateFromPayload();

        try {
            $this->policy()->applyRating($state, 'Good', $this->now());
            $this->fail('Expected CustomStudyPreviewPolicyException for invalid_rating');
        } catch (CustomStudyPreviewPolicyException $e) {
            $this->assertSame('invalid_rating', $e->getReason());
        }
    }

    // ---------- 20. Rating with null current rejected ----------

    public function test_apply_rating_with_null_current_throws_no_current_card(): void
    {
        $state = $this->stateFromPayload([
            'ready_queue' => [],
            'current_card_id' => null,
            'ordered_candidate_ids' => [11, 12],
            'completed_ids' => [11],
            'skipped_ineligible_ids' => [12],
            'completed_count' => 1,
            'total_count' => 2,
            'step' => 5,
        ]);

        try {
            $this->policy()->applyRating($state, 'good', $this->now());
            $this->fail('Expected CustomStudyPreviewPolicyException for no_current_card');
        } catch (CustomStudyPreviewPolicyException $e) {
            $this->assertSame('no_current_card', $e->getReason());
        }
    }

    // ============================================================
    // Resume tests (21-29)
    // ============================================================

    // ---------- 21. current exists → same current returned ----------

    public function test_resume_with_existing_current_keeps_same_current(): void
    {
        $state = $this->stateFromPayload();
        $next = $this->policy()->resume($state, $this->now());

        $this->assertSame(11, $next->currentCardId());
    }

    // ---------- 22. current exists → queues unchanged ----------

    public function test_resume_with_existing_current_keeps_queues_unchanged(): void
    {
        $state = $this->stateFromPayload([
            'ready_queue' => [12, 13],
            'delayed_repeat_queue' => [['card_id' => 14, 'available_at' => 1720000050]],
            'completed_ids' => [15],
            'skipped_ineligible_ids' => [16],
            'ordered_candidate_ids' => [11, 12, 13, 14, 15, 16],
            'completed_count' => 1,
            'total_count' => 6,
        ]);
        $next = $this->policy()->resume($state, $this->now());

        $this->assertSame([12, 13], $next->readyQueue());
        $this->assertSame(
            [['card_id' => 14, 'available_at' => 1720000050]],
            $next->delayedRepeatQueue()
        );
        $this->assertSame([15], $next->completedIds());
        $this->assertSame([16], $next->skippedIneligibleIds());
    }

    // ---------- 23. resume step +1 ----------

    public function test_resume_increments_step_by_one(): void
    {
        $state = $this->stateFromPayload(['step' => 7]);
        $next = $this->policy()->resume($state, $this->now());

        $this->assertSame(8, $next->step());
    }

    // ---------- 24. no current + ready non-empty → pop first ready ----------

    public function test_resume_with_null_current_pops_first_ready(): void
    {
        $state = $this->stateFromPayload([
            'ready_queue' => [12, 13],
            'current_card_id' => null,
            'completed_ids' => [11],
            'completed_count' => 1,
            'step' => 3,
        ]);
        $next = $this->policy()->resume($state, $this->now());

        $this->assertSame(12, $next->currentCardId());
        $this->assertSame([13], $next->readyQueue());
    }

    // ---------- 25. ready empty → pop mature delayed ----------

    public function test_resume_with_empty_ready_pops_mature_delayed(): void
    {
        $state = $this->stateFromPayload([
            'ready_queue' => [],
            'delayed_repeat_queue' => [
                ['card_id' => 12, 'available_at' => 1720000200],
                ['card_id' => 13, 'available_at' => 1720000050],
            ],
            'current_card_id' => null,
            'completed_ids' => [11],
            'completed_count' => 1,
        ]);
        $next = $this->policy()->resume($state, $this->now());

        // 13 is earliest mature
        $this->assertSame(13, $next->currentCardId());
        $delayedIds = array_map(fn ($e) => $e['card_id'], $next->delayedRepeatQueue());
        $this->assertSame([12], $delayedIds);
    }

    // ---------- 26. only immature delayed → current stays null ----------

    public function test_resume_with_only_immature_delayed_keeps_current_null(): void
    {
        $state = $this->stateFromPayload([
            'ordered_candidate_ids' => [11, 12],
            'ready_queue' => [],
            'delayed_repeat_queue' => [
                ['card_id' => 12, 'available_at' => 1720000200],
            ],
            'current_card_id' => null,
            'completed_ids' => [11],
            'completed_count' => 1,
            'total_count' => 2,
        ]);
        $next = $this->policy()->resume($state, $this->now());

        $this->assertNull($next->currentCardId());
        $this->assertFalse($next->isCompleted());
    }

    // ---------- 27. waitUntil correct ----------

    public function test_resume_with_immature_delayed_returns_correct_wait_until(): void
    {
        $state = $this->stateFromPayload([
            'ordered_candidate_ids' => [11, 12],
            'ready_queue' => [],
            'delayed_repeat_queue' => [
                ['card_id' => 12, 'available_at' => 1720000200],
            ],
            'current_card_id' => null,
            'completed_ids' => [11],
            'completed_count' => 1,
            'total_count' => 2,
        ]);
        $next = $this->policy()->resume($state, $this->now());

        $this->assertSame(1720000200, $next->waitUntil());
    }

    // ---------- 28. all active empty → isCompleted true ----------

    public function test_resume_with_all_active_empty_returns_completed_state(): void
    {
        $state = $this->stateFromPayload([
            'ordered_candidate_ids' => [11, 12],
            'ready_queue' => [],
            'delayed_repeat_queue' => [],
            'current_card_id' => null,
            'completed_ids' => [11],
            'skipped_ineligible_ids' => [12],
            'completed_count' => 1,
            'total_count' => 2,
        ]);
        $next = $this->policy()->resume($state, $this->now());

        $this->assertTrue($next->isCompleted());
    }

    // ---------- 29. completed/skipped non-empty doesn't affect completion ----------

    public function test_resume_completion_not_affected_by_completed_and_skipped(): void
    {
        $state = $this->stateFromPayload([
            'ordered_candidate_ids' => [11, 12, 13, 14],
            'ready_queue' => [],
            'delayed_repeat_queue' => [],
            'current_card_id' => null,
            'completed_ids' => [11, 12],
            'skipped_ineligible_ids' => [13, 14],
            'completed_count' => 2,
            'total_count' => 4,
        ]);
        $next = $this->policy()->resume($state, $this->now());

        $this->assertTrue($next->isCompleted());
    }

    // ============================================================
    // Architecture tests (30-44)
    // ============================================================

    // ---------- 30. Does not call State::toArray() ----------

    public function test_policy_does_not_call_state_to_array(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../app/Services/CustomStudy/CustomStudyPreviewPolicy.php'
        );
        $this->assertStringNotContainsString('->toArray()', $source);
        $this->assertStringNotContainsString('::toArray()', $source);
    }

    // ---------- 31. Does not call State::fromArray() ----------

    public function test_policy_does_not_call_state_from_array(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../app/Services/CustomStudy/CustomStudyPreviewPolicy.php'
        );
        $this->assertStringNotContainsString('->fromArray(', $source);
        $this->assertStringNotContainsString('::fromArray(', $source);
    }

    // ---------- 32. Only creates new state via withProgress ----------

    public function test_policy_creates_new_state_only_via_with_progress(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../app/Services/CustomStudy/CustomStudyPreviewPolicy.php'
        );
        $this->assertStringContainsString('->withProgress(', $source);
    }

    // ---------- 33. No DB access ----------

    public function test_policy_does_not_access_db(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../app/Services/CustomStudy/CustomStudyPreviewPolicy.php'
        );
        $this->assertStringNotContainsString('use Illuminate\Support\Facades\DB;', $source);
        $this->assertStringNotContainsString('DB::', $source);
    }

    // ---------- 34. No Auth ----------

    public function test_policy_does_not_use_auth(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../app/Services/CustomStudy/CustomStudyPreviewPolicy.php'
        );
        $this->assertStringNotContainsString('use Illuminate\Support\Facades\Auth;', $source);
        $this->assertStringNotContainsString('Auth::', $source);
    }

    // ---------- 35. No Request ----------

    public function test_policy_does_not_use_request(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../app/Services/CustomStudy/CustomStudyPreviewPolicy.php'
        );
        $this->assertStringNotContainsString('use Illuminate\Http\Request;', $source);
        $this->assertStringNotContainsString('request(', $source);
    }

    // ---------- 36. No Crypt/Encrypter ----------

    public function test_policy_does_not_use_crypt_or_encrypter(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../app/Services/CustomStudy/CustomStudyPreviewPolicy.php'
        );
        $this->assertStringNotContainsString('use Illuminate\Support\Facades\Crypt;', $source);
        $this->assertStringNotContainsString('use Illuminate\Contracts\Encryption\Encrypter;', $source);
        $this->assertStringNotContainsString('Crypt::', $source);
    }

    // ---------- 37. No ReviewLog writes ----------

    public function test_policy_does_not_write_review_log(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../app/Services/CustomStudy/CustomStudyPreviewPolicy.php'
        );
        $this->assertStringNotContainsString('ReviewLog', $source);
    }

    // ---------- 38. No FSRS changes ----------

    public function test_policy_does_not_modify_fsrs(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../app/Services/CustomStudy/CustomStudyPreviewPolicy.php'
        );
        $this->assertStringNotContainsString('fsrs', $source);
        $this->assertStringNotContainsString('Fsrs', $source);
        $this->assertStringNotContainsString('FSRS', $source);
    }

    // ---------- 39. No lifecycle changes ----------

    public function test_policy_does_not_modify_lifecycle(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../app/Services/CustomStudy/CustomStudyPreviewPolicy.php'
        );
        $this->assertStringNotContainsString('lifecycle', $source);
        $this->assertStringNotContainsString('Lifecycle', $source);
    }

    // ---------- 40. No AI calls ----------

    public function test_policy_does_not_call_ai(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../app/Services/CustomStudy/CustomStudyPreviewPolicy.php'
        );
        $this->assertStringNotContainsString('openai', $source);
        $this->assertStringNotContainsString('OpenAI', $source);
        $this->assertStringNotContainsString('ChatGPT', $source);
    }

    // ---------- 41. No array return ----------

    public function test_policy_methods_return_state_instances(): void
    {
        $reflection = new ReflectionClass(CustomStudyPreviewPolicy::class);
        $applyRating = $reflection->getMethod('applyRating');
        $resume = $reflection->getMethod('resume');

        $this->assertSame(
            CustomStudySessionState::class,
            $applyRating->getReturnType()->getName()
        );
        $this->assertSame(
            CustomStudySessionState::class,
            $resume->getReturnType()->getName()
        );
    }

    // ---------- 42. No token creation ----------

    public function test_policy_does_not_create_tokens(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../app/Services/CustomStudy/CustomStudyPreviewPolicy.php'
        );
        $this->assertStringNotContainsString('TokenService', $source);
        $this->assertStringNotContainsString('->issue(', $source);
    }

    // ---------- 43. No QueryService calls ----------

    public function test_policy_does_not_call_query_service(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../app/Services/CustomStudy/CustomStudyPreviewPolicy.php'
        );
        $this->assertStringNotContainsString('QueryService', $source);
        $this->assertStringNotContainsString('candidateIds', $source);
    }

    // ---------- 44. Uses injected Carbon, not now()/Carbon::now() ----------

    public function test_policy_uses_injected_carbon_not_global_now(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../app/Services/CustomStudy/CustomStudyPreviewPolicy.php'
        );
        // Must NOT call now() or Carbon::now() — must use the injected parameter.
        $this->assertStringNotContainsString('Carbon::now()', $source);
        $this->assertStringNotContainsString('now()', $source);
        // Must accept a Carbon parameter in both methods.
        $reflection = new ReflectionClass(CustomStudyPreviewPolicy::class);
        $applyRatingParams = $reflection->getMethod('applyRating')->getParameters();
        $resumeParams = $reflection->getMethod('resume')->getParameters();

        $applyRatingHasCarbon = false;
        foreach ($applyRatingParams as $p) {
            $type = $p->getType();
            if ($type !== null && $type->getName() === Carbon::class) {
                $applyRatingHasCarbon = true;
                break;
            }
        }
        $this->assertTrue($applyRatingHasCarbon, 'applyRating must accept a Carbon parameter');

        $resumeHasCarbon = false;
        foreach ($resumeParams as $p) {
            $type = $p->getType();
            if ($type !== null && $type->getName() === Carbon::class) {
                $resumeHasCarbon = true;
                break;
            }
        }
        $this->assertTrue($resumeHasCarbon, 'resume must accept a Carbon parameter');
    }

    // ---------- Additional: applyRating with Again when ready empty picks mature delayed ----------

    public function test_again_with_ready_empty_picks_mature_delayed_as_next(): void
    {
        $state = $this->stateFromPayload([
            'ordered_candidate_ids' => [11, 12],
            'ready_queue' => [],
            'delayed_repeat_queue' => [
                ['card_id' => 12, 'available_at' => 1720000050],
            ],
            'total_count' => 2,
        ]);
        $next = $this->policy()->applyRating($state, 'again', $this->now());

        // 11 → delayed (available_at = now+60). 12 is mature → becomes current.
        $this->assertSame(12, $next->currentCardId());
        $delayedIds = array_map(fn ($e) => $e['card_id'], $next->delayedRepeatQueue());
        $this->assertContains(11, $delayedIds);
        $this->assertNotContains(12, $delayedIds);
    }

    // ---------- Additional: full session lifecycle sanity check ----------

    public function test_full_session_lifecycle_again_then_good_completes(): void
    {
        $policy = $this->policy();
        $now = $this->now();

        // Session with 2 cards: current=11, ready=[12]
        $state = $this->stateFromPayload([
            'ordered_candidate_ids' => [11, 12],
            'ready_queue' => [12],
            'total_count' => 2,
        ]);

        // Rate 11 as 'again' → 11 goes to delayed, 12 becomes current
        $afterAgain = $policy->applyRating($state, 'again', $now);
        $this->assertSame(12, $afterAgain->currentCardId());
        $this->assertSame(1, $afterAgain->step());

        // Rate 12 as 'good' → 12 goes to completed, no ready, no mature delayed → current=null
        $afterGood = $policy->applyRating($afterAgain, 'good', $now);
        $this->assertNull($afterGood->currentCardId());
        $this->assertFalse($afterGood->isCompleted()); // delayed still has 11
        $this->assertSame(2, $afterGood->step());

        // Wait until 11 matures, then resume → 11 becomes current
        $future = Carbon::createFromTimestamp(1720000161); // 1 second after 11 matures
        $resumed = $policy->resume($afterGood, $future);
        $this->assertSame(11, $resumed->currentCardId());
        $this->assertSame(3, $resumed->step());

        // Rate 11 as 'easy' → 11 goes to completed, all done
        $final = $policy->applyRating($resumed, 'easy', $future);
        $this->assertNull($final->currentCardId());
        $this->assertTrue($final->isCompleted());
        $this->assertSame([12, 11], $final->completedIds());
    }
}
