<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FsrsOptimizationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'FSRS Settings User',
            'email' => 'fsrs-settings@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other FSRS Settings User',
            'email' => 'other-fsrs-settings@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    public function test_optimization_status_returns_review_count_threshold_and_message(): void
    {
        $card = $this->createSenseCard($this->createSense($this->user->id, 'english'));
        $this->createReviewLogs($card, 2);

        $response = $this->actingAs($this->user)->getJson('/settings/fsrs/optimization-status');

        $response->assertOk();
        $response->assertJsonPath('review_count', 2);
        $response->assertJsonPath('min_required', SettingsService::FSRS_OPTIMIZATION_MIN_REQUIRED);
        $response->assertJsonPath('can_optimize', false);
        $response->assertJsonPath('message', SettingsService::FSRS_OPTIMIZATION_INSUFFICIENT_MESSAGE);
    }

    public function test_optimization_status_can_optimize_when_review_count_is_sufficient(): void
    {
        $card = $this->createSenseCard($this->createSense($this->user->id, 'english'));
        $this->createReviewLogs($card, SettingsService::FSRS_OPTIMIZATION_MIN_REQUIRED);

        $response = $this->actingAs($this->user)->getJson('/settings/fsrs/optimization-status');

        $response->assertOk();
        $response->assertJsonPath('review_count', SettingsService::FSRS_OPTIMIZATION_MIN_REQUIRED);
        $response->assertJsonPath('can_optimize', true);
        $response->assertJsonPath('message', SettingsService::FSRS_OPTIMIZATION_PENDING_MESSAGE);
    }

    public function test_review_count_excludes_legacy_word_cards(): void
    {
        $senseCard = $this->createSenseCard($this->createSense($this->user->id, 'english'));
        $wordCard = $this->createWordCard($this->user->id, 'english');

        $this->createReviewLogs($senseCard, 1);
        $this->createReviewLogs($wordCard, 1);

        $response = $this->actingAs($this->user)->getJson('/settings/fsrs/optimization-status');

        $response->assertOk();
        $response->assertJsonPath('review_count', 1);
    }

    public function test_review_count_excludes_reset_logs(): void
    {
        $card = $this->createSenseCard($this->createSense($this->user->id, 'english'));

        $this->createReviewLogs($card, 1);
        $this->createReviewLogs($card, 1, [
            'rating' => 'reset',
            'source' => 'reset',
            'previous_state' => 'review',
            'new_state' => 'new',
        ]);

        $response = $this->actingAs($this->user)->getJson('/settings/fsrs/optimization-status');

        $response->assertOk();
        $response->assertJsonPath('review_count', 1);
    }

    public function test_review_count_excludes_other_users(): void
    {
        $userCard = $this->createSenseCard($this->createSense($this->user->id, 'english'));
        $otherCard = $this->createSenseCard($this->createSense($this->otherUser->id, 'english'));

        $this->createReviewLogs($userCard, 1);
        $this->createReviewLogs($otherCard, 1);

        $response = $this->actingAs($this->user)->getJson('/settings/fsrs/optimization-status');

        $response->assertOk();
        $response->assertJsonPath('review_count', 1);
    }

    public function test_review_count_excludes_other_languages(): void
    {
        $englishCard = $this->createSenseCard($this->createSense($this->user->id, 'english'));
        $spanishCard = $this->createSenseCard($this->createSense($this->user->id, 'spanish'));

        $this->createReviewLogs($englishCard, 1);
        $this->createReviewLogs($spanishCard, 1);

        $response = $this->actingAs($this->user)->getJson('/settings/fsrs/optimization-status');

        $response->assertOk();
        $response->assertJsonPath('review_count', 1);
    }

    public function test_optimize_preflight_does_not_modify_fsrs_settings_when_records_are_insufficient(): void
    {
        Setting::forceCreate([
            'user_id' => -1,
            'name' => 'fsrsDesiredRetention',
            'value' => json_encode(0.92),
        ]);

        $card = $this->createSenseCard($this->createSense($this->user->id, 'english'));
        $this->createReviewLogs($card, 1);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/optimize');

        $response->assertOk();
        $response->assertJsonPath('optimized', false);
        $response->assertJsonPath('can_optimize', false);
        $response->assertJsonPath('message', SettingsService::FSRS_OPTIMIZATION_INSUFFICIENT_MESSAGE);

        $this->assertSame(
            0.92,
            json_decode(Setting::where('name', 'fsrsDesiredRetention')->where('user_id', -1)->value('value'), true)
        );
    }

    public function test_optimize_preview_returns_preview_available_false_when_insufficient(): void
    {
        $card = $this->createSenseCard($this->createSense($this->user->id, 'english'));
        $this->createReviewLogs($card, 50);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/optimize');

        $response->assertOk();
        $response->assertJsonPath('preview_available', false);
        $response->assertJsonPath('applied', false);
        $response->assertJsonPath('optimized', false);
        $response->assertJsonPath('can_optimize', false);
        $response->assertJsonPath('optimized_parameters', []);
        $response->assertJsonPath('parameter_count', 0);
    }

    public function test_optimize_preview_returns_preview_available_true_when_sufficient(): void
    {
        $card = $this->createSenseCard($this->createSense($this->user->id, 'english'));
        $this->createReviewLogs($card, SettingsService::FSRS_OPTIMIZATION_MIN_REQUIRED, [], 1);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/optimize');

        $response->assertOk();
        $response->assertJsonPath('preview_available', true);
        $response->assertJsonPath('applied', false);
        $response->assertJsonPath('optimized', false);
        $response->assertJsonPath('can_optimize', true);
        $paramCount = $response->json('parameter_count');
        $this->assertTrue($paramCount >= 19 && $paramCount <= 21, "parameter_count $paramCount not in [19,21]");
        $response->assertJsonPath('review_count', SettingsService::FSRS_OPTIMIZATION_MIN_REQUIRED);
        $response->assertJsonPath('card_count', 1);

        $optimizedParams = $response->json('optimized_parameters');
        $this->assertIsArray($optimizedParams);
        $this->assertCount($paramCount, $optimizedParams);
        foreach ($optimizedParams as $p) {
            $this->assertIsFloat($p);
            $this->assertTrue(is_finite($p));
        }

        $currentParams = $response->json('current_parameters');
        $this->assertIsArray($currentParams);
        $this->assertCount(19, $currentParams);
    }

    public function test_optimize_preview_excludes_reset_logs(): void
    {
        $card = $this->createSenseCard($this->createSense($this->user->id, 'english'));
        $this->createReviewLogs($card, 300, [], 1);
        // Add reset logs that should be excluded
        $this->createReviewLogs($card, 2, [
            'rating' => 'reset',
            'source' => 'reset',
            'previous_state' => 'review',
            'new_state' => 'new',
        ]);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/optimize');

        $response->assertOk();
        $response->assertJsonPath('preview_available', true);
        // review_count should NOT include the 2 reset logs
        $this->assertEquals(300, $response->json('review_count'));
    }

    public function test_optimize_preview_excludes_word_cards(): void
    {
        $senseCard = $this->createSenseCard($this->createSense($this->user->id, 'english'));
        $wordCard = $this->createWordCard($this->user->id, 'english');

        $this->createReviewLogs($senseCard, 300, [], 1);
        $this->createReviewLogs($wordCard, 50);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/optimize');

        $response->assertOk();
        $response->assertJsonPath('preview_available', true);
        $this->assertEquals(300, $response->json('review_count'));
    }

    public function test_optimize_preview_excludes_other_users(): void
    {
        $userCard = $this->createSenseCard($this->createSense($this->user->id, 'english'));
        $otherCard = $this->createSenseCard($this->createSense($this->otherUser->id, 'english'));

        $this->createReviewLogs($userCard, 300, [], 1);
        $this->createReviewLogs($otherCard, 50);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/optimize');

        $response->assertOk();
        $response->assertJsonPath('preview_available', true);
        $this->assertEquals(300, $response->json('review_count'));
    }

    public function test_optimize_preview_excludes_other_languages(): void
    {
        $englishCard = $this->createSenseCard($this->createSense($this->user->id, 'english'));
        $spanishCard = $this->createSenseCard($this->createSense($this->user->id, 'spanish'));

        $this->createReviewLogs($englishCard, 300, [], 1);
        $this->createReviewLogs($spanishCard, 50);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/optimize');

        $response->assertOk();
        $response->assertJsonPath('preview_available', true);
        $this->assertEquals(300, $response->json('review_count'));
    }

    public function test_optimize_preview_excludes_unconfirmed_sense(): void
    {
        $confirmedSense = $this->createSense($this->user->id, 'english');
        $confirmedCard = $this->createSenseCard($confirmedSense);
        $this->createReviewLogs($confirmedCard, 300, [], 1);

        $unconfirmedSense = $this->createSense($this->user->id, 'english', [
            'lemma' => 'unconfirmed',
            'status' => 'ai_suggested',
        ]);
        $unconfirmedCard = $this->createSenseCard($unconfirmedSense);
        $this->createReviewLogs($unconfirmedCard, 50);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/optimize');

        $response->assertOk();
        $response->assertJsonPath('preview_available', true);
        $this->assertEquals(300, $response->json('review_count'));
    }

    public function test_optimize_preview_does_not_save_parameters(): void
    {
        $card = $this->createSenseCard($this->createSense($this->user->id, 'english'));
        $this->createReviewLogs($card, SettingsService::FSRS_OPTIMIZATION_MIN_REQUIRED, [], 1);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/optimize');

        $response->assertOk();
        $response->assertJsonPath('applied', false);

        $this->assertDatabaseMissing('settings', [
            'name' => 'fsrs_parameters',
        ]);
        $this->assertDatabaseMissing('settings', [
            'name' => 'fsrs_parameters_source',
        ]);
        $this->assertDatabaseMissing('settings', [
            'name' => 'fsrs_parameters_optimized_at',
        ]);
    }

    public function test_optimize_preview_does_not_reschedule_cards(): void
    {
        $card = $this->createSenseCard($this->createSense($this->user->id, 'english'), [
            'fsrs_due_at' => Carbon::now()->addDays(3),
            'fsrs_stability' => 5.0,
            'fsrs_difficulty' => 4.0,
        ]);
        $this->createReviewLogs($card, SettingsService::FSRS_OPTIMIZATION_MIN_REQUIRED, [], 1);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/optimize');

        $response->assertOk();
        $response->assertJsonPath('preview_available', true);

        $card->refresh();
        $this->assertEquals(5.0, $card->fsrs_stability);
        $this->assertEquals(4.0, $card->fsrs_difficulty);
        // due_at should be approximately the same (within a second)
        $this->assertTrue(Carbon::now()->addDays(3)->diffInSeconds($card->fsrs_due_at) < 5);
    }

    public function test_optimize_preview_rating_mapping_all_ratings_accepted(): void
    {
        $card = $this->createSenseCard($this->createSense($this->user->id, 'english'));
        $this->createReviewLogs($card, 1, ['rating' => 'again'], 1);
        $this->createReviewLogs($card, 1, ['rating' => 'hard'], 1);
        $this->createReviewLogs($card, 1, ['rating' => 'good'], 1);
        $this->createReviewLogs($card, 1, ['rating' => 'easy'], 1);

        // Need at least 300 total, so add more
        $this->createReviewLogs($card, SettingsService::FSRS_OPTIMIZATION_MIN_REQUIRED - 4, [], 1);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/optimize');

        $response->assertOk();
        $response->assertJsonPath('preview_available', true);
        $response->assertJsonPath('optimized', false);
        $optimizedParams = $response->json('optimized_parameters');
        $this->assertCount($response->json('parameter_count'), $optimizedParams);
    }

    private function createSense(int $userId, string $language, array $overrides = []): WordSense
    {
        $lemma = $overrides['lemma'] ?? 'test';
        $pos = $overrides['pos'] ?? 'noun';
        $senseZh = $overrides['sense_zh'] ?? '测试';
        $senseEn = $overrides['sense_en'] ?? 'test';

        return WordSense::forceCreate(array_merge([
            'user_id' => $userId,
            'language' => $language,
            'language_id' => $language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => $pos,
            'sense_zh' => $senseZh,
            'sense_en' => $senseEn,
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'This is a test.',
            'example_sentence_zh' => '这是一个测试。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower("{$language}|{$lemma}|{$pos}|{$senseZh}|{$senseEn}")),
        ], $overrides));
    }

    private function createSenseCard(WordSense $sense, array $overrides = []): ReviewCard
    {
        return ReviewCard::forceCreate(array_merge([
            'user_id' => $sense->user_id,
            'language_id' => $sense->language_id,
            'language' => $sense->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
        ], $overrides));
    }

    private function createWordCard(int $userId, string $language): ReviewCard
    {
        $word = EncounteredWord::forceCreate([
            'user_id' => $userId,
            'language' => $language,
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
            'user_id' => $userId,
            'language_id' => $language,
            'language' => $language,
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => $word->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
        ]);
    }

    private function createReviewLogs(ReviewCard $card, int $count, array $overrides = [], int $daysSpacing = 0): void
    {
        for ($i = 0; $i < $count; $i++) {
            $time = $daysSpacing > 0
                ? Carbon::now()->subDays($i * $daysSpacing)
                : Carbon::now()->subMinutes($i);

            ReviewLog::forceCreate(array_merge([
                'user_id' => $card->user_id,
                'language_id' => $card->language_id,
                'language' => $card->language_id,
                'review_card_id' => $card->id,
                'rating' => 'good',
                'reviewed_at' => $time,
                'previous_state' => $card->fsrs_state,
                'new_state' => 'review',
                'previous_due_at' => $card->fsrs_due_at,
                'new_due_at' => Carbon::now()->addDays(3),
                'previous_stability' => $card->fsrs_stability,
                'new_stability' => 5.0,
                'previous_difficulty' => $card->fsrs_difficulty,
                'new_difficulty' => 4.5,
                'source' => 'sense_review',
            ], $overrides));
        }
    }
}
