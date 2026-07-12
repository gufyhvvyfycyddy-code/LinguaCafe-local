<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\ReviewCardLifecycleCommandService;
use App\Services\SenseReviewLeechQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewLeechLifecycleBoundaryTest
 *
 * ADR-0011: Tests for the boundary between leech classification and
 * lifecycle state machine.
 *
 * Verifies:
 *  - Leech does NOT directly write lifecycle fields
 *  - Suspended/archived cards still show leech_status on management page
 *  - Suspended/archived cards do NOT appear in review queue
 *  - Suspend must go through lifecycle endpoint, not leech service
 *  - blocked_actions prevents suggesting suspend on suspended cards
 *  - resume preserves leech history
 */
class SenseReviewLeechLifecycleBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private SenseReviewLeechQueryService $leechQuery;
    private ReviewCardLifecycleCommandService $lifecycleCmd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::forceCreate([
            'name' => 'Boundary Test',
            'email' => 'boundary-' . Str::uuid() . '@example.com',
            'password' => \Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $this->leechQuery = app(SenseReviewLeechQueryService::class);
        $this->lifecycleCmd = app(ReviewCardLifecycleCommandService::class);
    }

    public function test_leech_does_not_modify_lifecycle_state(): void
    {
        $card = $this->makeCardWithLeechHistory();
        $originalState = $card->lifecycle_state;

        $this->leechQuery->describeForCard($card);

        $this->assertSame($originalState, $card->fresh()->lifecycle_state);
    }

    public function test_suspended_card_still_shows_leech_status(): void
    {
        $card = $this->makeCardWithLeechHistory();

        // Suspend the card via lifecycle
        $this->lifecycleCmd->act(
            $card, 'suspend', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $this->assertSame('suspended', $card->fresh()->lifecycle_state);

        // Leech status should still be computable
        $result = $this->leechQuery->describeForCard($card);
        $this->assertSame('leech', $result['status']);
    }

    public function test_archived_card_still_shows_leech_status(): void
    {
        $card = $this->makeCardWithLeechHistory();

        // Archive the card via lifecycle
        $this->lifecycleCmd->act(
            $card, 'archive', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $this->assertSame('archived', $card->fresh()->lifecycle_state);

        // Leech status should still be computable
        $result = $this->leechQuery->describeForCard($card);
        $this->assertSame('leech', $result['status']);
    }

    public function test_suspended_card_blocks_suspend_suggestion(): void
    {
        $card = $this->makeCardWithLeechHistory();

        $this->lifecycleCmd->act(
            $card, 'suspend', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        // Refresh the in-memory model — act() modifies a locked copy, not the
        // passed-in instance.
        $card->refresh();

        $result = $this->leechQuery->describeForCard($card);

        $this->assertContains('suspend_temporarily', $result['blocked_actions']);
    }

    public function test_suspended_card_not_in_review_queue(): void
    {
        $card = $this->makeCardWithLeechHistory();

        $this->lifecycleCmd->act(
            $card, 'suspend', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        // The card should NOT be in the review queue
        $queueCount = ReviewCard::senseReviewEligible($this->user->id, 'english', now())
            ->where('id', $card->id)
            ->count();

        $this->assertSame(0, $queueCount);
    }

    public function test_archived_card_not_in_review_queue(): void
    {
        $card = $this->makeCardWithLeechHistory();

        $this->lifecycleCmd->act(
            $card, 'archive', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $queueCount = ReviewCard::senseReviewEligible($this->user->id, 'english', now())
            ->where('id', $card->id)
            ->count();

        $this->assertSame(0, $queueCount);
    }

    public function test_resume_preserves_leech_history(): void
    {
        $card = $this->makeCardWithLeechHistory();

        // Suspend
        $this->lifecycleCmd->act(
            $card, 'suspend', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        // Resume
        $this->lifecycleCmd->act(
            $card, 'resume', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $this->assertSame('active', $card->fresh()->lifecycle_state);

        // Leech status should still reflect the review history
        $result = $this->leechQuery->describeForCard($card);
        $this->assertSame('leech', $result['status']);
    }

    public function test_leech_service_does_not_create_state_events(): void
    {
        $card = $this->makeCardWithLeechHistory();
        $eventsBefore = \App\Models\ReviewCardStateEvent::where('review_card_id', $card->id)->count();

        $this->leechQuery->describeForCard($card);

        $eventsAfter = \App\Models\ReviewCardStateEvent::where('review_card_id', $card->id)->count();
        $this->assertSame($eventsBefore, $eventsAfter);
    }

    public function test_leech_service_does_not_create_review_logs(): void
    {
        $card = $this->makeCardWithLeechHistory();
        $logsBefore = ReviewLog::where('review_card_id', $card->id)->count();

        $this->leechQuery->describeForCard($card);

        $logsAfter = ReviewLog::where('review_card_id', $card->id)->count();
        $this->assertSame($logsBefore, $logsAfter);
    }

    public function test_suspend_via_lifecycle_endpoint_not_leech_service(): void
    {
        $card = $this->makeCardWithLeechHistory();

        // Suspend via HTTP lifecycle endpoint (not leech)
        $response = $this->actingAs($this->user)
            ->postJson("/review-cards/{$card->id}/lifecycle-actions", [
                'action' => 'suspend',
                'request_id' => Str::uuid()->toString(),
                'source' => 'leech_governance_test',
            ]);

        $response->assertStatus(200);
        $this->assertSame('suspended', $card->fresh()->lifecycle_state);
    }

    // ─── Helpers ───

    private function makeCardWithLeechHistory(): ReviewCard
    {
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'test' . Str::random(4),
            'surface_form' => 'test',
            'pos' => 'noun',
            'sense_zh' => '测试',
            'sense_en' => 'test',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'This is a test.',
            'example_sentence_zh' => '这是一个测试。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower('english|test|noun|测试|test')),
        ]);

        $card = ReviewCard::forceCreate([
            'user_id' => $sense->user_id,
            'language_id' => $sense->language_id,
            'language' => $sense->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
            'fsrs_stability' => null,
            'fsrs_difficulty' => null,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 3,
            'lifecycle_state' => 'active',
        ]);

        $ratings = [
            ['rating' => 'again', 'daysAgo' => 10],
            ['rating' => 'again', 'daysAgo' => 8],
            ['rating' => 'again', 'daysAgo' => 6],
            ['rating' => 'good', 'daysAgo' => 4],
            ['rating' => 'easy', 'daysAgo' => 2],
        ];
        foreach ($ratings as $r) {
            ReviewLog::create([
                'user_id' => $card->user_id,
                'language_id' => $card->language_id,
                'language' => $card->language,
                'review_card_id' => $card->id,
                'rating' => $r['rating'],
                'reviewed_at' => now()->subDays($r['daysAgo']),
                'previous_state' => 'review',
                'new_state' => 'review',
                'previous_due_at' => now()->subDays($r['daysAgo'] + 1),
                'new_due_at' => now()->subDays($r['daysAgo'] - 1),
                'previous_stability' => 1.0,
                'new_stability' => 1.5,
                'previous_difficulty' => 5.0,
                'new_difficulty' => 5.0,
                'source' => 'sense_review',
            ]);
        }

        return $card;
    }
}
