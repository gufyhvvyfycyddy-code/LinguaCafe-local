<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\SenseReviewLeechQueryService;
use App\Services\SenseReviewLearningFeedbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewLeechQueryBudgetTest
 *
 * ADR-0011 update: Verifies that the leech query paths do NOT
 * produce N+1 ReviewLog queries.
 *
 * Required assertions (per task spec):
 *  - 1 card and 5 cards have the SAME ReviewLog query count for bulk rewrite.
 *  - Bulk rewrite does not increase ReviewLog queries with card count.
 *  - Single card rewrite does NOT duplicate ReviewLog queries.
 *  - Management page batch leech description does NOT per-card query ReviewLog.
 */
class SenseReviewLeechQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::forceCreate([
            'name' => 'Query Budget Test',
            'email' => 'qbudget-' . Str::uuid() . '@example.com',
            'password' => \Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    /**
     * 1. Bulk rewrite: 1 card vs 5 cards → same ReviewLog query count (1).
     */
    public function test_bulk_rewrite_1_vs_5_cards_same_review_log_query_count(): void
    {
        $card1 = $this->makeLeechCard();
        $cardIds1 = [$card1->id];

        $fiveCards = [$card1];
        for ($i = 0; $i < 4; $i++) {
            $fiveCards[] = $this->makeLeechCard();
        }
        $cardIds5 = array_map(fn($c) => $c->id, $fiveCards);

        // 1 card
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response1 = $this->actingAs($this->user)
            ->postJson('/review-cards/manage/bulk-leech-rewrite-packages', ['ids' => $cardIds1]);
        $queries1 = $this->countReviewLogQueries(DB::getQueryLog());
        DB::disableQueryLog();

        $response1->assertStatus(200);

        // 5 cards
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response5 = $this->actingAs($this->user)
            ->postJson('/review-cards/manage/bulk-leech-rewrite-packages', ['ids' => $cardIds5]);
        $queries5 = $this->countReviewLogQueries(DB::getQueryLog());
        DB::disableQueryLog();

        $response5->assertStatus(200);

        // Both must have exactly 1 ReviewLog query (the batch buildForCards call).
        $this->assertSame(1, $queries1, "1 card: expected 1 review_logs query, got $queries1");
        $this->assertSame(1, $queries5, "5 cards: expected 1 review_logs query, got $queries5");
        $this->assertSame($queries1, $queries5, "Query count must be constant regardless of card count");
    }

    /**
     * 2. Single card rewrite package: only 1 ReviewLog query (not 2).
     *
     * Before fix: rewritePackage() called buildForCard() (1 query) then
     * describeForCard() which called buildForCard() again (2nd query).
     * After fix: describeForCardWithFeedback() reuses the pre-built feedback.
     */
    public function test_single_card_rewrite_package_no_duplicate_review_log_query(): void
    {
        $card = $this->makeLeechCard();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($this->user)
            ->postJson("/reviews/senses/{$card->id}/leech/rewrite-package");
        $queries = $this->countReviewLogQueries(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertStatus(200);
        $this->assertSame(1, $queries, "Single card rewrite: expected 1 review_logs query, got $queries (was 2 before fix)");
    }

    /**
     * 3. describeForCards batch: 1 vs 5 cards → same ReviewLog query count (1).
     */
    public function test_describe_for_cards_batch_constant_review_log_query_count(): void
    {
        $service = app(SenseReviewLeechQueryService::class);

        $card1 = $this->makeLeechCard();
        $fiveCards = [$card1];
        for ($i = 0; $i < 4; $i++) {
            $fiveCards[] = $this->makeLeechCard();
        }

        // 1 card
        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->describeForCards([$card1->id]);
        $queries1 = $this->countReviewLogQueries(DB::getQueryLog());
        DB::disableQueryLog();

        // 5 cards
        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->describeForCards(array_map(fn($c) => $c->id, $fiveCards));
        $queries5 = $this->countReviewLogQueries(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries1, "1 card: expected 1 review_logs query, got $queries1");
        $this->assertSame(1, $queries5, "5 cards: expected 1 review_logs query, got $queries5");
    }

    /**
     * 4. describeForCardsWithFeedbackMap: 0 ReviewLog queries (pre-built feedback).
     */
    public function test_describe_for_cards_with_feedback_map_zero_review_log_queries(): void
    {
        $service = app(SenseReviewLeechQueryService::class);
        $feedbackService = app(SenseReviewLearningFeedbackService::class);

        $cards = collect();
        for ($i = 0; $i < 5; $i++) {
            $cards->push($this->makeLeechCard());
        }
        $cardIds = $cards->pluck('id')->all();

        // Pre-build feedback (1 query outside the measurement).
        $feedbackMap = $feedbackService->buildForCards($cardIds);

        // Measure: describeForCardsWithFeedbackMap should issue 0 ReviewLog queries.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->describeForCardsWithFeedbackMap($cardIds, $cards, $feedbackMap);
        $queries = $this->countReviewLogQueries(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $queries, "describeForCardsWithFeedbackMap: expected 0 review_logs queries, got $queries");
    }

    /**
     * 5. Management page leech filter: does NOT per-card query ReviewLog.
     *
     * The filter uses batch classification (1 ReviewLog query) regardless
     * of how many cards match.
     */
    public function test_management_page_leech_filter_no_per_card_review_log_query(): void
    {
        // Create 5 leech cards + 5 stable cards.
        $leechCards = [];
        for ($i = 0; $i < 5; $i++) {
            $leechCards[] = $this->makeLeechCard();
        }
        for ($i = 0; $i < 5; $i++) {
            $this->makeStableCard();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=leech&include_leech=1&per_page=100');
        $queries = $this->countReviewLogQueries(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertStatus(200);

        // Should be at most 2 ReviewLog queries:
        // 1 from getLeechFilteredCardIds (batch classification)
        // 1 from describeForCards (leech descriptor injection for the page items)
        $this->assertLessThanOrEqual(2, $queries, "Management leech filter: expected ≤2 review_logs queries, got $queries");
    }

    // ─── Helpers ───

    private function countReviewLogQueries(array $queryLog): int
    {
        $count = 0;
        foreach ($queryLog as $entry) {
            $sql = $entry['query'] ?? '';
            if (preg_match('/\breview_logs\b/i', $sql)) {
                $count++;
            }
        }
        return $count;
    }

    private function makeLeechCard(): ReviewCard
    {
        $card = $this->makeCard(['fsrs_lapses' => 3]);
        $this->makeLogs($card, [
            ['rating' => 'again', 'daysAgo' => 10],
            ['rating' => 'again', 'daysAgo' => 8],
            ['rating' => 'again', 'daysAgo' => 6],
            ['rating' => 'good', 'daysAgo' => 4],
            ['rating' => 'easy', 'daysAgo' => 2],
        ]);
        return $card;
    }

    private function makeStableCard(): ReviewCard
    {
        $card = $this->makeCard();
        $this->makeLogs($card, [
            ['rating' => 'good', 'daysAgo' => 5],
            ['rating' => 'easy', 'daysAgo' => 3],
        ]);
        return $card;
    }

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
            'sense_key' => hash('sha256', strtolower('english|test|noun|测试|test') . Str::random(4)),
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

    private function makeLogs(ReviewCard $card, array $ratings): void
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
                'source' => 'sense_review',
            ]);
        }
    }
}
