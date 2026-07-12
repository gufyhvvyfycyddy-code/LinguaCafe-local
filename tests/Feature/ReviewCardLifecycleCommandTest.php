<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewCardStateEvent;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\LifecycleConflictException;
use App\Services\ReviewCardLifecycleCommandService;
use App\Services\ReviewCardLifecyclePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ReviewCardLifecycleCommandTest
 *
 * ADR-0010: Feature tests for the unified lifecycle mutation service.
 *
 * Covers:
 *   - All legal transitions (bury/unbury/suspend/resume/archive/restore)
 *   - Illegal transitions return 409
 *   - Idempotency: same request_id returns already_applied=true
 *   - Version conflict: stale expected_version returns 409
 *   - No ReviewLog created by any lifecycle action
 *   - FSRS scheduling fields are never modified
 *   - State event audit records are created correctly
 *   - fsrs_enabled mirror invariant maintained
 *   - Access control: other user/language/word card/rejected sense → 404
 */
class ReviewCardLifecycleCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private ReviewCardLifecycleCommandService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Command Test User',
            'email' => 'cmd-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other User',
            'email' => 'other-cmd-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->service = app(ReviewCardLifecycleCommandService::class);
    }

    // ─── Legal transitions ───

    public function test_bury_active_card(): void
    {
        $card = $this->makeCard();

        $result = $this->service->act(
            $card, 'bury', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $this->assertFalse($result['already_applied']);
        $this->assertNotNull($result['event_id']);
        $this->assertSame('buried', $card->fresh()->lifecycle_state);
        $this->assertNotNull($card->fresh()->buried_until);
        $this->assertSame(1, (int) $card->fresh()->lifecycle_version);
        $this->assertTrue((bool) $card->fresh()->fsrs_enabled, 'buried should mirror fsrs_enabled=true');
    }

    public function test_unbury_buried_card(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'buried', 'buried_until' => now()->addHours(12)]);

        $this->service->act(
            $card, 'unbury', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $this->assertSame('active', $card->fresh()->lifecycle_state);
        $this->assertNull($card->fresh()->buried_until);
        $this->assertTrue((bool) $card->fresh()->fsrs_enabled);
    }

    public function test_suspend_active_card(): void
    {
        $card = $this->makeCard();

        $this->service->act(
            $card, 'suspend', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $this->assertSame('suspended', $card->fresh()->lifecycle_state);
        $this->assertFalse((bool) $card->fresh()->fsrs_enabled, 'suspended should mirror fsrs_enabled=false');
    }

    public function test_resume_suspended_card_preserves_due(): void
    {
        $originalDue = now()->addDays(3);
        $card = $this->makeCard([
            'lifecycle_state' => 'suspended',
            'fsrs_due_at' => $originalDue,
            'fsrs_enabled' => false,
        ]);

        $this->service->act(
            $card, 'resume', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $fresh = $card->fresh();
        $this->assertSame('active', $fresh->lifecycle_state);
        $this->assertTrue((bool) $fresh->fsrs_enabled);
        // Resume should NOT change the due date.
        $this->assertEquals(
            $originalDue->timestamp,
            $fresh->fsrs_due_at->timestamp,
            'Resume must preserve the original due date'
        );
    }

    public function test_archive_active_card(): void
    {
        $card = $this->makeCard();

        $this->service->act(
            $card, 'archive', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $this->assertSame('archived', $card->fresh()->lifecycle_state);
        $this->assertFalse((bool) $card->fresh()->fsrs_enabled);
    }

    public function test_archive_suspended_card(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'suspended', 'fsrs_enabled' => false]);

        $this->service->act(
            $card, 'archive', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $this->assertSame('archived', $card->fresh()->lifecycle_state);
        $this->assertFalse((bool) $card->fresh()->fsrs_enabled);
    }

    public function test_restore_archived_card(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'archived', 'fsrs_enabled' => false]);

        $this->service->act(
            $card, 'restore', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $this->assertSame('active', $card->fresh()->lifecycle_state);
        $this->assertTrue((bool) $card->fresh()->fsrs_enabled);
    }

    // ─── Illegal transitions ───

    public function test_illegal_bury_from_suspended_returns_409(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'suspended', 'fsrs_enabled' => false]);

        $this->expectException(LifecycleConflictException::class);
        $this->service->act(
            $card, 'bury', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );
    }

    public function test_illegal_unbury_from_active_returns_409(): void
    {
        $card = $this->makeCard();

        $this->expectException(LifecycleConflictException::class);
        $this->service->act(
            $card, 'unbury', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );
    }

    public function test_illegal_restore_from_active_returns_409(): void
    {
        $card = $this->makeCard();

        $this->expectException(LifecycleConflictException::class);
        $this->service->act(
            $card, 'restore', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );
    }

    // ─── Idempotency ───

    public function test_same_request_id_returns_already_applied(): void
    {
        $card = $this->makeCard();
        $requestId = Str::uuid()->toString();

        $first = $this->service->act(
            $card, 'suspend', $requestId, null,
            'test', $this->user->id, 'english', 'UTC'
        );
        $this->assertFalse($first['already_applied']);

        $second = $this->service->act(
            $card, 'suspend', $requestId, null,
            'test', $this->user->id, 'english', 'UTC'
        );
        $this->assertTrue($second['already_applied']);
        $this->assertSame($first['event_id'], $second['event_id']);
        // Only one state event should exist.
        $this->assertSame(1, ReviewCardStateEvent::where('review_card_id', $card->id)->count());
    }

    // ─── Version conflict ───

    public function test_stale_expected_version_returns_409(): void
    {
        $card = $this->makeCard();

        // First action increments version to 1.
        $this->service->act(
            $card, 'suspend', Str::uuid()->toString(), 0,
            'test', $this->user->id, 'english', 'UTC'
        );

        // Second action with stale version 0 should conflict.
        $this->expectException(LifecycleConflictException::class);
        $this->service->act(
            $card, 'resume', Str::uuid()->toString(), 0,
            'test', $this->user->id, 'english', 'UTC'
        );
    }

    public function test_correct_expected_version_succeeds(): void
    {
        $card = $this->makeCard();

        $this->service->act(
            $card, 'suspend', Str::uuid()->toString(), 0,
            'test', $this->user->id, 'english', 'UTC'
        );

        // Version is now 1. Resume with correct version.
        $this->service->act(
            $card, 'resume', Str::uuid()->toString(), 1,
            'test', $this->user->id, 'english', 'UTC'
        );

        $this->assertSame('active', $card->fresh()->lifecycle_state);
        $this->assertSame(2, (int) $card->fresh()->lifecycle_version);
    }

    // ─── No ReviewLog ───

    public function test_no_review_log_created_by_bury(): void
    {
        $card = $this->makeCard();
        $logsBefore = ReviewLog::count();

        $this->service->act(
            $card, 'bury', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $this->assertSame($logsBefore, ReviewLog::count(), 'Bury must not create ReviewLog');
    }

    public function test_no_review_log_created_by_suspend(): void
    {
        $card = $this->makeCard();
        $logsBefore = ReviewLog::count();

        $this->service->act(
            $card, 'suspend', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $this->assertSame($logsBefore, ReviewLog::count());
    }

    public function test_no_review_log_created_by_archive(): void
    {
        $card = $this->makeCard();
        $logsBefore = ReviewLog::count();

        $this->service->act(
            $card, 'archive', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $this->assertSame($logsBefore, ReviewLog::count());
    }

    // ─── FSRS invariance ───

    public function test_lifecycle_does_not_modify_fsrs_fields(): void
    {
        $card = $this->makeCard([
            'fsrs_stability' => 5.5,
            'fsrs_difficulty' => 0.3,
            'fsrs_reps' => 7,
            'fsrs_lapses' => 2,
            'fsrs_state' => 'review',
        ]);

        $this->service->act(
            $card, 'suspend', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $fresh = $card->fresh();
        $this->assertSame(5.5, (float) $fresh->fsrs_stability);
        $this->assertSame(0.3, (float) $fresh->fsrs_difficulty);
        $this->assertSame(7, (int) $fresh->fsrs_reps);
        $this->assertSame(2, (int) $fresh->fsrs_lapses);
        $this->assertSame('review', $fresh->fsrs_state);
    }

    // ─── State events ───

    public function test_state_event_records_previous_and_new_state(): void
    {
        $card = $this->makeCard();
        $requestId = Str::uuid()->toString();

        $this->service->act(
            $card, 'suspend', $requestId, null,
            'sense_review_more', $this->user->id, 'english', 'UTC'
        );

        $event = ReviewCardStateEvent::where('request_id', $requestId)->first();
        $this->assertNotNull($event);
        $this->assertSame('suspend', $event->action);
        $this->assertSame('sense_review_more', $event->source);
        $this->assertNotNull($event->previous_state);
        $this->assertNotNull($event->new_state);

        // previous_state and new_state are cast as 'array' by Eloquent.
        $prev = $event->previous_state;
        $new = $event->new_state;
        $this->assertSame('active', $prev['lifecycle_state']);
        $this->assertSame('suspended', $new['lifecycle_state']);
    }

    // ─── Access control ───

    public function test_other_user_cannot_modify_card(): void
    {
        $card = $this->makeCard();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->service->act(
            $card, 'suspend', Str::uuid()->toString(), null,
            'test', $this->otherUser->id, 'english', 'UTC'
        );
    }

    public function test_word_card_rejected(): void
    {
        $card = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => 999,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->service->act(
            $card, 'suspend', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );
    }

    public function test_rejected_sense_rejected(): void
    {
        $sense = $this->createSense(['status' => WordSense::STATUS_REJECTED]);
        $card = $this->makeCardForSense($sense);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->service->act(
            $card, 'suspend', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );
    }

    // ─── Expired bury ───

    public function test_expired_buried_treated_as_active(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'buried',
            'buried_until' => now()->subHour(),
        ]);

        // Expired buried can transition as if active (e.g., suspend).
        $this->service->act(
            $card, 'suspend', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $this->assertSame('suspended', $card->fresh()->lifecycle_state);
    }

    // ─── Bulk operations ───

    public function test_bulk_suspend_returns_per_item_results(): void
    {
        $cardA = $this->makeCard();
        $cardB = $this->makeCard();
        $cardC = $this->makeCard();

        $result = $this->service->bulkAct(
            [$cardA->id, $cardB->id, $cardC->id],
            'suspend', 'bulk_test',
            $this->user->id, 'english', 'UTC'
        );

        $this->assertCount(3, $result['results']);
        foreach ($result['results'] as $r) {
            $this->assertTrue($r['success']);
            $this->assertNotNull($r['event_id']);
        }
    }

    public function test_bulk_partial_failure_not_masked(): void
    {
        $cardA = $this->makeCard();
        $cardB = $this->makeCard(['lifecycle_state' => 'suspended', 'fsrs_enabled' => false]);

        $result = $this->service->bulkAct(
            [$cardA->id, $cardB->id],
            'suspend', 'bulk_test',
            $this->user->id, 'english', 'UTC'
        );

        // cardA succeeds; cardB is already suspended (conflict).
        $this->assertTrue($result['results'][0]['success']);
        $this->assertArrayHasKey('conflict', $result['results'][1]);
    }

    // ─── Helpers ───

    private function makeCard(array $overrides = []): ReviewCard
    {
        $sense = $this->createSense();
        return $this->makeCardForSense($sense, $overrides);
    }

    private function makeCardForSense(WordSense $sense, array $overrides = []): ReviewCard
    {
        return ReviewCard::forceCreate(array_merge([
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
            'fsrs_lapses' => 0,
        ], $overrides));
    }

    private function createSense(array $overrides = []): WordSense
    {
        $lemma = $overrides['lemma'] ?? 'test' . Str::random(4);
        $data = array_merge([
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
        ], $overrides);

        return WordSense::forceCreate($data);
    }
}
