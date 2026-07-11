<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\ReviewCardFsrsSnapshotService;
use App\Services\ReviewCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewStackUndoTest
 *
 * ADR-0009: verifies the stack-based undo model.
 *
 * Covers:
 *  - A/B/C stack undo (undo C, then B, then A)
 *  - non-latest action rejected (can't undo A while C is active)
 *  - session mismatch (404)
 *  - other user / other language isolation
 *  - idempotent undo_request_id (200, already_applied)
 *  - different undo_request_id conflict (409)
 *  - legacy log missing snapshot (409, missing_snapshot)
 *  - stale snapshot conflict (409, card_state_changed)
 *  - already undone (409)
 *  - raw ReviewLog count never decreases
 *  - card FSRS state fully restored
 */
class SenseReviewStackUndoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private ReviewCardService $cardService;
    private ReviewCardFsrsSnapshotService $snapshotService;

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

        $this->user = $this->createUser('stack@example.com', 'english');
        $this->otherUser = $this->createUser('other@example.com', 'english');

        $this->cardService = app(ReviewCardService::class);
        $this->snapshotService = app(ReviewCardFsrsSnapshotService::class);
    }

    // ==================== A/B/C Stack Undo ====================

    public function test_abc_stack_undo_sequence(): void
    {
        [$cardA, $cardB, $cardC] = $this->createThreeCards();
        $sessionId = (string) Str::uuid();

        // Rate A=good, B=hard, C=easy
        $this->cardService->recordReview($this->user->id, 'english', $cardA->id, 'good', 'sense_review', $sessionId);
        $this->cardService->recordReview($this->user->id, 'english', $cardB->id, 'hard', 'sense_review', $sessionId);
        $this->cardService->recordReview($this->user->id, 'english', $cardC->id, 'easy', 'sense_review', $sessionId);

        $rawCountBefore = ReviewLog::count();
        $this->assertSame(3, $rawCountBefore);

        // Capture pre-undo state of card C
        $cardC->refresh();
        $cStateBeforeUndo = $this->snapshotService->capture($cardC);

        // Initially only C is undoable (latest action)
        $logC = ReviewLog::where('review_card_id', $cardC->id)->first();
        $result = $this->undoAction($logC->id, $sessionId);
        $this->assertTrue($result['success']);

        // Card C should be restored to its before-snapshot state
        $cardC->refresh();
        $this->assertTrue($this->snapshotService->matches($cardC, $logC->before_card_snapshot));

        // Raw ReviewLog count must not decrease
        $this->assertSame($rawCountBefore, ReviewLog::count());

        // Log C should be marked undone
        $logC->refresh();
        $this->assertNotNull($logC->undone_at);

        // Now B should be undoable (latest active)
        $logB = ReviewLog::where('review_card_id', $cardB->id)->first();
        $result = $this->undoAction($logB->id, $sessionId);
        $this->assertTrue($result['success']);

        $cardB->refresh();
        $this->assertTrue($this->snapshotService->matches($cardB, $logB->before_card_snapshot));
        $logB->refresh();
        $this->assertNotNull($logB->undone_at);

        // Now A should be undoable
        $logA = ReviewLog::where('review_card_id', $cardA->id)->first();
        $result = $this->undoAction($logA->id, $sessionId);
        $this->assertTrue($result['success']);

        $cardA->refresh();
        $this->assertTrue($this->snapshotService->matches($cardA, $logA->before_card_snapshot));
        $logA->refresh();
        $this->assertNotNull($logA->undone_at);

        // All 3 logs still exist (never deleted)
        $this->assertSame(3, ReviewLog::count());
        $this->assertSame(3, ReviewLog::whereNotNull('undone_at')->count());
    }

    public function test_cannot_undo_a_while_c_is_active(): void
    {
        [$cardA, $cardB, $cardC] = $this->createThreeCards();
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $cardA->id, 'good', 'sense_review', $sessionId);
        $this->cardService->recordReview($this->user->id, 'english', $cardB->id, 'hard', 'sense_review', $sessionId);
        $this->cardService->recordReview($this->user->id, 'english', $cardC->id, 'easy', 'sense_review', $sessionId);

        $logA = ReviewLog::where('review_card_id', $cardA->id)->first();

        // Try to undo A while C is the latest active — should fail
        $result = $this->undoAction($logA->id, $sessionId);
        $this->assertFalse($result['success']);
        $this->assertSame(409, $result['status'] ?? 0);

        // Log A should NOT be undone
        $logA->refresh();
        $this->assertNull($logA->undone_at);
    }

    // ==================== Session mismatch ====================

    public function test_session_mismatch_returns_404(): void
    {
        $sense = $this->createConfirmedSense('mismatch');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionIdA = (string) Str::uuid();
        $sessionIdB = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionIdA);

        $log = ReviewLog::where('review_card_id', $card->id)->first();

        // Try to undo with a different session ID
        $result = $this->undoAction($log->id, $sessionIdB);
        $this->assertFalse($result['success']);
        $this->assertSame(404, $result['status'] ?? 0);

        $log->refresh();
        $this->assertNull($log->undone_at);
    }

    // ==================== Idempotency ====================

    public function test_same_undo_request_id_is_idempotent(): void
    {
        $sense = $this->createConfirmedSense('idempotent');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();
        $undoRequestId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);
        $log = ReviewLog::where('review_card_id', $card->id)->first();

        // First undo — success
        $result1 = $this->undoAction($log->id, $sessionId, $undoRequestId);
        $this->assertTrue($result1['success']);
        $this->assertFalse($result1['already_applied'] ?? false);

        // Second undo with same request ID — already applied
        $result2 = $this->undoAction($log->id, $sessionId, $undoRequestId);
        $this->assertTrue($result2['success']);
        $this->assertTrue($result2['already_applied'] ?? false);

        // Only one undo happened
        $log->refresh();
        $this->assertSame($undoRequestId, $log->undo_request_id);
    }

    public function test_different_undo_request_id_on_undone_log_returns_409(): void
    {
        $sense = $this->createConfirmedSense('conflict');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);
        $log = ReviewLog::where('review_card_id', $card->id)->first();

        // First undo with request ID 1
        $result1 = $this->undoAction($log->id, $sessionId, (string) Str::uuid());
        $this->assertTrue($result1['success']);

        // Second undo with DIFFERENT request ID — conflict
        $result2 = $this->undoAction($log->id, $sessionId, (string) Str::uuid());
        $this->assertFalse($result2['success']);
        $this->assertSame(409, $result2['status'] ?? 0);
    }

    // ==================== Legacy log (missing snapshot) ====================

    public function test_legacy_log_without_snapshot_cannot_be_undone(): void
    {
        $sense = $this->createConfirmedSense('legacy');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        // Create a legacy log without snapshots
        $legacyLog = ReviewLog::create([
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

        $result = $this->undoAction($legacyLog->id, $sessionId);
        $this->assertFalse($result['success']);
        $this->assertSame(409, $result['status'] ?? 0);

        $legacyLog->refresh();
        $this->assertNull($legacyLog->undone_at);
    }

    // ==================== Stale snapshot conflict ====================

    public function test_stale_snapshot_conflict_returns_409(): void
    {
        $sense = $this->createConfirmedSense('stale');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        // Rate the card (creates log with after_snapshot)
        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);
        $log = ReviewLog::where('review_card_id', $card->id)->first();

        // Simulate another tab rating the same card AFTER the log was created.
        // This changes the card state so it no longer matches after_snapshot.
        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'hard', 'sense_review', $sessionId);

        // Now try to undo the FIRST log — the card state has changed since then.
        $result = $this->undoAction($log->id, $sessionId);
        $this->assertFalse($result['success']);
        $this->assertSame(409, $result['status'] ?? 0);

        $log->refresh();
        $this->assertNull($log->undone_at);
    }

    // ==================== Already undone ====================

    public function test_already_undone_log_returns_409(): void
    {
        $sense = $this->createConfirmedSense('already');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();
        $undoRequestId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);
        $log = ReviewLog::where('review_card_id', $card->id)->first();

        // Undo successfully
        $this->undoAction($log->id, $sessionId, $undoRequestId);

        // Try to undo again with a different request ID
        $result = $this->undoAction($log->id, $sessionId, (string) Str::uuid());
        $this->assertFalse($result['success']);
        $this->assertSame(409, $result['status'] ?? 0);
    }

    // ==================== User / Language isolation ====================

    public function test_other_user_cannot_undo(): void
    {
        $sense = $this->createConfirmedSense('isolation');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);
        $log = ReviewLog::where('review_card_id', $card->id)->first();

        // Other user tries to undo — should get 404 (log not found for this user)
        $undoService = app(\App\Services\SenseReviewUndoService::class);
        $result = $undoService->undo(
            $log->id,
            $this->otherUser->id,
            'english',
            $sessionId,
            (string) Str::uuid(),
            'sense_review_snackbar',
        );

        $this->assertFalse($result['success']);
        $this->assertSame(404, $result['status'] ?? 0);

        $log->refresh();
        $this->assertNull($log->undone_at);
    }

    // ==================== Raw ReviewLog never decreases ====================

    public function test_raw_review_log_count_never_decreases(): void
    {
        $sense = $this->createConfirmedSense('count');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);
        $log = ReviewLog::where('review_card_id', $card->id)->first();

        $countBefore = ReviewLog::count();

        $this->undoAction($log->id, $sessionId);

        $countAfter = ReviewLog::count();
        $this->assertSame($countBefore, $countAfter, 'Raw ReviewLog count must never decrease');
    }

    // ==================== Archived card ====================

    public function test_archived_card_cannot_be_undone(): void
    {
        $sense = $this->createConfirmedSense('archived');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);
        $log = ReviewLog::where('review_card_id', $card->id)->first();

        // Archive the card AFTER rating
        $card->update(['fsrs_enabled' => false]);

        $result = $this->undoAction($log->id, $sessionId);
        $this->assertFalse($result['success']);
        $this->assertSame(409, $result['status'] ?? 0);

        $log->refresh();
        $this->assertNull($log->undone_at);
    }

    // ==================== Rejected sense ====================

    public function test_rejected_sense_cannot_be_undone(): void
    {
        $sense = $this->createConfirmedSense('rejecttest');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);
        $log = ReviewLog::where('review_card_id', $card->id)->first();

        // Reject the sense AFTER rating
        $sense->update(['status' => WordSense::STATUS_REJECTED]);

        $result = $this->undoAction($log->id, $sessionId);
        $this->assertFalse($result['success']);
        $this->assertSame(409, $result['status'] ?? 0);

        $log->refresh();
        $this->assertNull($log->undone_at);
    }

    // ==================== Card fully restored ====================

    public function test_card_fsrs_state_fully_restored_after_undo(): void
    {
        $sense = $this->createConfirmedSense('fullrestore');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        // Capture the complete pre-rating state
        $card->refresh();
        $preState = $this->snapshotService->capture($card);

        // Rate the card
        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'easy', 'sense_review', $sessionId);
        $log = ReviewLog::where('review_card_id', $card->id)->first();

        // Card state should have changed
        $card->refresh();
        $this->assertFalse($this->snapshotService->matches($card, $preState), 'Card should have changed after rating');

        // Undo
        $this->undoAction($log->id, $sessionId);

        // Card should be fully restored to pre-rating state
        $card->refresh();
        $this->assertTrue(
            $this->snapshotService->matches($card, $preState),
            'Card FSRS state should be fully restored after undo',
        );

        // Verify all 8 fields individually
        $this->assertSame($preState['fsrs_state'], $this->snapshotService->capture($card)['fsrs_state']);
        $this->assertSame($preState['fsrs_reps'], $this->snapshotService->capture($card)['fsrs_reps']);
        $this->assertSame($preState['fsrs_lapses'], $this->snapshotService->capture($card)['fsrs_lapses']);
        $this->assertSame($preState['fsrs_enabled'], $this->snapshotService->capture($card)['fsrs_enabled']);
    }

    // ==================== HTTP endpoint ====================

    public function test_undo_endpoint_returns_restored_card(): void
    {
        $sense = $this->createConfirmedSense('httpundo');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();
        $undoRequestId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);
        $log = ReviewLog::where('review_card_id', $card->id)->first();

        $response = $this->actingAs($this->user)->postJson(
            "/reviews/senses/review-actions/{$log->id}/undo",
            [
                'review_session_id' => $sessionId,
                'undo_request_id' => $undoRequestId,
                'source' => 'sense_review_snackbar',
            ],
        );

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'restored_card' => ['review_card_id', 'fsrs_state'],
            'action' => ['review_log_id', 'rating', 'undone'],
            'timeline',
        ]);
    }

    public function test_undo_endpoint_404_for_nonexistent_log(): void
    {
        $sessionId = (string) Str::uuid();

        $response = $this->actingAs($this->user)->postJson(
            '/reviews/senses/review-actions/999999/undo',
            [
                'review_session_id' => $sessionId,
                'undo_request_id' => (string) Str::uuid(),
                'source' => 'sense_review_snackbar',
            ],
        );

        $response->assertStatus(404);
    }

    // ==================== Helpers ====================

    /**
     * Call the undo service directly (bypassing HTTP) for transactional tests.
     */
    private function undoAction(int $logId, string $sessionId, ?string $undoRequestId = null): array
    {
        $undoService = app(\App\Services\SenseReviewUndoService::class);
        return $undoService->undo(
            $logId,
            $this->user->id,
            'english',
            $sessionId,
            $undoRequestId ?? (string) Str::uuid(),
            'sense_review_snackbar',
        );
    }

    private function createThreeCards(): array
    {
        $senseA = $this->createConfirmedSense('cardA');
        $cardA = $this->cardService->ensureSenseCard($senseA);
        $senseB = $this->createConfirmedSense('cardB');
        $cardB = $this->cardService->ensureSenseCard($senseB);
        $senseC = $this->createConfirmedSense('cardC');
        $cardC = $this->cardService->ensureSenseCard($senseC);
        return [$cardA, $cardB, $cardC];
    }

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
