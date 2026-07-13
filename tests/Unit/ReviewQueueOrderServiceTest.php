<?php

namespace Tests\Unit;

use App\Models\ReviewCard;
use App\Services\ReviewQueueOrderOptions;
use App\Services\ReviewQueueOrderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for ReviewQueueOrderService.
 *
 * Tests classification, retrievability, daily hash, and sort key computation.
 * Uses RefreshDatabase because ReviewCard is an Eloquent model with casts.
 */
class ReviewQueueOrderServiceTest extends TestCase
{
    use RefreshDatabase;
    private ReviewQueueOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReviewQueueOrderService();
    }

    private function makeCard(array $attrs): ReviewCard
    {
        $card = new ReviewCard();
        $card->id = $attrs['id'] ?? 1;
        $card->fsrs_state = $attrs['fsrs_state'] ?? 'review';
        $card->fsrs_due_at = $attrs['fsrs_due_at'] ?? Carbon::now();
        // Use array_key_exists to allow explicit null values
        $card->fsrs_stability = array_key_exists('fsrs_stability', $attrs) ? $attrs['fsrs_stability'] : 1.0;
        $card->fsrs_difficulty = array_key_exists('fsrs_difficulty', $attrs) ? $attrs['fsrs_difficulty'] : 5.0;
        $card->fsrs_last_reviewed_at = array_key_exists('fsrs_last_reviewed_at', $attrs) ? $attrs['fsrs_last_reviewed_at'] : null;
        $card->fsrs_enabled = $attrs['fsrs_enabled'] ?? true;
        return $card;
    }

    // === Classification tests ===

    public function test_classify_new_card(): void
    {
        $card = $this->makeCard(['fsrs_state' => 'new']);
        $now = Carbon::now('UTC');
        $this->assertSame('new', $this->service->classify($card, 'UTC', $now));
    }

    public function test_classify_review_card(): void
    {
        $card = $this->makeCard(['fsrs_state' => 'review']);
        $now = Carbon::now('UTC');
        $this->assertSame('review', $this->service->classify($card, 'UTC', $now));
    }

    public function test_classify_intraday_learning(): void
    {
        $now = Carbon::parse('2026-07-13 10:00:00', 'UTC');
        $card = $this->makeCard([
            'fsrs_state' => 'learning',
            'fsrs_last_reviewed_at' => Carbon::parse('2026-07-13 08:00:00', 'UTC'),
            'fsrs_due_at' => Carbon::parse('2026-07-13 12:00:00', 'UTC'),
        ]);
        $this->assertSame('intraday', $this->service->classify($card, 'UTC', $now));
    }

    public function test_classify_intraday_relearning(): void
    {
        $now = Carbon::parse('2026-07-13 10:00:00', 'UTC');
        $card = $this->makeCard([
            'fsrs_state' => 'relearning',
            'fsrs_last_reviewed_at' => Carbon::parse('2026-07-13 08:00:00', 'UTC'),
            'fsrs_due_at' => Carbon::parse('2026-07-13 12:00:00', 'UTC'),
        ]);
        $this->assertSame('intraday', $this->service->classify($card, 'UTC', $now));
    }

    public function test_classify_interday_learning(): void
    {
        $now = Carbon::parse('2026-07-13 10:00:00', 'UTC');
        $card = $this->makeCard([
            'fsrs_state' => 'learning',
            'fsrs_last_reviewed_at' => Carbon::parse('2026-07-12 08:00:00', 'UTC'),
            'fsrs_due_at' => Carbon::parse('2026-07-13 12:00:00', 'UTC'),
        ]);
        $this->assertSame('interday', $this->service->classify($card, 'UTC', $now));
    }

    public function test_classify_interday_relearning(): void
    {
        $now = Carbon::parse('2026-07-13 10:00:00', 'UTC');
        $card = $this->makeCard([
            'fsrs_state' => 'relearning',
            'fsrs_last_reviewed_at' => Carbon::parse('2026-07-12 08:00:00', 'UTC'),
            'fsrs_due_at' => Carbon::parse('2026-07-13 12:00:00', 'UTC'),
        ]);
        $this->assertSame('interday', $this->service->classify($card, 'UTC', $now));
    }

    public function test_classify_null_last_reviewed_at_treated_as_interday(): void
    {
        $now = Carbon::now('UTC');
        $card = $this->makeCard([
            'fsrs_state' => 'learning',
            'fsrs_last_reviewed_at' => null,
        ]);
        $this->assertSame('interday', $this->service->classify($card, 'UTC', $now));
    }

    public function test_classify_cross_midnight_in_la_timezone(): void
    {
        // 2026-07-13 23:30 UTC = 2026-07-13 16:30 in LA (PDT, UTC-7)
        // 2026-07-14 01:00 UTC = 2026-07-13 18:00 in LA (still same day)
        $now = Carbon::parse('2026-07-14 01:00:00', 'UTC');
        $card = $this->makeCard([
            'fsrs_state' => 'learning',
            'fsrs_last_reviewed_at' => Carbon::parse('2026-07-13 23:30:00', 'UTC'),
            'fsrs_due_at' => Carbon::parse('2026-07-14 02:00:00', 'UTC'),
        ]);
        // In LA timezone, both are 2026-07-13 → intraday
        $this->assertSame('intraday', $this->service->classify($card, 'America/Los_Angeles', $now));
    }

    public function test_classify_dst_boundary(): void
    {
        // DST transition in US: 2026-03-08 02:00 → 03:00 (spring forward)
        // Before: 2026-03-07 23:00 UTC = 2026-03-07 15:00 PST (UTC-8)
        // After: 2026-03-08 10:00 UTC = 2026-03-08 03:00 PDT (UTC-7)
        $now = Carbon::parse('2026-03-08 10:00:00', 'UTC');
        $card = $this->makeCard([
            'fsrs_state' => 'learning',
            'fsrs_last_reviewed_at' => Carbon::parse('2026-03-07 23:00:00', 'UTC'),
            'fsrs_due_at' => Carbon::parse('2026-03-08 10:00:00', 'UTC'),
        ]);
        // Different dates in LA → interday
        $this->assertSame('interday', $this->service->classify($card, 'America/Los_Angeles', $now));
    }

    // === Retrievability tests ===

    public function test_retrievability_with_valid_stability(): void
    {
        $now = Carbon::parse('2026-07-13 10:00:00', 'UTC');
        $card = $this->makeCard([
            'fsrs_stability' => 10.0,
            'fsrs_last_reviewed_at' => Carbon::parse('2026-07-12 10:00:00', 'UTC'), // 1 day ago
        ]);

        $r = $this->service->computeRetrievability($card, $now);

        // R = (1 + (19/81) * 1/10) ^ (-0.5) ≈ 0.988
        $this->assertGreaterThan(0.0, $r);
        $this->assertLessThan(1.0, $r);
        $this->assertGreaterThan(0.95, $r);
        $this->assertLessThan(1.0, $r);
    }

    public function test_retrievability_lower_stability_means_lower_r(): void
    {
        $now = Carbon::parse('2026-07-13 10:00:00', 'UTC');

        $highStability = $this->makeCard([
            'fsrs_stability' => 100.0,
            'fsrs_last_reviewed_at' => Carbon::parse('2026-07-12 10:00:00', 'UTC'),
        ]);

        $lowStability = $this->makeCard([
            'fsrs_stability' => 1.0,
            'fsrs_last_reviewed_at' => Carbon::parse('2026-07-12 10:00:00', 'UTC'),
        ]);

        $rHigh = $this->service->computeRetrievability($highStability, $now);
        $rLow = $this->service->computeRetrievability($lowStability, $now);

        $this->assertGreaterThan($rLow, $rHigh, 'Higher stability should mean higher retrievability');
    }

    public function test_retrievability_null_stability_returns_zero(): void
    {
        $now = Carbon::now('UTC');
        $card = $this->makeCard(['fsrs_stability' => null]);

        $this->assertSame(0.0, $this->service->computeRetrievability($card, $now));
    }

    public function test_retrievability_zero_stability_returns_zero(): void
    {
        $now = Carbon::now('UTC');
        $card = $this->makeCard(['fsrs_stability' => 0.0]);

        $this->assertSame(0.0, $this->service->computeRetrievability($card, $now));
    }

    public function test_retrievability_null_last_reviewed_at_returns_one(): void
    {
        $now = Carbon::now('UTC');
        $card = $this->makeCard([
            'fsrs_stability' => 10.0,
            'fsrs_last_reviewed_at' => null,
        ]);

        // elapsed = 0, R = (1 + 0) ^ (-0.5) = 1.0
        $this->assertSame(1.0, $this->service->computeRetrievability($card, $now));
    }

    // === Daily hash tests ===

    public function test_daily_hash_is_stable(): void
    {
        $h1 = $this->service->dailyHash(1, 'english', '2026-07-13', 100);
        $h2 = $this->service->dailyHash(1, 'english', '2026-07-13', 100);
        $this->assertSame($h1, $h2);
    }

    public function test_daily_hash_different_date_may_differ(): void
    {
        $h1 = $this->service->dailyHash(1, 'english', '2026-07-13', 100);
        $h2 = $this->service->dailyHash(1, 'english', '2026-07-14', 100);
        // Not guaranteed to differ, but very likely
        // We test that the function accepts different dates without error
        $this->assertIsFloat($h1);
        $this->assertIsFloat($h2);
    }

    public function test_daily_hash_different_user_differs(): void
    {
        $h1 = $this->service->dailyHash(1, 'english', '2026-07-13', 100);
        $h2 = $this->service->dailyHash(2, 'english', '2026-07-13', 100);
        $this->assertNotSame($h1, $h2);
    }

    public function test_daily_hash_different_card_differs(): void
    {
        $h1 = $this->service->dailyHash(1, 'english', '2026-07-13', 100);
        $h2 = $this->service->dailyHash(1, 'english', '2026-07-13', 101);
        $this->assertNotSame($h1, $h2);
    }

    public function test_daily_hash_in_range(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $h = $this->service->dailyHash(1, 'english', '2026-07-13', $i);
            $this->assertGreaterThanOrEqual(0.0, $h);
            $this->assertLessThan(1.0, $h);
        }
    }

    // === Sort key tests ===

    public function test_review_sort_due_stable_uses_due_at(): void
    {
        $now = Carbon::now('UTC');
        $opts = ReviewQueueOrderOptions::fromArray(['review_sort_order' => 'due_stable']);

        $earlier = $this->makeCard(['id' => 1, 'fsrs_due_at' => Carbon::parse('2026-07-13 08:00:00', 'UTC')]);
        $later = $this->makeCard(['id' => 2, 'fsrs_due_at' => Carbon::parse('2026-07-13 12:00:00', 'UTC')]);

        $keyEarlier = $this->service->computeSortKey($earlier, 'review', $opts, 1, 'english', '2026-07-13', $now);
        $keyLater = $this->service->computeSortKey($later, 'review', $opts, 1, 'english', '2026-07-13', $now);

        $this->assertLessThan($keyLater, $keyEarlier);
    }

    public function test_review_sort_ascending_retrievability_lower_first(): void
    {
        $now = Carbon::parse('2026-07-13 10:00:00', 'UTC');
        $opts = ReviewQueueOrderOptions::fromArray(['review_sort_order' => 'ascending_retrievability']);

        $forgotten = $this->makeCard([
            'id' => 1,
            'fsrs_stability' => 1.0,
            'fsrs_last_reviewed_at' => Carbon::parse('2026-07-10 10:00:00', 'UTC'), // 3 days ago
        ]);

        $remembered = $this->makeCard([
            'id' => 2,
            'fsrs_stability' => 100.0,
            'fsrs_last_reviewed_at' => Carbon::parse('2026-07-10 10:00:00', 'UTC'),
        ]);

        $keyForgotten = $this->service->computeSortKey($forgotten, 'review', $opts, 1, 'english', '2026-07-13', $now);
        $keyRemembered = $this->service->computeSortKey($remembered, 'review', $opts, 1, 'english', '2026-07-13', $now);

        $this->assertLessThan($keyRemembered, $keyForgotten, 'Lower retrievability should sort first');
    }

    public function test_review_sort_random_is_stable_same_day(): void
    {
        $now = Carbon::now('UTC');
        $opts = ReviewQueueOrderOptions::fromArray(['review_sort_order' => 'random']);

        $card = $this->makeCard(['id' => 42]);

        $key1 = $this->service->computeSortKey($card, 'review', $opts, 1, 'english', '2026-07-13', $now);
        $key2 = $this->service->computeSortKey($card, 'review', $opts, 1, 'english', '2026-07-13', $now);

        $this->assertSame($key1, $key2);
    }

    public function test_review_sort_due_random_same_date_same_order(): void
    {
        $now = Carbon::now('UTC');
        $opts = ReviewQueueOrderOptions::fromArray(['review_sort_order' => 'due_random']);

        $card1 = $this->makeCard(['id' => 1, 'fsrs_due_at' => Carbon::parse('2026-07-13 10:00:00', 'UTC')]);
        $card2 = $this->makeCard(['id' => 2, 'fsrs_due_at' => Carbon::parse('2026-07-13 11:00:00', 'UTC')]);

        // Same date → primary key is date, secondary is hash
        $key1 = $this->service->computeSortKey($card1, 'review', $opts, 1, 'english', '2026-07-13', $now);
        $key2 = $this->service->computeSortKey($card2, 'review', $opts, 1, 'english', '2026-07-13', $now);

        // Both have same date part, different hash part → stable ordering
        $this->assertNotSame($key1, $key2);
    }

    public function test_new_sort_created_asc(): void
    {
        $opts = ReviewQueueOrderOptions::fromArray(['new_sort_order' => 'created_asc']);
        $now = Carbon::now('UTC');

        $lower = $this->makeCard(['id' => 10, 'fsrs_state' => 'new']);
        $higher = $this->makeCard(['id' => 20, 'fsrs_state' => 'new']);

        $keyLower = $this->service->computeSortKey($lower, 'new', $opts, 1, 'english', '2026-07-13', $now);
        $keyHigher = $this->service->computeSortKey($higher, 'new', $opts, 1, 'english', '2026-07-13', $now);

        $this->assertLessThan($keyHigher, $keyLower);
    }

    public function test_new_sort_created_desc(): void
    {
        $opts = ReviewQueueOrderOptions::fromArray(['new_sort_order' => 'created_desc']);
        $now = Carbon::now('UTC');

        $lower = $this->makeCard(['id' => 10, 'fsrs_state' => 'new']);
        $higher = $this->makeCard(['id' => 20, 'fsrs_state' => 'new']);

        $keyLower = $this->service->computeSortKey($lower, 'new', $opts, 1, 'english', '2026-07-13', $now);
        $keyHigher = $this->service->computeSortKey($higher, 'new', $opts, 1, 'english', '2026-07-13', $now);

        // Desc: higher id first → lower sort_key
        $this->assertLessThan($keyLower, $keyHigher);
    }

    public function test_new_sort_random_is_stable(): void
    {
        $opts = ReviewQueueOrderOptions::fromArray(['new_sort_order' => 'random']);
        $now = Carbon::now('UTC');

        $card = $this->makeCard(['id' => 42, 'fsrs_state' => 'new']);

        $key1 = $this->service->computeSortKey($card, 'new', $opts, 1, 'english', '2026-07-13', $now);
        $key2 = $this->service->computeSortKey($card, 'new', $opts, 1, 'english', '2026-07-13', $now);

        $this->assertSame($key1, $key2);
    }

    // === DEV-QO-3: due_random must use local date, not UTC date ===

    public function test_due_random_groups_by_local_date_not_utc_date(): void
    {
        // Two cards: 2026-07-13 23:00 UTC and 2026-07-14 01:00 UTC.
        // In America/Los_Angeles (UTC-7 in July), both are 2026-07-13 local.
        // They must be treated as the SAME due date group.
        $opts = ReviewQueueOrderOptions::fromArray(['review_sort_order' => 'due_random']);
        $nowInLa = Carbon::create(2026, 7, 13, 20, 0, 0, 'America/Los_Angeles');

        $card1 = $this->makeCard(['id' => 1, 'fsrs_due_at' => Carbon::parse('2026-07-13 23:00:00', 'UTC')]);
        $card2 = $this->makeCard(['id' => 2, 'fsrs_due_at' => Carbon::parse('2026-07-14 01:00:00', 'UTC')]);

        $localDate = '2026-07-13';
        $key1 = $this->service->computeSortKey($card1, 'review', $opts, 1, 'english', $localDate, $nowInLa);
        $key2 = $this->service->computeSortKey($card2, 'review', $opts, 1, 'english', $localDate, $nowInLa);

        // Extract the integer (date) part — both must have the same date score
        $date1 = (int) $key1;
        $date2 = (int) $key2;
        $this->assertSame($date1, $date2, 'Cards in same local date must share date score');
    }

    public function test_due_random_separates_cards_across_local_midnight(): void
    {
        // 2026-07-13 06:59 UTC = 2026-07-12 23:59 PDT (previous local day)
        // 2026-07-13 07:01 UTC = 2026-07-13 00:01 PDT (next local day)
        $opts = ReviewQueueOrderOptions::fromArray(['review_sort_order' => 'due_random']);
        $nowInLa = Carbon::create(2026, 7, 13, 12, 0, 0, 'America/Los_Angeles');

        $card1 = $this->makeCard(['id' => 1, 'fsrs_due_at' => Carbon::parse('2026-07-13 06:59:00', 'UTC')]);
        $card2 = $this->makeCard(['id' => 2, 'fsrs_due_at' => Carbon::parse('2026-07-13 07:01:00', 'UTC')]);

        $localDate = '2026-07-13';
        $key1 = $this->service->computeSortKey($card1, 'review', $opts, 1, 'english', $localDate, $nowInLa);
        $key2 = $this->service->computeSortKey($card2, 'review', $opts, 1, 'english', $localDate, $nowInLa);

        // card1 local date = 2026-07-12, card2 local date = 2026-07-13
        // card1 date score < card2 date score (earlier date first)
        $this->assertLessThan($key2, $key1, 'Card before local midnight must sort before card after midnight');
    }

    public function test_due_random_utc_timezone_uses_utc_date(): void
    {
        $opts = ReviewQueueOrderOptions::fromArray(['review_sort_order' => 'due_random']);
        $nowUtc = Carbon::create(2026, 7, 13, 12, 0, 0, 'UTC');

        $card1 = $this->makeCard(['id' => 1, 'fsrs_due_at' => Carbon::parse('2026-07-13 23:00:00', 'UTC')]);
        $card2 = $this->makeCard(['id' => 2, 'fsrs_due_at' => Carbon::parse('2026-07-14 01:00:00', 'UTC')]);

        $localDate = '2026-07-13';
        $key1 = $this->service->computeSortKey($card1, 'review', $opts, 1, 'english', $localDate, $nowUtc);
        $key2 = $this->service->computeSortKey($card2, 'review', $opts, 1, 'english', $localDate, $nowUtc);

        // In UTC, these are different dates → card1 < card2
        $this->assertLessThan($key2, $key1);
    }

    // === DEV-QO-4: retrievability fallback consistency ===

    public function test_retrievability_fallback_for_null_stability_returns_zero(): void
    {
        // The code returns 0.0 for null/invalid stability. The comment must
        // match this — no "negative timestamp" claim.
        $card = $this->makeCard([
            'fsrs_stability' => null,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(5),
        ]);
        $now = Carbon::now('UTC');
        $r = $this->service->computeRetrievability($card, $now);
        $this->assertSame(0.0, $r);
    }

    public function test_retrievability_fallback_for_zero_stability_returns_zero(): void
    {
        $card = $this->makeCard([
            'fsrs_stability' => 0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(5),
        ]);
        $now = Carbon::now('UTC');
        $r = $this->service->computeRetrievability($card, $now);
        $this->assertSame(0.0, $r);
    }

    public function test_retrievability_fallback_for_negative_stability_returns_zero(): void
    {
        $card = $this->makeCard([
            'fsrs_stability' => -1.0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(5),
        ]);
        $now = Carbon::now('UTC');
        $r = $this->service->computeRetrievability($card, $now);
        $this->assertSame(0.0, $r);
    }

    public function test_retrievability_no_last_reviewed_returns_one(): void
    {
        // elapsed = 0 → R = (1 + 0)^DECAY = 1.0
        $card = $this->makeCard([
            'fsrs_stability' => 10.0,
            'fsrs_last_reviewed_at' => null,
        ]);
        $now = Carbon::now('UTC');
        $r = $this->service->computeRetrievability($card, $now);
        $this->assertEqualsWithDelta(1.0, $r, 0.0001);
    }

    public function test_retrievability_known_value_cross_check(): void
    {
        // FSRS-5 formula: R = (1 + (19/81) * elapsed / stability)^(-0.5)
        // stability = 10, elapsed = 10 days → R = (1 + (19/81) * 1)^(-0.5)
        //   = (1 + 0.234568...)^(-0.5) = (1.234568...)^(-0.5)
        //   = 1 / sqrt(1.234568...) = 1 / 1.11111 = 0.9
        $card = $this->makeCard([
            'fsrs_stability' => 10.0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(10),
        ]);
        $now = Carbon::now('UTC');
        $r = $this->service->computeRetrievability($card, $now);
        $this->assertEqualsWithDelta(0.9, $r, 0.01, 'R for stability=10, elapsed=10d must be ~0.9');
    }

    public function test_retrievability_smaller_stability_lower_r(): void
    {
        $now = Carbon::now('UTC');
        $card1 = $this->makeCard([
            'fsrs_stability' => 5.0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(10),
        ]);
        $card2 = $this->makeCard([
            'fsrs_stability' => 20.0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(10),
        ]);
        $r1 = $this->service->computeRetrievability($card1, $now);
        $r2 = $this->service->computeRetrievability($card2, $now);
        $this->assertLessThan($r2, $r1, 'Lower stability → lower R → higher priority');
    }

    public function test_retrievability_never_returns_nan_or_infinity(): void
    {
        $now = Carbon::now('UTC');
        $stabilities = [null, 0, -1.0, 0.001, 1.0, 100.0, 99999.0];
        foreach ($stabilities as $s) {
            $card = $this->makeCard([
                'fsrs_stability' => $s,
                'fsrs_last_reviewed_at' => Carbon::now()->subDays(5),
            ]);
            $r = $this->service->computeRetrievability($card, $now);
            $this->assertTrue(is_finite($r), "R for stability={$s} must be finite, got {$r}");
            $this->assertGreaterThanOrEqual(0.0, $r, "R for stability={$s} must be >= 0");
            $this->assertLessThanOrEqual(1.0, $r, "R for stability={$s} must be <= 1");
        }
    }
}
