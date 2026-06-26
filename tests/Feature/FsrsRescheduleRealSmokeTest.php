<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\RescheduleSnapshot;
use App\Models\RescheduleSnapshotItem;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * D.4-c-e: Real-data smoke validation.
 *
 * Uses test database but emulates real data conditions.
 * Does NOT write ReviewLog entries with source=reschedule.
 */
class FsrsRescheduleRealSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private array $eligibleCards = [];
    private ReviewCard $wordCard;
    private ReviewCard $disabledCard;
    private ReviewCard $aiSuggestedCard;
    private ReviewCard $rejectedCard;
    private ReviewCard $otherUserCard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'FSRS D4 CE Smoke',
            'email' => '__VG_EMAIL_a1b2c3d4e5f6__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'FSRS D4 CE Other',
            'email' => '__VG_EMAIL_f6e5d4c3b2a1__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        // Create eligible sense cards (3)
        foreach (['compute', 'execute', 'compile'] as $lemma) {
            $this->eligibleCards[] = $this->createEligibleCard($lemma);
        }

        // Create ineligible word card
        $this->wordCard = $this->createWordCard();

        // Create disabled sense card
        $this->disabledCard = $this->createEligibleCard('disabled_test', ['fsrs_enabled' => false]);

        // Create ai_suggested sense card (unconfirmed)
        $this->aiSuggestedCard = $this->createEligibleCard('ai_suggested_test', [], WordSense::STATUS_AI_SUGGESTED);

        // Create rejected sense card
        $this->rejectedCard = $this->createEligibleCard('rejected_test', [], WordSense::STATUS_REJECTED);

        // Create other user's card
        $otherSense = WordSense::forceCreate([
            'user_id' => $this->otherUser->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'other_user_test',
            'surface_form' => 'other_user_test',
            'pos' => 'noun',
            'sense_zh' => '其他用户',
            'sense_en' => 'other user',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Other user test.',
            'example_sentence_zh' => '其他用户测试。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', "english|other_user_test|noun|其他用户|other user"),
        ]);
        $this->otherUserCard = ReviewCard::forceCreate([
            'user_id' => $this->otherUser->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $otherSense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subDay(),
            'fsrs_stability' => 5.0,
            'fsrs_difficulty' => 4.0,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => now()->subDays(3),
            'fsrs_enabled' => true,
        ]);
        $this->addReviewLog($this->otherUserCard, $this->otherUser);
    }

    // ═══════ Helpers ═══════

    private function createEligibleCard(string $lemma, array $cardOverrides = [], string $senseStatus = WordSense::STATUS_CONFIRMED): ReviewCard
    {
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => "{$lemma}释义",
            'sense_en' => "{$lemma} meaning",
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => "This is {$lemma}.",
            'example_sentence_zh' => "这是{$lemma}。",
            'status' => $senseStatus,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', "english|{$lemma}|noun|{$lemma}释义|{$lemma} meaning"),
        ]);

        $card = ReviewCard::forceCreate(array_merge([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subDay(),
            'fsrs_stability' => 5.0,
            'fsrs_difficulty' => 4.0,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => now()->subDays(3),
            'fsrs_enabled' => true,
        ], $cardOverrides));

        $this->addReviewLog($card, $this->user);
        return $card;
    }

    private function createWordCard(): ReviewCard
    {
        $word = EncounteredWord::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'stage' => -1,
            'word' => 'hardware',
            'lemma' => 'hardware',
            'kanji' => '',
            'study_base' => 'hardware',
            'reading' => '',
            'base_word' => 'hardware',
            'base_word_reading' => '',
            'translation' => '',
            'lookup_count' => 0,
            'read_count' => 0,
            'relearning' => false,
        ]);
        return ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => $word->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subDay(),
            'fsrs_stability' => 5.0,
            'fsrs_difficulty' => 4.0,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => now()->subDays(3),
            'fsrs_enabled' => true,
        ]);
    }

    private function addReviewLog(ReviewCard $card, User $user): ReviewLog
    {
        return ReviewLog::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'review_card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => now()->subDay(),
            'previous_state' => 'new',
            'new_state' => 'review',
            'previous_due_at' => now()->subDays(2),
            'new_due_at' => now()->subDay(),
            'previous_stability' => null,
            'new_stability' => 5.0,
            'previous_difficulty' => null,
            'new_difficulty' => 4.0,
            'source' => 'sense_review',
        ]);
    }

    // ═══════ Tests ═══════

    public function test_preview_shows_only_eligible_cards(): void
    {
        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $response->assertOk();
        $data = $response->json();

        $this->assertEquals(3, $data['total_candidates'], 'Should only count 3 confirmed enabled sense cards');
        $this->assertTrue($data['preview_available']);
        $this->assertNotNull($data['preview_hash']);
    }

    public function test_confirm_preflight_passes_for_valid_hash(): void
    {
        $preview = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $hash = $preview->json('preview_hash');

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
        ]);
        $response->assertOk();
    }

    public function test_full_reschedule_flow_eligible_cards_change(): void
    {
        // Before: record state
        $beforeStates = [];
        foreach ($this->eligibleCards as $card) {
            $card->refresh();
            $beforeStates[$card->id] = [
                'due' => $card->fsrs_due_at->format('Y-m-d H:i:s'),
                'stability' => $card->fsrs_stability,
                'difficulty' => $card->fsrs_difficulty,
                'reps' => $card->fsrs_reps,
                'lapses' => $card->fsrs_lapses,
                'last_reviewed' => $card->fsrs_last_reviewed_at?->format('Y-m-d H:i:s'),
            ];
        }

        // Preview + Confirm + Apply
        $preview = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $hash = $preview->json('preview_hash');

        $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash, 'confirm' => true,
        ])->assertOk();

        $apply = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash, 'confirm' => true, 'apply' => true,
        ]);
        $apply->assertOk();
        $applyData = $apply->json();
        $this->assertStringContainsString('已重排', $applyData['message'] ?? '');

        // After: verify eligible cards changed
        foreach ($this->eligibleCards as $card) {
            $card->refresh();
            $before = $beforeStates[$card->id];

            // due/stability/difficulty must change
            $changedDue = $card->fsrs_due_at->format('Y-m-d H:i:s') !== $before['due'];
            $changedStab = abs($card->fsrs_stability - $before['stability']) > 0.01;
            $changedDiff = abs($card->fsrs_difficulty - $before['difficulty']) > 0.01;

            $this->assertTrue(
                $changedDue || $changedStab || $changedDiff,
                "Card {$card->id}: at least one FSRS field should change"
            );

            // reps/lapses/last_reviewed must NOT change
            $this->assertEquals($before['reps'], $card->fsrs_reps, "Card {$card->id}: reps unchanged");
            $this->assertEquals($before['lapses'], $card->fsrs_lapses, "Card {$card->id}: lapses unchanged");
            $this->assertEquals($before['last_reviewed'], $card->fsrs_last_reviewed_at?->format('Y-m-d H:i:s'), "Card {$card->id}: last_reviewed unchanged");
        }
    }

    public function test_full_reschedule_flow_ineligible_cards_unchanged(): void
    {
        $preview = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $hash = $preview->json('preview_hash');

        $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash, 'confirm' => true,
        ])->assertOk();

        $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash, 'confirm' => true, 'apply' => true,
        ])->assertOk();

        // Verify ineligible cards unchanged
        $ineligible = [$this->wordCard, $this->disabledCard, $this->aiSuggestedCard, $this->rejectedCard, $this->otherUserCard];
        foreach ($ineligible as $card) {
            $card->refresh();
            $this->assertEquals(5.0, $card->fsrs_stability, "Card {$card->id} stability unchanged");
            $this->assertEquals(4.0, $card->fsrs_difficulty, "Card {$card->id} difficulty unchanged");
        }
    }

    public function test_full_reschedule_flow_no_review_log_written(): void
    {
        $beforeCount = ReviewLog::count();

        $preview = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $hash = $preview->json('preview_hash');

        $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash, 'confirm' => true,
        ])->assertOk();

        $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash, 'confirm' => true, 'apply' => true,
        ])->assertOk();

        $this->assertEquals($beforeCount, ReviewLog::count(), 'No new ReviewLog entries created');
        $this->assertEquals(0, ReviewLog::where('source', 'reschedule')->count(), 'No source=reschedule entries');
    }

    public function test_stale_hash_returns_409(): void
    {
        $preview = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $hash = $preview->json('preview_hash');

        // Modify a card to invalidate hash
        $card = $this->eligibleCards[0];
        $card->fsrs_due_at = $card->fsrs_due_at->copy()->addDay();
        $card->save();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash, 'confirm' => true,
        ]);
        $response->assertStatus(409);
    }

    public function test_preview_shows_after_reschedule(): void
    {
        $preview = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $hash = $preview->json('preview_hash');

        $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash, 'confirm' => true,
        ])->assertOk();

        $apply = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash, 'confirm' => true, 'apply' => true,
        ]);
        $apply->assertOk();
        $this->assertStringContainsString('已重排', $apply->json('message') ?? '');

        // After apply, a new preview should still work
        $preview2 = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $preview2->assertOk();
        $data2 = $preview2->json();
        $this->assertNotNull($data2['preview_hash'], 'New preview should have a hash');
    }

    public function test_reschedule_creates_snapshot_with_items(): void
    {
        $preview = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $hash = $preview->json('preview_hash');

        $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash, 'confirm' => true,
        ])->assertOk();

        $apply = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash, 'confirm' => true, 'apply' => true,
        ]);
        $apply->assertOk();
        $applyData = $apply->json();

        $this->assertEquals(1, RescheduleSnapshot::count(), 'One snapshot should exist');
        $snapshot = RescheduleSnapshot::first();
        $this->assertNotNull($snapshot);
        $this->assertEquals($this->user->id, $snapshot->user_id);
        $this->assertEquals('english', $snapshot->language_id);
        $this->assertNotNull($snapshot->batch_id);
        $this->assertEquals($applyData['applied_count'], $snapshot->applied_count);
        $this->assertEquals($applyData['applied_count'], RescheduleSnapshotItem::count(), 'Item count should match applied count');
        $this->assertEquals($snapshot->batch_id, $applyData['snapshot_batch_id'] ?? null);
    }
}
