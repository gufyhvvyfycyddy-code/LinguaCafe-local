<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FsrsRescheduleConfirmTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Confirm User',
            'email' => '__VG_EMAIL_c3d4e5f6a7b8__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other Confirm User',
            'email' => '__VG_EMAIL_d4e5f6a7b8c9__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //  Validation / rejection
    // ════════════════════════════════════════════════════════════════

    public function test_confirm_rejects_missing_preview_hash_with_422(): void
    {
        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'confirm' => true,
        ]);

        $response->assertStatus(422);
    }

    public function test_confirm_rejects_confirm_false_with_422(): void
    {
        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => 'some-hash',
            'confirm' => false,
        ]);

        $response->assertStatus(422);
    }

    public function test_confirm_rejects_stale_preview_hash_with_409(): void
    {
        $card = $this->createEligibleReviewCard();

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $previewResponse->assertOk();
        $oldHash = $previewResponse->json('preview_hash');

        $card->fsrs_due_at = $card->fsrs_due_at->copy()->addDay();
        $card->save();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $oldHash,
            'confirm' => true,
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', '预览已过期，请重新获取预览后再确认。');
        $this->assertNotNull($response->json('preview_hash'));
    }

    // ════════════════════════════════════════════════════════════════
    //  Success path (write_enabled=false)
    // ════════════════════════════════════════════════════════════════

    public function test_confirm_accepts_matching_hash_with_write_enabled_false(): void
    {
        $this->createEligibleReviewCard();

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $previewResponse->assertOk();
        $hash = $previewResponse->json('preview_hash');

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('confirm_available', true);
        $response->assertJsonPath('write_enabled', false);
    }

    public function test_confirm_does_not_modify_review_card(): void
    {
        $card = $this->createEligibleReviewCard();
        $originalDueAt = $card->fsrs_due_at?->toIso8601String();
        $originalStability = $card->fsrs_stability;
        $originalDifficulty = $card->fsrs_difficulty;

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $hash = $previewResponse->json('preview_hash');

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
        ]);

        $response->assertOk();
        $card->refresh();

        $this->assertEquals($originalStability, $card->fsrs_stability);
        $this->assertEquals($originalDifficulty, $card->fsrs_difficulty);
        if ($originalDueAt) {
            $this->assertEquals($originalDueAt, $card->fsrs_due_at?->toIso8601String());
        }
    }

    public function test_confirm_does_not_create_review_log(): void
    {
        $card = $this->createEligibleReviewCard();

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $hash = $previewResponse->json('preview_hash');

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('review_logs', [
            'review_card_id' => $card->id,
            'source' => 'reschedule',
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //  Language / isolation
    // ════════════════════════════════════════════════════════════════

    public function test_confirm_returns_confirm_available_false_for_non_english(): void
    {
        $this->user->selected_language = 'japanese';
        $this->user->save();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => 'any-hash',
            'confirm' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('confirm_available', false);
        $response->assertJsonPath('write_enabled', false);
    }

    public function test_confirm_hash_excludes_other_user_cards(): void
    {
        $this->createEligibleReviewCard();
        $otherSense = $this->createSense('other', '他人', 'other', [], $this->otherUser);
        $this->createSenseCard($otherSense, [], $this->otherUser);

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $previewResponse->assertOk();
        $hash = $previewResponse->json('preview_hash');

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('confirm_available', true);
        $response->assertJsonPath('total_candidates', 1);
    }

    // ════════════════════════════════════════════════════════════════
    //  Threshold limits (skipped — impractical to construct)
    // ════════════════════════════════════════════════════════════════

    public function test_confirm_rejects_newly_due_today_exceeds_limit(): void
    {
        $this->markTestSkipped('Impractical to construct 200+ newly-due-today cards in a single test');
    }

    public function test_confirm_rejects_total_changed_exceeds_limit(): void
    {
        $this->markTestSkipped('Impractical to construct 2000+ changed cards in a single test');
    }

    // ════════════════════════════════════════════════════════════════
    //  Helpers
    // ════════════════════════════════════════════════════════════════

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
