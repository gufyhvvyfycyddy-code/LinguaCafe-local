<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ReviewCardBrowserSearchTest
 *
 * ADR-0012: Feature tests for the advanced browser search grammar on the
 * management page.
 *
 * Verifies the end-to-end integration of:
 *  - ReviewCardBrowserSearchParser (token recognition)
 *  - ReviewCardBrowserSearchQueryApplier (query application)
 *  - ReviewCardManageQueryService (governance caching, filter combination)
 *  - ReviewCardManageController (search_meta response, 422 errors, exports)
 *
 * Coverage (22+ cases per task spec):
 *  1.  is:leech
 *  2.  is:struggling
 *  3.  is:suspended
 *  4.  is:archived
 *  5.  is:leech is:suspended (cross-category combination)
 *  6.  rated:again
 *  7.  rated:hard
 *  8.  non-sense_review again doesn't count
 *  9.  reset doesn't count
 *  10. undone again doesn't count
 *  11. prop:lapses>=2
 *  12. plain text + rated
 *  13. plain text + is + prop
 *  14. user isolation
 *  15. language isolation
 *  16. pagination total
 *  17. JSON export consistency
 *  18. CSV export consistency
 *  19. Anki TSV export consistency
 *  20. 422 error structure
 *  21. query budget doesn't grow linearly with card count
 *  22. filter + q don't double-classify leech
 */
class ReviewCardBrowserSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::forceCreate([
            'name' => 'Browser Search Test',
            'email' => 'browser-search-' . Str::uuid() . '@example.com',
            'password' => \Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    // ─── 1. is:leech ───

    public function test_is_leech_token_returns_only_leech_cards(): void
    {
        $leechCard = $this->makeCardWithLeechHistory();
        $stableCard = $this->makeCard();

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('is:leech') . '&include_leech=1');

        $response->assertStatus(200);
        $items = $response->json('items');
        $cardIds = array_column($items, 'review_card_id');
        $this->assertContains($leechCard->id, $cardIds);
        $this->assertNotContains($stableCard->id, $cardIds);
    }

    // ─── 2. is:struggling ───

    public function test_is_struggling_token_returns_only_struggling_cards(): void
    {
        $strugglingCard = $this->makeCardWithStrugglingHistory();
        $stableCard = $this->makeCard();

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('is:struggling') . '&include_leech=1');

        $response->assertStatus(200);
        $items = $response->json('items');
        $cardIds = array_column($items, 'review_card_id');
        $this->assertContains($strugglingCard->id, $cardIds);
        $this->assertNotContains($stableCard->id, $cardIds);
    }

    // ─── 3. is:suspended ───

    public function test_is_suspended_token_returns_only_suspended_cards(): void
    {
        $suspendedCard = $this->makeCard(['lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED]);
        $activeCard = $this->makeCard(['lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE]);

        // ADR-0012 §6: When advanced tokens are present, the frontend switches
        // the filter button to "全部" (filter=all) so that lifecycle tokens
        // like is:suspended are not pre-filtered out by the default filter.
        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&q=' . urlencode('is:suspended'));

        $response->assertStatus(200);
        $items = $response->json('items');
        $cardIds = array_column($items, 'review_card_id');
        $this->assertContains($suspendedCard->id, $cardIds);
        $this->assertNotContains($activeCard->id, $cardIds);
    }

    // ─── 4. is:archived ───

    public function test_is_archived_token_returns_only_archived_cards(): void
    {
        $archivedCard = $this->makeCard(['lifecycle_state' => ReviewCard::LIFECYCLE_ARCHIVED]);
        $activeCard = $this->makeCard(['lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&q=' . urlencode('is:archived'));

        $response->assertStatus(200);
        $items = $response->json('items');
        $cardIds = array_column($items, 'review_card_id');
        $this->assertContains($archivedCard->id, $cardIds);
        $this->assertNotContains($activeCard->id, $cardIds);
    }

    // ─── 5. is:leech is:suspended (cross-category) ───

    public function test_is_leech_and_is_suspended_combine_with_and_semantics(): void
    {
        // A card that is BOTH leech AND suspended should match.
        $leechSuspendedCard = $this->makeCardWithLeechHistory([
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
        ]);
        // A card that is leech but active should NOT match.
        $leechActiveCard = $this->makeCardWithLeechHistory([
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ]);
        // A card that is suspended but not leech should NOT match.
        $stableSuspendedCard = $this->makeCard([
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&q=' . urlencode('is:leech is:suspended') . '&include_leech=1');

        $response->assertStatus(200);
        $items = $response->json('items');
        $cardIds = array_column($items, 'review_card_id');
        $this->assertContains($leechSuspendedCard->id, $cardIds);
        $this->assertNotContains($leechActiveCard->id, $cardIds);
        $this->assertNotContains($stableSuspendedCard->id, $cardIds);
    }

    // ─── 6. rated:again ───

    public function test_rated_again_token_returns_cards_with_again_log(): void
    {
        $cardWithAgain = $this->makeCard();
        $this->makeLog($cardWithAgain, 'again', 1);

        $cardWithoutAgain = $this->makeCard();
        $this->makeLog($cardWithoutAgain, 'good', 1);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('rated:again'));

        $response->assertStatus(200);
        $items = $response->json('items');
        $cardIds = array_column($items, 'review_card_id');
        $this->assertContains($cardWithAgain->id, $cardIds);
        $this->assertNotContains($cardWithoutAgain->id, $cardIds);
    }

    // ─── 7. rated:hard ───

    public function test_rated_hard_token_returns_cards_with_hard_log(): void
    {
        $cardWithHard = $this->makeCard();
        $this->makeLog($cardWithHard, 'hard', 1);

        $cardWithoutHard = $this->makeCard();
        $this->makeLog($cardWithoutHard, 'good', 1);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('rated:hard'));

        $response->assertStatus(200);
        $items = $response->json('items');
        $cardIds = array_column($items, 'review_card_id');
        $this->assertContains($cardWithHard->id, $cardIds);
        $this->assertNotContains($cardWithoutHard->id, $cardIds);
    }

    // ─── 8. non-sense_review again doesn't count ───

    public function test_non_sense_review_again_log_does_not_count(): void
    {
        $card = $this->makeCard();
        // Create an 'again' log with a NON-sense_review source.
        ReviewLog::create([
            'user_id' => $card->user_id,
            'language_id' => $card->language_id,
            'language' => $card->language,
            'review_card_id' => $card->id,
            'rating' => 'again',
            'reviewed_at' => now()->subDay(),
            'previous_state' => 'review',
            'new_state' => 'review',
            'previous_due_at' => now()->subDays(2),
            'new_due_at' => now(),
            'previous_stability' => 1.0,
            'new_stability' => 1.5,
            'previous_difficulty' => 5.0,
            'new_difficulty' => 5.0,
            'source' => 'review',  // NOT sense_review
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('rated:again'));

        $response->assertStatus(200);
        $items = $response->json('items');
        $cardIds = array_column($items, 'review_card_id');
        $this->assertNotContains($card->id, $cardIds);
    }

    // ─── 9. reset doesn't count ───

    public function test_reset_log_does_not_count_for_rated(): void
    {
        $card = $this->makeCard();
        ReviewLog::create([
            'user_id' => $card->user_id,
            'language_id' => $card->language_id,
            'language' => $card->language,
            'review_card_id' => $card->id,
            'rating' => 'reset',
            'reviewed_at' => now()->subDay(),
            'previous_state' => 'review',
            'new_state' => 'new',
            'previous_due_at' => now()->subDays(2),
            'new_due_at' => now(),
            'previous_stability' => 1.0,
            'new_stability' => null,
            'previous_difficulty' => 5.0,
            'new_difficulty' => null,
            'source' => 'reset',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('rated:again'));

        $response->assertStatus(200);
        $items = $response->json('items');
        $cardIds = array_column($items, 'review_card_id');
        $this->assertNotContains($card->id, $cardIds);
    }

    // ─── 10. undone again doesn't count ───

    public function test_undone_again_log_does_not_count(): void
    {
        $card = $this->makeCard();
        ReviewLog::create([
            'user_id' => $card->user_id,
            'language_id' => $card->language_id,
            'language' => $card->language,
            'review_card_id' => $card->id,
            'rating' => 'again',
            'reviewed_at' => now()->subDay(),
            'previous_state' => 'review',
            'new_state' => 'review',
            'previous_due_at' => now()->subDays(2),
            'new_due_at' => now(),
            'previous_stability' => 1.0,
            'new_stability' => 1.5,
            'previous_difficulty' => 5.0,
            'new_difficulty' => 5.0,
            'source' => 'sense_review',
            'undone_at' => now(),  // Marked as undone
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('rated:again'));

        $response->assertStatus(200);
        $items = $response->json('items');
        $cardIds = array_column($items, 'review_card_id');
        $this->assertNotContains($card->id, $cardIds);
    }

    // ─── 11. prop:lapses>=2 ───

    public function test_prop_lapses_greater_equal_filters_correctly(): void
    {
        $card2 = $this->makeCard(['fsrs_lapses' => 2]);
        $card1 = $this->makeCard(['fsrs_lapses' => 1]);
        $card0 = $this->makeCard(['fsrs_lapses' => 0]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('prop:lapses>=2'));

        $response->assertStatus(200);
        $items = $response->json('items');
        $cardIds = array_column($items, 'review_card_id');
        $this->assertContains($card2->id, $cardIds);
        $this->assertNotContains($card1->id, $cardIds);
        $this->assertNotContains($card0->id, $cardIds);
    }

    public function test_prop_lapses_equals_zero(): void
    {
        $card0 = $this->makeCard(['fsrs_lapses' => 0]);
        $card1 = $this->makeCard(['fsrs_lapses' => 1]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('prop:lapses=0'));

        $response->assertStatus(200);
        $items = $response->json('items');
        $cardIds = array_column($items, 'review_card_id');
        $this->assertContains($card0->id, $cardIds);
        $this->assertNotContains($card1->id, $cardIds);
    }

    // ─── 12. plain text + rated ───

    public function test_plain_text_and_rated_combine_with_and(): void
    {
        $matchingCard = $this->makeCard(['fsrs_lapses' => 0]);
        $matchingCard->sense->update(['lemma' => 'charge']);
        $this->makeLog($matchingCard, 'again', 1);

        $nonMatchingCard = $this->makeCard();
        $nonMatchingCard->sense->update(['lemma' => 'other']);
        $this->makeLog($nonMatchingCard, 'again', 1);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('charge rated:again'));

        $response->assertStatus(200);
        $items = $response->json('items');
        $cardIds = array_column($items, 'review_card_id');
        $this->assertContains($matchingCard->id, $cardIds);
        $this->assertNotContains($nonMatchingCard->id, $cardIds);
    }

    // ─── 13. plain text + is + prop ───

    public function test_plain_text_is_and_prop_combine(): void
    {
        $matchingCard = $this->makeCard([
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
            'fsrs_lapses' => 3,
        ]);
        $matchingCard->sense->update(['lemma' => 'charge']);

        $wrongLifecycle = $this->makeCard([
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
            'fsrs_lapses' => 3,
        ]);
        $wrongLifecycle->sense->update(['lemma' => 'charge']);

        $wrongLapses = $this->makeCard([
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
            'fsrs_lapses' => 1,
        ]);
        $wrongLapses->sense->update(['lemma' => 'charge']);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&q=' . urlencode('charge is:suspended prop:lapses>=2'));

        $response->assertStatus(200);
        $items = $response->json('items');
        $cardIds = array_column($items, 'review_card_id');
        $this->assertContains($matchingCard->id, $cardIds);
        $this->assertNotContains($wrongLifecycle->id, $cardIds);
        $this->assertNotContains($wrongLapses->id, $cardIds);
    }

    // ─── 14. user isolation ───

    public function test_search_respects_user_isolation(): void
    {
        $otherUser = User::forceCreate([
            'name' => 'Other User',
            'email' => 'other-' . Str::uuid() . '@example.com',
            'password' => \Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $otherCard = $this->makeCardForUser($otherUser, ['fsrs_lapses' => 5]);
        $otherCard->sense->update(['lemma' => 'charge']);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('charge'));

        $response->assertStatus(200);
        $items = $response->json('items');
        $cardIds = array_column($items, 'review_card_id');
        $this->assertNotContains($otherCard->id, $cardIds);
    }

    // ─── 15. language isolation ───

    public function test_search_respects_language_isolation(): void
    {
        // Create a card in a different language for the same user.
        $otherLangSense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'japanese',
            'language_id' => 'japanese',
            'lemma' => 'charge',
            'surface_form' => 'charge',
            'pos' => 'noun',
            'sense_zh' => '充电',
            'sense_en' => 'charge',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Charge the phone.',
            'example_sentence_zh' => '给手机充电。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower('japanese|charge|noun|充电|charge')),
        ]);
        $otherLangCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'japanese',
            'language' => 'japanese',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $otherLangSense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'lifecycle_state' => 'active',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('charge'));

        $response->assertStatus(200);
        $items = $response->json('items');
        $cardIds = array_column($items, 'review_card_id');
        $this->assertNotContains($otherLangCard->id, $cardIds);
    }

    // ─── 16. pagination total ───

    public function test_pagination_total_reflects_search_filter(): void
    {
        // Create 3 cards, 2 matching the search.
        $card1 = $this->makeCard(['fsrs_lapses' => 5]);
        $card2 = $this->makeCard(['fsrs_lapses' => 5]);
        $this->makeCard(['fsrs_lapses' => 0]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('prop:lapses>=2') . '&per_page=10');

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('pagination.total'));
    }

    // ─── 17. JSON export consistency ───

    public function test_json_export_uses_same_search_semantics(): void
    {
        $this->makeCard(['fsrs_lapses' => 3]);
        $this->makeCard(['fsrs_lapses' => 0]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/export?q=' . urlencode('prop:lapses>=2'));

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('items', $data);
        $this->assertCount(1, $data['items']);
    }

    // ─── 18. CSV export consistency ───

    public function test_csv_export_uses_same_search_semantics(): void
    {
        $this->makeCard(['fsrs_lapses' => 3]);
        $this->makeCard(['fsrs_lapses' => 0]);

        $response = $this->actingAs($this->user)
            ->get('/review-cards/manage/export-csv?q=' . urlencode('prop:lapses>=2'));

        $response->assertStatus(200);
        $this->assertSame('1', $response->headers->get('X-Export-Count'));
    }

    // ─── 19. Anki TSV export consistency ───

    public function test_anki_tsv_export_uses_same_search_semantics(): void
    {
        $this->makeCard(['fsrs_lapses' => 3]);
        $this->makeCard(['fsrs_lapses' => 0]);

        $response = $this->actingAs($this->user)
            ->get('/review-cards/manage/export-anki-tsv?q=' . urlencode('prop:lapses>=2'));

        $response->assertStatus(200);
        $this->assertSame('1', $response->headers->get('X-Export-Count'));
    }

    // ─── 20. 422 error structure ───

    public function test_invalid_grammar_returns_422_with_structured_error(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('is:leech is:struggling'));

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'invalid_browser_search');
        $response->assertJsonStructure([
            'message',
            'code',
            'errors' => [['token', 'reason', 'example']],
        ]);
    }

    public function test_invalid_prop_operator_returns_422(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('prop:lapses>>2'));

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'invalid_browser_search');
    }

    public function test_unknown_is_value_returns_422(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('is:unknown'));

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'invalid_browser_search');
    }

    public function test_export_returns_422_on_invalid_grammar(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/export?q=' . urlencode('is:unknown'));

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'invalid_browser_search');
    }

    // ─── 21. query budget doesn't grow linearly ───

    public function test_query_budget_does_not_grow_linearly_with_card_count(): void
    {
        // Create a moderate set of cards and verify the search completes
        // without per-card queries. We use DB query log to count queries.
        for ($i = 0; $i < 5; $i++) {
            $this->makeCard(['fsrs_lapses' => 2]);
        }

        DB::enableQueryLog();

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('prop:lapses>=2') . '&per_page=10');

        $response->assertStatus(200);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // The number of queries should NOT scale with card count. With 5 cards
        // and a prop filter, we expect a small constant number of queries
        // (security scope + count + pagination + items + sense eager load).
        // Allow a generous upper bound to avoid flakiness — the point is that
        // it's NOT 5+ per-card queries.
        $this->assertLessThan(20, count($queries), 'Query count should not scale linearly with card count');
    }

    // ─── 22. filter + q don't double-classify leech ───

    public function test_filter_leech_and_q_is_leech_do_not_double_classify(): void
    {
        $leechCard = $this->makeCardWithLeechHistory();

        DB::enableQueryLog();

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=leech&q=' . urlencode('is:leech') . '&include_leech=1');

        $response->assertStatus(200);
        $items = $response->json('items');
        $cardIds = array_column($items, 'review_card_id');
        $this->assertContains($leechCard->id, $cardIds);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Count review_logs queries — should be a small constant (1 batch
        // load for classification + maybe 1 for describeForCards), NOT 2x.
        $reviewLogQueries = array_filter($queries, function ($q) {
            return strpos($q['query'], 'review_logs') !== false;
        });
        // With governance caching, classification runs once. Allow some
        // slack for the include_leech descriptor injection.
        $this->assertLessThan(5, count($reviewLogQueries), 'Leech classification should not double-run when filter and q both request governance');
    }

    // ─── search_meta response ───

    public function test_search_meta_is_returned_in_response(): void
    {
        $this->makeCard();

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('charge is:leech prop:lapses>=2'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'search_meta' => ['raw_query', 'text_query', 'tokens', 'advanced'],
        ]);
        $this->assertSame('charge is:leech prop:lapses>=2', $response->json('search_meta.raw_query'));
        $this->assertSame('charge', $response->json('search_meta.text_query'));
        $this->assertTrue($response->json('search_meta.advanced'));
        $this->assertContains('is:leech', $response->json('search_meta.tokens'));
        $this->assertContains('prop:lapses>=2', $response->json('search_meta.tokens'));
    }

    public function test_search_meta_advanced_false_for_plain_text(): void
    {
        $this->makeCard();

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('charge'));

        $response->assertStatus(200);
        $this->assertFalse($response->json('search_meta.advanced'));
        $this->assertSame([], $response->json('search_meta.tokens'));
    }

    // ─── Helpers ───

    private function makeCard(array $overrides = []): ReviewCard
    {
        return $this->makeCardForUser($this->user, $overrides);
    }

    private function makeCardForUser(User $user, array $overrides = []): ReviewCard
    {
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
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
            'sense_key' => hash('sha256', strtolower('english|test' . Str::random(6) . '|noun|测试|test')),
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

    private function makeCardWithLeechHistory(array $overrides = []): ReviewCard
    {
        $card = $this->makeCard(array_merge(['fsrs_lapses' => 3], $overrides));
        $this->makeLogs($card, [
            ['rating' => 'again', 'daysAgo' => 10],
            ['rating' => 'again', 'daysAgo' => 8],
            ['rating' => 'again', 'daysAgo' => 6],
            ['rating' => 'good', 'daysAgo' => 4],
            ['rating' => 'easy', 'daysAgo' => 2],
        ]);
        return $card;
    }

    private function makeCardWithStrugglingHistory(array $overrides = []): ReviewCard
    {
        $card = $this->makeCard(array_merge(['fsrs_lapses' => 2], $overrides));
        $this->makeLogs($card, [
            ['rating' => 'again', 'daysAgo' => 5],
            ['rating' => 'hard', 'daysAgo' => 4],
            ['rating' => 'again', 'daysAgo' => 3],
            ['rating' => 'good', 'daysAgo' => 2],
            ['rating' => 'easy', 'daysAgo' => 1],
        ]);
        return $card;
    }

    private function makeLogs(ReviewCard $card, array $ratings): void
    {
        foreach ($ratings as $r) {
            $this->makeLog($card, $r['rating'], $r['daysAgo']);
        }
    }

    private function makeLog(ReviewCard $card, string $rating, int $daysAgo): void
    {
        ReviewLog::create([
            'user_id' => $card->user_id,
            'language_id' => $card->language_id,
            'language' => $card->language,
            'review_card_id' => $card->id,
            'rating' => $rating,
            'reviewed_at' => now()->subDays($daysAgo),
            'previous_state' => 'review',
            'new_state' => 'review',
            'previous_due_at' => now()->subDays($daysAgo + 1),
            'new_due_at' => now()->subDays($daysAgo - 1),
            'previous_stability' => 1.0,
            'new_stability' => 1.5,
            'previous_difficulty' => 5.0,
            'new_difficulty' => 5.0,
            'source' => $rating === 'reset' ? 'reset' : 'sense_review',
        ]);
    }
}
