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

    public function test_rated_good_token_returns_cards_with_good_log(): void
    {
        $cardWithGood = $this->makeCard();
        $this->makeLog($cardWithGood, 'good', 1);

        $cardWithoutGood = $this->makeCard();
        $this->makeLog($cardWithoutGood, 'easy', 1);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('rated:good'));

        $response->assertStatus(200);
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertContains($cardWithGood->id, $cardIds);
        $this->assertNotContains($cardWithoutGood->id, $cardIds);
    }

    public function test_rated_easy_token_returns_cards_with_easy_log(): void
    {
        $cardWithEasy = $this->makeCard();
        $this->makeLog($cardWithEasy, 'easy', 1);

        $cardWithoutEasy = $this->makeCard();
        $this->makeLog($cardWithoutEasy, 'good', 1);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('rated:easy'));

        $response->assertStatus(200);
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertContains($cardWithEasy->id, $cardIds);
        $this->assertNotContains($cardWithoutEasy->id, $cardIds);
    }

    public function test_rated_good_and_easy_exclude_non_formal_reset_and_undone_logs(): void
    {
        $matchingCard = $this->makeCard();
        $this->makeLog($matchingCard, 'good', 2);
        $this->makeLog($matchingCard, 'easy', 1);

        $nonFormalCard = $this->makeCard();
        $this->makeLog($nonFormalCard, 'good', 2, 'review');
        $this->makeLog($nonFormalCard, 'easy', 1, 'review');

        $resetSourceCard = $this->makeCard();
        $this->makeLog($resetSourceCard, 'good', 2, 'reset');
        $this->makeLog($resetSourceCard, 'easy', 1, 'reset');

        $undoneCard = $this->makeCard();
        $this->makeLog($undoneCard, 'good', 2);
        $this->makeLog($undoneCard, 'easy', 1, 'sense_review', now());

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&q=' . urlencode('rated:good rated:easy') . '&per_page=50');

        $response->assertStatus(200);
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertSame([$matchingCard->id], $cardIds);
        $this->assertNotContains($nonFormalCard->id, $cardIds);
        $this->assertNotContains($resetSourceCard->id, $cardIds);
        $this->assertNotContains($undoneCard->id, $cardIds);
    }

    public function test_rated_good_and_easy_respect_user_and_language_isolation(): void
    {
        $matchingCard = $this->makeCard();
        $this->makeLog($matchingCard, 'good', 2);
        $this->makeLog($matchingCard, 'easy', 1);

        $otherUser = User::forceCreate([
            'name' => 'Other Rated User',
            'email' => 'other-rated-' . Str::uuid() . '@example.com',
            'password' => \Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $otherUserCard = $this->makeCardForUser($otherUser);
        $this->makeLog($otherUserCard, 'good', 2);
        $this->makeLog($otherUserCard, 'easy', 1);

        $otherLanguageSense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'japanese',
            'language_id' => 'japanese',
            'lemma' => 'rated-language-scope',
            'surface_form' => 'rated-language-scope',
            'pos' => 'noun',
            'sense_zh' => '评分语言范围',
            'sense_en' => 'rated language scope',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Rated language scope.',
            'example_sentence_zh' => '评分语言范围。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower('japanese|rated-language-scope|noun|评分语言范围|rated language scope')),
        ]);
        $otherLanguageCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'japanese',
            'language' => 'japanese',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $otherLanguageSense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
            'fsrs_reps' => 2,
            'fsrs_lapses' => 0,
            'lifecycle_state' => 'active',
        ]);
        $this->makeLog($otherLanguageCard, 'good', 2);
        $this->makeLog($otherLanguageCard, 'easy', 1);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&q=' . urlencode('rated:good rated:easy') . '&per_page=50');

        $response->assertStatus(200);
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertContains($matchingCard->id, $cardIds);
        $this->assertNotContains($otherUserCard->id, $cardIds);
        $this->assertNotContains($otherLanguageCard->id, $cardIds);
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

    public function test_rated_good_and_easy_results_match_list_and_all_exports(): void
    {
        $matchingCard = $this->makeCard();
        $matchingCard->sense->update(['lemma' => 'phase8a-formal-match']);
        $this->makeLog($matchingCard, 'good', 2);
        $this->makeLog($matchingCard, 'easy', 1);

        $excludedCard = $this->makeCard();
        $excludedCard->sense->update(['lemma' => 'phase8a-excluded']);
        $this->makeLog($excludedCard, 'good', 1);

        $query = urlencode('phase8a-formal-match rated:good rated:easy');

        $listResponse = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&q=' . $query . '&per_page=50');
        $jsonResponse = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/export?filter=all&q=' . $query);
        $csvResponse = $this->actingAs($this->user)
            ->get('/review-cards/manage/export-csv?filter=all&q=' . $query);
        $tsvResponse = $this->actingAs($this->user)
            ->get('/review-cards/manage/export-anki-tsv?filter=all&q=' . $query);

        $listResponse->assertStatus(200);
        $jsonResponse->assertStatus(200);
        $csvResponse->assertStatus(200);
        $tsvResponse->assertStatus(200);

        $this->assertSame([$matchingCard->id], array_column($listResponse->json('items'), 'review_card_id'));
        $this->assertCount(1, $jsonResponse->json('items'));
        $this->assertSame('1', $csvResponse->headers->get('X-Export-Count'));
        $this->assertSame('1', $tsvResponse->headers->get('X-Export-Count'));

        $jsonItems = json_encode($jsonResponse->json('items'));
        $this->assertStringContainsString('phase8a-formal-match', $jsonItems);
        $this->assertStringContainsString('phase8a-formal-match', $csvResponse->getContent());
        $this->assertStringContainsString('phase8a-formal-match', $tsvResponse->getContent());
        $this->assertStringNotContainsString('phase8a-excluded', $jsonItems);
        $this->assertStringNotContainsString('phase8a-excluded', $csvResponse->getContent());
        $this->assertStringNotContainsString('phase8a-excluded', $tsvResponse->getContent());
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

    // ─── 20a. CSV export 422 on invalid grammar (Task 2000-6 fix) ───

    public function test_csv_export_returns_422_on_invalid_grammar(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/export-csv?q=' . urlencode('is:unknown'));

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'invalid_browser_search');
        $response->assertJsonStructure([
            'message',
            'code',
            'errors' => [['token', 'reason', 'example']],
        ]);
    }

    // ─── 20b. Anki TSV export 422 on invalid grammar (Task 2000-6 fix) ───

    public function test_anki_tsv_export_returns_422_on_invalid_grammar(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/export-anki-tsv?q=' . urlencode('is:unknown'));

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'invalid_browser_search');
        $response->assertJsonStructure([
            'message',
            'code',
            'errors' => [['token', 'reason', 'example']],
        ]);
    }

    // ─── 20c. CSV/TSV export limit-exceeded path also returns 422 JSON (Task 2000-6 fix) ───
    // Note: The limit-exceeded path uses the same `response()->json(...)` pattern as the
    // invalid-grammar path above. Since the return type is now Symfony Response (the common
    // parent of Illuminate\Http\Response and JsonResponse), both 422 paths are covered by
    // the same type fix. Creating 5001 cards (EXPORT_LIMIT=5000) to trigger the limit path
    // would make the test suite impractically slow; the invalid-grammar tests above prove
    // the JsonResponse return works without TypeError for both export endpoints.

    // ─── 20e. Legal CSV export still returns 200 with X-Export-Count (Task 2000-6 regression) ───

    public function test_legal_csv_export_returns_200_with_count(): void
    {
        $this->makeCard(['fsrs_lapses' => 3]);
        $this->makeCard(['fsrs_lapses' => 0]);

        $response = $this->actingAs($this->user)
            ->get('/review-cards/manage/export-csv?q=' . urlencode('prop:lapses>=2'));

        $response->assertStatus(200);
        $this->assertSame('1', $response->headers->get('X-Export-Count'));
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    // ─── 20f. Legal Anki TSV export still returns 200 with X-Export-Count (Task 2000-6 regression) ───

    public function test_legal_anki_tsv_export_returns_200_with_count(): void
    {
        $this->makeCard(['fsrs_lapses' => 3]);
        $this->makeCard(['fsrs_lapses' => 0]);

        $response = $this->actingAs($this->user)
            ->get('/review-cards/manage/export-anki-tsv?q=' . urlencode('prop:lapses>=2'));

        $response->assertStatus(200);
        $this->assertSame('1', $response->headers->get('X-Export-Count'));
        $this->assertStringContainsString('text/tab-separated-values', $response->headers->get('Content-Type'));
    }

    // ─── 20g. Search does not write ReviewLog (Task 2000-6 safety) ───

    public function test_search_does_not_write_review_log(): void
    {
        $this->makeCard(['fsrs_lapses' => 2]);

        $logBefore = ReviewLog::where('user_id', $this->user->id)->count();

        $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('is:leech rated:again prop:lapses>=2'));

        $this->actingAs($this->user)
            ->getJson('/review-cards/manage/export-csv?q=' . urlencode('is:leech rated:again prop:lapses>=2'));

        $this->actingAs($this->user)
            ->getJson('/review-cards/manage/export-anki-tsv?q=' . urlencode('is:leech rated:again prop:lapses>=2'));

        $logAfter = ReviewLog::where('user_id', $this->user->id)->count();
        $this->assertSame($logBefore, $logAfter, 'Search and export must not create ReviewLog entries.');
    }

    // ─── 20h. Search does not modify FSRS or lifecycle (Task 2000-6 safety) ───

    public function test_search_does_not_modify_fsrs_or_lifecycle(): void
    {
        $card = $this->makeCard([
            'fsrs_lapses' => 2,
            'fsrs_stability' => 5.0,
            'fsrs_difficulty' => 0.3,
            'fsrs_reps' => 3,
            'lifecycle_state' => 'active',
        ]);

        $snapshot = function () use ($card) {
            $c = ReviewCard::find($card->id);
            return [
                'fsrs_lapses' => $c->fsrs_lapses,
                'fsrs_stability' => $c->fsrs_stability,
                'fsrs_difficulty' => $c->fsrs_difficulty,
                'fsrs_reps' => $c->fsrs_reps,
                'fsrs_state' => $c->fsrs_state,
                'fsrs_due_at' => optional($c->fsrs_due_at)->toISOString(),
                'lifecycle_state' => $c->lifecycle_state,
                'lifecycle_version' => $c->lifecycle_version,
            ];
        };

        $before = $snapshot();

        $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('is:leech rated:again prop:lapses>=2'));
        $this->actingAs($this->user)
            ->getJson('/review-cards/manage/export-csv?q=' . urlencode('is:leech rated:again prop:lapses>=2'));
        $this->actingAs($this->user)
            ->getJson('/review-cards/manage/export-anki-tsv?q=' . urlencode('is:leech rated:again prop:lapses>=2'));

        $after = $snapshot();

        $this->assertSame($before, $after, 'Search and export must not modify FSRS or lifecycle fields.');
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

    // ═══════════════════════════════════════════════════════════════════════
    // ADR-0013: Pipeline Convergence Tests (Task 2000-7)
    //
    // These 16 tests verify the converged execution pipeline:
    //   - Parser is called exactly once per HTTP request (tests 1-4)
    //   - Legal search results are stable after convergence (test 5)
    //   - 422 contract is consistent across all 4 endpoints (test 6)
    //   - Duplicate tokens are deduplicated (test 7)
    //   - Conflicting tokens still return 422 (test 8)
    //   - User / language / sense-only isolation (tests 9-11)
    //   - rated: only counts real sense_review logs (test 12)
    //   - Governance query budget is O(1), not O(N) (test 13)
    //   - No ReviewLog writes, no FSRS mutation, no lifecycle mutation (tests 14-16)
    // ═══════════════════════════════════════════════════════════════════════

    // ─── 2000-7 §1. data endpoint parses exactly once ───

    public function test_adr_0013_data_endpoint_parses_search_string_exactly_once(): void
    {
        $this->makeCardWithLeechHistory();

        $callCount = 0;
        $this->spyParserCallCount($callCount);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&q=' . urlencode('is:leech rated:again prop:lapses>=2') . '&include_leech=1');

        $response->assertStatus(200);
        $this->assertSame(1, $callCount, 'ADR-0013: data endpoint must parse the search string exactly once per request.');
    }

    // ─── 2000-7 §2. JSON export parses exactly once ───

    public function test_adr_0013_json_export_parses_search_string_exactly_once(): void
    {
        $this->makeCard(['fsrs_lapses' => 3]);

        $callCount = 0;
        $this->spyParserCallCount($callCount);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/export?q=' . urlencode('prop:lapses>=2'));

        $response->assertStatus(200);
        $this->assertSame(1, $callCount, 'ADR-0013: JSON export must parse the search string exactly once per request.');
    }

    // ─── 2000-7 §3. CSV export parses exactly once ───

    public function test_adr_0013_csv_export_parses_search_string_exactly_once(): void
    {
        $this->makeCard(['fsrs_lapses' => 3]);

        $callCount = 0;
        $this->spyParserCallCount($callCount);

        $response = $this->actingAs($this->user)
            ->get('/review-cards/manage/export-csv?q=' . urlencode('prop:lapses>=2'));

        $response->assertStatus(200);
        $this->assertSame(1, $callCount, 'ADR-0013: CSV export must parse the search string exactly once per request.');
    }

    // ─── 2000-7 §4. TSV export parses exactly once ───

    public function test_adr_0013_tsv_export_parses_search_string_exactly_once(): void
    {
        $this->makeCard(['fsrs_lapses' => 3]);

        $callCount = 0;
        $this->spyParserCallCount($callCount);

        $response = $this->actingAs($this->user)
            ->get('/review-cards/manage/export-anki-tsv?q=' . urlencode('prop:lapses>=2'));

        $response->assertStatus(200);
        $this->assertSame(1, $callCount, 'ADR-0013: TSV export must parse the search string exactly once per request.');
    }

    // ─── 2000-7 §5. Legal search results unchanged after convergence ───

    public function test_adr_0013_legal_search_results_match_expected_set(): void
    {
        // Build a scenario with multiple token categories and verify the
        // exact expected card set is returned. This is the regression guard
        // proving the pipeline convergence did not change V1 semantics.
        $match = $this->makeCardWithLeechHistory([
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
            'fsrs_lapses' => 3,
        ]);
        $match->sense->update(['lemma' => 'converge']);

        $wrongLifecycle = $this->makeCardWithLeechHistory([
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
            'fsrs_lapses' => 3,
        ]);
        $wrongLifecycle->sense->update(['lemma' => 'converge']);

        $wrongLapses = $this->makeCardWithLeechHistory([
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
            'fsrs_lapses' => 1,
        ]);
        $wrongLapses->sense->update(['lemma' => 'converge']);

        $wrongText = $this->makeCardWithLeechHistory([
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
            'fsrs_lapses' => 3,
        ]);
        $wrongText->sense->update(['lemma' => 'other']);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&q=' . urlencode('converge is:leech is:suspended prop:lapses>=2 rated:again') . '&include_leech=1&per_page=50');

        $response->assertStatus(200);
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertContains($match->id, $cardIds);
        $this->assertNotContains($wrongLifecycle->id, $cardIds);
        $this->assertNotContains($wrongLapses->id, $cardIds);
        $this->assertNotContains($wrongText->id, $cardIds);
    }

    // ─── 2000-7 §6. All 4 endpoints return the same 422 structure ───

    public function test_adr_0013_all_four_endpoints_return_identical_422_structure(): void
    {
        $invalidQuery = 'is:leech is:struggling';
        $expectedStructure = [
            'message',
            'code',
            'errors' => [['token', 'reason', 'example']],
        ];

        // data
        $dataResponse = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode($invalidQuery));
        $dataResponse->assertStatus(422);
        $dataResponse->assertJsonStructure($expectedStructure);
        $dataResponse->assertJsonPath('code', 'invalid_browser_search');

        // JSON export
        $jsonResponse = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/export?q=' . urlencode($invalidQuery));
        $jsonResponse->assertStatus(422);
        $jsonResponse->assertJsonStructure($expectedStructure);
        $jsonResponse->assertJsonPath('code', 'invalid_browser_search');

        // CSV export
        $csvResponse = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/export-csv?q=' . urlencode($invalidQuery));
        $csvResponse->assertStatus(422);
        $csvResponse->assertJsonStructure($expectedStructure);
        $csvResponse->assertJsonPath('code', 'invalid_browser_search');

        // TSV export
        $tsvResponse = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/export-anki-tsv?q=' . urlencode($invalidQuery));
        $tsvResponse->assertStatus(422);
        $tsvResponse->assertJsonStructure($expectedStructure);
        $tsvResponse->assertJsonPath('code', 'invalid_browser_search');

        // All four must return byte-identical error payload (message + code + errors).
        $this->assertSame($dataResponse->json(), $jsonResponse->json());
        $this->assertSame($dataResponse->json(), $csvResponse->json());
        $this->assertSame($dataResponse->json(), $tsvResponse->json());
    }

    // ─── 2000-7 §7. Duplicate tokens produce a single chip ───

    public function test_adr_0013_duplicate_tokens_produce_single_search_meta_token(): void
    {
        $this->makeCardWithLeechHistory(['fsrs_lapses' => 3]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&q=' . urlencode('is:leech is:leech rated:again rated:again prop:lapses>=2 prop:lapses>=2') . '&include_leech=1');

        $response->assertStatus(200);
        $tokens = $response->json('search_meta.tokens');
        $this->assertSame(['is:leech', 'rated:again', 'prop:lapses>=2'], $tokens, 'Duplicate tokens must collapse to a single chip entry.');
        $this->assertCount(3, $tokens);
    }

    // ─── 2000-7 §8. Conflicting tokens still return 422 ───

    public function test_adr_0013_conflicting_tokens_still_return_422_after_convergence(): void
    {
        // Same-category conflict (two different governance statuses) must
        // still be rejected with 422 after the pipeline convergence.
        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('is:leech is:struggling'));

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'invalid_browser_search');
        $errors = $response->json('errors');
        $this->assertNotEmpty($errors);
        $this->assertNotEmpty($errors[0]['reason']);
    }

    public function test_adr_0013_conflicting_lifecycle_tokens_still_return_422(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=' . urlencode('is:active is:suspended'));

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'invalid_browser_search');
    }

    // ─── 2000-7 §9. User isolation ───

    public function test_adr_0013_search_isolates_by_user_id(): void
    {
        $otherUser = User::forceCreate([
            'name' => 'Isolation Other',
            'email' => 'iso-' . Str::uuid() . '@example.com',
            'password' => \Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        // Other user's card with leech history — must NOT appear for $this->user.
        $otherCard = $this->makeCardWithLeechHistoryForUser($otherUser);
        $otherCard->sense->update(['lemma' => 'isolated']);

        // Same-lemma card owned by $this->user — should appear.
        $ownCard = $this->makeCardWithLeechHistory();
        $ownCard->sense->update(['lemma' => 'isolated']);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&q=' . urlencode('isolated is:leech') . '&include_leech=1&per_page=50');

        $response->assertStatus(200);
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertContains($ownCard->id, $cardIds);
        $this->assertNotContains($otherCard->id, $cardIds);
    }

    // ─── 2000-7 §10. Language isolation ───

    public function test_adr_0013_search_isolates_by_language(): void
    {
        // Same user, different language — must NOT appear.
        $jpSense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'japanese',
            'language_id' => 'japanese',
            'lemma' => 'leech-word',
            'surface_form' => 'leech-word',
            'pos' => 'noun',
            'sense_zh' => '水蛭',
            'sense_en' => 'leech',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'A leech attaches.',
            'example_sentence_zh' => '水蛭会附着。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower('japanese|leech-word|noun|水蛭|leech')),
        ]);
        $jpCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'japanese',
            'language' => 'japanese',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $jpSense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 3,
            'lifecycle_state' => 'active',
        ]);

        $enCard = $this->makeCardWithLeechHistory();
        $enCard->sense->update(['lemma' => 'leech-word']);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&q=' . urlencode('leech-word is:leech') . '&include_leech=1&per_page=50');

        $response->assertStatus(200);
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertContains($enCard->id, $cardIds);
        $this->assertNotContains($jpCard->id, $cardIds);
    }

    // ─── 2000-7 §11. Sense-only (non-sense targets excluded) ───

    public function test_adr_0013_search_excludes_non_sense_target_cards(): void
    {
        // Create a word-target card (not sense) with matching lemma.
        $wordSense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'sense-only-guard',
            'surface_form' => 'sense-only-guard',
            'pos' => 'noun',
            'sense_zh' => 'sense-only 守卫',
            'sense_en' => 'sense-only guard',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'A guard stands.',
            'example_sentence_zh' => '守卫站着。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower('english|sense-only-guard|noun|sense-only 守卫|sense-only guard')),
        ]);
        $senseCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $wordSense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'lifecycle_state' => 'active',
        ]);

        // A word-target card pointing at a non-sense target. We use a dummy
        // target_id (0) since the search filter only checks target_type.
        $wordCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => 999999,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'lifecycle_state' => 'active',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&q=' . urlencode('sense-only-guard') . '&per_page=50');

        $response->assertStatus(200);
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertContains($senseCard->id, $cardIds);
        $this->assertNotContains($wordCard->id, $cardIds, 'ADR-0013: non-sense target cards must be excluded from browser search.');
    }

    // ─── 2000-7 §12. rated: only counts real sense_review logs ───

    public function test_adr_0013_rated_excludes_reset_undone_and_non_sense_review_logs(): void
    {
        // Three cards, each with an 'again' log that MUST NOT count:
        //   (a) source='review' (non-sense_review)
        //   (b) source='reset' (reset log)
        //   (c) source='sense_review' but undone_at set
        // Plus one card with a real sense_review again log that SHOULD count.
        $cardNonSense = $this->makeCard();
        ReviewLog::create([
            'user_id' => $cardNonSense->user_id, 'language_id' => $cardNonSense->language_id, 'language' => $cardNonSense->language,
            'review_card_id' => $cardNonSense->id, 'rating' => 'again',
            'reviewed_at' => now()->subDay(),
            'previous_state' => 'review', 'new_state' => 'review',
            'previous_due_at' => now()->subDays(2), 'new_due_at' => now(),
            'previous_stability' => 1.0, 'new_stability' => 1.5,
            'previous_difficulty' => 5.0, 'new_difficulty' => 5.0,
            'source' => 'review',
        ]);

        $cardReset = $this->makeCard();
        ReviewLog::create([
            'user_id' => $cardReset->user_id, 'language_id' => $cardReset->language_id, 'language' => $cardReset->language,
            'review_card_id' => $cardReset->id, 'rating' => 'again',
            'reviewed_at' => now()->subDay(),
            'previous_state' => 'review', 'new_state' => 'new',
            'previous_due_at' => now()->subDays(2), 'new_due_at' => now(),
            'previous_stability' => 1.0, 'new_stability' => null,
            'previous_difficulty' => 5.0, 'new_difficulty' => null,
            'source' => 'reset',
        ]);

        $cardUndone = $this->makeCard();
        ReviewLog::create([
            'user_id' => $cardUndone->user_id, 'language_id' => $cardUndone->language_id, 'language' => $cardUndone->language,
            'review_card_id' => $cardUndone->id, 'rating' => 'again',
            'reviewed_at' => now()->subDay(),
            'previous_state' => 'review', 'new_state' => 'review',
            'previous_due_at' => now()->subDays(2), 'new_due_at' => now(),
            'previous_stability' => 1.0, 'new_stability' => 1.5,
            'previous_difficulty' => 5.0, 'new_difficulty' => 5.0,
            'source' => 'sense_review',
            'undone_at' => now(),
        ]);

        $cardReal = $this->makeCard();
        $this->makeLog($cardReal, 'again', 1);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&q=' . urlencode('rated:again') . '&per_page=50');

        $response->assertStatus(200);
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertContains($cardReal->id, $cardIds);
        $this->assertNotContains($cardNonSense->id, $cardIds, 'Non-sense_review source logs must not count for rated:');
        $this->assertNotContains($cardReset->id, $cardIds, 'Reset logs must not count for rated:');
        $this->assertNotContains($cardUndone->id, $cardIds, 'Undone logs must not count for rated:');
    }

    // ─── 2000-7 §13. Governance query budget is O(1) ───

    public function test_adr_0013_governance_query_count_does_not_grow_with_card_count(): void
    {
        // Create a larger set of leech cards and verify the review_logs query
        // count stays constant (does not scale linearly with card count).
        for ($i = 0; $i < 15; $i++) {
            $this->makeCardWithLeechHistory();
        }

        DB::enableQueryLog();
        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&q=' . urlencode('is:leech') . '&include_leech=1&per_page=50');
        $response->assertStatus(200);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $reviewLogQueries = array_filter($queries, function ($q) {
            return strpos($q['query'], 'review_logs') !== false;
        });

        // With O(1) governance classification, review_logs queries should be
        // a small constant (1 batch load for classification + descriptor
        // injection). Must NOT scale with the 15 cards.
        $this->assertLessThan(6, count($reviewLogQueries), 'Governance classification must be O(1) — review_logs query count must not grow with card count.');
    }

    // ─── 2000-7 §14. No ReviewLog writes from any endpoint ───

    public function test_adr_0013_no_endpoint_writes_review_log(): void
    {
        $this->makeCardWithLeechHistory(['fsrs_lapses' => 3]);

        $logBefore = ReviewLog::where('user_id', $this->user->id)->count();

        // Hit all 4 endpoints with a multi-token query.
        $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&q=' . urlencode('is:leech rated:again prop:lapses>=2') . '&include_leech=1');
        $this->actingAs($this->user)
            ->getJson('/review-cards/manage/export?filter=all&q=' . urlencode('is:leech rated:again prop:lapses>=2'));
        $this->actingAs($this->user)
            ->get('/review-cards/manage/export-csv?filter=all&q=' . urlencode('is:leech rated:again prop:lapses>=2'));
        $this->actingAs($this->user)
            ->get('/review-cards/manage/export-anki-tsv?filter=all&q=' . urlencode('is:leech rated:again prop:lapses>=2'));

        $logAfter = ReviewLog::where('user_id', $this->user->id)->count();
        $this->assertSame($logBefore, $logAfter, 'ADR-0013: No endpoint may create ReviewLog entries.');
    }

    // ─── 2000-7 §15. No FSRS mutation from any endpoint ───

    public function test_adr_0013_no_endpoint_mutates_fsrs_fields(): void
    {
        $card = $this->makeCardWithLeechHistory([
            'fsrs_stability' => 7.5,
            'fsrs_difficulty' => 0.2,
            'fsrs_reps' => 8,
            'fsrs_lapses' => 3,
            'fsrs_state' => 'review',
        ]);

        $snapshot = function () use ($card) {
            $c = ReviewCard::find($card->id);
            return [
                'fsrs_stability' => $c->fsrs_stability,
                'fsrs_difficulty' => $c->fsrs_difficulty,
                'fsrs_reps' => $c->fsrs_reps,
                'fsrs_lapses' => $c->fsrs_lapses,
                'fsrs_state' => $c->fsrs_state,
                'fsrs_due_at' => optional($c->fsrs_due_at)->toISOString(),
                'fsrs_enabled' => $c->fsrs_enabled,
            ];
        };

        $before = $snapshot();

        $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&q=' . urlencode('is:leech rated:again prop:lapses>=2') . '&include_leech=1');
        $this->actingAs($this->user)
            ->getJson('/review-cards/manage/export?filter=all&q=' . urlencode('is:leech rated:again prop:lapses>=2'));
        $this->actingAs($this->user)
            ->get('/review-cards/manage/export-csv?filter=all&q=' . urlencode('is:leech rated:again prop:lapses>=2'));
        $this->actingAs($this->user)
            ->get('/review-cards/manage/export-anki-tsv?filter=all&q=' . urlencode('is:leech rated:again prop:lapses>=2'));

        $after = $snapshot();
        $this->assertSame($before, $after, 'ADR-0013: No endpoint may mutate FSRS fields.');
    }

    // ─── 2000-7 §16. No lifecycle mutation from any endpoint ───

    public function test_adr_0013_no_endpoint_mutates_lifecycle_state(): void
    {
        $card = $this->makeCardWithLeechHistory([
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
        ]);

        $snapshot = function () use ($card) {
            $c = ReviewCard::find($card->id);
            return [
                'lifecycle_state' => $c->lifecycle_state,
                'lifecycle_version' => $c->lifecycle_version,
                'fsrs_enabled' => $c->fsrs_enabled,
            ];
        };

        $before = $snapshot();

        $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&q=' . urlencode('is:leech is:suspended rated:again prop:lapses>=2') . '&include_leech=1');
        $this->actingAs($this->user)
            ->getJson('/review-cards/manage/export?filter=all&q=' . urlencode('is:leech is:suspended rated:again prop:lapses>=2'));
        $this->actingAs($this->user)
            ->get('/review-cards/manage/export-csv?filter=all&q=' . urlencode('is:leech is:suspended rated:again prop:lapses>=2'));
        $this->actingAs($this->user)
            ->get('/review-cards/manage/export-anki-tsv?filter=all&q=' . urlencode('is:leech is:suspended rated:again prop:lapses>=2'));

        $after = $snapshot();
        $this->assertSame($before, $after, 'ADR-0013: No endpoint may mutate lifecycle state or version.');
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

    private function makeCardWithLeechHistoryForUser(User $user, array $overrides = []): ReviewCard
    {
        $card = $this->makeCardForUser($user, array_merge(['fsrs_lapses' => 3], $overrides));
        $this->makeLogs($card, [
            ['rating' => 'again', 'daysAgo' => 10],
            ['rating' => 'again', 'daysAgo' => 8],
            ['rating' => 'again', 'daysAgo' => 6],
            ['rating' => 'good', 'daysAgo' => 4],
            ['rating' => 'easy', 'daysAgo' => 2],
        ]);
        return $card;
    }

    /**
     * ADR-0013: Install a Mockery spy on ReviewCardBrowserSearchParser that
     * counts parse() calls and delegates to the real parser. The $callCount
     * parameter is passed by reference so the caller can assert the count
     * after the request completes.
     */
    private function spyParserCallCount(int &$callCount): void
    {
        $realParser = new \App\Services\ReviewCardBrowserSearchParser();
        $mock = \Mockery::mock(\App\Services\ReviewCardBrowserSearchParser::class);
        $mock->shouldReceive('parse')
            ->andReturnUsing(function (string $q) use ($realParser, &$callCount) {
                $callCount++;
                return $realParser->parse($q);
            });
        $this->app->instance(\App\Services\ReviewCardBrowserSearchParser::class, $mock);
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

    private function makeLog(
        ReviewCard $card,
        string $rating,
        int $daysAgo,
        ?string $source = null,
        ?\DateTimeInterface $undoneAt = null,
    ): void {
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
            'source' => $source ?? ($rating === 'reset' ? 'reset' : 'sense_review'),
            'undone_at' => $undoneAt,
        ]);
    }
}
