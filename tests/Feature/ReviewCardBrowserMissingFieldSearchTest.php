<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewCardBrowserMissingFieldSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->makeUser('phase8g');
    }

    public function test_missing_tokens_match_the_existing_top_level_filters(): void
    {
        $this->makeCard([
            'lemma' => 'missing-definition',
            'sense_zh' => '',
            'sense_en' => null,
        ]);
        $this->makeCard([
            'lemma' => 'missing-example',
            'example_sentence_en' => null,
        ]);
        $this->makeCard([
            'lemma' => 'missing-source',
            'source_chapter_id' => null,
        ]);
        $chapter = $this->makeChapter($this->makeBook());
        $this->makeCard([
            'lemma' => 'complete-card',
            'source_chapter_id' => $chapter->id,
        ]);

        foreach ([
            'definition' => 'missing_definition',
            'example' => 'missing_example',
            'source' => 'missing_source',
        ] as $field => $filter) {
            $tokenResponse = $this->search($this->token($field));
            $filterResponse = $this->actingAs($this->user)
                ->getJson('/review-cards/manage/data?filter=' . $filter . '&per_page=100');

            $tokenResponse->assertStatus(200);
            $filterResponse->assertStatus(200);
            $this->assertSame(
                $this->sortedIds($filterResponse->json('items')),
                $this->sortedIds($tokenResponse->json('items')),
                'Token and top-level filter must share the same membership for ' . $field
            );
        }
    }

    public function test_definition_and_example_tokens_use_the_frozen_field_meanings(): void
    {
        $bothMissing = $this->makeCard([
            'lemma' => 'both-definition-fields-missing',
            'sense_zh' => '',
            'sense_en' => '',
        ]);
        $chinesePresent = $this->makeCard([
            'lemma' => 'chinese-definition-present',
            'sense_zh' => '定义',
            'sense_en' => '',
        ]);
        $englishPresent = $this->makeCard([
            'lemma' => 'english-definition-present',
            'sense_zh' => '',
            'sense_en' => 'definition',
        ]);
        $englishExampleMissing = $this->makeCard([
            'lemma' => 'english-example-missing',
            'example_sentence_en' => null,
            'example_sentence_zh' => '只有中文例句。',
        ]);
        $englishExamplePresent = $this->makeCard([
            'lemma' => 'english-example-present',
            'example_sentence_en' => 'Stored English example.',
            'example_sentence_zh' => null,
        ]);

        $definition = $this->search($this->token('definition'));
        $example = $this->search($this->token('example'));

        $definition->assertStatus(200);
        $example->assertStatus(200);
        $definitionIds = $this->sortedIds($definition->json('items'));
        $exampleIds = $this->sortedIds($example->json('items'));

        $this->assertContains($bothMissing->id, $definitionIds);
        $this->assertNotContains($chinesePresent->id, $definitionIds);
        $this->assertNotContains($englishPresent->id, $definitionIds);
        $this->assertContains($englishExampleMissing->id, $exampleIds);
        $this->assertNotContains($englishExamplePresent->id, $exampleIds);
    }

    public function test_missing_source_excludes_direct_and_bound_sources_but_not_other_occurrence_states(): void
    {
        $book = $this->makeBook();
        $chapter = $this->makeChapter($book);

        $missing = $this->makeCard(['lemma' => 'no-source']);
        $direct = $this->makeCard([
            'lemma' => 'direct-source',
            'source_chapter_id' => $chapter->id,
        ]);
        $bound = $this->makeCard(['lemma' => 'bound-source']);
        $this->makeOccurrence($bound->sense, $chapter);

        $stillMissing = [];
        foreach ([
            WordSenseOccurrence::STATUS_PENDING,
            WordSenseOccurrence::STATUS_REJECTED,
            WordSenseOccurrence::STATUS_IGNORED,
        ] as $status) {
            $card = $this->makeCard(['lemma' => 'non-bound-' . $status]);
            $this->makeOccurrence($card->sense, $chapter, ['status' => $status]);
            $stillMissing[] = $card->id;
        }

        $chapterless = $this->makeCard(['lemma' => 'chapterless-bound']);
        $this->makeOccurrence($chapterless->sense, null);
        $stillMissing[] = $chapterless->id;

        $response = $this->search($this->token('source'));
        $response->assertStatus(200);
        $ids = $this->sortedIds($response->json('items'));

        $this->assertContains($missing->id, $ids);
        foreach ($stillMissing as $cardId) {
            $this->assertContains($cardId, $ids);
        }
        $this->assertNotContains($direct->id, $ids);
        $this->assertNotContains($bound->id, $ids);
    }

    public function test_distinct_missing_tokens_use_and_semantics_and_combine_with_existing_tokens(): void
    {
        $matching = $this->makeCard([
            'lemma' => 'phase8g-match',
            'sense_zh' => '',
            'sense_en' => '',
            'example_sentence_en' => null,
            'fsrs_state' => 'review',
            'fsrs_lapses' => 3,
        ]);
        $onlyDefinition = $this->makeCard([
            'lemma' => 'phase8g-definition-only',
            'sense_zh' => '',
            'sense_en' => '',
            'example_sentence_en' => 'Present.',
            'fsrs_state' => 'review',
            'fsrs_lapses' => 3,
        ]);
        $onlyExample = $this->makeCard([
            'lemma' => 'phase8g-example-only',
            'sense_zh' => '有释义',
            'sense_en' => '',
            'example_sentence_en' => null,
            'fsrs_state' => 'review',
            'fsrs_lapses' => 3,
        ]);

        $query = implode(' ', [
            'phase8g',
            $this->token('definition'),
            $this->token('example'),
            $this->advancedToken('state', 'review'),
            $this->advancedToken('prop', 'lapses>=2'),
        ]);
        $response = $this->search($query);

        $response->assertStatus(200);
        $this->assertSame([$matching->id], $this->sortedIds($response->json('items')));
        $this->assertNotContains($onlyDefinition->id, $this->sortedIds($response->json('items')));
        $this->assertNotContains($onlyExample->id, $this->sortedIds($response->json('items')));
    }

    public function test_missing_search_preserves_user_language_confirmed_sense_and_sense_only_isolation(): void
    {
        $matching = $this->makeCard([
            'lemma' => 'isolated-current',
            'sense_zh' => '',
            'sense_en' => '',
        ]);

        $otherUser = $this->makeUser('phase8g-other');
        $this->makeCard([
            'lemma' => 'isolated-other-user',
            'sense_zh' => '',
            'sense_en' => '',
        ], $otherUser);
        $this->makeCard([
            'lemma' => 'isolated-other-language',
            'sense_zh' => '',
            'sense_en' => '',
        ], $this->user, 'french');
        $this->makeCard([
            'lemma' => 'isolated-rejected',
            'sense_zh' => '',
            'sense_en' => '',
            'sense_status' => WordSense::STATUS_REJECTED,
        ]);
        $this->makeCard([
            'lemma' => 'isolated-legacy-word-card',
            'sense_zh' => '',
            'sense_en' => '',
            'target_type' => ReviewCard::TARGET_WORD,
        ]);

        $response = $this->search($this->token('definition'));
        $response->assertStatus(200);
        $this->assertSame([$matching->id], $this->sortedIds($response->json('items')));
    }

    public function test_missing_results_match_list_and_all_export_consumers_for_all_lifecycle_states(): void
    {
        $suspended = $this->makeCard([
            'lemma' => 'phase8g-suspended-missing-source',
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
        ]);
        $archived = $this->makeCard([
            'lemma' => 'phase8g-archived-missing-source',
            'lifecycle_state' => ReviewCard::LIFECYCLE_ARCHIVED,
        ]);
        $chapter = $this->makeChapter($this->makeBook());
        $this->makeCard([
            'lemma' => 'phase8g-has-source',
            'source_chapter_id' => $chapter->id,
        ]);

        $query = urlencode($this->token('source'));
        $base = '/review-cards/manage/';
        $list = $this->actingAs($this->user)->getJson($base . 'data?filter=all&per_page=50&q=' . $query);
        $json = $this->actingAs($this->user)->getJson($base . 'export?filter=all&q=' . $query);
        $csv = $this->actingAs($this->user)->get($base . 'export-csv?filter=all&q=' . $query);
        $tsv = $this->actingAs($this->user)->get($base . 'export-anki-tsv?filter=all&q=' . $query);

        $list->assertStatus(200);
        $json->assertStatus(200);
        $csv->assertStatus(200);
        $tsv->assertStatus(200);
        $expected = [$suspended->id, $archived->id];
        sort($expected);
        $this->assertSame($expected, $this->sortedIds($list->json('items')));
        $this->assertSame($expected, $this->sortedIds($json->json('items')));
        $this->assertSame('2', $csv->headers->get('X-Export-Count'));
        $this->assertSame('2', $tsv->headers->get('X-Export-Count'));
        $this->assertStringContainsString('phase8g-suspended-missing-source', $csv->getContent());
        $this->assertStringContainsString('phase8g-archived-missing-source', $tsv->getContent());
    }

    public function test_invalid_missing_grammar_returns_the_existing_structured_422(): void
    {
        $response = $this->search($this->token('unknown'));

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'invalid_browser_search');
        $response->assertJsonStructure([
            'message',
            'code',
            'errors' => [['token', 'reason', 'example']],
        ]);
    }

    public function test_missing_search_is_read_only_and_uses_constant_query_shape(): void
    {
        for ($index = 0; $index < 5; $index++) {
            $this->makeCard([
                'lemma' => 'phase8g-readonly-' . $index,
                'sense_zh' => '',
                'sense_en' => '',
            ]);
        }

        $before = [
            ReviewCard::count(),
            ReviewLog::count(),
            WordSense::count(),
            WordSenseOccurrence::count(),
        ];

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->search($this->token('definition'));
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('items'));
        $this->assertLessThan(25, $queryCount);
        $this->assertSame($before, [
            ReviewCard::count(),
            ReviewLog::count(),
            WordSense::count(),
            WordSenseOccurrence::count(),
        ]);
    }

    private function search(string $query)
    {
        return $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&per_page=100&q=' . urlencode($query));
    }

    private function sortedIds(array $items): array
    {
        $ids = array_map('intval', array_column($items, 'review_card_id'));
        sort($ids);
        return $ids;
    }

    private function token(string $value): string
    {
        return $this->advancedToken('missing', $value);
    }

    private function advancedToken(string $prefix, string $value): string
    {
        return sprintf('%s%c%s', $prefix, 58, $value);
    }

    private function makeUser(string $prefix): User
    {
        return User::forceCreate([
            'name' => $prefix,
            'email' => $prefix . '-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function makeBook(?User $user = null, string $language = 'english'): Book
    {
        $user ??= $this->user;
        return Book::forceCreate([
            'user_id' => $user->id,
            'name' => 'Book-' . Str::random(8),
            'language' => $language,
        ]);
    }

    private function makeChapter(Book $book, ?User $user = null, ?string $language = null): Chapter
    {
        $user ??= $this->user;
        $language ??= $book->language;
        return Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'name' => 'Chapter-' . Str::random(8),
            'language' => $language,
            'raw_text' => 'Missing field source chapter.',
            'word_count' => 4,
            'read_count' => 0,
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }

    private function makeCard(
        array $overrides = [],
        ?User $user = null,
        string $language = 'english',
    ): ReviewCard {
        $user ??= $this->user;
        $senseStatus = $overrides['sense_status'] ?? WordSense::STATUS_CONFIRMED;
        $targetType = $overrides['target_type'] ?? ReviewCard::TARGET_SENSE;
        unset($overrides['sense_status'], $overrides['target_type']);

        $senseFields = [
            'lemma',
            'surface_form',
            'pos',
            'sense_zh',
            'sense_en',
            'example_sentence_en',
            'example_sentence_zh',
            'source_chapter_id',
        ];
        $senseOverrides = [];
        foreach ($senseFields as $field) {
            if (array_key_exists($field, $overrides)) {
                $senseOverrides[$field] = $overrides[$field];
                unset($overrides[$field]);
            }
        }

        $lemma = $senseOverrides['lemma'] ?? 'missing-' . Str::random(8);
        $sense = WordSense::forceCreate(array_merge([
            'user_id' => $user->id,
            'language' => $language,
            'language_id' => $language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => '释义',
            'sense_en' => 'definition',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'A stored English example.',
            'example_sentence_zh' => '一个保存的中文例句。',
            'source_chapter_id' => null,
            'status' => $senseStatus,
            'sense_key' => hash('sha256', Str::random(20)),
        ], $senseOverrides));

        return ReviewCard::forceCreate(array_merge([
            'user_id' => $user->id,
            'language' => $language,
            'language_id' => $language,
            'target_type' => $targetType,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
            'fsrs_reps' => 1,
            'fsrs_lapses' => 0,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ], $overrides));
    }

    private function makeOccurrence(
        WordSense $sense,
        ?Chapter $chapter,
        array $overrides = [],
    ): WordSenseOccurrence {
        return WordSenseOccurrence::forceCreate(array_merge([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter?->id,
            'sentence_id' => (string) Str::uuid(),
            'sentence_en' => 'Occurrence sentence.',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => $sense->surface_form,
            'lemma' => $sense->lemma,
            'decision' => 'accept',
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
        ], $overrides));
    }
}
