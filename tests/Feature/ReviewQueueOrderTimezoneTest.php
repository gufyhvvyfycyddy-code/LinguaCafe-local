<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\SenseReviewService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ReviewQueueOrderTimezoneTest
 *
 * DEV-QO-3 / DEV-QO-8 — Feature-level timezone integration tests.
 *
 * Verifies that the unified learning timezone boundary
 * (ReviewStudyTimezoneService) correctly affects:
 *   1. due_random local date grouping (not UTC date)
 *   2. due_random separation across local midnight
 *   3. due_random in UTC timezone
 *   4. reviewedTodayCount day boundary (DEV-QO-8)
 *   5. DST boundary handling
 *   6. Both endpoints consistent across timezone boundaries
 *
 * IMPORTANT: Laravel stores datetimes via toDateTimeString() which uses
 * the Carbon's own timezone, and reads them back in the app timezone.
 * To avoid round-trip timezone confusion, all test Carbons are created
 * in the APP timezone (matching config('app.timezone')).
 */
class ReviewQueueOrderTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $language = 'english';
    private ?string $originalTz = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTz = config('app.timezone');

        $this->user = User::forceCreate([
            'name' => 'TZ Test',
            'email' => '__VG_EMAIL_tz_test__',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->originalTz !== null) {
            config(['app.timezone' => $this->originalTz]);
        }
        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────

    private function setTimezone(string $tz): void
    {
        config(['app.timezone' => $tz]);
    }

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

    private function createReviewLog(ReviewCard $card, Carbon $reviewedAt, string $rating = 'good'): ReviewLog
    {
        return ReviewLog::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => $this->language,
            'language' => $this->language,
            'review_card_id' => $card->id,
            'rating' => $rating,
            'reviewed_at' => $reviewedAt,
            'previous_state' => 'review',
            'new_state' => 'review',
            'previous_due_at' => Carbon::now()->subDays(1),
            'new_due_at' => Carbon::now()->addDays(3),
            'source' => 'sense_review',
            'undone_at' => null,
        ]);
    }

    private function saveQueueOrder(array $settings): void
    {
        $this->actingAs($this->user)->postJson('/settings/fsrs/queue-order', $settings)->assertOk();
    }

    private function cardIdFor(WordSense $sense): int
    {
        return ReviewCard::where('target_id', $sense->id)->value('id');
    }

    // ── Tests: due_random local date grouping ───────────

    /**
     * DEV-QO-3: Cards due on an earlier local date must come before cards
     * due on a later local date. Cards on the same local date are grouped
     * together (ordered by stable daily hash, not by due_at timestamp).
     */
    public function test_due_random_groups_cards_by_local_date(): void
    {
        $this->setTimezone('America/Los_Angeles');
        $this->saveQueueOrder(['review_sort_order' => 'due_random']);

        // Mock "now" to 2026-07-13 20:00 LA
        Carbon::setTestNow(Carbon::create(2026, 7, 13, 20, 0, 0, 'America/Los_Angeles'));

        $s1 = $this->createSense('alpha'); // earlier local date
        $s2 = $this->createSense('bravo'); // later local date (today)
        $s3 = $this->createSense('charlie'); // later local date (today)

        // Card 1: due 2026-07-12 23:00 LA (yesterday — earlier local date)
        $this->createCard($s1, [
            'fsrs_due_at' => Carbon::create(2026, 7, 12, 23, 0, 0, 'America/Los_Angeles'),
        ]);
        // Card 2: due 2026-07-13 10:00 LA (today — later local date)
        $this->createCard($s2, [
            'fsrs_due_at' => Carbon::create(2026, 7, 13, 10, 0, 0, 'America/Los_Angeles'),
        ]);
        // Card 3: due 2026-07-13 14:00 LA (today — same local date as card 2)
        $this->createCard($s3, [
            'fsrs_due_at' => Carbon::create(2026, 7, 13, 14, 0, 0, 'America/Los_Angeles'),
        ]);

        $response = $this->actingAs($this->user)->getJson('/reviews/senses');
        $response->assertOk();

        $cardIds = collect($response->json('cards'))->pluck('review_card_id')->all();
        $this->assertCount(3, $cardIds, 'All three cards must be in the queue');

        $card1Id = $this->cardIdFor($s1);
        $card2Id = $this->cardIdFor($s2);
        $card3Id = $this->cardIdFor($s3);

        $pos1 = array_search($card1Id, $cardIds);
        $pos2 = array_search($card2Id, $cardIds);
        $pos3 = array_search($card3Id, $cardIds);

        $this->assertNotFalse($pos1, 'Card 1 must be in the queue');
        $this->assertNotFalse($pos2, 'Card 2 must be in the queue');
        $this->assertNotFalse($pos3, 'Card 3 must be in the queue');

        // Card 1 (LA date 2026-07-12) must come before cards 2 and 3 (LA date 2026-07-13)
        $this->assertLessThan($pos2, $pos1, 'Card 1 (earlier LA date) must come before Card 2');
        $this->assertLessThan($pos3, $pos1, 'Card 1 (earlier LA date) must come before Card 3');
    }

    /**
     * DEV-QO-3: Cards crossing local midnight must be separated by
     * due_random (earlier local date first).
     */
    public function test_due_random_separates_cards_across_local_midnight(): void
    {
        $this->setTimezone('America/Los_Angeles');
        $this->saveQueueOrder(['review_sort_order' => 'due_random']);

        // Mock "now" to 2026-07-13 12:00 LA
        Carbon::setTestNow(Carbon::create(2026, 7, 13, 12, 0, 0, 'America/Los_Angeles'));

        $s1 = $this->createSense('alpha');
        $s2 = $this->createSense('bravo');

        // Card 1: due 2026-07-12 23:59 LA (yesterday in LA)
        $this->createCard($s1, [
            'fsrs_due_at' => Carbon::create(2026, 7, 12, 23, 59, 0, 'America/Los_Angeles'),
        ]);
        // Card 2: due 2026-07-13 00:01 LA (today in LA)
        $this->createCard($s2, [
            'fsrs_due_at' => Carbon::create(2026, 7, 13, 0, 1, 0, 'America/Los_Angeles'),
        ]);

        $response = $this->actingAs($this->user)->getJson('/reviews/senses');
        $response->assertOk();

        $cardIds = collect($response->json('cards'))->pluck('review_card_id')->all();
        $this->assertCount(2, $cardIds);

        $card1Id = $this->cardIdFor($s1);
        $card2Id = $this->cardIdFor($s2);

        $pos1 = array_search($card1Id, $cardIds);
        $pos2 = array_search($card2Id, $cardIds);

        // Card 1 (LA date 2026-07-12) must come before Card 2 (LA date 2026-07-13)
        $this->assertNotFalse($pos1);
        $this->assertNotFalse($pos2);
        $this->assertLessThan($pos2, $pos1, 'Card with earlier LA local date must come first');
    }

    /**
     * DEV-QO-3: In UTC timezone, due_random uses UTC dates.
     */
    public function test_due_random_utc_timezone_uses_utc_date(): void
    {
        $this->setTimezone('UTC');
        $this->saveQueueOrder(['review_sort_order' => 'due_random']);

        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $s1 = $this->createSense('alpha');
        $s2 = $this->createSense('bravo');

        // Card 1: due 2026-07-13 23:30 UTC (earlier UTC date)
        $this->createCard($s1, [
            'fsrs_due_at' => Carbon::create(2026, 7, 13, 23, 30, 0, 'UTC'),
        ]);
        // Card 2: due 2026-07-14 00:30 UTC (later UTC date)
        $this->createCard($s2, [
            'fsrs_due_at' => Carbon::create(2026, 7, 14, 0, 30, 0, 'UTC'),
        ]);

        $response = $this->actingAs($this->user)->getJson('/reviews/senses');
        $response->assertOk();

        $cardIds = collect($response->json('cards'))->pluck('review_card_id')->all();
        $this->assertCount(2, $cardIds);

        $card1Id = $this->cardIdFor($s1);
        $card2Id = $this->cardIdFor($s2);

        $pos1 = array_search($card1Id, $cardIds);
        $pos2 = array_search($card2Id, $cardIds);

        // In UTC, card 1 (07-13) must come before card 2 (07-14)
        $this->assertNotFalse($pos1);
        $this->assertNotFalse($pos2);
        $this->assertLessThan($pos2, $pos1, 'Card with earlier UTC date must come first');
    }

    // ── Tests: reviewedTodayCount timezone boundary (DEV-QO-8) ───────────

    /**
     * DEV-QO-8: reviewedTodayCount must use the learning timezone boundary.
     *
     * With app.timezone = America/Los_Angeles:
     *   - now = 2026-07-13 12:00 LA
     *   - dayStart = 2026-07-13 00:00 LA
     *   - ReviewLog at 2026-07-12 23:59 LA → NOT today
     *   - ReviewLog at 2026-07-13 00:01 LA → today
     */
    public function test_reviewed_today_count_uses_learning_timezone(): void
    {
        $this->setTimezone('America/Los_Angeles');

        Carbon::setTestNow(Carbon::create(2026, 7, 13, 12, 0, 0, 'America/Los_Angeles'));

        $s1 = $this->createSense('alpha');
        $s2 = $this->createSense('bravo');
        $card1 = $this->createCard($s1);
        $card2 = $this->createCard($s2);

        // ReviewLog at 2026-07-12 23:59 LA (yesterday)
        $this->createReviewLog($card1, Carbon::create(2026, 7, 12, 23, 59, 0, 'America/Los_Angeles'));
        // ReviewLog at 2026-07-13 00:01 LA (today)
        $this->createReviewLog($card2, Carbon::create(2026, 7, 13, 0, 1, 0, 'America/Los_Angeles'));

        /** @var SenseReviewService $service */
        $service = app(SenseReviewService::class);
        $count = $service->reviewedTodayCount($this->user->id, $this->language);

        // Only the second ReviewLog (today in LA) should be counted.
        $this->assertSame(1, $count, 'reviewedTodayCount must use learning timezone: the 07-12 23:59 LA log is yesterday');
    }

    /**
     * DEV-QO-8: In UTC timezone, the boundary is UTC midnight.
     */
    public function test_reviewed_today_count_utc_timezone(): void
    {
        $this->setTimezone('UTC');

        Carbon::setTestNow(Carbon::create(2026, 7, 13, 12, 0, 0, 'UTC'));

        $s1 = $this->createSense('alpha');
        $s2 = $this->createSense('bravo');
        $card1 = $this->createCard($s1);
        $card2 = $this->createCard($s2);

        // ReviewLog at 2026-07-12 23:59 UTC (yesterday in UTC)
        $this->createReviewLog($card1, Carbon::create(2026, 7, 12, 23, 59, 0, 'UTC'));
        // ReviewLog at 2026-07-13 00:01 UTC (today in UTC)
        $this->createReviewLog($card2, Carbon::create(2026, 7, 13, 0, 1, 0, 'UTC'));

        /** @var SenseReviewService $service */
        $service = app(SenseReviewService::class);
        $count = $service->reviewedTodayCount($this->user->id, $this->language);

        $this->assertSame(1, $count, 'In UTC, only the 00:01 UTC log is today');
    }

    // ── Tests: DST boundary ───────────

    /**
     * DEV-QO-3: DST boundary (US spring forward 2026-03-08 02:00 → 03:00)
     * must not cause misclassification or ordering errors.
     */
    public function test_dst_boundary_cards_handled_correctly(): void
    {
        $this->setTimezone('America/Los_Angeles');
        $this->saveQueueOrder(['review_sort_order' => 'due_random']);

        // Mock "now" to 2026-03-08 12:00 PDT (after DST transition)
        Carbon::setTestNow(Carbon::create(2026, 3, 8, 12, 0, 0, 'America/Los_Angeles'));

        $s1 = $this->createSense('alpha');
        $s2 = $this->createSense('bravo');

        // Card 1: due 2026-03-07 14:00 LA (before DST transition, earlier local date)
        $this->createCard($s1, [
            'fsrs_due_at' => Carbon::create(2026, 3, 7, 14, 0, 0, 'America/Los_Angeles'),
        ]);
        // Card 2: due 2026-03-08 03:00 LA (after DST transition, later local date)
        $this->createCard($s2, [
            'fsrs_due_at' => Carbon::create(2026, 3, 8, 3, 0, 0, 'America/Los_Angeles'),
        ]);

        $response = $this->actingAs($this->user)->getJson('/reviews/senses');
        $response->assertOk();

        $cardIds = collect($response->json('cards'))->pluck('review_card_id')->all();
        $this->assertCount(2, $cardIds, 'Both DST-boundary cards must be in the queue');

        $card1Id = $this->cardIdFor($s1);
        $card2Id = $this->cardIdFor($s2);

        $pos1 = array_search($card1Id, $cardIds);
        $pos2 = array_search($card2Id, $cardIds);

        // Card 1 (LA date 2026-03-07) must come before Card 2 (LA date 2026-03-08)
        $this->assertNotFalse($pos1);
        $this->assertNotFalse($pos2);
        $this->assertLessThan($pos2, $pos1, 'Card with earlier LA date (pre-DST) must come first');
    }

    // ── Tests: Both endpoints consistent ───────────

    /**
     * DEV-QO-3: /reviews and /reviews/senses must return the same card
     * order when cards span a timezone boundary.
     */
    public function test_both_endpoints_consistent_across_timezone_boundary(): void
    {
        $this->setTimezone('America/Los_Angeles');
        $this->saveQueueOrder(['review_sort_order' => 'due_stable']);

        Carbon::setTestNow(Carbon::create(2026, 7, 13, 20, 0, 0, 'America/Los_Angeles'));

        $s1 = $this->createSense('alpha');
        $s2 = $this->createSense('bravo');
        $s3 = $this->createSense('charlie');

        // Three cards with different due_at times (all on same LA date)
        $this->createCard($s1, [
            'fsrs_due_at' => Carbon::create(2026, 7, 13, 15, 0, 0, 'America/Los_Angeles'),
        ]);
        $this->createCard($s2, [
            'fsrs_due_at' => Carbon::create(2026, 7, 13, 16, 30, 0, 'America/Los_Angeles'),
        ]);
        $this->createCard($s3, [
            'fsrs_due_at' => Carbon::create(2026, 7, 13, 17, 30, 0, 'America/Los_Angeles'),
        ]);

        // Get order from /reviews/senses
        $senseResponse = $this->actingAs($this->user)->getJson('/reviews/senses');
        $senseResponse->assertOk();
        $senseCardIds = collect($senseResponse->json('cards'))->pluck('review_card_id')->all();

        // Get order from /reviews
        $legacyResponse = $this->actingAs($this->user)->postJson('/reviews', [
            'practiceMode' => false,
            'bookId' => -1,
            'chapterId' => -1,
        ]);
        $legacyResponse->assertOk();
        $legacyCardIds = collect($legacyResponse->json('reviews'))->pluck('review_card_id')->all();

        // Both endpoints must return the same order
        $this->assertSame(
            $senseCardIds,
            $legacyCardIds,
            'Both endpoints must return identical card order across timezone boundaries'
        );
    }
}
