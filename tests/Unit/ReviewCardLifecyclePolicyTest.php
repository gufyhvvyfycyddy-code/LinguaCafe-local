<?php

namespace Tests\Unit;

use App\Models\ReviewCard;
use App\Services\ReviewCardLifecyclePolicy;
use Carbon\Carbon;
use Tests\TestCase;

class ReviewCardLifecyclePolicyTest extends TestCase
{
    private ReviewCardLifecyclePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ReviewCardLifecyclePolicy();
    }

    // ─── canTransition ───

    public function test_can_transition_active_to_buried(): void
    {
        $this->assertTrue($this->policy->canTransition('active', 'bury'));
    }

    public function test_can_transition_active_to_suspended(): void
    {
        $this->assertTrue($this->policy->canTransition('active', 'suspend'));
    }

    public function test_can_transition_active_to_archived(): void
    {
        $this->assertTrue($this->policy->canTransition('active', 'archive'));
    }

    public function test_can_transition_buried_to_active_via_unbury(): void
    {
        $this->assertTrue($this->policy->canTransition('buried', 'unbury'));
    }

    public function test_can_transition_suspended_to_active_via_resume(): void
    {
        $this->assertTrue($this->policy->canTransition('suspended', 'resume'));
    }

    public function test_can_transition_suspended_to_archived(): void
    {
        $this->assertTrue($this->policy->canTransition('suspended', 'archive'));
    }

    public function test_can_transition_archived_to_active_via_restore(): void
    {
        $this->assertTrue($this->policy->canTransition('archived', 'restore'));
    }

    // ─── Illegal transitions ───

    public function test_cannot_transition_buried_to_archived(): void
    {
        $this->assertFalse($this->policy->canTransition('buried', 'archive'));
    }

    public function test_cannot_transition_buried_to_suspended(): void
    {
        $this->assertFalse($this->policy->canTransition('buried', 'suspend'));
    }

    public function test_cannot_transition_archived_to_suspended(): void
    {
        $this->assertFalse($this->policy->canTransition('archived', 'suspend'));
    }

    public function test_cannot_transition_active_to_unbury(): void
    {
        $this->assertFalse($this->policy->canTransition('active', 'unbury'));
    }

    public function test_cannot_transition_active_to_resume(): void
    {
        $this->assertFalse($this->policy->canTransition('active', 'resume'));
    }

    public function test_cannot_transition_active_to_restore(): void
    {
        $this->assertFalse($this->policy->canTransition('active', 'restore'));
    }

    public function test_cannot_transition_suspended_to_bury(): void
    {
        $this->assertFalse($this->policy->canTransition('suspended', 'bury'));
    }

    public function test_cannot_transition_archived_to_bury(): void
    {
        $this->assertFalse($this->policy->canTransition('archived', 'bury'));
    }

    // ─── transitionTo ───

    public function test_transition_to_returns_target_state(): void
    {
        $this->assertSame('buried', $this->policy->transitionTo('active', 'bury'));
        $this->assertSame('suspended', $this->policy->transitionTo('active', 'suspend'));
        $this->assertSame('archived', $this->policy->transitionTo('active', 'archive'));
        $this->assertSame('active', $this->policy->transitionTo('buried', 'unbury'));
        $this->assertSame('active', $this->policy->transitionTo('suspended', 'resume'));
        $this->assertSame('archived', $this->policy->transitionTo('suspended', 'archive'));
        $this->assertSame('active', $this->policy->transitionTo('archived', 'restore'));
    }

    public function test_transition_to_returns_null_for_illegal(): void
    {
        $this->assertNull($this->policy->transitionTo('active', 'unbury'));
        $this->assertNull($this->policy->transitionTo('buried', 'archive'));
        $this->assertNull($this->policy->transitionTo('archived', 'suspend'));
    }

    // ─── effectiveState ───

    public function test_effective_state_active_is_active(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'active']);
        $this->assertSame('active', $this->policy->effectiveState($card, Carbon::now()));
    }

    public function test_effective_state_buried_not_expired_is_buried(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'buried',
            'buried_until' => Carbon::now()->addHours(12),
        ]);
        $this->assertSame('buried', $this->policy->effectiveState($card, Carbon::now()));
    }

    public function test_effective_state_buried_expired_is_active(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'buried',
            'buried_until' => Carbon::now()->subHour(),
        ]);
        $this->assertSame('active', $this->policy->effectiveState($card, Carbon::now()));
    }

    public function test_effective_state_suspended_is_suspended(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'suspended']);
        $this->assertSame('suspended', $this->policy->effectiveState($card, Carbon::now()));
    }

    public function test_effective_state_archived_is_archived(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'archived']);
        $this->assertSame('archived', $this->policy->effectiveState($card, Carbon::now()));
    }

    // ─── isTemporarilyBuried ───

    public function test_is_temporarily_buried_true_when_buried_and_future(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'buried',
            'buried_until' => Carbon::now()->addHours(12),
        ]);
        $this->assertTrue($this->policy->isTemporarilyBuried($card, Carbon::now()));
    }

    public function test_is_temporarily_buried_false_when_expired(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'buried',
            'buried_until' => Carbon::now()->subHour(),
        ]);
        $this->assertFalse($this->policy->isTemporarilyBuried($card, Carbon::now()));
    }

    public function test_is_temporarily_buried_false_when_active(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'active']);
        $this->assertFalse($this->policy->isTemporarilyBuried($card, Carbon::now()));
    }

    // ─── describe ───

    public function test_describe_active_card(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'active',
            'lifecycle_version' => 0,
        ]);
        $d = $this->policy->describe($card, Carbon::now(), 'UTC');
        $this->assertSame('active', $d['persistent_state']);
        $this->assertFalse($d['temporarily_buried']);
        $this->assertSame('active', $d['effective_state']);
        $this->assertTrue($d['queue_eligible']);
        $this->assertNull($d['blocked_reason']);
        $this->assertContains('bury', $d['available_actions']);
        $this->assertContains('suspend', $d['available_actions']);
        $this->assertContains('archive', $d['available_actions']);
        $this->assertSame(0, $d['version']);
    }

    public function test_describe_buried_card_not_expired(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'buried',
            'buried_until' => Carbon::now()->addHours(12),
            'lifecycle_version' => 3,
        ]);
        $d = $this->policy->describe($card, Carbon::now(), 'Asia/Shanghai');
        $this->assertSame('buried', $d['persistent_state']);
        $this->assertTrue($d['temporarily_buried']);
        $this->assertSame('buried', $d['effective_state']);
        $this->assertFalse($d['queue_eligible']);
        $this->assertSame('temporarily_buried', $d['blocked_reason']);
        $this->assertContains('unbury', $d['available_actions']);
        $this->assertSame(3, $d['version']);
        $this->assertSame('Asia/Shanghai', $d['timezone']);
    }

    public function test_describe_buried_card_expired(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'buried',
            'buried_until' => Carbon::now()->subHour(),
        ]);
        $d = $this->policy->describe($card, Carbon::now(), 'UTC');
        $this->assertSame('buried', $d['persistent_state']);
        $this->assertFalse($d['temporarily_buried']);
        $this->assertSame('active', $d['effective_state']);
        $this->assertTrue($d['queue_eligible']);
        $this->assertContains('bury', $d['available_actions']);
        $this->assertContains('suspend', $d['available_actions']);
    }

    public function test_describe_suspended_card(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'suspended']);
        $d = $this->policy->describe($card, Carbon::now(), 'UTC');
        $this->assertSame('suspended', $d['persistent_state']);
        $this->assertSame('suspended', $d['effective_state']);
        $this->assertFalse($d['queue_eligible']);
        $this->assertSame('suspended', $d['blocked_reason']);
        $this->assertContains('resume', $d['available_actions']);
        $this->assertContains('archive', $d['available_actions']);
        $this->assertNotContains('bury', $d['available_actions']);
    }

    public function test_describe_archived_card(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'archived']);
        $d = $this->policy->describe($card, Carbon::now(), 'UTC');
        $this->assertSame('archived', $d['persistent_state']);
        $this->assertSame('archived', $d['effective_state']);
        $this->assertFalse($d['queue_eligible']);
        $this->assertSame('archived', $d['blocked_reason']);
        $this->assertContains('restore', $d['available_actions']);
        $this->assertNotContains('bury', $d['available_actions']);
        $this->assertNotContains('suspend', $d['available_actions']);
    }

    // ─── isTerminalState ───

    public function test_is_terminal_state_suspended(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'suspended']);
        $this->assertTrue($this->policy->isTerminalState($card));
    }

    public function test_is_terminal_state_archived(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'archived']);
        $this->assertTrue($this->policy->isTerminalState($card));
    }

    public function test_is_not_terminal_state_active(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'active']);
        $this->assertFalse($this->policy->isTerminalState($card));
    }

    public function test_is_not_terminal_state_buried(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'buried',
            'buried_until' => Carbon::now()->addHour(),
        ]);
        $this->assertFalse($this->policy->isTerminalState($card));
    }

    // ─── availableActionsForState ───

    public function test_available_actions_for_active(): void
    {
        $actions = $this->policy->availableActionsForState('active');
        $this->assertCount(3, $actions);
        $this->assertContains('bury', $actions);
        $this->assertContains('suspend', $actions);
        $this->assertContains('archive', $actions);
    }

    public function test_available_actions_for_buried(): void
    {
        $actions = $this->policy->availableActionsForState('buried');
        $this->assertSame(['unbury'], $actions);
    }

    public function test_available_actions_for_suspended(): void
    {
        $actions = $this->policy->availableActionsForState('suspended');
        $this->assertCount(2, $actions);
        $this->assertContains('resume', $actions);
        $this->assertContains('archive', $actions);
    }

    public function test_available_actions_for_archived(): void
    {
        $actions = $this->policy->availableActionsForState('archived');
        $this->assertSame(['restore'], $actions);
    }

    public function test_available_actions_excludes_reset_and_delete(): void
    {
        foreach (['active', 'buried', 'suspended', 'archived'] as $state) {
            $actions = $this->policy->availableActionsForState($state);
            $this->assertNotContains('reset', $actions);
            $this->assertNotContains('delete', $actions);
        }
    }

    // ─── Helper ───

    private function makeCard(array $attrs = []): ReviewCard
    {
        $card = new ReviewCard();
        $card->lifecycle_state = $attrs['lifecycle_state'] ?? 'active';
        $card->buried_until = $attrs['buried_until'] ?? null;
        $card->lifecycle_version = $attrs['lifecycle_version'] ?? 0;
        $card->lifecycle_changed_at = $attrs['lifecycle_changed_at'] ?? null;
        $card->fsrs_enabled = $attrs['fsrs_enabled'] ?? true;
        return $card;
    }
}
