<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewLeechFilterConsistencyTest
 *
 * ADR-0011 update: Verifies that the management page leech/struggling
 * filters use the SAME classification as SenseReviewLeechPolicy.
 *
 * 12 required scenarios:
 *  1.  累计 Again 达标 → leech
 *  2.  累计 Again 不达标，但最近 Again + Hard 达标 → leech
 *  3.  最近 5 次困难达标 → struggling
 *  4.  lapses 达标但 trend 不是 declining → 不得仅因此进入 struggling
 *  5.  lapses 达标且 trend=declining → struggling
 *  6.  reset 日志不影响
 *  7.  undone 日志不影响
 *  8.  非 sense_review 日志不影响
 *  9.  suspended 卡仍可在管理页 Leech 筛选中找到
 *  10. archived 卡仍可在管理页 Leech 筛选中找到
 *  11. stable 卡不得混入 leech / struggling
 *  12. 分页 total 与真实匹配数量一致
 */
class SenseReviewLeechFilterConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::forceCreate([
            'name' => 'Filter Consistency Test',
            'email' => 'filter-cons-' . Str::uuid() . '@example.com',
            'password' => \Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    /**
     * 1. 累计 Again 达标 → leech.
     * 3 again + 5 total → leech by cumulative rule.
     */
    public function test_01_cumulative_again_leech(): void
    {
        $card = $this->makeCard(['fsrs_lapses' => 3]);
        $this->makeLogs($card, [
            ['rating' => 'again', 'daysAgo' => 10],
            ['rating' => 'again', 'daysAgo' => 8],
            ['rating' => 'again', 'daysAgo' => 6],
            ['rating' => 'good', 'daysAgo' => 4],
            ['rating' => 'easy', 'daysAgo' => 2],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=leech&include_leech=1&per_page=100');

        $response->assertStatus(200);
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertContains($card->id, $cardIds, "Card with 3 again + 5 total must appear in leech filter");
    }

    /**
     * 2. 累计 Again 不达标，但最近 Again + Hard 达标 → leech.
     * 2 again total (not leech by cumulative), but last 5 has 4 again+hard → leech by recent rule.
     */
    public function test_02_recent_again_hard_leech(): void
    {
        $card = $this->makeCard();
        // 5 logs: good, again, hard, again, hard (oldest→newest)
        // again_count=2 (< 3, not cumulative leech)
        // recent_reviews (newest first, take 5) = [hard, again, hard, again, good]
        // last5 again+hard = 4 >= 4, count=5 >= 4 → leech by recent
        $this->makeLogs($card, [
            ['rating' => 'good', 'daysAgo' => 10],
            ['rating' => 'again', 'daysAgo' => 8],
            ['rating' => 'hard', 'daysAgo' => 6],
            ['rating' => 'again', 'daysAgo' => 4],
            ['rating' => 'hard', 'daysAgo' => 2],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=leech&include_leech=1&per_page=100');

        $response->assertStatus(200);
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertContains($card->id, $cardIds, "Card with 2 again but 4 again+hard in last 5 must appear in leech filter");
    }

    /**
     * 3. 最近 5 次困难达标 → struggling.
     * last 5: again+hard >= 3, but not leech.
     */
    public function test_03_recent_5_hard_struggling(): void
    {
        $card = $this->makeCard();
        // 6 logs: good, good, good, again, hard, again (oldest→newest)
        // again_count=2 (< 3, not cumulative leech)
        // recent (newest first, take 5) = [again, hard, again, good, good]
        // last5 again+hard = 3 >= 3 → struggling
        // last7 (=last5) again+hard = 3 < 4 → not leech by recent
        $this->makeLogs($card, [
            ['rating' => 'good', 'daysAgo' => 10],
            ['rating' => 'good', 'daysAgo' => 8],
            ['rating' => 'good', 'daysAgo' => 6],
            ['rating' => 'again', 'daysAgo' => 4],
            ['rating' => 'hard', 'daysAgo' => 2],
            ['rating' => 'again', 'daysAgo' => 1],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=struggling&include_leech=1&per_page=100');

        $response->assertStatus(200);
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertContains($card->id, $cardIds, "Card with 3 again+hard in last 5 must appear in struggling filter");
    }

    /**
     * 4. lapses 达标但 trend 不是 declining → 不得仅因此进入 struggling.
     * fsrs_lapses >= 2, trend = improving, last5 again+hard < 3 → NOT struggling.
     */
    public function test_04_lapses_non_declining_not_struggling(): void
    {
        $card = $this->makeCard(['fsrs_lapses' => 2]);
        // 6 logs oldest→newest: again, good, good, good, good, good
        // feedback logs (newest first): good(d1), good(d2), good(d4), good(d6), good(d8), again(d10)
        // recent (take 5) = [good, good, good, good, good] → again+hard=0 < 3
        // trendLogs (take 6, reverse) = [again(d10), good(d8), good(d6), good(d4), good(d2), good(d1)]
        // half=3, early=[again, good, good] again=1, late=[good, good, good] again=0 → improving
        $this->makeLogs($card, [
            ['rating' => 'again', 'daysAgo' => 10],
            ['rating' => 'good', 'daysAgo' => 8],
            ['rating' => 'good', 'daysAgo' => 6],
            ['rating' => 'good', 'daysAgo' => 4],
            ['rating' => 'good', 'daysAgo' => 2],
            ['rating' => 'good', 'daysAgo' => 1],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=struggling&include_leech=1&per_page=100');

        $response->assertStatus(200);
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertNotContains($card->id, $cardIds, "Card with lapses>=2 but improving trend must NOT appear in struggling filter");
    }

    /**
     * 5. lapses 达标且 trend=declining → struggling.
     * fsrs_lapses >= 2, trend = declining, not leech → struggling.
     */
    public function test_05_lapses_declining_struggling(): void
    {
        $card = $this->makeCard(['fsrs_lapses' => 2]);
        // 6 logs oldest→newest: good, good, good, again, good, again
        // again_count=2 (< 3, not cumulative leech)
        // feedback logs (newest first): again(d1), good(d2), again(d4), good(d6), good(d8), good(d10)
        // recent (take 5) = [again, good, again, good, good] → again+hard=2 < 3 (not struggling by recent5)
        // last7 (=last5) again+hard=2 < 4 → not leech by recent
        // trendLogs (take 6, reverse) = [good(d10), good(d8), good(d6), again(d4), good(d2), again(d1)]
        // half=3, early=[good, good, good] again=0, late=[again, good, again] again=2 → declining
        // fsrs_lapses=2, trend=declining → struggling by lapses+declining
        $this->makeLogs($card, [
            ['rating' => 'good', 'daysAgo' => 10],
            ['rating' => 'good', 'daysAgo' => 8],
            ['rating' => 'good', 'daysAgo' => 6],
            ['rating' => 'again', 'daysAgo' => 4],
            ['rating' => 'good', 'daysAgo' => 2],
            ['rating' => 'again', 'daysAgo' => 1],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=struggling&include_leech=1&per_page=100');

        $response->assertStatus(200);
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertContains($card->id, $cardIds, "Card with lapses>=2 and declining trend must appear in struggling filter");
    }

    /**
     * 6. reset 日志不影响.
     * Create leech-level sense_review logs + reset logs. Reset logs excluded → still leech.
     * Also create a card with ONLY reset logs → stable (not leech).
     */
    public function test_06_reset_logs_do_not_affect_classification(): void
    {
        // Card A: leech by sense_review logs + extra reset logs → still leech
        $leechCard = $this->makeCard(['fsrs_lapses' => 3]);
        $this->makeLogs($leechCard, [
            ['rating' => 'again', 'daysAgo' => 10],
            ['rating' => 'again', 'daysAgo' => 8],
            ['rating' => 'again', 'daysAgo' => 6],
            ['rating' => 'good', 'daysAgo' => 4],
            ['rating' => 'easy', 'daysAgo' => 2],
        ]);
        // Add reset logs (should be excluded)
        $this->makeLogsWithSource($leechCard, 'reset', [
            ['rating' => 'reset', 'daysAgo' => 3],
            ['rating' => 'reset', 'daysAgo' => 5],
        ]);

        // Card B: only reset logs → stable
        $resetOnlyCard = $this->makeCard();
        $this->makeLogsWithSource($resetOnlyCard, 'reset', [
            ['rating' => 'reset', 'daysAgo' => 10],
            ['rating' => 'reset', 'daysAgo' => 8],
            ['rating' => 'reset', 'daysAgo' => 6],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=leech&include_leech=1&per_page=100');

        $response->assertStatus(200);
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertContains($leechCard->id, $cardIds, "Leech card with extra reset logs must still appear in leech filter");
        $this->assertNotContains($resetOnlyCard->id, $cardIds, "Card with only reset logs must NOT appear in leech filter");
    }

    /**
     * 7. undone 日志不影响.
     * Create leech-level logs, mark some as undone. Undone excluded.
     * Card with all logs undone → stable.
     */
    public function test_07_undone_logs_do_not_affect_classification(): void
    {
        // Card A: leech by some logs, with some undone logs mixed in
        $leechCard = $this->makeCard(['fsrs_lapses' => 3]);
        $this->makeLogs($leechCard, [
            ['rating' => 'again', 'daysAgo' => 10],
            ['rating' => 'again', 'daysAgo' => 8],
            ['rating' => 'again', 'daysAgo' => 6],
            ['rating' => 'good', 'daysAgo' => 4],
            ['rating' => 'easy', 'daysAgo' => 2],
        ]);
        // Add an undone log (should be excluded)
        $this->makeLogs($leechCard, [
            ['rating' => 'again', 'daysAgo' => 3],
        ], 'sense_review', true); // undone=true

        // Card B: all logs undone → stable
        $allUndoneCard = $this->makeCard(['fsrs_lapses' => 3]);
        $this->makeLogs($allUndoneCard, [
            ['rating' => 'again', 'daysAgo' => 10],
            ['rating' => 'again', 'daysAgo' => 8],
            ['rating' => 'again', 'daysAgo' => 6],
            ['rating' => 'good', 'daysAgo' => 4],
            ['rating' => 'easy', 'daysAgo' => 2],
        ], 'sense_review', true); // all undone

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=leech&include_leech=1&per_page=100');

        $response->assertStatus(200);
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertContains($leechCard->id, $cardIds, "Leech card with an undone log must still appear in leech filter");
        $this->assertNotContains($allUndoneCard->id, $cardIds, "Card with all logs undone must NOT appear in leech filter");
    }

    /**
     * 8. 非 sense_review 日志不影响.
     * Create logs with source='review' (word review source). These should be excluded.
     * Card with only non-sense_review logs → stable.
     */
    public function test_08_non_sense_review_logs_do_not_affect_classification(): void
    {
        // Card A: leech by sense_review logs + extra 'review' source logs → still leech
        $leechCard = $this->makeCard(['fsrs_lapses' => 3]);
        $this->makeLogs($leechCard, [
            ['rating' => 'again', 'daysAgo' => 10],
            ['rating' => 'again', 'daysAgo' => 8],
            ['rating' => 'again', 'daysAgo' => 6],
            ['rating' => 'good', 'daysAgo' => 4],
            ['rating' => 'easy', 'daysAgo' => 2],
        ]);
        // Add non-sense_review logs (should be excluded)
        $this->makeLogsWithSource($leechCard, 'review', [
            ['rating' => 'again', 'daysAgo' => 3],
            ['rating' => 'again', 'daysAgo' => 5],
        ]);

        // Card B: only non-sense_review logs → stable
        $reviewOnlyCard = $this->makeCard();
        $this->makeLogsWithSource($reviewOnlyCard, 'review', [
            ['rating' => 'again', 'daysAgo' => 10],
            ['rating' => 'again', 'daysAgo' => 8],
            ['rating' => 'again', 'daysAgo' => 6],
            ['rating' => 'good', 'daysAgo' => 4],
            ['rating' => 'easy', 'daysAgo' => 2],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=leech&include_leech=1&per_page=100');

        $response->assertStatus(200);
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertContains($leechCard->id, $cardIds, "Leech card with extra non-sense_review logs must still appear in leech filter");
        $this->assertNotContains($reviewOnlyCard->id, $cardIds, "Card with only non-sense_review logs must NOT appear in leech filter");
    }

    /**
     * 9. suspended 卡仍可在管理页 Leech 筛选中找到.
     */
    public function test_09_suspended_card_in_leech_filter(): void
    {
        $card = $this->makeCard(['fsrs_lapses' => 3, 'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED]);
        $this->makeLogs($card, [
            ['rating' => 'again', 'daysAgo' => 10],
            ['rating' => 'again', 'daysAgo' => 8],
            ['rating' => 'again', 'daysAgo' => 6],
            ['rating' => 'good', 'daysAgo' => 4],
            ['rating' => 'easy', 'daysAgo' => 2],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=leech&include_leech=1&per_page=100');

        $response->assertStatus(200);
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertContains($card->id, $cardIds, "Suspended leech card must be findable in leech filter");
    }

    /**
     * 10. archived 卡仍可在管理页 Leech 筛选中找到.
     */
    public function test_10_archived_card_in_leech_filter(): void
    {
        $card = $this->makeCard(['fsrs_lapses' => 3, 'lifecycle_state' => ReviewCard::LIFECYCLE_ARCHIVED]);
        $this->makeLogs($card, [
            ['rating' => 'again', 'daysAgo' => 10],
            ['rating' => 'again', 'daysAgo' => 8],
            ['rating' => 'again', 'daysAgo' => 6],
            ['rating' => 'good', 'daysAgo' => 4],
            ['rating' => 'easy', 'daysAgo' => 2],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=leech&include_leech=1&per_page=100');

        $response->assertStatus(200);
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertContains($card->id, $cardIds, "Archived leech card must be findable in leech filter");
    }

    /**
     * 11. stable 卡不得混入 leech / struggling.
     */
    public function test_11_stable_card_not_in_leech_or_struggling(): void
    {
        $card = $this->makeCard();
        // Only good/easy logs → stable
        $this->makeLogs($card, [
            ['rating' => 'good', 'daysAgo' => 10],
            ['rating' => 'easy', 'daysAgo' => 8],
            ['rating' => 'good', 'daysAgo' => 6],
        ]);

        $leechResponse = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=leech&include_leech=1&per_page=100');
        $leekCardIds = array_column($leechResponse->json('items'), 'review_card_id');
        $this->assertNotContains($card->id, $leekCardIds, "Stable card must NOT appear in leech filter");

        $strugglingResponse = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=struggling&include_leech=1&per_page=100');
        $strugglingCardIds = array_column($strugglingResponse->json('items'), 'review_card_id');
        $this->assertNotContains($card->id, $strugglingCardIds, "Stable card must NOT appear in struggling filter");
    }

    /**
     * 12. 分页 total 与真实匹配数量一致.
     * Create 3 leech cards + 5 stable cards. leech filter total must = 3.
     */
    public function test_12_pagination_total_matches_real_count(): void
    {
        // 3 leech cards
        $leechCardIds = [];
        for ($i = 0; $i < 3; $i++) {
            $card = $this->makeCard(['fsrs_lapses' => 3]);
            $this->makeLogs($card, [
                ['rating' => 'again', 'daysAgo' => 10],
                ['rating' => 'again', 'daysAgo' => 8],
                ['rating' => 'again', 'daysAgo' => 6],
                ['rating' => 'good', 'daysAgo' => 4],
                ['rating' => 'easy', 'daysAgo' => 2],
            ]);
            $leechCardIds[] = $card->id;
        }
        // 5 stable cards
        for ($i = 0; $i < 5; $i++) {
            $card = $this->makeCard();
            $this->makeLogs($card, [
                ['rating' => 'good', 'daysAgo' => 5],
                ['rating' => 'easy', 'daysAgo' => 3],
            ]);
        }

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=leech&include_leech=1&per_page=100');

        $response->assertStatus(200);
        $total = $response->json('pagination.total');
        $items = $response->json('items');
        $this->assertCount(3, $items, "leech filter must return exactly 3 items");
        $this->assertSame(3, $total, "pagination total must match real leech count (3)");
        $returnedIds = array_column($items, 'review_card_id');
        sort($leechCardIds);
        sort($returnedIds);
        $this->assertSame($leechCardIds, $returnedIds, "leech filter must return exactly the 3 leech card IDs");
    }

    // ─── Helpers ───

    private function makeCard(array $overrides = []): ReviewCard
    {
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'test' . Str::random(6),
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
            'sense_key' => hash('sha256', strtolower('english|test|noun|测试|test') . Str::random(6)),
        ]);

        return ReviewCard::forceCreate(array_merge([
            'user_id' => $sense->user_id,
            'language_id' => $sense->language_id,
            'language' => $sense->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
            'fsrs_stability' => null,
            'fsrs_difficulty' => null,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'lifecycle_state' => 'active',
        ], $overrides));
    }

    private function makeLogs(ReviewCard $card, array $ratings, string $source = 'sense_review', bool $undone = false): void
    {
        foreach ($ratings as $r) {
            ReviewLog::create([
                'user_id' => $card->user_id,
                'language_id' => $card->language_id,
                'language' => $card->language,
                'review_card_id' => $card->id,
                'rating' => $r['rating'],
                'reviewed_at' => now()->subDays($r['daysAgo']),
                'previous_state' => 'review',
                'new_state' => 'review',
                'previous_due_at' => now()->subDays($r['daysAgo'] + 1),
                'new_due_at' => now()->subDays($r['daysAgo'] - 1),
                'previous_stability' => 1.0,
                'new_stability' => 1.5,
                'previous_difficulty' => 5.0,
                'new_difficulty' => 5.0,
                'source' => $r['rating'] === 'reset' ? 'reset' : $source,
                'undone_at' => $undone ? now() : null,
            ]);
        }
    }

    private function makeLogsWithSource(ReviewCard $card, string $source, array $ratings): void
    {
        foreach ($ratings as $r) {
            ReviewLog::create([
                'user_id' => $card->user_id,
                'language_id' => $card->language_id,
                'language' => $card->language,
                'review_card_id' => $card->id,
                'rating' => $r['rating'],
                'reviewed_at' => now()->subDays($r['daysAgo']),
                'previous_state' => 'review',
                'new_state' => 'review',
                'previous_due_at' => now()->subDays($r['daysAgo'] + 1),
                'new_due_at' => now()->subDays($r['daysAgo'] - 1),
                'previous_stability' => 1.0,
                'new_stability' => 1.5,
                'previous_difficulty' => 5.0,
                'new_difficulty' => 5.0,
                'source' => $source,
            ]);
        }
    }
}
