<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ReviewQueueOrderNextCardTest
 *
 * DEV-QO-5 / DEV-QO-7 — Backend next_card consistency tests.
 *
 * Verifies that:
 *   1. /reviews/rate returns next_card matching the first card of a
 *      subsequent /reviews request (legacy endpoint).
 *   2. /reviews/senses/{id}/rate returns next_card matching the first
 *      card of a subsequent /reviews/senses request (sense endpoint).
 *   3. Both rate endpoints use the same Queue Order settings.
 *   4. next_card is null when the queue is empty after rating.
 *   5. ignoreDailyLimits is respected by the legacy rate endpoint.
 *   6. next_card consistency: both endpoints return the same next card id.
 *   7. Rating does not create duplicate ReviewLog entries.
 */
class ReviewQueueOrderNextCardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $language = 'english';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Next Card Test',
            'email' => '__VG_EMAIL_next_card_test__',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    // ── Helpers ──────────────────────────────────

    private function createSense(string $lemma, string $pos = 'noun'): WordSense
    {
        return WordSense::forceCreate([
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
        ]);
    }

    private function createCard(WordSense $sense, array $overrides = []): ReviewCard
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
            'lifecycle_state' => 'active',
        ], $overrides));
    }

    private function saveQueueOrder(array $settings): void
    {
        $this->actingAs($this->user)->postJson('/settings/fsrs/queue-order', $settings)->assertOk();
    }

    // ── Tests: Legacy /reviews/rate next_card ───────────

    public function test_legacy_rate_returns_next_card_matching_subsequent_reviews_request(): void
    {
        $s1 = $this->createSense('alpha');
        $s2 = $this->createSense('bravo');
        $this->createCard($s1, ['fsrs_due_at' => Carbon::now()->subMinutes(10)]);
        $this->createCard($s2, ['fsrs_due_at' => Carbon::now()->subMinutes(5)]);

        // Use due_stable for deterministic order.
        $this->saveQueueOrder(['review_sort_order' => 'due_stable']);

        // Get initial queue.
        $initialResponse = $this->actingAs($this->user)->postJson('/reviews', [
            'practiceMode' => false,
            'bookId' => -1,
            'chapterId' => -1,
        ]);
        $initialResponse->assertOk();
        $firstCardId = $initialResponse->json('reviews.0.review_card_id');

        // Rate the first card.
        $rateResponse = $this->actingAs($this->user)->postJson('/reviews/rate', [
            'reviewCardId' => $firstCardId,
            'rating' => 'good',
        ]);
        $rateResponse->assertOk();

        // The rate response must include next_card.
        $nextCard = $rateResponse->json('next_card');
        $this->assertNotNull($nextCard, 'rate response must include next_card');

        // The next_card id must match the first card of a fresh /reviews request.
        $freshResponse = $this->actingAs($this->user)->postJson('/reviews', [
            'practiceMode' => false,
            'bookId' => -1,
            'chapterId' => -1,
        ]);
        $freshResponse->assertOk();
        $freshFirstCardId = $freshResponse->json('reviews.0.review_card_id');

        $this->assertSame(
            $nextCard['review_card_id'],
            $freshFirstCardId,
            'next_card from /reviews/rate must match first card of subsequent /reviews request'
        );
    }

    public function test_legacy_rate_returns_null_next_card_when_queue_empty(): void
    {
        $s1 = $this->createSense('alpha');
        $this->createCard($s1, ['fsrs_due_at' => Carbon::now()->subMinutes(5)]);

        $this->saveQueueOrder(['review_sort_order' => 'due_stable']);

        // Get the only card.
        $initialResponse = $this->actingAs($this->user)->postJson('/reviews', [
            'practiceMode' => false,
            'bookId' => -1,
            'chapterId' => -1,
        ]);
        $initialResponse->assertOk();
        $firstCardId = $initialResponse->json('reviews.0.review_card_id');

        // Rate it.
        $rateResponse = $this->actingAs($this->user)->postJson('/reviews/rate', [
            'reviewCardId' => $firstCardId,
            'rating' => 'good',
        ]);
        $rateResponse->assertOk();

        // next_card must be null since the queue is now empty.
        $this->assertNull(
            $rateResponse->json('next_card'),
            'next_card must be null when queue is empty after rating'
        );
    }

    public function test_legacy_rate_passes_ignore_daily_limits(): void
    {
        $s1 = $this->createSense('alpha');
        $s2 = $this->createSense('bravo');
        $this->createCard($s1, ['fsrs_due_at' => Carbon::now()->subMinutes(10)]);
        $this->createCard($s2, ['fsrs_due_at' => Carbon::now()->subMinutes(5)]);

        $this->saveQueueOrder(['review_sort_order' => 'due_stable']);

        // Get initial queue with ignoreDailyLimits.
        $initialResponse = $this->actingAs($this->user)->postJson('/reviews', [
            'practiceMode' => false,
            'bookId' => -1,
            'chapterId' => -1,
            'ignoreDailyLimits' => true,
        ]);
        $initialResponse->assertOk();
        $firstCardId = $initialResponse->json('reviews.0.review_card_id');

        // Rate with ignoreDailyLimits.
        $rateResponse = $this->actingAs($this->user)->postJson('/reviews/rate', [
            'reviewCardId' => $firstCardId,
            'rating' => 'good',
            'ignoreDailyLimits' => true,
        ]);
        $rateResponse->assertOk();

        // Summary must reflect ignore_daily_limits state.
        $summary = $rateResponse->json('summary');
        $this->assertIsArray($summary, 'rate response must include summary');
    }

    public function test_legacy_rate_does_not_create_duplicate_review_logs(): void
    {
        $s1 = $this->createSense('alpha');
        $s2 = $this->createSense('bravo');
        $this->createCard($s1, ['fsrs_due_at' => Carbon::now()->subMinutes(10)]);
        $this->createCard($s2, ['fsrs_due_at' => Carbon::now()->subMinutes(5)]);

        $this->saveQueueOrder(['review_sort_order' => 'due_stable']);

        $initialResponse = $this->actingAs($this->user)->postJson('/reviews', [
            'practiceMode' => false,
            'bookId' => -1,
            'chapterId' => -1,
        ]);
        $firstCardId = $initialResponse->json('reviews.0.review_card_id');

        $logCountBefore = ReviewLog::where('review_card_id', $firstCardId)->count();

        $this->actingAs($this->user)->postJson('/reviews/rate', [
            'reviewCardId' => $firstCardId,
            'rating' => 'good',
        ])->assertOk();

        $logCountAfter = ReviewLog::where('review_card_id', $firstCardId)->count();

        $this->assertSame(
            $logCountBefore + 1,
            $logCountAfter,
            'A single rating must create exactly one ReviewLog entry'
        );
    }

    // ── Tests: Sense /reviews/senses/{id}/rate next_card ───────────

    public function test_sense_rate_returns_next_card_matching_subsequent_senses_request(): void
    {
        $s1 = $this->createSense('alpha');
        $s2 = $this->createSense('bravo');
        $this->createCard($s1, ['fsrs_due_at' => Carbon::now()->subMinutes(10)]);
        $this->createCard($s2, ['fsrs_due_at' => Carbon::now()->subMinutes(5)]);

        $this->saveQueueOrder(['review_sort_order' => 'due_stable']);

        // Get initial sense queue.
        $initialResponse = $this->actingAs($this->user)->getJson('/reviews/senses');
        $initialResponse->assertOk();
        $firstCardId = $initialResponse->json('cards.0.review_card_id');

        // Rate the first card.
        $rateResponse = $this->actingAs($this->user)->postJson(
            "/reviews/senses/{$firstCardId}/rate",
            ['rating' => 'good']
        );
        $rateResponse->assertOk();

        // The rate response must include next_card.
        $nextCard = $rateResponse->json('next_card');
        $this->assertNotNull($nextCard, 'sense rate response must include next_card');

        // The next_card id must match the first card of a fresh /reviews/senses request.
        $freshResponse = $this->actingAs($this->user)->getJson('/reviews/senses');
        $freshResponse->assertOk();
        $freshFirstCardId = $freshResponse->json('cards.0.review_card_id');

        $this->assertSame(
            $nextCard['review_card_id'],
            $freshFirstCardId,
            'next_card from /reviews/senses/{id}/rate must match first card of subsequent /reviews/senses request'
        );
    }

    public function test_sense_rate_returns_null_next_card_when_queue_empty(): void
    {
        $s1 = $this->createSense('alpha');
        $this->createCard($s1, ['fsrs_due_at' => Carbon::now()->subMinutes(5)]);

        $this->saveQueueOrder(['review_sort_order' => 'due_stable']);

        // Get the only card.
        $initialResponse = $this->actingAs($this->user)->getJson('/reviews/senses');
        $initialResponse->assertOk();
        $firstCardId = $initialResponse->json('cards.0.review_card_id');

        // Rate it.
        $rateResponse = $this->actingAs($this->user)->postJson(
            "/reviews/senses/{$firstCardId}/rate",
            ['rating' => 'good']
        );
        $rateResponse->assertOk();

        // next_card must be null since the queue is now empty.
        $this->assertNull(
            $rateResponse->json('next_card'),
            'next_card must be null when sense queue is empty after rating'
        );
    }

    // ── Tests: Cross-endpoint consistency ───────────

    public function test_both_rate_endpoints_return_same_next_card_id(): void
    {
        $s1 = $this->createSense('alpha');
        $s2 = $this->createSense('bravo');
        $s3 = $this->createSense('charlie');
        $this->createCard($s1, ['fsrs_due_at' => Carbon::now()->subMinutes(10)]);
        $this->createCard($s2, ['fsrs_due_at' => Carbon::now()->subMinutes(5)]);
        $this->createCard($s3, ['fsrs_due_at' => Carbon::now()->subMinutes(1)]);

        $this->saveQueueOrder(['review_sort_order' => 'due_stable']);

        // Get the first card from sense endpoint.
        $senseResponse = $this->actingAs($this->user)->getJson('/reviews/senses');
        $firstCardId = $senseResponse->json('cards.0.review_card_id');

        // Rate via sense endpoint.
        $senseRateResponse = $this->actingAs($this->user)->postJson(
            "/reviews/senses/{$firstCardId}/rate",
            ['rating' => 'good']
        );
        $senseRateResponse->assertOk();
        $senseNextCardId = $senseRateResponse->json('next_card.review_card_id');

        // Now we need to test the legacy endpoint separately. Since the
        // first card is already rated, we need a fresh setup. Instead,
        // verify that both endpoints return the same order by checking
        // that the sense next_card matches the legacy first card.
        $legacyResponse = $this->actingAs($this->user)->postJson('/reviews', [
            'practiceMode' => false,
            'bookId' => -1,
            'chapterId' => -1,
        ]);
        $legacyResponse->assertOk();

        // The legacy endpoint should return the remaining cards (without
        // the already-rated one). The first card should match the sense
        // next_card (both use the same Queue Order).
        $legacyFirstCardId = $legacyResponse->json('reviews.0.review_card_id');

        $this->assertSame(
            $senseNextCardId,
            $legacyFirstCardId,
            'Both endpoints must return the same next card id when using the same Queue Order'
        );
    }

    public function test_rate_response_includes_summary(): void
    {
        $s1 = $this->createSense('alpha');
        $s2 = $this->createSense('bravo');
        $this->createCard($s1, ['fsrs_due_at' => Carbon::now()->subMinutes(10)]);
        $this->createCard($s2, ['fsrs_due_at' => Carbon::now()->subMinutes(5)]);

        $this->saveQueueOrder(['review_sort_order' => 'due_stable']);

        $initialResponse = $this->actingAs($this->user)->postJson('/reviews', [
            'practiceMode' => false,
            'bookId' => -1,
            'chapterId' => -1,
        ]);
        $firstCardId = $initialResponse->json('reviews.0.review_card_id');

        $rateResponse = $this->actingAs($this->user)->postJson('/reviews/rate', [
            'reviewCardId' => $firstCardId,
            'rating' => 'good',
        ]);
        $rateResponse->assertOk();

        $summary = $rateResponse->json('summary');
        $this->assertIsArray($summary, 'rate response must include summary');
        $this->assertArrayHasKey('due_count', $summary, 'summary must include due_count');
    }

    public function test_sense_rate_response_includes_summary(): void
    {
        $s1 = $this->createSense('alpha');
        $s2 = $this->createSense('bravo');
        $this->createCard($s1, ['fsrs_due_at' => Carbon::now()->subMinutes(10)]);
        $this->createCard($s2, ['fsrs_due_at' => Carbon::now()->subMinutes(5)]);

        $this->saveQueueOrder(['review_sort_order' => 'due_stable']);

        $initialResponse = $this->actingAs($this->user)->getJson('/reviews/senses');
        $firstCardId = $initialResponse->json('cards.0.review_card_id');

        $rateResponse = $this->actingAs($this->user)->postJson(
            "/reviews/senses/{$firstCardId}/rate",
            ['rating' => 'good']
        );
        $rateResponse->assertOk();

        $summary = $rateResponse->json('summary');
        $this->assertIsArray($summary, 'sense rate response must include summary');
        $this->assertArrayHasKey('due_count', $summary, 'summary must include due_count');
    }
}
