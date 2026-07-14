<?php

namespace Tests\Unit;

use App\Services\CustomStudy\CustomStudyCriteria;
use App\Services\CustomStudy\CustomStudyPreviewPolicy;
use App\Services\CustomStudy\CustomStudySessionState;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Task 2000-22 — Phase 4B PreviewPolicy::resolveEligibility() tests.
 *
 * Verifies the pure eligibility resolution method that moves ineligible cards
 * from current/ready/delayed to skipped_ineligible WITHOUT incrementing step.
 *
 * Pure unit tests — no Laravel container, no DB, no Auth, no Request, no Crypt.
 *
 * These tests are RED until DEV-ELIGIBILITY-POLICY-1 lands the implementation.
 */
class CustomStudyPreviewPolicyEligibilityTest extends TestCase
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

    private function policy(): CustomStudyPreviewPolicy
    {
        return new CustomStudyPreviewPolicy();
    }

    /**
     * Creates an initial state with three candidates [11, 12, 13]:
     *   current = 11, ready = [12, 13], delayed = [], completed = [], skipped = [].
     *   available_candidate_count = 5 (simulates card_limit truncation).
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
            $this->validDelayConfig(),
            5
        );
    }

    /**
     * Reconstructs a state from a payload so tests can drive edge cases.
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
            'available_candidate_count' => 3,
        ];
        return CustomStudySessionState::fromArray(array_merge($base, $overrides));
    }

    // ---------- 1. Method exists ----------

    public function test_resolve_eligibility_method_exists(): void
    {
        $this->assertTrue(
            method_exists(CustomStudyPreviewPolicy::class, 'resolveEligibility'),
            'CustomStudyPreviewPolicy must expose resolveEligibility() method.'
        );
    }

    // ---------- 2. Returns a new instance ----------

    public function test_resolve_eligibility_returns_new_instance(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $next = $this->policy()->resolveEligibility($state, []);

        $this->assertInstanceOf(CustomStudySessionState::class, $next);
        $this->assertNotSame($state, $next);
    }

    // ---------- 3. Does NOT increment step (invariant 18) ----------

    public function test_resolve_eligibility_does_not_increment_step(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $this->assertSame(0, $state->step());

        $next = $this->policy()->resolveEligibility($state, [12]);

        $this->assertSame(0, $next->step(), 'resolveEligibility must NOT increment step.');
    }

    // ---------- 4. Preserves available_candidate_count ----------

    public function test_resolve_eligibility_preserves_available_candidate_count(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $this->assertSame(5, $state->availableCandidateCount());

        $next = $this->policy()->resolveEligibility($state, [12]);

        $this->assertSame(5, $next->availableCandidateCount());
    }

    // ---------- 5. Preserves identity fields ----------

    public function test_resolve_eligibility_preserves_identity_fields(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $next = $this->policy()->resolveEligibility($state, [12]);

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
    }

    // ---------- 6. Empty ineligible list → state unchanged ----------

    public function test_resolve_eligibility_with_empty_ineligible_returns_equivalent_state(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        $next = $this->policy()->resolveEligibility($state, []);

        $this->assertSame($state->currentCardId(), $next->currentCardId());
        $this->assertSame($state->readyQueue(), $next->readyQueue());
        $this->assertSame($state->delayedRepeatQueue(), $next->delayedRepeatQueue());
        $this->assertSame($state->completedIds(), $next->completedIds());
        $this->assertSame($state->skippedIneligibleIds(), $next->skippedIneligibleIds());
    }

    // ---------- 7. Moves ineligible ready card to skipped ----------

    public function test_resolve_eligibility_moves_ineligible_ready_card_to_skipped(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        // current=11, ready=[12, 13], delayed=[], completed=[], skipped=[]
        $next = $this->policy()->resolveEligibility($state, [12]);

        $this->assertNotContains(12, $next->readyQueue());
        $this->assertContains(12, $next->skippedIneligibleIds());
    }

    // ---------- 8. Keeps eligible ready cards ----------

    public function test_resolve_eligibility_keeps_eligible_ready_cards(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        // current=11, ready=[12, 13]
        $next = $this->policy()->resolveEligibility($state, [12]);

        $this->assertContains(13, $next->readyQueue());
    }

    // ---------- 9. Moves ineligible delayed card to skipped ----------

    public function test_resolve_eligibility_moves_ineligible_delayed_card_to_skipped(): void
    {
        $state = $this->stateFromPayload([
            'ready_queue' => [],
            'delayed_repeat_queue' => [
                ['card_id' => 12, 'available_at' => 1720000160],
                ['card_id' => 13, 'available_at' => 1720000700],
            ],
        ]);
        $next = $this->policy()->resolveEligibility($state, [12]);

        $delayedIds = array_map(fn ($e) => $e['card_id'], $next->delayedRepeatQueue());
        $this->assertNotContains(12, $delayedIds);
        $this->assertContains(12, $next->skippedIneligibleIds());
    }

    // ---------- 10. Keeps eligible delayed cards ----------

    public function test_resolve_eligibility_keeps_eligible_delayed_cards(): void
    {
        $state = $this->stateFromPayload([
            'ready_queue' => [],
            'delayed_repeat_queue' => [
                ['card_id' => 12, 'available_at' => 1720000160],
                ['card_id' => 13, 'available_at' => 1720000700],
            ],
        ]);
        $next = $this->policy()->resolveEligibility($state, [12]);

        $delayedIds = array_map(fn ($e) => $e['card_id'], $next->delayedRepeatQueue());
        $this->assertContains(13, $delayedIds);
    }

    // ---------- 11. Moves ineligible current card to skipped ----------

    public function test_resolve_eligibility_moves_ineligible_current_to_skipped(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        // current=11, ready=[12, 13]
        $next = $this->policy()->resolveEligibility($state, [11]);

        $this->assertContains(11, $next->skippedIneligibleIds());
        $this->assertNotSame(11, $next->currentCardId());
    }

    // ---------- 12. Picks next from ready when current is ineligible ----------

    public function test_resolve_eligibility_picks_next_from_ready_when_current_ineligible(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        // current=11, ready=[12, 13]
        $next = $this->policy()->resolveEligibility($state, [11]);

        $this->assertSame(12, $next->currentCardId());
        $this->assertSame([13], $next->readyQueue());
    }

    // ---------- 13. Sets current to null when current is ineligible and ready is empty ----------

    public function test_resolve_eligibility_sets_current_null_when_current_ineligible_and_ready_empty(): void
    {
        $state = $this->stateFromPayload([
            'current_card_id' => 11,
            'ready_queue' => [],
            'delayed_repeat_queue' => [
                ['card_id' => 12, 'available_at' => 1720000160],
                ['card_id' => 13, 'available_at' => 1720000700],
            ],
        ]);
        $next = $this->policy()->resolveEligibility($state, [11]);

        $this->assertNull($next->currentCardId());
        $this->assertContains(11, $next->skippedIneligibleIds());
    }

    // ---------- 14. Handles all cards ineligible ----------

    public function test_resolve_eligibility_handles_all_cards_ineligible(): void
    {
        $state = $this->initialStateWithThreeCandidates();
        // current=11, ready=[12, 13]
        $next = $this->policy()->resolveEligibility($state, [11, 12, 13]);

        $this->assertNull($next->currentCardId());
        $this->assertSame([], $next->readyQueue());
        $this->assertSame([], $next->delayedRepeatQueue());
        // All three cards should be in skipped_ineligible (order may vary
        // because ready is processed before current).
        $this->assertEqualsCanonicalizing([11, 12, 13], $next->skippedIneligibleIds());
    }

    // ---------- 15. Ignores ineligible IDs already in completed ----------

    public function test_resolve_eligibility_ignores_ineligible_ids_already_in_completed(): void
    {
        $state = $this->stateFromPayload([
            'current_card_id' => 13,
            'ready_queue' => [],
            'completed_ids' => [11, 12],
            'completed_count' => 2,
        ]);
        $next = $this->policy()->resolveEligibility($state, [11]);

        // 11 is already in completed — should NOT appear in skipped
        $this->assertNotContains(11, $next->skippedIneligibleIds());
        $this->assertContains(11, $next->completedIds());
    }

    // ---------- 16. Ignores ineligible IDs already in skipped ----------

    public function test_resolve_eligibility_ignores_ineligible_ids_already_in_skipped(): void
    {
        $state = $this->stateFromPayload([
            'current_card_id' => 13,
            'ready_queue' => [],
            'delayed_repeat_queue' => [
                ['card_id' => 12, 'available_at' => 1720000160],
            ],
            'skipped_ineligible_ids' => [11],
        ]);
        $next = $this->policy()->resolveEligibility($state, [11]);

        // 11 should appear exactly once in skipped, not duplicated
        $skipped = $next->skippedIneligibleIds();
        $count = array_filter($skipped, fn ($id) => $id === 11);
        $this->assertCount(1, $count);
    }

    // ---------- 17. Handles mixed ineligible from ready + delayed + current ----------

    public function test_resolve_eligibility_handles_mixed_ineligible_from_ready_delayed_current(): void
    {
        $state = $this->stateFromPayload([
            'ordered_candidate_ids' => [11, 12, 13, 14, 15],
            'current_card_id' => 11,
            'ready_queue' => [12, 13],
            'delayed_repeat_queue' => [
                ['card_id' => 14, 'available_at' => 1720000160],
            ],
            'completed_ids' => [15],
            'completed_count' => 1,
            'total_count' => 5,
            'available_candidate_count' => 5,
        ]);
        // Make 11 (current), 12 (ready), 14 (delayed) ineligible
        $next = $this->policy()->resolveEligibility($state, [11, 12, 14]);

        // 11 moved from current to skipped, next picked from ready (13)
        $this->assertSame(13, $next->currentCardId());
        $this->assertContains(11, $next->skippedIneligibleIds());
        $this->assertContains(12, $next->skippedIneligibleIds());
        $this->assertContains(14, $next->skippedIneligibleIds());
        // 13 was in ready, now is current
        $this->assertNotContains(13, $next->readyQueue());
        // 14 removed from delayed
        $delayedIds = array_map(fn ($e) => $e['card_id'], $next->delayedRepeatQueue());
        $this->assertNotContains(14, $delayedIds);
        // 15 still in completed
        $this->assertContains(15, $next->completedIds());
    }

    // ---------- 18. Preserves completed_ids ----------

    public function test_resolve_eligibility_preserves_completed_ids(): void
    {
        $state = $this->stateFromPayload([
            'current_card_id' => 13,
            'ready_queue' => [],
            'completed_ids' => [11, 12],
            'completed_count' => 2,
        ]);
        $next = $this->policy()->resolveEligibility($state, []);

        $this->assertSame([11, 12], $next->completedIds());
    }

    // ---------- 19. Method signature has exactly 2 parameters ----------

    public function test_resolve_eligibility_signature_has_two_parameters(): void
    {
        $reflection = new ReflectionClass(CustomStudyPreviewPolicy::class);
        $method = $reflection->getMethod('resolveEligibility');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('state', $params[0]->getName());
        $this->assertSame('ineligibleCardIds', $params[1]->getName());
    }

    // ---------- 20. Pure method — no DB/Auth/Request/Crypt in source ----------

    public function test_resolve_eligibility_source_is_pure(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(CustomStudyPreviewPolicy::class))->getFileName()
        );

        // Extract the resolveEligibility method body
        $start = strpos($source, 'function resolveEligibility');
        $this->assertNotFalse($start, 'resolveEligibility method must exist in source');

        $end = strpos($source, "\n    }", $start);
        $this->assertNotFalse($end, 'resolveEligibility method must have a closing brace');

        $methodBody = substr($source, $start, $end - $start);

        // Pure method constraints — no side-effecting operations
        $this->assertStringNotContainsString('Auth::', $methodBody);
        $this->assertStringNotContainsString('Request::', $methodBody);
        $this->assertStringNotContainsString('Crypt::', $methodBody);
        $this->assertStringNotContainsString('DB::', $methodBody);
        $this->assertStringNotContainsString('ReviewLog', $methodBody);
        $this->assertStringNotContainsString('->toArray()', $methodBody);
        $this->assertStringNotContainsString('::fromArray(', $methodBody);
    }
}
