<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\FsrsReschedulePreviewService;
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
    //  Threshold limits (inject small thresholds via subclass)
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

    public function test_confirm_rejects_newly_due_today_exceeds_limit(): void
    {
        // Threshold 0 means ANY newly_due_today triggers the risk check
        $this->injectThresholdService(0, 10);
        // Create cards due far in the future, last reviewed long ago
        // so FSRS preview_due_at lands <= now (is_newly_due_today = true)
        for ($i = 0; $i < 5; $i++) {
            $sense = $this->createSense("card_{$i}", "释义_{$i}", "card_{$i}");
            $card = $this->createSenseCard($sense, [
                'fsrs_due_at' => now()->addDays(30),
                'fsrs_stability' => 0.5,
                'fsrs_difficulty' => 5.0,
                'fsrs_last_reviewed_at' => now()->subDays(60),
            ]);
            $this->addReviewLog($card);
        }

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $previewResponse->assertOk();
        $hash = $previewResponse->json('preview_hash');

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
            'apply' => true,
        ]);
        $response->assertStatus(422);
        $response->assertJsonPath('risk_level', 'high');
        $response->assertJsonPath('requires_risk_confirm', true);
    }

    public function test_confirm_rejects_total_changed_exceeds_limit(): void
    {
        $this->injectThresholdService(10, 2);
        $card1 = $this->createEligibleReviewCard('card1');
        $card2 = $this->createEligibleReviewCard('card2');
        $card3 = $this->createEligibleReviewCard('card3');

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $previewResponse->assertOk();
        $hash = $previewResponse->json('preview_hash');

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
            'apply' => true,
        ]);
        $response->assertStatus(422);
        $response->assertJsonPath('risk_level', 'blocked');
        $response->assertJsonPath('requires_risk_confirm', false);
    }

    // ════════════════════════════════════════════════════════════════
    //  Apply path (apply=true)
    // ════════════════════════════════════════════════════════════════

    public function test_confirm_apply_true_writes_fsrs_due_at(): void
    {
        $this->injectThresholdService(10, 10);
        $card = $this->createEligibleReviewCard();
        $originalDueAt = $card->fsrs_due_at?->toIso8601String();

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $previewResponse->assertOk();
        $hash = $previewResponse->json('preview_hash');

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
            'apply' => true,
        ]);
        $response->assertOk();
        $response->assertJsonPath('applied', true);
        $response->assertJsonPath('write_enabled', true);
        $response->assertJsonPath('applied_count', 1);

        $card->refresh();
        $this->assertNotNull($card->fsrs_due_at);
        // Due date should have changed (preview recomputes new interval)
        $this->assertNotEquals($originalDueAt, $card->fsrs_due_at?->toIso8601String());
    }

    public function test_confirm_apply_true_updates_stability_difficulty(): void
    {
        $this->injectThresholdService(10, 10);
        $card = $this->createEligibleReviewCard();
        $originalStability = $card->fsrs_stability;
        $originalDifficulty = $card->fsrs_difficulty;

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $hash = $previewResponse->json('preview_hash');

        $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
            'apply' => true,
        ])->assertOk();

        $card->refresh();
        // Stability and difficulty should be updated (new values from goodState)
        $this->assertNotEquals($originalStability, $card->fsrs_stability);
        $this->assertNotEquals($originalDifficulty, $card->fsrs_difficulty);
    }

    public function test_confirm_apply_true_does_not_change_reps_or_last_reviewed(): void
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

    public function test_confirm_apply_true_does_not_create_review_log(): void
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

    public function test_confirm_apply_true_does_not_touch_other_user_cards(): void
    {
        $this->injectThresholdService(10, 10);
        $this->createEligibleReviewCard(); // own card
        $otherSense = $this->createSense('other', '他人', 'other', [], $this->otherUser);
        $otherCard = $this->createSenseCard($otherSense, [], $this->otherUser);
        $originalOtherDue = $otherCard->fsrs_due_at?->toIso8601String();

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $hash = $previewResponse->json('preview_hash');

        $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
            'apply' => true,
        ])->assertOk();

        $otherCard->refresh();
        $this->assertEquals($originalOtherDue, $otherCard->fsrs_due_at?->toIso8601String());
    }

    public function test_confirm_apply_true_skips_word_cards(): void
    {
        $this->injectThresholdService(10, 10);
        $senseCard = $this->createEligibleReviewCard('sense_only');
        $wordCard = $this->createWordCard();

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $previewResponse->assertOk();
        $this->assertEquals(1, $previewResponse->json('total_candidates'));
        $hash = $previewResponse->json('preview_hash');

        $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
            'apply' => true,
        ])->assertOk();

        $wordCard->refresh();
        // Word card should not be included in candidate set
        $this->assertNotNull($wordCard->fsrs_due_at);
    }

    public function test_confirm_apply_true_stale_hash_returns_409(): void
    {
        $this->injectThresholdService(10, 10);
        $card = $this->createEligibleReviewCard();

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $oldHash = $previewResponse->json('preview_hash');

        // Change card to invalidate hash
        $card->fsrs_due_at = $card->fsrs_due_at->copy()->addDay();
        $card->save();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $oldHash,
            'confirm' => true,
            'apply' => true,
        ]);
        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $this->assertNotNull($response->json('preview_hash'));

        // Verify nothing was written
        $card->refresh();
        $this->assertNotEquals($card->fsrs_due_at->toIso8601String(), $card->fsrs_due_at->copy()->subDay()->toIso8601String());
    }

    public function test_confirm_apply_true_risk_confirm_allows_high(): void
    {
        $this->injectThresholdService(2, 10);
        $this->createEligibleReviewCard('card1');
        $this->createEligibleReviewCard('card2');
        $this->createEligibleReviewCard('card3');

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $hash = $previewResponse->json('preview_hash');

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
            'apply' => true,
            'risk_confirm' => true,
        ]);
        $response->assertOk();
        $response->assertJsonPath('applied', true);
        $response->assertJsonPath('write_enabled', true);
    }

    public function test_confirm_apply_false_still_does_not_write(): void
    {
        $this->injectThresholdService(10, 10);
        $card = $this->createEligibleReviewCard();
        $originalDueAt = $card->fsrs_due_at?->toIso8601String();
        $originalStability = $card->fsrs_stability;

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $hash = $previewResponse->json('preview_hash');

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
            'apply' => false,
        ]);
        $response->assertOk();
        $response->assertJsonPath('write_enabled', false);
        // Should NOT have 'applied' key since confirmPreflight is called

        $card->refresh();
        $this->assertEquals($originalDueAt, $card->fsrs_due_at?->toIso8601String());
        $this->assertEquals($originalStability, $card->fsrs_stability);
    }

    public function test_confirm_apply_true_skips_ineligible_cards(): void
    {
        $this->injectThresholdService(10, 10);
        $eligible = $this->createEligibleReviewCard('eligible');

        // Create a disabled card
        $senseDisabled = $this->createSense('disabled', '禁用', 'disabled');
        $disabledCard = $this->createSenseCard($senseDisabled, ['fsrs_enabled' => false]);

        $previewResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $previewResponse->assertOk();
        $this->assertEquals(1, $previewResponse->json('total_candidates'));
        $hash = $previewResponse->json('preview_hash');

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash,
            'confirm' => true,
            'apply' => true,
        ]);
        $response->assertOk();
        $response->assertJsonPath('applied_count', 1);

        $disabledCard->refresh();
        // Disabled card should not have fsrs_due_at changed (still past due)
        $this->assertTrue($disabledCard->fsrs_due_at->lt(now())); // should not be touched
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
