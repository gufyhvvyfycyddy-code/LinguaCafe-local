<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\ReviewCardFsrsSnapshotService;
use App\Services\ReviewCardService;
use App\Services\SenseReviewRatingContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewActionTransactionTest
 *
 * ADR-0009: verifies that recordReview() runs in a transaction
 * and writes before/after snapshots + review_session_id.
 *
 * Covers:
 *  - before/after snapshot capture
 *  - session_id propagation
 *  - all 4 ratings produce snapshots
 *  - new/learning/review/relearning states
 *  - same-card and different-card consecutive ratings
 *  - transaction rollback on failure
 *  - legacy callers (no session_id) still work but can't undo
 */
class SenseReviewActionTransactionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
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

        $this->user = User::forceCreate([
            'name' => 'Transaction User',
            'email' => 'txn@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->cardService = app(ReviewCardService::class);
        $this->snapshotService = app(ReviewCardFsrsSnapshotService::class);
    }

    // ==================== Snapshot capture ====================

    public function test_record_review_writes_before_and_after_snapshots(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview(
            $this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId,
        );

        $log = ReviewLog::where('review_card_id', $card->id)->first();
        $this->assertNotNull($log);
        $this->assertNotNull($log->before_card_snapshot, 'before_card_snapshot must be written');
        $this->assertNotNull($log->after_card_snapshot, 'after_card_snapshot must be written');

        // before snapshot should contain 8 fields
        $this->assertCount(8, $log->before_card_snapshot);
        $this->assertCount(8, $log->after_card_snapshot);

        // before snapshot should reflect the 'new' state
        $this->assertSame('new', $log->before_card_snapshot['fsrs_state']);
        $this->assertSame(0, $log->before_card_snapshot['fsrs_reps']);

        // after snapshot should reflect the updated state
        $card->refresh();
        $this->assertSame($card->fsrs_state, $log->after_card_snapshot['fsrs_state']);
        $this->assertSame($card->fsrs_reps, $log->after_card_snapshot['fsrs_reps']);
    }

    public function test_record_review_writes_session_id(): void
    {
        $sense = $this->createConfirmedSense('banana');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview(
            $this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId,
        );

        $log = ReviewLog::where('review_card_id', $card->id)->first();
        $this->assertSame($sessionId, $log->review_session_id);
    }

    public function test_record_review_without_session_id_still_works(): void
    {
        $sense = $this->createConfirmedSense('cherry');
        $card = $this->cardService->ensureSenseCard($sense);

        // Legacy caller — no session_id
        $this->cardService->recordReview(
            $this->user->id, 'english', $card->id, 'good', 'sense_review', null,
        );

        $log = ReviewLog::where('review_card_id', $card->id)->first();
        $this->assertNotNull($log);
        $this->assertNull($log->review_session_id);
        // Snapshots are still captured (for future use), but undo is not
        // possible without a session_id.
        $this->assertNotNull($log->before_card_snapshot);
        $this->assertNotNull($log->after_card_snapshot);
    }

    // ==================== All 4 ratings ====================

    public function test_all_four_ratings_produce_snapshots(): void
    {
        foreach (['again', 'hard', 'good', 'easy'] as $rating) {
            ReviewLog::query()->delete();
            ReviewCard::query()->delete();
            WordSense::query()->delete();

            $sense = $this->createConfirmedSense("word-{$rating}");
            $card = $this->cardService->ensureSenseCard($sense);
            $sessionId = (string) Str::uuid();

            $this->cardService->recordReview(
                $this->user->id, 'english', $card->id, $rating, 'sense_review', $sessionId,
            );

            $log = ReviewLog::where('review_card_id', $card->id)->first();
            $this->assertNotNull($log->before_card_snapshot, "Rating {$rating}: before snapshot missing");
            $this->assertNotNull($log->after_card_snapshot, "Rating {$rating}: after snapshot missing");
            $this->assertSame($rating, $log->rating);
            $this->assertSame($sessionId, $log->review_session_id);
        }
    }

    // ==================== State transitions ====================

    public function test_new_card_rating_produces_correct_snapshots(): void
    {
        $sense = $this->createConfirmedSense('newword');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        // Before: new card
        $card->refresh();
        $this->assertSame('new', $card->fsrs_state);
        $this->assertSame(0, $card->fsrs_reps);

        $this->cardService->recordReview(
            $this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId,
        );

        $log = ReviewLog::where('review_card_id', $card->id)->first();
        $this->assertSame('new', $log->before_card_snapshot['fsrs_state']);
        $this->assertSame(0, $log->before_card_snapshot['fsrs_reps']);

        $card->refresh();
        $this->assertSame($card->fsrs_state, $log->after_card_snapshot['fsrs_state']);
        $this->assertSame(1, $log->after_card_snapshot['fsrs_reps']);
    }

    public function test_review_card_rating_produces_correct_snapshots(): void
    {
        $sense = $this->createConfirmedSense('reviewword');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        // First rating to move out of 'new'
        $this->cardService->recordReview(
            $this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId,
        );

        $card->refresh();
        $firstState = $card->fsrs_state;

        // Second rating
        $this->cardService->recordReview(
            $this->user->id, 'english', $card->id, 'hard', 'sense_review', $sessionId,
        );

        $logs = ReviewLog::where('review_card_id', $card->id)->orderBy('id', 'desc')->get();
        $this->assertSame(2, $logs->count());

        $latestLog = $logs->first();
        $this->assertSame($firstState, $latestLog->before_card_snapshot['fsrs_state']);
        $this->assertSame(1, $latestLog->before_card_snapshot['fsrs_reps']);

        $card->refresh();
        $this->assertSame($card->fsrs_state, $latestLog->after_card_snapshot['fsrs_state']);
        $this->assertSame(2, $latestLog->after_card_snapshot['fsrs_reps']);
    }

    // ==================== Consecutive ratings ====================

    public function test_same_card_consecutive_ratings_each_have_snapshots(): void
    {
        $sense = $this->createConfirmedSense('consecutive');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        foreach (['again', 'hard', 'good', 'easy'] as $rating) {
            $this->cardService->recordReview(
                $this->user->id, 'english', $card->id, $rating, 'sense_review', $sessionId,
            );
        }

        $logs = ReviewLog::where('review_card_id', $card->id)->orderBy('id')->get();
        $this->assertSame(4, $logs->count());

        foreach ($logs as $log) {
            $this->assertNotNull($log->before_card_snapshot);
            $this->assertNotNull($log->after_card_snapshot);
            $this->assertSame($sessionId, $log->review_session_id);
        }

        // Each log's before snapshot should match the previous log's after snapshot
        // (for the same card, consecutive ratings).
        for ($i = 1; $i < $logs->count(); $i++) {
            $prevAfter = $logs[$i - 1]->after_card_snapshot;
            $currBefore = $logs[$i]->before_card_snapshot;
            $this->assertSame(
                $prevAfter['fsrs_reps'],
                $currBefore['fsrs_reps'],
                'Before snapshot of log ' . $i . ' should match after snapshot of log ' . ($i - 1),
            );
        }
    }

    public function test_different_cards_in_same_session_each_have_snapshots(): void
    {
        $senseA = $this->createConfirmedSense('cardA');
        $cardA = $this->cardService->ensureSenseCard($senseA);
        $senseB = $this->createConfirmedSense('cardB');
        $cardB = $this->cardService->ensureSenseCard($senseB);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview(
            $this->user->id, 'english', $cardA->id, 'good', 'sense_review', $sessionId,
        );
        $this->cardService->recordReview(
            $this->user->id, 'english', $cardB->id, 'easy', 'sense_review', $sessionId,
        );

        $logA = ReviewLog::where('review_card_id', $cardA->id)->first();
        $logB = ReviewLog::where('review_card_id', $cardB->id)->first();

        $this->assertSame($sessionId, $logA->review_session_id);
        $this->assertSame($sessionId, $logB->review_session_id);
        $this->assertNotNull($logA->before_card_snapshot);
        $this->assertNotNull($logB->before_card_snapshot);
    }

    // ==================== Transaction rollback ====================

    public function test_transaction_rollback_on_card_not_found(): void
    {
        $sessionId = (string) Str::uuid();

        // Non-existent card ID — should throw, no log created.
        $this->expectException(\Exception::class);
        $this->cardService->recordReview(
            $this->user->id, 'english', 999999, 'good', 'sense_review', $sessionId,
        );

        $this->assertSame(0, ReviewLog::count());
    }

    public function test_transaction_rollback_on_disabled_card(): void
    {
        $sense = $this->createConfirmedSense('disabled');
        $card = $this->cardService->ensureSenseCard($sense);
        $card->update(['fsrs_enabled' => false]);
        $sessionId = (string) Str::uuid();

        $this->expectException(\Exception::class);
        $this->cardService->recordReview(
            $this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId,
        );

        $this->assertSame(0, ReviewLog::count());
    }

    // ==================== Snapshot consistency ====================

    public function test_after_snapshot_matches_current_card_state(): void
    {
        $sense = $this->createConfirmedSense('consistency');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview(
            $this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId,
        );

        $log = ReviewLog::where('review_card_id', $card->id)->first();
        $card->refresh();

        $this->assertTrue($this->snapshotService->matches($card, $log->after_card_snapshot));
    }

    public function test_before_snapshot_matches_pre_rating_state(): void
    {
        $sense = $this->createConfirmedSense('beforecheck');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        // Capture state before rating
        $preSnapshot = $this->snapshotService->capture($card);

        $this->cardService->recordReview(
            $this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId,
        );

        $log = ReviewLog::where('review_card_id', $card->id)->first();

        // The log's before_snapshot should match what we captured before the rating
        $this->assertSame(
            $this->snapshotService->fingerprint($preSnapshot),
            $this->snapshotService->fingerprint($log->before_card_snapshot),
        );
    }

    // ==================== HTTP endpoint ====================

    public function test_rate_endpoint_returns_action_metadata(): void
    {
        $sense = $this->createConfirmedSense('endpoint');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $response = $this->actingAs($this->user)->postJson(
            "/reviews/senses/{$card->id}/rate",
            [
                'rating' => 'good',
                'review_session_id' => $sessionId,
            ],
        );

        $response->assertOk();
        $action = $response->json('action');
        $this->assertNotNull($action);
        $this->assertArrayHasKey('review_log_id', $action);
        $this->assertArrayHasKey('review_session_id', $action);
        $this->assertSame($sessionId, $action['review_session_id']);
        $this->assertSame('good', $action['rating']);
        $this->assertSame('记得', $action['rating_label']);
        $this->assertTrue($action['undoable']);
    }

    public function test_rate_endpoint_without_session_id_returns_non_undoable_action(): void
    {
        $sense = $this->createConfirmedSense('nosession');
        $card = $this->cardService->ensureSenseCard($sense);

        $response = $this->actingAs($this->user)->postJson(
            "/reviews/senses/{$card->id}/rate",
            ['rating' => 'good'],
        );

        $response->assertOk();
        $action = $response->json('action');
        $this->assertNotNull($action);
        $this->assertFalse($action['undoable']);
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
}
