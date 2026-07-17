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

class ReviewCardBrowserQuotedPhraseNegationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->makeUser('phase8k');
    }

    public function test_positive_quoted_phrases_match_contiguous_text_with_global_and_semantics(): void
    {
        $matching = $this->makeCard([
            'sense_en' => 'Please take charge now.',
            'example_sentence_en' => 'Accept responsibility today.',
        ]);
        $gap = $this->makeCard([
            'sense_en' => 'Please take full charge now.',
            'example_sentence_en' => 'Accept responsibility today.',
        ]);
        $missingSecondPhrase = $this->makeCard([
            'sense_en' => 'Please take charge now.',
            'example_sentence_en' => 'Avoid the task.',
        ]);

        $response = $this->actingAs($this->user)->getJson(
            '/review-cards/manage/data?filter=all&per_page=50&q=' . urlencode('"take charge" "responsibility today"')
        );

        $response->assertOk();
        $this->assertSame([$matching->id], array_column($response->json('items'), 'review_card_id'));
        $this->assertNotContains($gap->id, array_column($response->json('items'), 'review_card_id'));
        $this->assertNotContains($missingSecondPhrase->id, array_column($response->json('items'), 'review_card_id'));
    }

    public function test_negative_plain_and_phrase_predicates_are_null_safe_across_all_searchable_fields(): void
    {
        $matching = $this->makeCard([
            'lemma' => 'accept',
            'surface_form' => 'accepted',
            'sense_zh' => '承担',
            'sense_en' => null,
            'example_sentence_en' => null,
        ]);
        $plainExcluded = $this->makeCard(['lemma' => 'burdened']);
        $phraseExcluded = $this->makeCard(['example_sentence_en' => 'They avoid responsibility whenever possible.']);

        $response = $this->actingAs($this->user)->getJson(
            '/review-cards/manage/data?filter=all&per_page=50&q=' . urlencode('-burden -"avoid responsibility"')
        );

        $response->assertOk();
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertContains($matching->id, $cardIds);
        $this->assertNotContains($plainExcluded->id, $cardIds);
        $this->assertNotContains($phraseExcluded->id, $cardIds);
    }

    public function test_new_literal_text_predicates_escape_percent_underscore_escape_character_and_backslash(): void
    {
        $literal = $this->makeCard([
            'sense_en' => 'Use 100%_safe! mode in C:\\temp.',
        ]);
        $wildcardDecoy = $this->makeCard([
            'sense_en' => 'Use 100XXsafeY mode in C:/temp.',
        ]);

        $wildcardResponse = $this->actingAs($this->user)->getJson(
            '/review-cards/manage/data?filter=all&per_page=50&q=' . urlencode('"100%_safe!"')
        );
        $backslashResponse = $this->actingAs($this->user)->getJson(
            '/review-cards/manage/data?filter=all&per_page=50&q=' . urlencode('"C:\\\\temp"')
        );

        $wildcardResponse->assertOk();
        $backslashResponse->assertOk();
        $this->assertSame([$literal->id], array_column($wildcardResponse->json('items'), 'review_card_id'));
        $this->assertSame([$literal->id], array_column($backslashResponse->json('items'), 'review_card_id'));
        $this->assertNotContains($wildcardDecoy->id, array_column($wildcardResponse->json('items'), 'review_card_id'));
    }

    public function test_text_query_phrase_negation_and_existing_tokens_share_all_consumers(): void
    {
        $matching = $this->makeCard(
            [
                'lemma' => 'charge',
                'sense_en' => 'take charge of a task',
                'example_sentence_en' => 'She accepts the duty.',
            ],
            ['fsrs_state' => 'review']
        );
        $wrongNegative = $this->makeCard(
            [
                'lemma' => 'charge',
                'sense_en' => 'take charge of a burden',
            ],
            ['fsrs_state' => 'review']
        );
        $wrongState = $this->makeCard(
            [
                'lemma' => 'charge',
                'sense_en' => 'take charge of a task',
            ],
            ['fsrs_state' => 'new']
        );

        $query = 'charge "take charge" -burden state:review';
        $encoded = urlencode($query);
        $list = $this->actingAs($this->user)->getJson('/review-cards/manage/data?filter=all&per_page=50&q=' . $encoded);
        $json = $this->actingAs($this->user)->getJson('/review-cards/manage/export?filter=all&q=' . $encoded);
        $csv = $this->actingAs($this->user)->get('/review-cards/manage/export-csv?filter=all&q=' . $encoded);
        $tsv = $this->actingAs($this->user)->get('/review-cards/manage/export-anki-tsv?filter=all&q=' . $encoded);

        $list->assertOk();
        $json->assertOk();
        $csv->assertOk();
        $tsv->assertOk();

        $this->assertSame([$matching->id], array_column($list->json('items'), 'review_card_id'));
        $this->assertCount(1, $json->json('items'));
        $this->assertSame('1', $csv->headers->get('X-Export-Count'));
        $this->assertSame('1', $tsv->headers->get('X-Export-Count'));
        $this->assertSame('charge', $list->json('search_meta.text_query'));
        $this->assertSame(['state:review'], $list->json('search_meta.tokens'));
        $this->assertNotContains($wrongNegative->id, array_column($list->json('items'), 'review_card_id'));
        $this->assertNotContains($wrongState->id, array_column($list->json('items'), 'review_card_id'));
    }

    public function test_filter_all_keeps_suspended_and_archived_matches_and_positive_negative_contradiction_is_legal(): void
    {
        $suspended = $this->makeCard(
            ['sense_en' => 'take charge immediately'],
            ['lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED]
        );
        $archived = $this->makeCard(
            ['sense_en' => 'take charge carefully'],
            ['lifecycle_state' => ReviewCard::LIFECYCLE_ARCHIVED]
        );

        $visible = $this->actingAs($this->user)->getJson(
            '/review-cards/manage/data?filter=all&per_page=50&q=' . urlencode('"take charge"')
        );
        $contradiction = $this->actingAs($this->user)->getJson(
            '/review-cards/manage/data?filter=all&per_page=50&q=' . urlencode('"take charge" -"take charge"')
        );

        $visible->assertOk();
        $contradiction->assertOk();
        $this->assertContains($suspended->id, array_column($visible->json('items'), 'review_card_id'));
        $this->assertContains($archived->id, array_column($visible->json('items'), 'review_card_id'));
        $this->assertSame([], $contradiction->json('items'));
    }

    public function test_malformed_or_advanced_token_negation_returns_identical_structured_422_for_all_consumers(): void
    {
        $encoded = urlencode('-state:review');
        $responses = [
            $this->actingAs($this->user)->getJson('/review-cards/manage/data?q=' . $encoded),
            $this->actingAs($this->user)->getJson('/review-cards/manage/export?q=' . $encoded),
            $this->actingAs($this->user)->getJson('/review-cards/manage/export-csv?q=' . $encoded),
            $this->actingAs($this->user)->getJson('/review-cards/manage/export-anki-tsv?q=' . $encoded),
        ];

        foreach ($responses as $response) {
            $response->assertStatus(422);
            $response->assertJsonPath('code', 'invalid_browser_search');
            $response->assertJsonStructure(['message', 'code', 'errors' => [['token', 'reason', 'example']]]);
        }

        $this->assertSame($responses[0]->json(), $responses[1]->json());
        $this->assertSame($responses[0]->json(), $responses[2]->json());
        $this->assertSame($responses[0]->json(), $responses[3]->json());
    }

    public function test_new_predicates_preserve_scope_constant_query_shape_and_zero_business_writes(): void
    {
        $matching = $this->makeCard(['sense_en' => 'take responsibility now']);
        $otherUser = $this->makeUser('phase8k-other');
        $otherUserCard = $this->makeCard(['sense_en' => 'take responsibility now'], [], $otherUser);
        $otherLanguageCard = $this->makeCard(
            ['language' => 'french', 'language_id' => 'french', 'sense_en' => 'take responsibility now'],
            ['language' => 'french', 'language_id' => 'french']
        );
        $legacyWordCard = $this->makeCard(
            ['sense_en' => 'take responsibility now'],
            ['target_type' => ReviewCard::TARGET_WORD]
        );

        for ($i = 0; $i < 8; $i++) {
            $this->makeCard(['sense_en' => 'take responsibility sample ' . $i]);
        }

        $before = [
            'logs' => ReviewLog::count(),
            'cards' => ReviewCard::count(),
            'senses' => WordSense::count(),
            'matching' => $this->cardStateSnapshot($matching->id),
        ];

        DB::enableQueryLog();
        $response = $this->actingAs($this->user)->getJson(
            '/review-cards/manage/data?filter=all&per_page=50&q=' . urlencode('"take responsibility" -forbidden')
        );
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk();
        $cardIds = array_column($response->json('items'), 'review_card_id');
        $this->assertContains($matching->id, $cardIds);
        $this->assertNotContains($otherUserCard->id, $cardIds);
        $this->assertNotContains($otherLanguageCard->id, $cardIds);
        $this->assertNotContains($legacyWordCard->id, $cardIds);
        $this->assertLessThan(20, count($queries));

        $after = [
            'logs' => ReviewLog::count(),
            'cards' => ReviewCard::count(),
            'senses' => WordSense::count(),
            'matching' => $this->cardStateSnapshot($matching->id),
        ];
        $this->assertSame($before, $after);
    }

    private function cardStateSnapshot(int $cardId): array
    {
        $card = ReviewCard::findOrFail($cardId);

        return [
            'fsrs_state' => $card->fsrs_state,
            'fsrs_due_at' => optional($card->fsrs_due_at)->toISOString(),
            'fsrs_stability' => $card->fsrs_stability,
            'fsrs_difficulty' => $card->fsrs_difficulty,
            'fsrs_reps' => $card->fsrs_reps,
            'fsrs_lapses' => $card->fsrs_lapses,
            'lifecycle_state' => $card->lifecycle_state,
            'lifecycle_version' => $card->lifecycle_version,
        ];
    }

    private function makeUser(string $prefix): User
    {
        return User::forceCreate([
            'name' => 'Phase 8K Test',
            'email' => $prefix . '-' . Str::uuid() . '@example.com',
            'password' => \Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function makeCard(
        array $senseOverrides = [],
        array $cardOverrides = [],
        ?User $user = null,
    ): ReviewCard {
        $user ??= $this->user;
        $language = $senseOverrides['language_id'] ?? 'english';
        $lemma = $senseOverrides['lemma'] ?? 'phase8k-' . Str::random(8);

        $sense = WordSense::forceCreate(array_merge([
            'user_id' => $user->id,
            'language' => $language,
            'language_id' => $language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'verb',
            'sense_zh' => '承担',
            'sense_en' => 'accept responsibility',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'They accept responsibility.',
            'example_sentence_zh' => '他们承担责任。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', $user->id . '|' . $language . '|' . $lemma . '|' . Str::uuid()),
        ], $senseOverrides));

        return ReviewCard::forceCreate(array_merge([
            'user_id' => $user->id,
            'language' => $sense->language_id,
            'language_id' => $sense->language_id,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
            'fsrs_stability' => null,
            'fsrs_difficulty' => null,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ], $cardOverrides));
    }
}
