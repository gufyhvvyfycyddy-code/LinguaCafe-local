<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\SenseReviewService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReviewStatsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Stats User',
            'email' => 'stats@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other Stats User',
            'email' => 'other.stats@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    // ==================== Helpers ====================

    private function createSense(int $userId, string $language, array $overrides = []): WordSense
    {
        $lemma = $overrides['lemma'] ?? 'test';
        $pos = $overrides['pos'] ?? 'noun';
        $senseZh = $overrides['sense_zh'] ?? '测试';
        $senseEn = $overrides['sense_en'] ?? 'test';

        $data = array_merge([
            'user_id' => $userId,
            'language' => $language,
            'language_id' => $language,
            'lemma' => 'test',
            'surface_form' => 'test',
            'pos' => 'noun',
            'sense_zh' => '测试',
            'sense_en' => 'test',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'This is a test.',
            'example_sentence_zh' => '这是一个测试。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower("{$language}|{$lemma}|{$pos}|{$senseZh}|{$senseEn}")),
        ], $overrides);

        return WordSense::forceCreate($data);
    }

    private function createSenseCard(WordSense $sense, array $overrides = []): ReviewCard
    {
        $data = array_merge([
            'user_id' => $sense->user_id,
            'language_id' => $sense->language_id,
            'language' => $sense->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
        ], $overrides);

        return ReviewCard::forceCreate($data);
    }

    private function createWordCard(int $userId, string $language, int $wordId): ReviewCard
    {
        return ReviewCard::forceCreate([
            'user_id' => $userId,
            'language_id' => $language,
            'language' => $language,
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => $wordId,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
        ]);
    }

    private function createReviewLog(ReviewCard $card, array $overrides = []): ReviewLog
    {
        $data = array_merge([
            'user_id' => $card->user_id,
            'language_id' => $card->language_id,
            'language' => $card->language_id,
            'review_card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => Carbon::now(),
            'previous_state' => $card->fsrs_state,
            'new_state' => 'review',
            'previous_due_at' => $card->fsrs_due_at,
            'new_due_at' => Carbon::now()->addDays(3),
            'previous_stability' => $card->fsrs_stability,
            'new_stability' => 5.0,
            'previous_difficulty' => $card->fsrs_difficulty,
            'new_difficulty' => 4.5,
            'source' => 'sense_review',
        ], $overrides);

        return ReviewLog::forceCreate($data);
    }

    // ==================== Test 1: Empty data returns zero values ====================

    public function test_empty_stats_returns_zero_values(): void
    {
        $response = $this->actingAs($this->user)->getJson('/review-cards/stats');
        $response->assertOk();

        $response->assertJsonPath('total', 0);
        $response->assertJsonPath('enabled', 0);
        $response->assertJsonPath('archived', 0);
        $response->assertJsonPath('due', 0);
        $response->assertJsonPath('by_state.new', 0);
        $response->assertJsonPath('by_state.learning', 0);
        $response->assertJsonPath('by_state.review', 0);
        $response->assertJsonPath('by_state.relearning', 0);
        $response->assertJsonPath('average_stability', null);
        $response->assertJsonPath('average_difficulty', null);
        $response->assertJsonPath('lapses_total', 0);
        $response->assertJsonPath('reviewed_today', 0);
        $response->assertJsonPath('reset_count', 0);
    }

    // ==================== Test 2: User isolation ====================

    public function test_stats_only_include_current_user_cards(): void
    {
        $senseA = $this->createSense($this->user->id, 'english', ['lemma' => 'userA']);
        $this->createSenseCard($senseA);

        $senseB = $this->createSense($this->otherUser->id, 'english', ['lemma' => 'userB']);
        $this->createSenseCard($senseB);

        $response = $this->actingAs($this->user)->getJson('/review-cards/stats');
        $response->assertOk();
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('enabled', 1);
    }

    // ==================== Test 3: Language isolation ====================

    public function test_stats_only_include_current_selected_language_cards(): void
    {
        $senseEn = $this->createSense($this->user->id, 'english', ['lemma' => 'english']);
        $this->createSenseCard($senseEn);

        $senseJa = $this->createSense($this->user->id, 'japanese', ['lemma' => 'japanese']);
        $this->createSenseCard($senseJa);

        // User has selected_language = 'english'
        $response = $this->actingAs($this->user)->getJson('/review-cards/stats');
        $response->assertOk();
        $response->assertJsonPath('total', 1);

        // Switch to japanese
        $this->user->selected_language = 'japanese';
        $this->user->save();

        $response = $this->actingAs($this->user)->getJson('/review-cards/stats');
        $response->assertOk();
        $response->assertJsonPath('total', 1);
    }

    // ==================== Test 4: by_state distribution ====================

    public function test_by_state_distribution_is_correct(): void
    {
        $sense1 = $this->createSense($this->user->id, 'english', ['lemma' => 'new1']);
        $this->createSenseCard($sense1, ['fsrs_state' => 'new']);

        $sense2 = $this->createSense($this->user->id, 'english', ['lemma' => 'new2']);
        $this->createSenseCard($sense2, ['fsrs_state' => 'new']);

        $sense3 = $this->createSense($this->user->id, 'english', ['lemma' => 'learning1']);
        $this->createSenseCard($sense3, ['fsrs_state' => 'learning']);

        $sense4 = $this->createSense($this->user->id, 'english', ['lemma' => 'review1']);
        $this->createSenseCard($sense4, ['fsrs_state' => 'review']);

        $sense5 = $this->createSense($this->user->id, 'english', ['lemma' => 'review2']);
        $this->createSenseCard($sense5, ['fsrs_state' => 'review']);

        $sense6 = $this->createSense($this->user->id, 'english', ['lemma' => 'review3']);
        $this->createSenseCard($sense6, ['fsrs_state' => 'review']);

        $sense7 = $this->createSense($this->user->id, 'english', ['lemma' => 'relearning1']);
        $this->createSenseCard($sense7, ['fsrs_state' => 'relearning']);

        $response = $this->actingAs($this->user)->getJson('/review-cards/stats');
        $response->assertOk();
        $response->assertJsonPath('by_state.new', 2);
        $response->assertJsonPath('by_state.learning', 1);
        $response->assertJsonPath('by_state.review', 3);
        $response->assertJsonPath('by_state.relearning', 1);
        $response->assertJsonPath('total', 7);
        $response->assertJsonPath('enabled', 7);
    }

    // ==================== Test 5: archived cards excluded from enabled/due/by_state ====================

    public function test_archived_cards_excluded_from_enabled_due_and_by_state(): void
    {
        $senseActive = $this->createSense($this->user->id, 'english', ['lemma' => 'active']);
        $this->createSenseCard($senseActive, ['fsrs_enabled' => true, 'fsrs_state' => 'review']);

        $senseArchived = $this->createSense($this->user->id, 'english', ['lemma' => 'archived']);
        $this->createSenseCard($senseArchived, ['fsrs_enabled' => false, 'fsrs_state' => 'new']);

        $response = $this->actingAs($this->user)->getJson('/review-cards/stats');
        $response->assertOk();
        $response->assertJsonPath('total', 2);
        $response->assertJsonPath('enabled', 1);
        $response->assertJsonPath('archived', 1);
        $response->assertJsonPath('by_state.new', 0);   // archived new card excluded from by_state
        $response->assertJsonPath('by_state.review', 1);
    }

    // ==================== Test 6: due only counts enabled + due_at <= now ====================

    public function test_due_only_counts_enabled_cards_past_due(): void
    {
        $senseDue = $this->createSense($this->user->id, 'english', ['lemma' => 'due']);
        $this->createSenseCard($senseDue, [
            'fsrs_enabled' => true,
            'fsrs_due_at' => Carbon::now()->subDay(),
        ]);

        $senseFuture = $this->createSense($this->user->id, 'english', ['lemma' => 'future']);
        $this->createSenseCard($senseFuture, [
            'fsrs_enabled' => true,
            'fsrs_due_at' => Carbon::now()->addDays(5),
        ]);

        $senseDisabledDue = $this->createSense($this->user->id, 'english', ['lemma' => 'disabled_due']);
        $this->createSenseCard($senseDisabledDue, [
            'fsrs_enabled' => false,
            'fsrs_due_at' => Carbon::now()->subDay(),
        ]);

        $response = $this->actingAs($this->user)->getJson('/review-cards/stats');
        $response->assertOk();
        $response->assertJsonPath('total', 3);
        $response->assertJsonPath('enabled', 2);
        $response->assertJsonPath('archived', 1);
        $response->assertJsonPath('due', 1);
    }

    // ==================== Test 7: average_stability and average_difficulty exclude null ====================

    public function test_averages_exclude_null_values(): void
    {
        // Card with null stability/difficulty (new card)
        $senseNew = $this->createSense($this->user->id, 'english', ['lemma' => 'new']);
        $this->createSenseCard($senseNew, [
            'fsrs_state' => 'new',
            'fsrs_stability' => null,
            'fsrs_difficulty' => null,
        ]);

        // Card with known stability/difficulty
        $senseReview = $this->createSense($this->user->id, 'english', ['lemma' => 'review']);
        $this->createSenseCard($senseReview, [
            'fsrs_state' => 'review',
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 3.0,
        ]);

        $senseReview2 = $this->createSense($this->user->id, 'english', ['lemma' => 'review2']);
        $this->createSenseCard($senseReview2, [
            'fsrs_state' => 'review',
            'fsrs_stability' => 20.0,
            'fsrs_difficulty' => 7.0,
        ]);

        $response = $this->actingAs($this->user)->getJson('/review-cards/stats');
        $response->assertOk();
        // Average of 10.0 and 20.0 = 15.0 (json_encode drops .0 for whole numbers)
        $this->assertSame(15.0, (float) $response->json('average_stability'));
        // Average of 3.0 and 7.0 = 5.0
        $this->assertSame(5.0, (float) $response->json('average_difficulty'));
    }

    // ==================== Test 8: lapses_total ====================

    public function test_lapses_total_is_correct(): void
    {
        $sense1 = $this->createSense($this->user->id, 'english', ['lemma' => 'card1']);
        $this->createSenseCard($sense1, ['fsrs_lapses' => 3]);

        $sense2 = $this->createSense($this->user->id, 'english', ['lemma' => 'card2']);
        $this->createSenseCard($sense2, ['fsrs_lapses' => 0]);

        $sense3 = $this->createSense($this->user->id, 'english', ['lemma' => 'card3']);
        $this->createSenseCard($sense3, ['fsrs_lapses' => 7]);

        // Archived card — lapses should NOT count (only enabled)
        $senseArchived = $this->createSense($this->user->id, 'english', ['lemma' => 'archived']);
        $this->createSenseCard($senseArchived, ['fsrs_lapses' => 100, 'fsrs_enabled' => false]);

        $response = $this->actingAs($this->user)->getJson('/review-cards/stats');
        $response->assertOk();
        $response->assertJsonPath('lapses_total', 10); // 3 + 0 + 7, archived excluded
    }

    // ==================== Test 9: reviewed_today excludes reset, reset_count counts reset ====================

    public function test_reviewed_today_excludes_reset_logs(): void
    {
        $sense = $this->createSense($this->user->id, 'english', ['lemma' => 'test']);
        $card = $this->createSenseCard($sense);

        // 3 normal review logs
        $this->createReviewLog($card, ['rating' => 'again', 'source' => 'sense_review']);
        $this->createReviewLog($card, ['rating' => 'good', 'source' => 'sense_review']);
        $this->createReviewLog($card, ['rating' => 'easy', 'source' => 'sense_review']);

        // 1 reset log
        $this->createReviewLog($card, [
            'rating' => 'reset',
            'source' => 'reset',
            'previous_state' => 'review',
            'new_state' => 'new',
            'previous_stability' => 10.0,
            'new_stability' => null,
            'previous_difficulty' => 5.0,
            'new_difficulty' => null,
            'new_due_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->user)->getJson('/review-cards/stats');
        $response->assertOk();
        $response->assertJsonPath('reviewed_today', 3);
        $response->assertJsonPath('reset_count', 1);
    }

    // ==================== Test 10: rejected sense excluded from all stats ====================

    public function test_rejected_sense_excluded_from_stats(): void
    {
        $senseConfirmed = $this->createSense($this->user->id, 'english', [
            'lemma' => 'confirmed',
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
        $this->createSenseCard($senseConfirmed);

        $senseRejected = $this->createSense($this->user->id, 'english', [
            'lemma' => 'rejected',
            'status' => WordSense::STATUS_REJECTED,
        ]);
        $this->createSenseCard($senseRejected);

        $response = $this->actingAs($this->user)->getJson('/review-cards/stats');
        $response->assertOk();
        $response->assertJsonPath('total', 1);
    }

    // ==================== Test 11: legacy word card excluded from main stats ====================

    public function test_legacy_word_card_excluded_from_stats(): void
    {
        $sense = $this->createSense($this->user->id, 'english', ['lemma' => 'sense']);
        $this->createSenseCard($sense);

        // Create a word card — should not appear in stats
        $word = EncounteredWord::forceCreate([
            'user_id' => $this->user->id,
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
        $this->createWordCard($this->user->id, 'english', $word->id);

        $response = $this->actingAs($this->user)->getJson('/review-cards/stats');
        $response->assertOk();
        $response->assertJsonPath('total', 1);
    }

    // ==================== Test 12: SenseReviewService::summary() due_count matches stats due ====================

    public function test_summary_due_count_matches_stats_due(): void
    {
        $senseDue = $this->createSense($this->user->id, 'english', ['lemma' => 'due']);
        $this->createSenseCard($senseDue, [
            'fsrs_enabled' => true,
            'fsrs_due_at' => Carbon::now()->subDay(),
        ]);

        $senseFuture = $this->createSense($this->user->id, 'english', ['lemma' => 'future']);
        $this->createSenseCard($senseFuture, [
            'fsrs_enabled' => true,
            'fsrs_due_at' => Carbon::now()->addDays(3),
        ]);

        // Stats endpoint
        $statsResponse = $this->actingAs($this->user)->getJson('/review-cards/stats');
        $statsResponse->assertOk();
        $statsDue = $statsResponse->json('due');

        // SenseReviewService
        $service = app(SenseReviewService::class);
        $summary = $service->summary($this->user->id, 'english');

        $this->assertSame($statsDue, $summary['due_count']);
    }

    // ==================== Test 13: dueCount performance — does not hydrate models ====================

    public function test_due_count_does_not_hydrate_models(): void
    {
        // Create 5 due cards
        for ($i = 0; $i < 5; $i++) {
            $sense = $this->createSense($this->user->id, 'english', ['lemma' => "word{$i}"]);
            $this->createSenseCard($sense, [
                'fsrs_enabled' => true,
                'fsrs_due_at' => Carbon::now()->subDay(),
            ]);
        }

        $service = app(SenseReviewService::class);
        $count = $service->dueCount($this->user->id, 'english');

        $this->assertSame(5, $count);

        // Verify dueCards still returns full collection
        $cards = $service->dueCards($this->user->id, 'english');
        $this->assertCount(5, $cards);
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $cards);
    }

    // ==================== Test 14: old review logs from yesterday not counted ====================

    public function test_review_activity_only_counts_today(): void
    {
        $sense = $this->createSense($this->user->id, 'english', ['lemma' => 'test']);
        $card = $this->createSenseCard($sense);

        // Yesterday's review — should NOT be counted
        $this->createReviewLog($card, [
            'rating' => 'good',
            'source' => 'sense_review',
            'reviewed_at' => Carbon::now()->subDay(),
        ]);

        // Today's review
        $this->createReviewLog($card, [
            'rating' => 'good',
            'source' => 'sense_review',
            'reviewed_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->user)->getJson('/review-cards/stats');
        $response->assertOk();
        $response->assertJsonPath('reviewed_today', 1);
    }

    // ==================== Test 15: other user's review logs not counted ====================

    public function test_review_activity_excludes_other_user_logs(): void
    {
        $senseUser = $this->createSense($this->user->id, 'english', ['lemma' => 'user']);
        $cardUser = $this->createSenseCard($senseUser);
        $this->createReviewLog($cardUser);

        $senseOther = $this->createSense($this->otherUser->id, 'english', ['lemma' => 'other']);
        $cardOther = $this->createSenseCard($senseOther);
        $this->createReviewLog($cardOther, ['user_id' => $this->otherUser->id, 'language_id' => 'english']);

        $response = $this->actingAs($this->user)->getJson('/review-cards/stats');
        $response->assertOk();
        $response->assertJsonPath('reviewed_today', 1);
    }
}
