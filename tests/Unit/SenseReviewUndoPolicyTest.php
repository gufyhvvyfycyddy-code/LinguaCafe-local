<?php

namespace Tests\Unit;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Services\ReviewCardFsrsSnapshotService;
use App\Services\SenseReviewUndoPolicy;
use Tests\TestCase;

/**
 * SenseReviewUndoPolicyTest
 *
 * ADR-0009: pure unit tests for the undo policy service.
 *
 * The policy is a pure function — no database, no writes. The caller
 * provides the target log, the latest active log, the current card,
 * the request session ID, and optional context. The policy returns
 * undoable + blocked_reason.
 *
 * All 10 blocked reasons are covered, plus the happy path.
 */
class SenseReviewUndoPolicyTest extends TestCase
{
    private SenseReviewUndoPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new SenseReviewUndoPolicy();
    }

    /**
     * Build an in-memory ReviewLog with undo-relevant attributes.
     *
     * Uses array_key_exists (not ??) so that explicit null overrides
     * (e.g. before_card_snapshot => null for legacy logs) are respected.
     */
    private function makeLog(array $overrides = []): ReviewLog
    {
        $log = new ReviewLog();
        $log->id = array_key_exists('id', $overrides) ? $overrides['id'] : 100;
        $log->review_session_id = array_key_exists('review_session_id', $overrides) ? $overrides['review_session_id'] : 'session-uuid-123';
        $log->rating = array_key_exists('rating', $overrides) ? $overrides['rating'] : 'good';
        $log->source = array_key_exists('source', $overrides) ? $overrides['source'] : 'sense_review';
        $log->undone_at = array_key_exists('undone_at', $overrides) ? $overrides['undone_at'] : null;
        $log->before_card_snapshot = array_key_exists('before_card_snapshot', $overrides) ? $overrides['before_card_snapshot'] : $this->validSnapshot('before');
        $log->after_card_snapshot = array_key_exists('after_card_snapshot', $overrides) ? $overrides['after_card_snapshot'] : $this->validSnapshot('after');
        return $log;
    }

    /**
     * Build an in-memory ReviewCard matching an after-snapshot.
     */
    private function makeCard(array $overrides = []): ReviewCard
    {
        $card = new ReviewCard();
        $card->target_type = $overrides['target_type'] ?? ReviewCard::TARGET_SENSE;
        $card->fsrs_enabled = $overrides['fsrs_enabled'] ?? true;
        $card->lifecycle_state = $overrides['lifecycle_state'] ?? ReviewCard::LIFECYCLE_ACTIVE;
        $card->fsrs_state = $overrides['fsrs_state'] ?? 'review';
        $card->fsrs_due_at = null;
        $card->fsrs_stability = null;
        $card->fsrs_difficulty = null;
        $card->fsrs_last_reviewed_at = null;
        $card->fsrs_reps = 0;
        $card->fsrs_lapses = 0;
        return $card;
    }

    /**
     * A valid snapshot array for testing.
     */
    private function validSnapshot(string $tag = 'test'): array
    {
        return [
            'fsrs_state' => 'review',
            'fsrs_due_at' => null,
            'fsrs_stability' => null,
            'fsrs_difficulty' => null,
            'fsrs_last_reviewed_at' => null,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
        ];
    }

    /**
     * Build a snapshot that matches the given card's current state.
     */
    private function snapshotMatchingCard(ReviewCard $card): array
    {
        $service = app(ReviewCardFsrsSnapshotService::class);
        return $service->capture($card);
    }

    // ==================== Happy path ====================

    public function test_happy_path_returns_undoable(): void
    {
        $card = $this->makeCard();
        $afterSnapshot = $this->snapshotMatchingCard($card);

        $targetLog = $this->makeLog([
            'after_card_snapshot' => $afterSnapshot,
        ]);

        $result = $this->policy->evaluate(
            $targetLog,
            $targetLog, // it IS the latest active
            $card,
            'session-uuid-123',
        );

        $this->assertTrue($result['undoable']);
        $this->assertNull($result['blocked_reason']);
    }

    // ==================== wrong_session ====================

    public function test_wrong_session_blocks_undo(): void
    {
        $card = $this->makeCard();
        $targetLog = $this->makeLog([
            'review_session_id' => 'session-A',
        ]);

        $result = $this->policy->evaluate(
            $targetLog,
            $targetLog,
            $card,
            'session-B', // different session
        );

        $this->assertFalse($result['undoable']);
        $this->assertSame(SenseReviewUndoPolicy::REASON_WRONG_SESSION, $result['blocked_reason']);
    }

    // ==================== already_undone ====================

    public function test_already_undone_blocks_undo(): void
    {
        $card = $this->makeCard();
        $targetLog = $this->makeLog([
            'undone_at' => now(),
        ]);

        $result = $this->policy->evaluate(
            $targetLog,
            null, // no latest active (it's already undone)
            $card,
            'session-uuid-123',
        );

        $this->assertFalse($result['undoable']);
        $this->assertSame(SenseReviewUndoPolicy::REASON_ALREADY_UNDONE, $result['blocked_reason']);
    }

    // ==================== missing_snapshot ====================

    public function test_missing_snapshot_blocks_undo(): void
    {
        $card = $this->makeCard();
        $targetLog = $this->makeLog([
            'before_card_snapshot' => null, // legacy log
        ]);

        $result = $this->policy->evaluate(
            $targetLog,
            $targetLog,
            $card,
            'session-uuid-123',
        );

        $this->assertFalse($result['undoable']);
        $this->assertSame(SenseReviewUndoPolicy::REASON_MISSING_SNAPSHOT, $result['blocked_reason']);
    }

    // ==================== unsupported_rating ====================

    public function test_unsupported_rating_blocks_undo(): void
    {
        $card = $this->makeCard();
        $targetLog = $this->makeLog([
            'rating' => 'reset', // not supported
        ]);

        $result = $this->policy->evaluate(
            $targetLog,
            $targetLog,
            $card,
            'session-uuid-123',
        );

        $this->assertFalse($result['undoable']);
        $this->assertSame(SenseReviewUndoPolicy::REASON_UNSUPPORTED_RATING, $result['blocked_reason']);
    }

    // ==================== unsupported_source ====================

    public function test_unsupported_source_blocks_undo(): void
    {
        $card = $this->makeCard();
        $targetLog = $this->makeLog([
            'source' => 'import', // not supported
        ]);

        $result = $this->policy->evaluate(
            $targetLog,
            $targetLog,
            $card,
            'session-uuid-123',
        );

        $this->assertFalse($result['undoable']);
        $this->assertSame(SenseReviewUndoPolicy::REASON_UNSUPPORTED_SOURCE, $result['blocked_reason']);
    }

    // ==================== not_latest_action ====================

    public function test_not_latest_action_blocks_undo(): void
    {
        $card = $this->makeCard();
        $targetLog = $this->makeLog(['id' => 100]);
        $latestLog = $this->makeLog(['id' => 101]); // newer log

        $result = $this->policy->evaluate(
            $targetLog,
            $latestLog, // a newer active log exists
            $card,
            'session-uuid-123',
        );

        $this->assertFalse($result['undoable']);
        $this->assertSame(SenseReviewUndoPolicy::REASON_NOT_LATEST, $result['blocked_reason']);
    }

    public function test_null_latest_active_allows_undo_when_target_is_active(): void
    {
        // If latestActiveLog is null and the target is not undone, it's
        // undoable (the target IS the latest, just passed as null by caller).
        $card = $this->makeCard();
        $afterSnapshot = $this->snapshotMatchingCard($card);
        $targetLog = $this->makeLog([
            'after_card_snapshot' => $afterSnapshot,
        ]);

        $result = $this->policy->evaluate(
            $targetLog,
            null,
            $card,
            'session-uuid-123',
        );

        $this->assertTrue($result['undoable']);
    }

    // ==================== legacy_target ====================

    public function test_legacy_target_blocks_undo(): void
    {
        $card = $this->makeCard([
            'target_type' => ReviewCard::TARGET_WORD, // not sense
        ]);
        $afterSnapshot = $this->snapshotMatchingCard($card);
        $targetLog = $this->makeLog([
            'after_card_snapshot' => $afterSnapshot,
        ]);

        $result = $this->policy->evaluate(
            $targetLog,
            $targetLog,
            $card,
            'session-uuid-123',
        );

        $this->assertFalse($result['undoable']);
        $this->assertSame(SenseReviewUndoPolicy::REASON_LEGACY_TARGET, $result['blocked_reason']);
    }

    // ==================== card_archived ====================

    public function test_card_archived_blocks_undo(): void
    {
        $card = $this->makeCard([
            'fsrs_enabled' => false,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ARCHIVED,
        ]);
        $afterSnapshot = $this->snapshotMatchingCard($card);
        $targetLog = $this->makeLog([
            'after_card_snapshot' => $afterSnapshot,
        ]);

        $result = $this->policy->evaluate(
            $targetLog,
            $targetLog,
            $card,
            'session-uuid-123',
        );

        $this->assertFalse($result['undoable']);
        $this->assertSame(SenseReviewUndoPolicy::REASON_CARD_ARCHIVED, $result['blocked_reason']);
    }

    // ==================== sense_not_confirmed ====================

    public function test_sense_not_confirmed_blocks_undo(): void
    {
        $card = $this->makeCard();
        $afterSnapshot = $this->snapshotMatchingCard($card);
        $targetLog = $this->makeLog([
            'after_card_snapshot' => $afterSnapshot,
        ]);

        $result = $this->policy->evaluate(
            $targetLog,
            $targetLog,
            $card,
            'session-uuid-123',
            ['sense_confirmed' => false],
        );

        $this->assertFalse($result['undoable']);
        $this->assertSame(SenseReviewUndoPolicy::REASON_SENSE_NOT_CONFIRMED, $result['blocked_reason']);
    }

    public function test_sense_confirmed_allows_undo(): void
    {
        $card = $this->makeCard();
        $afterSnapshot = $this->snapshotMatchingCard($card);
        $targetLog = $this->makeLog([
            'after_card_snapshot' => $afterSnapshot,
        ]);

        $result = $this->policy->evaluate(
            $targetLog,
            $targetLog,
            $card,
            'session-uuid-123',
            ['sense_confirmed' => true],
        );

        $this->assertTrue($result['undoable']);
    }

    // ==================== card_state_changed ====================

    public function test_card_state_changed_blocks_undo(): void
    {
        $card = $this->makeCard(['fsrs_state' => 'review']);

        // after_card_snapshot says 'relearning', but card is 'review'.
        $staleAfterSnapshot = $this->validSnapshot();
        $staleAfterSnapshot['fsrs_state'] = 'relearning';

        $targetLog = $this->makeLog([
            'after_card_snapshot' => $staleAfterSnapshot,
        ]);

        $result = $this->policy->evaluate(
            $targetLog,
            $targetLog,
            $card,
            'session-uuid-123',
        );

        $this->assertFalse($result['undoable']);
        $this->assertSame(SenseReviewUndoPolicy::REASON_CARD_STATE_CHANGED, $result['blocked_reason']);
    }

    public function test_card_reps_changed_blocks_undo(): void
    {
        $card = $this->makeCard(['fsrs_reps' => 5]);

        $staleAfterSnapshot = $this->snapshotMatchingCard($card);
        $staleAfterSnapshot['fsrs_reps'] = 3; // different from current

        $targetLog = $this->makeLog([
            'after_card_snapshot' => $staleAfterSnapshot,
        ]);

        $result = $this->policy->evaluate(
            $targetLog,
            $targetLog,
            $card,
            'session-uuid-123',
        );

        $this->assertFalse($result['undoable']);
        $this->assertSame(SenseReviewUndoPolicy::REASON_CARD_STATE_CHANGED, $result['blocked_reason']);
    }

    // ==================== All 4 ratings are supported ====================

    public function test_all_four_ratings_are_undoable(): void
    {
        foreach (['again', 'hard', 'good', 'easy'] as $rating) {
            $card = $this->makeCard();
            $afterSnapshot = $this->snapshotMatchingCard($card);
            $targetLog = $this->makeLog([
                'rating' => $rating,
                'after_card_snapshot' => $afterSnapshot,
            ]);

            $result = $this->policy->evaluate(
                $targetLog,
                $targetLog,
                $card,
                'session-uuid-123',
            );

            $this->assertTrue($result['undoable'], "Rating {$rating} should be undoable");
        }
    }

    // ==================== Evaluation order ====================

    public function test_wrong_session_checked_before_already_undone(): void
    {
        // Both wrong_session AND already_undone are true.
        // wrong_session should take priority (it's checked first).
        $card = $this->makeCard();
        $targetLog = $this->makeLog([
            'review_session_id' => 'session-A',
            'undone_at' => now(),
        ]);

        $result = $this->policy->evaluate(
            $targetLog,
            null,
            $card,
            'session-B',
        );

        $this->assertSame(SenseReviewUndoPolicy::REASON_WRONG_SESSION, $result['blocked_reason']);
    }

    public function test_already_undone_checked_before_missing_snapshot(): void
    {
        // Both already_undone AND missing_snapshot are true.
        $card = $this->makeCard();
        $targetLog = $this->makeLog([
            'undone_at' => now(),
            'before_card_snapshot' => null,
        ]);

        $result = $this->policy->evaluate(
            $targetLog,
            null,
            $card,
            'session-uuid-123',
        );

        $this->assertSame(SenseReviewUndoPolicy::REASON_ALREADY_UNDONE, $result['blocked_reason']);
    }
}
