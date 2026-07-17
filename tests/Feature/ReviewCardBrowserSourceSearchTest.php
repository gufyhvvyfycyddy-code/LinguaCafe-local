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

class ReviewCardBrowserSourceSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->makeUser('phase8e');
    }

    public function test_chapter_source_matches_direct_and_bound_paths_without_duplicates(): void
    {
        $chapter = $this->makeChapter($this->makeBook());
        $direct = $this->makeCard();
        $direct->sense->update(['source_chapter_id' => $chapter->id]);
        $bound = $this->makeCard();
        $this->makeOccurrence($bound->sense, $chapter);
        $both = $this->makeCard();
        $both->sense->update(['source_chapter_id' => $chapter->id]);
        $this->makeOccurrence($both->sense, $chapter, ['sentence_id' => '2']);
        $excluded = $this->makeCard();

        $response = $this->search($this->sourceToken('chapter', $chapter->id));

        $response->assertStatus(200);
        $ids = array_column($response->json('items'), 'review_card_id');
        sort($ids);
        $expected = [$direct->id, $bound->id, $both->id];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertNotContains($excluded->id, $ids);
    }

    public function test_chapter_source_ignores_non_bound_occurrences_and_uses_and_semantics(): void
    {
        $book = $this->makeBook();
        $first = $this->makeChapter($book);
        $second = $this->makeChapter($book);

        $matching = $this->makeCard();
        $matching->sense->update(['source_chapter_id' => $first->id]);
        $this->makeOccurrence($matching->sense, $second);

        $onlyFirst = $this->makeCard();
        $onlyFirst->sense->update(['source_chapter_id' => $first->id]);

        foreach ([
            WordSenseOccurrence::STATUS_PENDING,
            WordSenseOccurrence::STATUS_REJECTED,
            WordSenseOccurrence::STATUS_IGNORED,
        ] as $status) {
            $card = $this->makeCard();
            $this->makeOccurrence($card->sense, $second, ['status' => $status]);
        }

        $response = $this->search(implode(' ', [
            $this->sourceToken('chapter', $first->id),
            $this->sourceToken('chapter', $second->id),
        ]));

        $response->assertStatus(200);
        $this->assertSame([$matching->id], array_column($response->json('items'), 'review_card_id'));
        $this->assertNotContains($onlyFirst->id, array_column($response->json('items'), 'review_card_id'));
    }

    public function test_book_source_matches_owned_paths_and_fails_closed_for_foreign_or_other_language_ids(): void
    {
        $book = $this->makeBook();
        $chapter = $this->makeChapter($book);
        $direct = $this->makeCard();
        $direct->sense->update(['source_chapter_id' => $chapter->id]);
        $bound = $this->makeCard();
        $this->makeOccurrence($bound->sense, $chapter);

        $otherUser = $this->makeUser('phase8e-other');
        $foreignBook = $this->makeBook($otherUser);
        $foreignChapter = $this->makeChapter($foreignBook, $otherUser);
        $foreignPointer = $this->makeCard();
        $foreignPointer->sense->update(['source_chapter_id' => $foreignChapter->id]);

        $frenchBook = $this->makeBook($this->user, 'french');
        $frenchChapter = $this->makeChapter($frenchBook, $this->user, 'french');
        $languagePointer = $this->makeCard();
        $languagePointer->sense->update(['source_chapter_id' => $frenchChapter->id]);

        $owned = $this->search($this->sourceToken('book', $book->id));
        $owned->assertStatus(200);
        $ids = array_column($owned->json('items'), 'review_card_id');
        sort($ids);
        $expected = [$direct->id, $bound->id];
        sort($expected);
        $this->assertSame($expected, $ids);

        foreach ([$foreignBook->id, $frenchBook->id, 999999] as $bookId) {
            $response = $this->search($this->sourceToken('book', $bookId));
            $response->assertStatus(200);
            $this->assertSame([], array_column($response->json('items'), 'review_card_id'));
        }

        foreach ([$foreignChapter->id, $frenchChapter->id, 999999] as $chapterId) {
            $response = $this->search($this->sourceToken('chapter', $chapterId));
            $response->assertStatus(200);
            $this->assertSame([], array_column($response->json('items'), 'review_card_id'));
        }

        $this->assertNotSame($foreignPointer->id, $languagePointer->id);
    }

    public function test_source_results_preserve_filter_all_and_match_all_export_consumers(): void
    {
        $chapter = $this->makeChapter($this->makeBook());
        $suspended = $this->makeCard(['lifecycle_state' => 'suspended']);
        $suspended->sense->update([
            'source_chapter_id' => $chapter->id,
            'lemma' => 'phase8e-source-suspended',
        ]);
        $archived = $this->makeCard(['lifecycle_state' => 'archived']);
        $archived->sense->update(['lemma' => 'phase8e-source-archived']);
        $this->makeOccurrence($archived->sense, $chapter);

        $query = urlencode($this->sourceToken('chapter', $chapter->id));
        $base = '/review-cards/manage/';
        $list = $this->actingAs($this->user)->getJson($base . 'data?filter=all&per_page=50&q=' . $query);
        $json = $this->actingAs($this->user)->getJson($base . 'export?filter=all&q=' . $query);
        $csv = $this->actingAs($this->user)->get($base . 'export-csv?filter=all&q=' . $query);
        $tsv = $this->actingAs($this->user)->get($base . 'export-anki-tsv?filter=all&q=' . $query);

        $list->assertStatus(200);
        $json->assertStatus(200);
        $csv->assertStatus(200);
        $tsv->assertStatus(200);
        $this->assertCount(2, $list->json('items'));
        $this->assertCount(2, $json->json('items'));
        $this->assertSame('2', $csv->headers->get('X-Export-Count'));
        $this->assertSame('2', $tsv->headers->get('X-Export-Count'));
        $this->assertStringContainsString('phase8e-source-suspended', $csv->getContent());
        $this->assertStringContainsString('phase8e-source-archived', $tsv->getContent());
    }

    public function test_source_search_is_read_only_and_uses_a_constant_query_shape(): void
    {
        $chapter = $this->makeChapter($this->makeBook());
        for ($index = 0; $index < 5; $index++) {
            $card = $this->makeCard();
            $this->makeOccurrence($card->sense, $chapter, ['sentence_id' => (string) ($index + 1)]);
        }

        $before = [
            ReviewCard::count(),
            ReviewLog::count(),
            WordSense::count(),
            WordSenseOccurrence::count(),
        ];

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->search($this->sourceToken('chapter', $chapter->id));
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
            ->getJson('/review-cards/manage/data?filter=all&per_page=50&q=' . urlencode($query));
    }

    private function sourceToken(string $kind, int $id): string
    {
        return implode(chr(58), ['source', $kind, (string) $id]);
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
            'raw_text' => 'Source chapter.',
            'word_count' => 2,
            'read_count' => 0,
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }

    private function makeCard(array $overrides = []): ReviewCard
    {
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'source-' . Str::random(8),
            'surface_form' => 'source',
            'pos' => 'noun',
            'sense_zh' => '来源',
            'sense_en' => 'source',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'A source sentence.',
            'status' => WordSense::STATUS_CONFIRMED,
            'sense_key' => hash('sha256', Str::random(20)),
        ]);

        return ReviewCard::forceCreate(array_merge([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
            'fsrs_reps' => 1,
            'fsrs_lapses' => 0,
            'lifecycle_state' => 'active',
        ], $overrides));
    }

    private function makeOccurrence(WordSense $sense, Chapter $chapter, array $overrides = []): WordSenseOccurrence
    {
        return WordSenseOccurrence::forceCreate(array_merge([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => '1',
            'sentence_en' => 'A source sentence.',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => $sense->surface_form,
            'lemma' => $sense->lemma,
            'decision' => 'accept',
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
        ], $overrides));
    }
}
