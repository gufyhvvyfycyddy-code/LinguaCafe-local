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

    private function createReviewLogs(ReviewCard $card, int $count, array $overrides = []): void
    {
        for ($i = 0; $i < $count; $i++) {
            ReviewLog::forceCreate(array_merge([
                'user_id' => $card->user_id,
                'language_id' => $card->language_id,
                'language' => $card->language_id,
                'review_card_id' => $card->id,
                'rating' => 'good',
                'reviewed_at' => Carbon::now()->subMinutes($i),
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
