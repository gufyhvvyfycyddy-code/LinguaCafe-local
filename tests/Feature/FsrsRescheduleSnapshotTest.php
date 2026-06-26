<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\RescheduleSnapshot;
use App\Models\RescheduleSnapshotItem;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\FsrsReschedulePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FsrsRescheduleSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Snapshot User',
            'email' => '__VG_EMAIL_e5f6a7b8c9d0__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other Snapshot User',
            'email' => '__VG_EMAIL_f6a7b8c9d0e1__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //  Snapshot creation on apply=true
    // ════════════════════════════════════════════════════════════════

    public function test_full_reschedule_creates_snapshot_header_with_correct_fields(): void
    {
        $this->injectThresholdService(10, 10);
        $card = $this->createEligibleReviewCard();

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $previewResponse->assertOk();
        $hash = $previewResponse->json('preview_hash');

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
            'apply' => true,
        ]);
        $response->assertOk();

        $this->assertDatabaseCount('reschedule_snapshots', 1);
        $snapshot = RescheduleSnapshot::first();
        $this->assertEquals($this->user->id, $snapshot->user_id);
        $this->assertEquals('english', $snapshot->language_id);
        $this->assertNotNull($snapshot->batch_id);
        $this->assertEquals($hash, $snapshot->preview_hash);
        $this->assertEquals(1, $snapshot->total_cards);
        $this->assertEquals(1, $snapshot->applied_count);
        $this->assertEquals(0, $snapshot->skipped_count);
        $this->assertNotNull($snapshot->expires_at);
        $this->assertNull($snapshot->undone_at);
    }

    public function test_snapshot_items_count_equals_applied_count(): void
    {
        $this->injectThresholdService(10, 10);
        $card1 = $this->createEligibleReviewCard('card1');
        $card2 = $this->createEligibleReviewCard('card2');

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $previewResponse->assertOk();
        $hash = $previewResponse->json('preview_hash');

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
            'apply' => true,
        ]);
        $response->assertOk();

        $snapshot = RescheduleSnapshot::first();
        $this->assertNotNull($snapshot);
        $this->assertEquals(2, $snapshot->applied_count);
        $this->assertEquals(2, RescheduleSnapshotItem::count());
    }

    public function test_snapshot_item_previous_and_new_values_match_card_states(): void
    {
        $this->injectThresholdService(10, 10);
        $card = $this->createEligibleReviewCard();
        $card->refresh();
        $originalDueAt = $card->fsrs_due_at;
        $originalStability = $card->fsrs_stability;
        $originalDifficulty = $card->fsrs_difficulty;

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $previewResponse->assertOk();
        $hash = $previewResponse->json('preview_hash');

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
            'apply' => true,
        ]);
        $response->assertOk();

        $card->refresh();
        $item = RescheduleSnapshotItem::first();
        $this->assertNotNull($item);
        $this->assertEquals($card->id, $item->review_card_id);

        $this->assertEquals($originalDueAt->toIso8601String(), $item->previous_due_at->toIso8601String());
        $this->assertEquals($originalStability, $item->previous_stability);
        $this->assertEquals($originalDifficulty, $item->previous_difficulty);

        $this->assertEquals($card->fsrs_due_at->toIso8601String(), $item->new_due_at->toIso8601String());
        $this->assertEquals($card->fsrs_stability, $item->new_stability);
        $this->assertEquals($card->fsrs_difficulty, $item->new_difficulty);
    }

    public function test_ineligible_cards_not_in_snapshot(): void
    {
        $this->injectThresholdService(10, 10);
        $eligible = $this->createEligibleReviewCard('eligible');
        $wordCard = $this->createWordCard();
        $disabledSense = $this->createSense('disabled', '禁用', 'disabled');
        $disabledCard = $this->createSenseCard($disabledSense, ['fsrs_enabled' => false]);
        $aiSense = $this->createSense('ai', 'AI', 'ai', ['status' => WordSense::STATUS_AI_SUGGESTED]);
        $aiCard = $this->createSenseCard($aiSense);
        $rejectedSense = $this->createSense('rejected', '拒绝', 'rejected', ['status' => WordSense::STATUS_REJECTED]);
        $rejectedCard = $this->createSenseCard($rejectedSense);
        $otherSense = $this->createSense('other', '他人', 'other', [], $this->otherUser);
        $otherCard = $this->createSenseCard($otherSense, [], $this->otherUser);

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $previewResponse->assertOk();
        $hash = $previewResponse->json('preview_hash');

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
            'apply' => true,
        ]);
        $response->assertOk();

        $snapshot = RescheduleSnapshot::first();
        $this->assertNotNull($snapshot);
        $this->assertEquals(1, $snapshot->applied_count);

        $snapshotCardIds = RescheduleSnapshotItem::pluck('review_card_id')->toArray();
        $this->assertContains($eligible->id, $snapshotCardIds);
        $this->assertNotContains($wordCard->id, $snapshotCardIds);
        $this->assertNotContains($disabledCard->id, $snapshotCardIds);
        $this->assertNotContains($aiCard->id, $snapshotCardIds);
        $this->assertNotContains($rejectedCard->id, $snapshotCardIds);
        $this->assertNotContains($otherCard->id, $snapshotCardIds);
    }

    public function test_reschedule_does_not_create_review_log(): void
    {
        $this->injectThresholdService(10, 10);
        $card = $this->createEligibleReviewCard();
        $beforeCount = ReviewLog::count();

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $hash = $previewResponse->json('preview_hash');

        $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
            'apply' => true,
        ])->assertOk();

        $this->assertEquals($beforeCount, ReviewLog::count());
        $this->assertDatabaseMissing('review_logs', [
            'review_card_id' => $card->id,
            'source' => 'reschedule',
        ]);
    }

    public function test_reschedule_does_not_change_reps_lapses_last_reviewed(): void
    {
        $this->injectThresholdService(10, 10);
        $sense = $this->createSense('noreps', '不变', 'noreps');
        $card = $this->createSenseCard($sense, [
            'fsrs_reps' => 5,
            'fsrs_lapses' => 2,
            'fsrs_due_at' => now()->subDay(),
            'fsrs_last_reviewed_at' => now()->subDays(3),
        ]);
        $this->addReviewLog($card);
        $originalReps = $card->fsrs_reps;
        $originalLapses = $card->fsrs_lapses;
        $originalLastReviewed = $card->fsrs_last_reviewed_at?->toIso8601String();

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $hash = $previewResponse->json('preview_hash');

        $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
            'apply' => true,
        ])->assertOk();

        $card->refresh();
        $this->assertEquals($originalReps, $card->fsrs_reps);
        $this->assertEquals($originalLapses, $card->fsrs_lapses);
        $this->assertEquals($originalLastReviewed, $card->fsrs_last_reviewed_at?->toIso8601String());
    }

    public function test_preflight_does_not_create_snapshot(): void
    {
        $this->injectThresholdService(10, 10);
        $this->createEligibleReviewCard();

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $hash = $previewResponse->json('preview_hash');

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
            'apply' => false,
        ]);
        $response->assertOk();

        $this->assertDatabaseCount('reschedule_snapshots', 0);
        $this->assertDatabaseCount('reschedule_snapshot_items', 0);
    }

    public function test_stale_hash_does_not_create_snapshot(): void
    {
        $this->injectThresholdService(10, 10);
        $card = $this->createEligibleReviewCard();

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $oldHash = $previewResponse->json('preview_hash');

        $card->fsrs_due_at = $card->fsrs_due_at->copy()->addDay();
        $card->save();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $oldHash,
            'confirm' => true,
            'apply' => true,
        ]);
        $response->assertStatus(409);

        $this->assertDatabaseCount('reschedule_snapshots', 0);
        $this->assertDatabaseCount('reschedule_snapshot_items', 0);
    }

    public function test_response_contains_snapshot_batch_id(): void
    {
        $this->injectThresholdService(10, 10);
        $this->createEligibleReviewCard();

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $hash = $previewResponse->json('preview_hash');

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
            'apply' => true,
        ]);
        $response->assertOk();

        $batchId = $response->json('snapshot_batch_id');
        $this->assertNotNull($batchId);
        $this->assertDatabaseHas('reschedule_snapshots', [
            'batch_id' => $batchId,
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //  Helpers
    // ════════════════════════════════════════════════════════════════

    private function injectThresholdService(int $maxNewlyDue = 3, int $maxTotalChanged = 5): void
    {
        $service = new class($maxNewlyDue, $maxTotalChanged) extends FsrsReschedulePreviewService {
            private int $maxNewlyDue;
            private int $maxTotalChanged;
            public function __construct(int $maxNewlyDue, int $maxTotalChanged)
            {
                parent::__construct();
                $this->maxNewlyDue = $maxNewlyDue;
                $this->maxTotalChanged = $maxTotalChanged;
            }
            protected function getMaxNewlyDueToday(): int { return $this->maxNewlyDue; }
            protected function getMaxTotalChanged(): int { return $this->maxTotalChanged; }
        };
        app()->instance(FsrsReschedulePreviewService::class, $service);
    }

    private function createSense(string $lemma, string $senseZh, string $senseEn, array $overrides = [], ?User $user = null): WordSense
    {
        $user = $user ?? $this->user;
        return WordSense::forceCreate(array_merge([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => $senseZh,
            'sense_en' => $senseEn,
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'This is a test sentence.',
            'example_sentence_zh' => '这是一个测试句。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower("english|{$lemma}|noun|{$senseZh}|{$senseEn}")),
        ], $overrides));
    }

    private function createSenseCard(WordSense $sense, array $overrides = [], ?User $user = null): ReviewCard
    {
        $user = $user ?? $this->user;
        return ReviewCard::forceCreate(array_merge([
            'user_id' => $user->id,
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
        ], $overrides));
    }

    private function createWordCard(?User $user = null): ReviewCard
    {
        $user = $user ?? $this->user;
        $word = EncounteredWord::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'stage' => -1,
            'word' => 'apple',
            'lemma' => 'apple',
            'kanji' => '',
            'study_base' => 'apple',
            'reading' => '',
            'base_word' => 'apple',
            'base_word_reading' => '',
            'translation' => '',
            'lookup_count' => 0,
            'read_count' => 0,
            'relearning' => false,
        ]);

        return ReviewCard::forceCreate([
            'user_id' => $user->id,
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

    private function addReviewLog(ReviewCard $card, array $overrides = []): ReviewLog
    {
        return ReviewLog::forceCreate(array_merge([
            'user_id' => $card->user_id,
            'language_id' => $card->language_id,
            'language' => $card->language_id,
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
        ], $overrides));
    }

    private function createEligibleReviewCard(string $lemma = 'eligible'): ReviewCard
    {
        $sense = $this->createSense($lemma, "释义_{$lemma}", $lemma);
        $card = $this->createSenseCard($sense);
        $this->addReviewLog($card);

        return $card;
    }
}
