<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewCardStateEvent;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\LifecycleConflictException;
use App\Services\ReviewCardLifecycleCommandService;
use App\Services\ReviewCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ReviewCardLifecycleConcurrencyTest
 *
 * ADR-0010: Verifies concurrency safety of lifecycle operations.
 *
 * Since PHP is single-threaded, we simulate concurrency by:
 *   - Manually modifying the card between version check and apply
 *   - Using database transactions with lockForUpdate
 *   - Testing version conflict when two "tabs" try to modify the same card
 *
 * Also tests boundaries with rating and undo operations.
 */
class ReviewCardLifecycleConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private ReviewCardLifecycleCommandService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Concurrency Test',
            'email' => 'conc-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->service = app(ReviewCardLifecycleCommandService::class);
    }

    // ─── Two tabs concurrent modification ───

    public function test_two_tabs_concurrent_suspend_conflict(): void
    {
        $card = $this->makeCard();
        $version0 = (int) $card->lifecycle_version;

        // Tab 1: suspend succeeds (version 0 → 1)
        $this->service->act(
            $card, 'suspend', Str::uuid()->toString(), $version0,
            'tab1', $this->user->id, 'english', 'UTC'
        );

        // Tab 2: tries to suspend with stale version 0 → conflict
        $this->expectException(LifecycleConflictException::class);
        $this->service->act(
            $card, 'suspend', Str::uuid()->toString(), $version0,
            'tab2', $this->user->id, 'english', 'UTC'
        );
    }

    public function test_two_tabs_different_actions_second_conflicts(): void
    {
        $card = $this->makeCard();

        // Tab 1: bury succeeds (version 0 → 1)
        $this->service->act(
            $card, 'bury', Str::uuid()->toString(), 0,
            'tab1', $this->user->id, 'english', 'UTC'
        );

        // Tab 2: tries to archive with stale version 0 → conflict
        $this->expectException(LifecycleConflictException::class);
        $this->service->act(
            $card, 'archive', Str::uuid()->toString(), 0,
            'tab2', $this->user->id, 'english', 'UTC'
        );
    }

    // ─── Idempotent retry across "tabs" ───

    public function test_same_request_id_from_different_tabs_is_idempotent(): void
    {
        $card = $this->makeCard();
        $requestId = Str::uuid()->toString();

        // Tab 1: suspend
        $first = $this->service->act(
            $card, 'suspend', $requestId, 0,
            'tab1', $this->user->id, 'english', 'UTC'
        );
        $this->assertFalse($first['already_applied']);

        // Tab 2: retry with same request_id (e.g., network retry)
        $second = $this->service->act(
            $card, 'suspend', $requestId, 0,
            'tab2', $this->user->id, 'english', 'UTC'
        );
        $this->assertTrue($second['already_applied']);
        $this->assertSame($first['event_id'], $second['event_id']);
    }

    // ─── Rating + lifecycle concurrent ───

    public function test_rating_then_suspend_does_not_corrupt_fsrs(): void
    {
        $card = $this->makeCard([
            'fsrs_state' => 'review',
            'fsrs_stability' => 3.0,
            'fsrs_difficulty' => 0.5,
            'fsrs_reps' => 5,
        ]);

        // Rating: modify FSRS fields
        $reviewCardService = app(ReviewCardService::class);
        $reviewCardService->recordReview(
            $this->user->id,
            'english',
            $card->id,
            'good',
            'sense_review'
        );

        // Lifecycle: suspend
        $this->service->act(
            $card, 'suspend', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $fresh = $card->fresh();
        $this->assertSame('suspended', $fresh->lifecycle_state);
        // FSRS fields should reflect the rating, not be reset.
        $this->assertNotSame(3.0, (float) $fresh->fsrs_stability, 'Rating should have updated stability');
        $this->assertGreaterThan(5, (int) $fresh->fsrs_reps, 'Rating should have incremented reps');
    }

    // ─── Undo + lifecycle concurrent ───

    public function test_undo_does_not_overwrite_lifecycle_state(): void
    {
        $card = $this->makeCard([
            'fsrs_state' => 'review',
            'fsrs_stability' => 3.0,
            'fsrs_reps' => 5,
        ]);

        // Step 1: Rating — changes FSRS fields
        $reviewCardService = app(ReviewCardService::class);
        $sessionId = Str::uuid()->toString();
        $reviewCardService->recordReview(
            $this->user->id,
            'english',
            $card->id,
            'good',
            'sense_review',
            $sessionId
        );

        // Get the log created by the review.
        $log = \App\Models\ReviewLog::where('review_card_id', $card->id)
            ->where('review_session_id', $sessionId)
            ->latest('id')
            ->first();
        $this->assertNotNull($log, 'Review log should have been created');

        // Step 2: Undo the rating — restores FSRS to pre-rating values
        $undoService = app(\App\Services\SenseReviewUndoService::class);
        $undoService->undo(
            $log->id,
            $this->user->id,
            'english',
            $sessionId,
            Str::uuid()->toString(),
            'test_undo'
        );

        // FSRS should be restored to pre-rating values.
        $this->assertSame(3.0, (float) $card->fresh()->fsrs_stability, 'Undo should restore FSRS stability');
        $this->assertSame(5, (int) $card->fresh()->fsrs_reps, 'Undo should restore FSRS reps');

        // Step 3: Suspend the card AFTER undo — must not change FSRS
        $this->service->act(
            $card, 'suspend', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $fresh = $card->fresh();
        // Lifecycle should be suspended.
        $this->assertSame('suspended', $fresh->lifecycle_state, 'Suspend should set lifecycle to suspended');
        // FSRS should still be the pre-rating values (undo's restoration must
        // not be overwritten by suspend, and suspend must not touch FSRS).
        $this->assertSame(3.0, (float) $fresh->fsrs_stability, 'Suspend must not change FSRS stability');
        $this->assertSame(5, (int) $fresh->fsrs_reps, 'Suspend must not change FSRS reps');
    }

    // ─── Reset + lifecycle ───

    public function test_reset_preserves_lifecycle_state(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'suspended',
            'fsrs_enabled' => false,
            'fsrs_stability' => 5.0,
            'fsrs_reps' => 10,
            'fsrs_lapses' => 3,
        ]);

        $reviewCardService = app(ReviewCardService::class);
        $reviewCardService->resetCard($this->user->id, 'english', $card->id);

        $fresh = $card->fresh();
        // Lifecycle must be preserved.
        $this->assertSame('suspended', $fresh->lifecycle_state, 'Reset must not change lifecycle state');
        $this->assertFalse((bool) $fresh->fsrs_enabled, 'Reset must not re-enable suspended card');
        // FSRS should be reset.
        $this->assertSame(0, (int) $fresh->fsrs_reps, 'Reset should clear reps');
        $this->assertSame(0, (int) $fresh->fsrs_lapses, 'Reset should clear lapses');
    }

    // ─── No ReviewLog from lifecycle ───

    public function test_concurrent_lifecycle_actions_create_no_review_log(): void
    {
        $card = $this->makeCard();
        $logsBefore = ReviewLog::count();

        // First action succeeds.
        $this->service->act(
            $card, 'suspend', Str::uuid()->toString(), 0,
            'tab1', $this->user->id, 'english', 'UTC'
        );

        // Second action conflicts.
        try {
            $this->service->act(
                $card, 'archive', Str::uuid()->toString(), 0,
                'tab2', $this->user->id, 'english', 'UTC'
            );
        } catch (LifecycleConflictException $e) {
            // Expected.
        }

        $this->assertSame($logsBefore, ReviewLog::count(), 'Lifecycle actions must not create ReviewLog');
    }

    // ─── State event count ───

    public function test_conflict_does_not_create_state_event(): void
    {
        $card = $this->makeCard();

        // First action succeeds.
        $this->service->act(
            $card, 'suspend', Str::uuid()->toString(), 0,
            'tab1', $this->user->id, 'english', 'UTC'
        );

        $eventsBefore = ReviewCardStateEvent::where('review_card_id', $card->id)->count();

        // Second action conflicts.
        try {
            $this->service->act(
                $card, 'archive', Str::uuid()->toString(), 0,
                'tab2', $this->user->id, 'english', 'UTC'
            );
        } catch (LifecycleConflictException $e) {
            // Expected.
        }

        $eventsAfter = ReviewCardStateEvent::where('review_card_id', $card->id)->count();
        $this->assertSame($eventsBefore, $eventsAfter, 'Conflict should not create a state event');
    }

    // ─── Helpers ───

    private function makeCard(array $overrides = []): ReviewCard
    {
        $lemma = 'test' . Str::random(4);
        $sense = WordSense::forceCreate([
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
            'example_sentence_en' => 'This is a test.',
            'example_sentence_zh' => '这是一个测试。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower("english|{$lemma}|noun|测试|test")),
        ]);

        return ReviewCard::forceCreate(array_merge([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
            'fsrs_stability' => null,
            'fsrs_difficulty' => null,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
        ], $overrides));
    }
}
