<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\ReviewCardFsrsSnapshotService;
use App\Services\ReviewCardService;
use App\Services\SenseReviewSessionActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewSessionActionsTest
 *
 * ADR-0009: verifies the session action timeline endpoint.
 *
 * Covers:
 *  - returns actions for the current session
 *  - includes undone actions (for audit)
 *  - only the latest active action is undoable
 *  - other user / language isolation
 *  - 20-item limit
 *  - N+1 query budget
 *  - HTTP endpoint authentication
 */
class SenseReviewSessionActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private ReviewCardService $cardService;
    private SenseReviewSessionActionService $sessionActionService;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Setting::where('name', 'reviewIntervals')->exists()) {
            Setting::forceCreate([
                'name' => 'reviewIntervals',
                'value' => json_encode([
                    '-7' => [0], '-6' => [1], '-5' => [2], '-4' => [3],
                    '-3' => [7], '-2' => [15], '-1' => [30],
                ]),
            ]);
        }

        $this->user = $this->createUser('session@example.com', 'english');
        $this->otherUser = $this->createUser('other@example.com', 'english');

        $this->cardService = app(ReviewCardService::class);
        $this->sessionActionService = app(SenseReviewSessionActionService::class);
    }

    // ==================== Basic timeline ====================

    public function test_empty_session_returns_empty_array(): void
    {
        $sessionId = (string) Str::uuid();
        $actions = $this->sessionActionService->timeline($this->user->id, 'english', $sessionId);
        $this->assertSame([], $actions);
    }

    public function test_timeline_returns_actions_newest_first(): void
    {
        $sense = $this->createConfirmedSense('timeline');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'again', 'sense_review', $sessionId);
        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'hard', 'sense_review', $sessionId);
        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);

        $actions = $this->sessionActionService->timeline($this->user->id, 'english', $sessionId);

        $this->assertCount(3, $actions);
        // Newest first — good should be first
        $this->assertSame('good', $actions[0]['rating']);
        $this->assertSame('hard', $actions[1]['rating']);
        $this->assertSame('again', $actions[2]['rating']);
    }

    public function test_timeline_includes_undone_actions(): void
    {
        $sense = $this->createConfirmedSense('undone-tl');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);
        $log = ReviewLog::where('review_card_id', $card->id)->first();

        // Undo it
        $undoService = app(\App\Services\SenseReviewUndoService::class);
        $undoService->undo(
            $log->id, $this->user->id, 'english', $sessionId,
            (string) Str::uuid(), 'sense_review_snackbar',
        );

        // Timeline should still include the undone action
        $actions = $this->sessionActionService->timeline($this->user->id, 'english', $sessionId);
        $this->assertCount(1, $actions);
        $this->assertTrue($actions[0]['undone']);
        $this->assertNotNull($actions[0]['undone_at']);
        $this->assertSame('sense_review_snackbar', $actions[0]['undo_source']);
    }

    // ==================== Only latest active is undoable ====================

    public function test_only_latest_active_action_is_undoable(): void
    {
        $senseA = $this->createConfirmedSense('senseA');
        $cardA = $this->cardService->ensureSenseCard($senseA);
        $senseB = $this->createConfirmedSense('senseB');
        $cardB = $this->cardService->ensureSenseCard($senseB);
        $senseC = $this->createConfirmedSense('senseC');
        $cardC = $this->cardService->ensureSenseCard($senseC);
        $sessionId = (string) Str::uuid();

        // Rate A, B, C
        $this->cardService->recordReview($this->user->id, 'english', $cardA->id, 'good', 'sense_review', $sessionId);
        $this->cardService->recordReview($this->user->id, 'english', $cardB->id, 'hard', 'sense_review', $sessionId);
        $this->cardService->recordReview($this->user->id, 'english', $cardC->id, 'easy', 'sense_review', $sessionId);

        $actions = $this->sessionActionService->timeline($this->user->id, 'english', $sessionId);

        $this->assertCount(3, $actions);

        // Only the first (newest = C) should be undoable
        $this->assertTrue($actions[0]['undoable'], 'Latest action (C) should be undoable');
        $this->assertNull($actions[0]['blocked_reason']);

        $this->assertFalse($actions[1]['undoable'], 'Action B should not be undoable');
        $this->assertSame('not_latest_action', $actions[1]['blocked_reason']);

        $this->assertFalse($actions[2]['undoable'], 'Action A should not be undoable');
        $this->assertSame('not_latest_action', $actions[2]['blocked_reason']);
    }

    public function test_after_undoing_latest_previous_becomes_undoable(): void
    {
        $senseA = $this->createConfirmedSense('senseA');
        $cardA = $this->cardService->ensureSenseCard($senseA);
        $senseB = $this->createConfirmedSense('senseB');
        $cardB = $this->cardService->ensureSenseCard($senseB);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $cardA->id, 'good', 'sense_review', $sessionId);
        $this->cardService->recordReview($this->user->id, 'english', $cardB->id, 'hard', 'sense_review', $sessionId);

        $logB = ReviewLog::where('review_card_id', $cardB->id)->first();

        // Undo B
        $undoService = app(\App\Services\SenseReviewUndoService::class);
        $undoService->undo(
            $logB->id, $this->user->id, 'english', $sessionId,
            (string) Str::uuid(), 'sense_review_snackbar',
        );

        // Now A should be undoable
        $actions = $this->sessionActionService->timeline($this->user->id, 'english', $sessionId);
        $this->assertCount(2, $actions);

        // B (newest) is undone
        $this->assertTrue($actions[0]['undone']);

        // A is now the latest active — should be undoable
        $this->assertTrue($actions[1]['undoable'], 'After undoing B, A should be undoable');
        $this->assertNull($actions[1]['blocked_reason']);
    }

    // ==================== Action payload fields ====================

    public function test_timeline_action_has_all_required_fields(): void
    {
        $sense = $this->createConfirmedSense('fields');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);

        $actions = $this->sessionActionService->timeline($this->user->id, 'english', $sessionId);
        $this->assertCount(1, $actions);

        $action = $actions[0];
        $this->assertArrayHasKey('review_log_id', $action);
        $this->assertArrayHasKey('review_card_id', $action);
        $this->assertArrayHasKey('word_sense_id', $action);
        $this->assertArrayHasKey('lemma', $action);
        $this->assertArrayHasKey('sense_zh', $action);
        $this->assertArrayHasKey('rating', $action);
        $this->assertArrayHasKey('rating_label', $action);
        $this->assertArrayHasKey('reviewed_at', $action);
        $this->assertArrayHasKey('previous_due_at', $action);
        $this->assertArrayHasKey('new_due_at', $action);
        $this->assertArrayHasKey('undone', $action);
        $this->assertArrayHasKey('undone_at', $action);
        $this->assertArrayHasKey('undo_source', $action);
        $this->assertArrayHasKey('undoable', $action);
        $this->assertArrayHasKey('blocked_reason', $action);

        $this->assertSame('good', $action['rating']);
        $this->assertSame('记得', $action['rating_label']);
        $this->assertSame('fields', $action['lemma']);
        $this->assertSame('测试', $action['sense_zh']);
    }

    // ==================== User / Language isolation ====================

    public function test_other_user_actions_not_in_timeline(): void
    {
        $sense = $this->createConfirmedSense('otheruser');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);

        // Other user queries with the same session ID — should get nothing
        $actions = $this->sessionActionService->timeline($this->otherUser->id, 'english', $sessionId);
        $this->assertSame([], $actions);
    }

    public function test_other_session_actions_not_in_timeline(): void
    {
        $sense = $this->createConfirmedSense('othersession');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionIdA = (string) Str::uuid();
        $sessionIdB = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionIdA);

        // Query with a different session ID
        $actions = $this->sessionActionService->timeline($this->user->id, 'english', $sessionIdB);
        $this->assertSame([], $actions);
    }

    // ==================== 20-item limit ====================

    public function test_timeline_limited_to_20_items(): void
    {
        $sessionId = (string) Str::uuid();

        // Create 25 cards and rate each once
        for ($i = 0; $i < 25; $i++) {
            $sense = $this->createConfirmedSense("limit{$i}");
            $card = $this->cardService->ensureSenseCard($sense);
            $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);
        }

        $actions = $this->sessionActionService->timeline($this->user->id, 'english', $sessionId);
        $this->assertCount(20, $actions);
    }

    // ==================== Legacy log in timeline ====================

    public function test_legacy_log_in_timeline_is_not_undoable(): void
    {
        $sense = $this->createConfirmedSense('legacytl');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        // Create a legacy log without snapshot
        ReviewLog::create([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'review_card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => now(),
            'previous_state' => 'new',
            'new_state' => 'review',
            'previous_due_at' => now()->subDay(),
            'new_due_at' => now()->addDay(),
            'previous_stability' => 1.0,
            'new_stability' => 1.5,
            'previous_difficulty' => 5.0,
            'new_difficulty' => 4.8,
            'source' => 'sense_review',
            'review_session_id' => $sessionId,
            'before_card_snapshot' => null,
            'after_card_snapshot' => null,
        ]);

        $actions = $this->sessionActionService->timeline($this->user->id, 'english', $sessionId);
        $this->assertCount(1, $actions);
        $this->assertFalse($actions[0]['undoable']);
        $this->assertSame('missing_snapshot', $actions[0]['blocked_reason']);
    }

    // ==================== HTTP endpoint ====================

    public function test_session_actions_endpoint_requires_auth(): void
    {
        $response = $this->getJson('/reviews/senses/session-actions?review_session_id=' . Str::uuid());
        $response->assertStatus(401);
    }

    public function test_session_actions_endpoint_returns_actions(): void
    {
        $sense = $this->createConfirmedSense('http');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);

        $response = $this->actingAs($this->user)->getJson(
            '/reviews/senses/session-actions?review_session_id=' . $sessionId,
        );

        $response->assertOk();
        $response->assertJsonStructure([
            'review_session_id',
            'actions' => [['review_log_id', 'rating', 'undoable']],
        ]);
        $this->assertSame($sessionId, $response->json('review_session_id'));
        $this->assertCount(1, $response->json('actions'));
    }

    public function test_session_actions_endpoint_requires_session_id(): void
    {
        $response = $this->actingAs($this->user)->getJson('/reviews/senses/session-actions');
        $response->assertStatus(422);
    }

    // ==================== Query budget (no N+1) ====================

    public function test_timeline_does_not_have_n_plus_1_queries(): void
    {
        $sessionId = (string) Str::uuid();

        // Create 5 cards and rate each
        for ($i = 0; $i < 5; $i++) {
            $sense = $this->createConfirmedSense("nplus{$i}");
            $card = $this->cardService->ensureSenseCard($sense);
            $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);
        }

        // Enable query log and run timeline
        DB::enableQueryLog();
        $this->sessionActionService->timeline($this->user->id, 'english', $sessionId);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // The timeline should use at most 3 queries:
        // 1. Fetch logs
        // 2. Fetch cards (eager loaded)
        // 3. Fetch senses (eager loaded)
        // Allow a small margin for safety.
        $this->assertLessThanOrEqual(4, count($queries), 'Timeline should not have N+1 queries. Got: ' . count($queries));
    }

    // ==================== Helpers ====================

    private function createConfirmedSense(string $lemma): WordSense
    {
        return WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => '测试',
            'sense_en' => 'test',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => null,
            'example_sentence_zh' => null,
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower("english|{$lemma}|noun|测试|test")),
        ]);
    }

    private function createUser(string $email, string $language): User
    {
        return User::forceCreate([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }
}
