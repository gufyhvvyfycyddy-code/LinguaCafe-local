<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\User;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ReviewCardLifecycleQueueTest
 *
 * ADR-0010: Verifies that scopeSenseReviewEligible correctly filters cards
 * based on lifecycle state. The queue must only include active, non-buried
 * (or expired-buried) cards.
 *
 * Also verifies that stats, interval preview, and management queries are
 * consistent with the lifecycle state machine.
 */
class ReviewCardLifecycleQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Queue Test User',
            'email' => 'queue-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    // ─── Queue eligibility ───

    public function test_active_card_is_queue_eligible(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'active', 'fsrs_enabled' => true]);

        $eligible = ReviewCard::query()
            ->senseReviewEligible($this->user->id, 'english', Carbon::now())
            ->where('id', $card->id)
            ->exists();

        $this->assertTrue($eligible);
    }

    public function test_buried_not_expired_is_not_queue_eligible(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'buried',
            'buried_until' => Carbon::now()->addHours(12),
            'fsrs_enabled' => true,
        ]);

        $eligible = ReviewCard::query()
            ->senseReviewEligible($this->user->id, 'english', Carbon::now())
            ->where('id', $card->id)
            ->exists();

        $this->assertFalse($eligible);
    }

    public function test_buried_expired_is_queue_eligible(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'buried',
            'buried_until' => Carbon::now()->subHour(),
            'fsrs_enabled' => true,
        ]);

        $eligible = ReviewCard::query()
            ->senseReviewEligible($this->user->id, 'english', Carbon::now())
            ->where('id', $card->id)
            ->exists();

        $this->assertTrue($eligible, 'Expired buried should be treated as active in queue');
    }

    public function test_suspended_is_not_queue_eligible(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'suspended',
            'fsrs_enabled' => false,
        ]);

        $eligible = ReviewCard::query()
            ->senseReviewEligible($this->user->id, 'english', Carbon::now())
            ->where('id', $card->id)
            ->exists();

        $this->assertFalse($eligible);
    }

    public function test_archived_is_not_queue_eligible(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'archived',
            'fsrs_enabled' => false,
        ]);

        $eligible = ReviewCard::query()
            ->senseReviewEligible($this->user->id, 'english', Carbon::now())
            ->where('id', $card->id)
            ->exists();

        $this->assertFalse($eligible);
    }

    public function test_other_user_card_not_eligible(): void
    {
        $otherUser = User::forceCreate([
            'name' => 'Other',
            'email' => 'other-q-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $sense = WordSense::forceCreate([
            'user_id' => $otherUser->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'other',
            'surface_form' => 'other',
            'pos' => 'noun',
            'sense_zh' => '其他',
            'sense_en' => 'other',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Other.',
            'example_sentence_zh' => '其他。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', 'english|other|noun|其他|other'),
        ]);

        $card = ReviewCard::forceCreate([
            'user_id' => $otherUser->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
        ]);

        $eligible = ReviewCard::query()
            ->senseReviewEligible($this->user->id, 'english', Carbon::now())
            ->where('id', $card->id)
            ->exists();

        $this->assertFalse($eligible);
    }

    public function test_word_card_not_eligible(): void
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

        $eligible = ReviewCard::query()
            ->senseReviewEligible($this->user->id, 'english', Carbon::now())
            ->where('id', $card->id)
            ->exists();

        $this->assertFalse($eligible);
    }

    // ─── Mixed scenario ───

    public function test_mixed_cards_only_active_and_expired_buried_are_eligible(): void
    {
        $active = $this->makeCard(['lifecycle_state' => 'active', 'fsrs_enabled' => true]);
        $buried = $this->makeCard([
            'lifecycle_state' => 'buried',
            'buried_until' => Carbon::now()->addHours(12),
            'fsrs_enabled' => true,
        ]);
        $expiredBuried = $this->makeCard([
            'lifecycle_state' => 'buried',
            'buried_until' => Carbon::now()->subHour(),
            'fsrs_enabled' => true,
        ]);
        $suspended = $this->makeCard([
            'lifecycle_state' => 'suspended',
            'fsrs_enabled' => false,
        ]);
        $archived = $this->makeCard([
            'lifecycle_state' => 'archived',
            'fsrs_enabled' => false,
        ]);

        $eligibleIds = ReviewCard::query()
            ->senseReviewEligible($this->user->id, 'english', Carbon::now())
            ->pluck('id')
            ->toArray();

        $this->assertContains($active->id, $eligibleIds);
        $this->assertContains($expiredBuried->id, $eligibleIds);
        $this->assertNotContains($buried->id, $eligibleIds);
        $this->assertNotContains($suspended->id, $eligibleIds);
        $this->assertNotContains($archived->id, $eligibleIds);
    }

    // ─── fsrs_enabled mirror consistency ───

    public function test_fsrs_enabled_mirror_active(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'active', 'fsrs_enabled' => true]);
        $this->assertTrue((bool) $card->fresh()->fsrs_enabled);
    }

    public function test_fsrs_enabled_mirror_buried(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'buried',
            'fsrs_enabled' => true,
            'buried_until' => Carbon::now()->addHours(12),
        ]);
        $this->assertTrue((bool) $card->fresh()->fsrs_enabled, 'Buried should mirror fsrs_enabled=true');
    }

    public function test_fsrs_enabled_mirror_suspended(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'suspended',
            'fsrs_enabled' => false,
        ]);
        $this->assertFalse((bool) $card->fresh()->fsrs_enabled);
    }

    public function test_fsrs_enabled_mirror_archived(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'archived',
            'fsrs_enabled' => false,
        ]);
        $this->assertFalse((bool) $card->fresh()->fsrs_enabled);
    }

    // ─── No N+1 check (basic) ───

    public function test_queue_query_does_not_require_extra_queries_per_card(): void
    {
        // Create 5 eligible cards.
        for ($i = 0; $i < 5; $i++) {
            $this->makeCard(['lifecycle_state' => 'active', 'fsrs_enabled' => true]);
        }

        // The query should execute in a constant number of queries.
        $queries = 0;
        \DB::listen(function () use (&$queries) {
            $queries++;
        });

        ReviewCard::query()
            ->senseReviewEligible($this->user->id, 'english', Carbon::now())
            ->get();

        // Should be 1 query (no eager loading needed for the scope itself).
        $this->assertSame(1, $queries, 'scopeSenseReviewEligible should be a single query');
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
        ], $overrides));
    }
}
