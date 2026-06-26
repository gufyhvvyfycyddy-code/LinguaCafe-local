<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FsrsReschedulePreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Preview User',
            'email' => '__VG_EMAIL_f5a3c8d1e2b4__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other Preview User',
            'email' => '__VG_EMAIL_b7c2d9e3f1a6__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //  Authentication
    // ════════════════════════════════════════════════════════════════

    public function test_unauthenticated_user_cannot_access_preview(): void
    {
        $response = $this->postJson('/settings/fsrs/reschedule-preview');

        // API-style route returns 401 (Unauthenticated) when not logged in
        $response->assertStatus(401);
    }

    // ════════════════════════════════════════════════════════════════
    //  Happy path
    // ════════════════════════════════════════════════════════════════

    public function test_preview_returns_correct_structure_for_eligible_card(): void
    {
        $card = $this->createEligibleReviewCard();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('preview_available', true);
        $response->assertJsonPath('language', 'english');
        $response->assertJsonPath('target_type', 'sense');
        $response->assertJsonPath('total_candidates', 1);
        $response->assertJsonPath('total_changed', 1);
        $response->assertJsonPath('skipped_count', 0);
        $response->assertJsonStructure([
            'success',
            'preview_available',
            'language',
            'target_type',
            'total_candidates',
            'total_changed',
            'skipped_count',
            'summary' => [
                'will_move_earlier',
                'will_move_later',
                'unchanged',
                'currently_due',
                'newly_due_today',
                'due_today_after_reschedule',
                'max_earlier_days',
                'max_later_days',
            ],
            'samples',
            'warnings',
        ]);
    }

    public function test_preview_samples_contain_expected_fields(): void
    {
        $this->createEligibleReviewCard();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $samples = $response->json('samples');
        $this->assertNotEmpty($samples);

        $sample = $samples[0];
        $this->assertArrayHasKey('review_card_id', $sample);
        $this->assertArrayHasKey('word_sense_id', $sample);
        $this->assertArrayHasKey('lemma', $sample);
        $this->assertArrayHasKey('sense_zh', $sample);
        $this->assertArrayHasKey('current_due_at', $sample);
        $this->assertArrayHasKey('preview_due_at', $sample);
        $this->assertArrayHasKey('days_change', $sample);
        $this->assertArrayHasKey('fsrs_stability', $sample);
        $this->assertArrayHasKey('fsrs_difficulty', $sample);
        $this->assertArrayHasKey('fsrs_last_reviewed_at', $sample);
    }

    public function test_preview_returns_at_most_20_samples(): void
    {
        // Create 25 eligible cards
        for ($i = 0; $i < 25; $i++) {
            $this->createEligibleReviewCard("lemma_{$i}");
        }

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $samples = $response->json('samples');
        $this->assertCount(20, $samples);
    }

    // ════════════════════════════════════════════════════════════════
    //  Exclusion filters
    // ════════════════════════════════════════════════════════════════

    public function test_preview_excludes_word_cards(): void
    {
        $this->createEligibleReviewCard();
        $this->createWordCard();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $this->assertEquals(1, $response->json('total_candidates'));
    }

    public function test_preview_excludes_new_cards(): void
    {
        $sense = $this->createSense('test_new', '新卡', 'new card');
        $card = $this->createSenseCard($sense, [
            'fsrs_state' => 'new',
            'fsrs_stability' => null,
            'fsrs_difficulty' => null,
            'fsrs_last_reviewed_at' => null,
        ]);
        $this->addReviewLog($card);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $this->assertEquals(0, $response->json('total_candidates'));
    }

    public function test_preview_excludes_learning_cards(): void
    {
        $sense = $this->createSense('test_learning', '学习中', 'learning');
        $this->createSenseCard($sense, [
            'fsrs_state' => 'learning',
        ]);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $this->assertEquals(0, $response->json('total_candidates'));
    }

    public function test_preview_excludes_relearning_cards(): void
    {
        $sense = $this->createSense('test_relearning', '重学', 'relearning');
        $this->createSenseCard($sense, [
            'fsrs_state' => 'relearning',
        ]);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $this->assertEquals(0, $response->json('total_candidates'));
    }

    public function test_preview_excludes_disabled_cards(): void
    {
        $sense = $this->createSense('test_disabled', '禁用', 'disabled');
        $this->createSenseCard($sense, [
            'fsrs_enabled' => false,
        ]);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $this->assertEquals(0, $response->json('total_candidates'));
    }

    public function test_preview_excludes_unconfirmed_sense(): void
    {
        $sense = $this->createSense('test_unconfirmed', '未确认', 'unconfirmed', [
            'status' => WordSense::STATUS_AI_SUGGESTED,
        ]);
        $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $this->assertEquals(0, $response->json('total_candidates'));
    }

    public function test_preview_excludes_rejected_sense(): void
    {
        $sense = $this->createSense('test_rejected', '已拒绝', 'rejected', [
            'status' => WordSense::STATUS_REJECTED,
        ]);
        $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $this->assertEquals(0, $response->json('total_candidates'));
    }

    public function test_preview_excludes_cards_without_stability(): void
    {
        $sense = $this->createSense('test_nostab', '无稳定度', 'no stability');
        $this->createSenseCard($sense, [
            'fsrs_stability' => null,
        ]);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $this->assertEquals(0, $response->json('total_candidates'));
    }

    public function test_preview_excludes_cards_without_difficulty(): void
    {
        $sense = $this->createSense('test_nodiff', '无难度', 'no difficulty');
        $this->createSenseCard($sense, [
            'fsrs_difficulty' => null,
        ]);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $this->assertEquals(0, $response->json('total_candidates'));
    }

    public function test_preview_excludes_cards_without_last_reviewed_at(): void
    {
        $sense = $this->createSense('test_norev', '无复习时间', 'no reviewed');
        $this->createSenseCard($sense, [
            'fsrs_last_reviewed_at' => null,
        ]);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $this->assertEquals(0, $response->json('total_candidates'));
    }

    public function test_preview_excludes_other_users_cards(): void
    {
        $this->createEligibleReviewCard(); // user's card
        $otherSense = $this->createSense('other', '他人', 'other', [], $this->otherUser);
        $this->createSenseCard($otherSense, [], $this->otherUser);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $this->assertEquals(1, $response->json('total_candidates'));
    }

    // ════════════════════════════════════════════════════════════════
    //  Preview does NOT write to DB
    // ════════════════════════════════════════════════════════════════

    public function test_preview_does_not_modify_review_card(): void
    {
        $card = $this->createEligibleReviewCard();
        $originalDueAt = $card->fsrs_due_at?->toIso8601String();
        $originalStability = $card->fsrs_stability;
        $originalDifficulty = $card->fsrs_difficulty;

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $card->refresh();

        $this->assertEquals($originalStability, $card->fsrs_stability);
        $this->assertEquals($originalDifficulty, $card->fsrs_difficulty);
        if ($originalDueAt) {
            $this->assertEquals($originalDueAt, $card->fsrs_due_at?->toIso8601String());
        }
    }

    public function test_preview_does_not_create_review_log(): void
    {
        $card = $this->createEligibleReviewCard();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $this->assertDatabaseMissing('review_logs', [
            'review_card_id' => $card->id,
            'source' => 'reschedule',
        ]);
    }

    public function test_preview_does_not_create_any_review_log(): void
    {
        $this->createEligibleReviewCard();
        $beforeCount = ReviewLog::count();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $this->assertEquals($beforeCount, ReviewLog::count());
    }

    // ════════════════════════════════════════════════════════════════
    //  Warning messages
    // ════════════════════════════════════════════════════════════════

    public function test_preview_includes_warning_messages(): void
    {
        $this->createEligibleReviewCard();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $warnings = $response->json('warnings');
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('预览', $warnings[0]);
    }

    public function test_preview_returns_unavailable_for_non_english_language(): void
    {
        $this->user->selected_language = 'japanese';
        $this->user->save();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('preview_available', false);
        $response->assertJsonPath('language', 'japanese');
        $response->assertJsonPath('total_candidates', 0);
        $response->assertJsonPath('skipped_count', 0);
        $warnings = $response->json('warnings');
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('只支持英语', $warnings[0]);
    }

    public function test_preview_excludes_cards_without_due_at(): void
    {
        $sense = $this->createSense('test_nodue', '无到期时间', 'no due at');
        $this->createSenseCard($sense, [
            'fsrs_due_at' => null,
            'fsrs_stability' => 5.0,
            'fsrs_difficulty' => 4.0,
            'fsrs_last_reviewed_at' => now()->subDays(3),
        ]);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $this->assertEquals(0, $response->json('total_candidates'));
    }

    public function test_preview_response_includes_skipped_count(): void
    {
        $this->createEligibleReviewCard();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $this->assertIsInt($response->json('skipped_count'));
    }

    public function test_preview_totals_balance(): void
    {
        $this->createEligibleReviewCard('card1');
        $this->createEligibleReviewCard('card2');
        $this->createEligibleReviewCard('card3');

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $data = $response->json();
        $this->assertEquals(
            $data['total_candidates'],
            $data['total_changed'] + $data['summary']['unchanged'] + $data['skipped_count']
        );
    }

    // ════════════════════════════════════════════════════════════════
    //  preview_hash
    // ════════════════════════════════════════════════════════════════

    public function test_preview_response_includes_preview_hash_for_english(): void
    {
        $this->createEligibleReviewCard();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $this->assertNotNull($response->json('preview_hash'));
        $this->assertIsString($response->json('preview_hash'));
    }

    public function test_preview_hash_is_stable_for_unchanged_data(): void
    {
        $this->createEligibleReviewCard();

        $response1 = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $response2 = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response1->assertOk();
        $response2->assertOk();
        $this->assertEquals($response1->json('preview_hash'), $response2->json('preview_hash'));
    }

    public function test_preview_hash_changes_when_candidate_due_at_changes(): void
    {
        $card = $this->createEligibleReviewCard();

        $response1 = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $hash1 = $response1->json('preview_hash');

        $card->fsrs_due_at = $card->fsrs_due_at->copy()->addDay();
        $card->save();

        $response2 = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $hash2 = $response2->json('preview_hash');

        $this->assertNotEquals($hash1, $hash2);
    }

    public function test_preview_hash_is_stable_for_unchanged_data_again(): void
    {
        $this->createEligibleReviewCard();

        // Hash should be stable across multiple calls (deterministic)
        $response1 = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $response1->assertOk();
        $hash1 = $response1->json('preview_hash');

        $response2 = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $response2->assertOk();
        $hash2 = $response2->json('preview_hash');

        $this->assertEquals($hash1, $hash2);
    }

    public function test_preview_hash_changes_when_active_params_order_changes(): void
    {
        $service = new \App\Services\FsrsReschedulePreviewService();
        $reflection = new \ReflectionMethod($service, 'buildPreviewHash');
        $reflection->setAccessible(true);

        $cards = collect();
        $language = 'english';
        $userId = 1;
        $desiredRetention = 0.9;

        // Use 19-element param arrays matching FSRS expected count
        $paramsForward  = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0, 11.0, 12.0, 13.0, 14.0, 15.0, 16.0, 17.0, 18.0, 19.0];
        $paramsReverse  = [19.0, 18.0, 17.0, 16.0, 15.0, 14.0, 13.0, 12.0, 11.0, 10.0, 9.0, 8.0, 7.0, 6.0, 5.0, 4.0, 3.0, 2.0, 1.0];
        $paramsSwapped  = [2.0, 1.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0, 11.0, 12.0, 13.0, 14.0, 15.0, 16.0, 17.0, 18.0, 19.0];

        $hashForward  = $reflection->invoke($service, $cards, $language, $userId, $paramsForward, $desiredRetention);
        $hashReverse  = $reflection->invoke($service, $cards, $language, $userId, $paramsReverse, $desiredRetention);
        $hashSwapped  = $reflection->invoke($service, $cards, $language, $userId, $paramsSwapped, $desiredRetention);
        $hashForward2 = $reflection->invoke($service, $cards, $language, $userId, $paramsForward, $desiredRetention);

        // 1. Prove different orders → different hashes (order sensitivity)
        $this->assertNotEquals($hashForward, $hashReverse, 'Reverse-ordered params must produce a different hash');
        $this->assertNotEquals($hashForward, $hashSwapped, 'Swapping the first two params must also change the hash');

        // 2. Prove deterministic: same params always give same hash
        $this->assertEquals($hashForward, $hashForward2, 'Same params in same order must produce identical hash');

        // 3. Prove hashes are valid SHA-256 strings
        $this->assertIsString($hashForward);
        $this->assertIsString($hashReverse);
        $this->assertSame(64, strlen($hashForward), 'SHA-256 hex digest must be 64 characters');
        $this->assertSame(64, strlen($hashReverse), 'SHA-256 hex digest must be 64 characters');

        // 4. Prove the underlying JSON serialization differs (root cause)
        $jsonForward = json_encode(array_values($paramsForward), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $jsonReverse = json_encode(array_values($paramsReverse), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertNotEquals($jsonForward, $jsonReverse, 'JSON encoding of differently ordered params must differ');
    }

    public function test_preview_available_false_has_null_preview_hash(): void
    {
        $this->user->selected_language = 'japanese';
        $this->user->save();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');

        $response->assertOk();
        $response->assertJsonPath('preview_available', false);
        $this->assertNull($response->json('preview_hash'));
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
