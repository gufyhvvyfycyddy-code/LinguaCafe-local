<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SenseReviewDailyLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $language = 'english';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Sense Daily Limits Test',
            'email' => '__VG_EMAIL_sense_daily_limits_test__',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    // ── Helpers ──────────────────────────────────

    private function createSense(array $overrides = []): WordSense
    {
        $lemma = $overrides['lemma'] ?? 'test';
        $pos = $overrides['pos'] ?? 'noun';

        return WordSense::forceCreate(array_merge([
            'user_id' => $this->user->id,
            'language' => $this->language,
            'language_id' => $this->language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => $pos,
            'sense_zh' => '测试',
            'sense_en' => 'test',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Test sentence.',
            'example_sentence_zh' => '测试句子。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower("{$this->language}|{$lemma}|{$pos}|测试")),
        ], $overrides));
    }

    private function createSenseCard(WordSense $sense, array $overrides = []): ReviewCard
    {
        return ReviewCard::forceCreate(array_merge([
            'user_id' => $this->user->id,
            'language_id' => $this->language,
            'language' => $this->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->subMinutes(5),
            'fsrs_enabled' => true,
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(3),
        ], $overrides));
    }

    private function createTodayReviewLog(ReviewCard $card, array $overrides = []): ReviewLog
    {
        return ReviewLog::forceCreate(array_merge([
            'user_id' => $this->user->id,
            'language_id' => $this->language,
            'language' => $this->language,
            'review_card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => Carbon::now()->subHours(1),
            'previous_state' => 'review',
            'new_state' => 'review',
            'previous_stability' => 10.0,
            'new_stability' => 12.0,
            'previous_difficulty' => 5.0,
            'new_difficulty' => 4.5,
            'source' => 'sense_review',
        ], $overrides));
    }

    // ── Tests ────────────────────────────────────

    public function test_default_limits_are_enabled(): void
    {
        // Default daily limits should be enabled
        $sense = $this->createSense();
        $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)->getJson('/settings/fsrs/daily-limits');

        $response->assertOk();
        $this->assertTrue($response->json('daily_review_limit_enabled'));
        $this->assertEquals(200, $response->json('daily_review_limit'));
        $this->assertTrue($response->json('daily_new_limit_enabled'));
        $this->assertEquals(20, $response->json('daily_new_limit'));
    }

    public function test_returns_all_cards_when_below_limit(): void
    {
        $sense1 = $this->createSense(['lemma' => 'word_a']);
        $sense2 = $this->createSense(['lemma' => 'word_b']);
        $this->createSenseCard($sense1);
        $this->createSenseCard($sense2);

        // Low review limit so all are visible (2 cards, limit 200)
        $response = $this->actingAs($this->user)->postJson('/reviews', ['bookId' => -1, 'chapterId' => -1, 'practiceMode' => false]);

        $response->assertOk();
        $this->assertCount(2, $response->json('reviews'));
        $this->assertEquals(0, $response->json('summary.hidden_due_count'));
        $this->assertFalse($response->json('summary.limit_reached'));
    }

    public function test_hides_cards_when_over_review_limit(): void
    {
        // Set a tight review limit
        Setting::forceCreate(['name' => 'daily_review_limit', 'user_id' => -1, 'value' => json_encode(2)]);
        Setting::forceCreate(['name' => 'daily_review_limit_enabled', 'user_id' => -1, 'value' => json_encode(true)]);

        $sense1 = $this->createSense(['lemma' => 'word_a']);
        $sense2 = $this->createSense(['lemma' => 'word_b']);
        $sense3 = $this->createSense(['lemma' => 'word_c']);
        $this->createSenseCard($sense1);
        $this->createSenseCard($sense2);
        $this->createSenseCard($sense3);

        $response = $this->actingAs($this->user)->postJson('/reviews', ['bookId' => -1, 'chapterId' => -1, 'practiceMode' => false]);

        $response->assertOk();
        $this->assertCount(2, $response->json('reviews'));
        $this->assertEquals(1, $response->json('summary.hidden_due_count'));
        $this->assertTrue($response->json('summary.limit_reached'));
        $this->assertTrue($response->json('summary.can_continue_over_limit'));
        $this->assertEquals(3, $response->json('summary.total_due_count'));
    }

    public function test_ignore_daily_limits_returns_all_cards(): void
    {
        Setting::forceCreate(['name' => 'daily_review_limit', 'user_id' => -1, 'value' => json_encode(1)]);
        Setting::forceCreate(['name' => 'daily_review_limit_enabled', 'user_id' => -1, 'value' => json_encode(true)]);

        $sense1 = $this->createSense(['lemma' => 'word_a']);
        $sense2 = $this->createSense(['lemma' => 'word_b']);
        $this->createSenseCard($sense1);
        $this->createSenseCard($sense2);

        $response = $this->actingAs($this->user)->postJson('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
            'ignoreDailyLimits' => true,
        ]);

        $response->assertOk();
        $this->assertCount(2, $response->json('reviews'));
        $this->assertEquals(0, $response->json('summary.hidden_due_count'));
        $this->assertTrue($response->json('summary.ignore_daily_limits'));
    }

    public function test_reviewed_today_reduces_remaining_slots(): void
    {
        // Set limit to 3
        Setting::forceCreate(['name' => 'daily_review_limit', 'user_id' => -1, 'value' => json_encode(3)]);
        Setting::forceCreate(['name' => 'daily_review_limit_enabled', 'user_id' => -1, 'value' => json_encode(true)]);

        $card = $this->createSenseCard($this->createSense(['lemma' => 'word_a']));
        $this->createTodayReviewLog($card);
        // Set card A's due_at to future so it's not in the due list (simulating real rating behavior)
        $card->fsrs_due_at = Carbon::now()->addDay();
        $card->save();

        $sense2 = $this->createSense(['lemma' => 'word_b']);
        $sense3 = $this->createSense(['lemma' => 'word_c']);
        $sense4 = $this->createSense(['lemma' => 'word_d']);
        $this->createSenseCard($sense2);
        $this->createSenseCard($sense3);
        $this->createSenseCard($sense4);

        // Reviewed 1 today, limit 3, remaining 2 slots
        $response = $this->actingAs($this->user)->postJson('/reviews', ['bookId' => -1, 'chapterId' => -1, 'practiceMode' => false]);

        $response->assertOk();
        $this->assertEquals(1, $response->json('summary.reviewed_today_count'));
        $this->assertEquals(2, $response->json('summary.remaining_review_slots'));
        $this->assertCount(2, $response->json('reviews'));
        $this->assertEquals(1, $response->json('summary.hidden_due_count'));
    }

    public function test_does_not_modify_due_at(): void
    {
        Setting::forceCreate(['name' => 'daily_review_limit', 'user_id' => -1, 'value' => json_encode(1)]);
        Setting::forceCreate(['name' => 'daily_review_limit_enabled', 'user_id' => -1, 'value' => json_encode(true)]);

        $card = $this->createSenseCard($this->createSense(['lemma' => 'word_a']));
        $originalDueAt = $card->fsrs_due_at->toIso8601String();

        $this->actingAs($this->user)->postJson('/reviews', ['bookId' => -1, 'chapterId' => -1, 'practiceMode' => false]);

        $card->refresh();
        $this->assertEquals($originalDueAt, $card->fsrs_due_at->toIso8601String());
    }

    public function test_does_not_create_review_log_on_get(): void
    {
        $logCount = ReviewLog::count();
        $this->actingAs($this->user)->postJson('/reviews', ['bookId' => -1, 'chapterId' => -1, 'practiceMode' => false]);

        $this->assertEquals($logCount, ReviewLog::count());
    }

    public function test_requires_auth(): void
    {
        $response = $this->postJson('/reviews', ['bookId' => -1, 'chapterId' => -1]);
        $response->assertStatus(401);
    }

    public function test_summary_has_complete_structure(): void
    {
        $sense = $this->createSense();
        $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)->postJson('/reviews', ['bookId' => -1, 'chapterId' => -1, 'practiceMode' => false]);
        $response->assertOk();

        $summary = $response->json('summary');
        $this->assertNotNull($summary);
        $this->assertArrayHasKey('due_count', $summary);
        $this->assertArrayHasKey('visible_count', $summary);
        $this->assertArrayHasKey('total_due_count', $summary);
        $this->assertArrayHasKey('hidden_due_count', $summary);
        $this->assertArrayHasKey('reviewed_today_count', $summary);
        $this->assertArrayHasKey('remaining_review_slots', $summary);
        $this->assertArrayHasKey('is_queue_enforced', $summary);
        $this->assertArrayHasKey('ignore_daily_limits', $summary);
        $this->assertArrayHasKey('limit_reached', $summary);
        $this->assertArrayHasKey('can_continue_over_limit', $summary);
        $this->assertTrue($summary['is_queue_enforced']);
    }

    public function test_new_cards_are_limited_when_review_limit_reached(): void
    {
        // Review limit: 1, New limit: 5, New cards ignore review limit: false
        Setting::forceCreate(['name' => 'daily_review_limit', 'user_id' => -1, 'value' => json_encode(1)]);
        Setting::forceCreate(['name' => 'daily_review_limit_enabled', 'user_id' => -1, 'value' => json_encode(true)]);
        Setting::forceCreate(['name' => 'daily_new_limit', 'user_id' => -1, 'value' => json_encode(5)]);
        Setting::forceCreate(['name' => 'daily_new_limit_enabled', 'user_id' => -1, 'value' => json_encode(true)]);
        Setting::forceCreate(['name' => 'new_cards_ignore_review_limit', 'user_id' => -1, 'value' => json_encode(false)]);

        // 1 review card + 3 new cards
        $this->createSenseCard($this->createSense(['lemma' => 'review_card']), ['fsrs_state' => 'review']);
        $this->createSenseCard($this->createSense(['lemma' => 'new_card_a']), ['fsrs_state' => 'new', 'fsrs_stability' => null, 'fsrs_difficulty' => null]);
        $this->createSenseCard($this->createSense(['lemma' => 'new_card_b']), ['fsrs_state' => 'new', 'fsrs_stability' => null, 'fsrs_difficulty' => null]);
        $this->createSenseCard($this->createSense(['lemma' => 'new_card_c']), ['fsrs_state' => 'new', 'fsrs_stability' => null, 'fsrs_difficulty' => null]);

        $response = $this->actingAs($this->user)->postJson('/reviews', ['bookId' => -1, 'chapterId' => -1, 'practiceMode' => false]);

        $response->assertOk();
        // Review limit 1, so only 1 review card. New cards compete for remaining slots (0 after 1 review)
        $this->assertCount(1, $response->json('reviews'));
        $this->assertEquals(3, $response->json('summary.hidden_due_count'));
    }

    public function test_new_cards_ignore_review_limit(): void
    {
        Setting::forceCreate(['name' => 'daily_review_limit', 'user_id' => -1, 'value' => json_encode(1)]);
        Setting::forceCreate(['name' => 'daily_review_limit_enabled', 'user_id' => -1, 'value' => json_encode(true)]);
        Setting::forceCreate(['name' => 'daily_new_limit', 'user_id' => -1, 'value' => json_encode(3)]);
        Setting::forceCreate(['name' => 'daily_new_limit_enabled', 'user_id' => -1, 'value' => json_encode(true)]);
        Setting::forceCreate(['name' => 'new_cards_ignore_review_limit', 'user_id' => -1, 'value' => json_encode(true)]);

        // 1 review card + 3 new cards
        $this->createSenseCard($this->createSense(['lemma' => 'review_card']), ['fsrs_state' => 'review']);
        $this->createSenseCard($this->createSense(['lemma' => 'new_card_a']), ['fsrs_state' => 'new', 'fsrs_stability' => null, 'fsrs_difficulty' => null]);
        $this->createSenseCard($this->createSense(['lemma' => 'new_card_b']), ['fsrs_state' => 'new', 'fsrs_stability' => null, 'fsrs_difficulty' => null]);

        $response = $this->actingAs($this->user)->postJson('/reviews', ['bookId' => -1, 'chapterId' => -1, 'practiceMode' => false]);

        $response->assertOk();
        // 1 review card + up to 3 new cards (new ignore review limit)
        $this->assertCount(3, $response->json('reviews'));
    }

    public function test_senses_endpoint_respects_daily_review_limits(): void
    {
        Setting::forceCreate(['name' => 'daily_review_limit', 'user_id' => -1, 'value' => json_encode(2)]);
        Setting::forceCreate(['name' => 'daily_review_limit_enabled', 'user_id' => -1, 'value' => json_encode(true)]);

        $sense1 = $this->createSense(['lemma' => 'word_a']);
        $sense2 = $this->createSense(['lemma' => 'word_b']);
        $sense3 = $this->createSense(['lemma' => 'word_c']);
        $this->createSenseCard($sense1);
        $this->createSenseCard($sense2);
        $this->createSenseCard($sense3);

        // GET /reviews/senses must also respect daily limits
        $response = $this->actingAs($this->user)->getJson('/reviews/senses');

        $response->assertOk();
        $this->assertCount(2, $response->json('cards'));
        $this->assertEquals(1, $response->json('summary.hidden_due_count'));
        $this->assertTrue($response->json('summary.limit_reached'));
        $this->assertTrue($response->json('summary.is_queue_enforced'));
    }

    public function test_senses_endpoint_ignore_daily_limits(): void
    {
        Setting::forceCreate(['name' => 'daily_review_limit', 'user_id' => -1, 'value' => json_encode(1)]);
        Setting::forceCreate(['name' => 'daily_review_limit_enabled', 'user_id' => -1, 'value' => json_encode(true)]);

        $sense1 = $this->createSense(['lemma' => 'word_a']);
        $sense2 = $this->createSense(['lemma' => 'word_b']);
        $this->createSenseCard($sense1);
        $this->createSenseCard($sense2);

        // GET /reviews/senses with ignore_daily_limits parameter
        $response = $this->actingAs($this->user)->getJson('/reviews/senses?ignore_daily_limits=true');

        $response->assertOk();
        $this->assertCount(2, $response->json('cards'));
        $this->assertEquals(0, $response->json('summary.hidden_due_count'));
        $this->assertTrue($response->json('summary.ignore_daily_limits'));
    }

    public function test_daily_new_limit_zero_hides_new_cards(): void
    {
        // New limit = 0, even with new_cards_ignore_review_limit = true
        Setting::forceCreate(['name' => 'daily_review_limit', 'user_id' => -1, 'value' => json_encode(10)]);
        Setting::forceCreate(['name' => 'daily_review_limit_enabled', 'user_id' => -1, 'value' => json_encode(true)]);
        Setting::forceCreate(['name' => 'daily_new_limit', 'user_id' => -1, 'value' => json_encode(0)]);
        Setting::forceCreate(['name' => 'daily_new_limit_enabled', 'user_id' => -1, 'value' => json_encode(true)]);
        Setting::forceCreate(['name' => 'new_cards_ignore_review_limit', 'user_id' => -1, 'value' => json_encode(true)]);

        // 2 new sense cards (no review-state cards)
        $this->createSenseCard($this->createSense(['lemma' => 'new_card_a']), [
            'fsrs_state' => 'new', 'fsrs_stability' => null, 'fsrs_difficulty' => null,
        ]);
        $this->createSenseCard($this->createSense(['lemma' => 'new_card_b']), [
            'fsrs_state' => 'new', 'fsrs_stability' => null, 'fsrs_difficulty' => null,
        ]);

        $response = $this->actingAs($this->user)->postJson('/reviews', [
            'bookId' => -1, 'chapterId' => -1, 'practiceMode' => false,
        ]);

        $response->assertOk();
        $this->assertCount(0, $response->json('reviews'));
        $summary = $response->json('summary');
        $this->assertEquals(2, $summary['total_due_count']);
        $this->assertEquals(0, $summary['visible_count']);
        $this->assertEquals(2, $summary['hidden_due_count']);
        $this->assertEquals(2, $summary['hidden_by_new_limit']);
    }

    public function test_reset_logs_do_not_reduce_remaining_review_slots(): void
    {
        Setting::forceCreate(['name' => 'daily_review_limit', 'user_id' => -1, 'value' => json_encode(2)]);
        Setting::forceCreate(['name' => 'daily_review_limit_enabled', 'user_id' => -1, 'value' => json_encode(true)]);

        // Card A with a reset review log today
        $cardA = $this->createSenseCard($this->createSense(['lemma' => 'word_a']));
        $this->createTodayReviewLog($cardA, [
            'rating' => 'reset',
            'source' => 'reset',
        ]);
        // Push card A's due_at to the future so it does not appear in the due queue
        $cardA->fsrs_due_at = Carbon::now()->addDay();
        $cardA->save();

        // 2 more due sense cards
        $this->createSenseCard($this->createSense(['lemma' => 'word_b']));
        $this->createSenseCard($this->createSense(['lemma' => 'word_c']));

        $response = $this->actingAs($this->user)->postJson('/reviews', [
            'bookId' => -1, 'chapterId' => -1, 'practiceMode' => false,
        ]);

        $response->assertOk();
        $summary = $response->json('summary');

        // Reset logs should NOT count toward reviewed_today_count
        $this->assertEquals(0, $summary['reviewed_today_count']);
        $this->assertEquals(2, $summary['remaining_review_slots']);
        $this->assertCount(2, $response->json('reviews'));
        $this->assertEquals(0, $summary['hidden_due_count']);
        $this->assertFalse($summary['limit_reached']);
    }
}
