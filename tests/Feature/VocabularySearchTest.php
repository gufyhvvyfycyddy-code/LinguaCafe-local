<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VocabularySearchTest extends TestCase
{
    use RefreshDatabase;

    private \App\Services\VocabularyService $service;
    private \App\Models\User $user;
    private string $language = 'english';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(\App\Services\VocabularyService::class);
        $this->user = \App\Models\User::factory()->create([
            'selected_language' => $this->language,
        ]);
    }

    // ==================== A. searchVocabulary basic structure ====================

    public function test_search_vocabulary_returns_core_structure(): void
    {
        $this->createWord('goose', 'goose', -7);
        $this->createWord('apple', 'apple', 0);

        $result = $this->search('anytext');

        $this->assertIsObject($result);
        $this->assertObjectHasProperty('wordCount', $result);
        $this->assertObjectHasProperty('words', $result);
        $this->assertObjectHasProperty('books', $result);
        $this->assertObjectHasProperty('bookIndex', $result);
        $this->assertObjectHasProperty('pageCount', $result);
        $this->assertObjectHasProperty('currentPage', $result);
        $this->assertObjectHasProperty('languageSpaces', $result);
        $this->assertSame(1, $result->currentPage);
        $this->assertTrue($result->languageSpaces); // english is not in languagesWithoutSpaces
    }

    // ==================== B. text matching word ====================

    public function test_search_by_text_matches_word(): void
    {
        $this->createWord('goose', 'goose', -7);
        $this->createWord('apple', 'apple', 0);

        $result = $this->search('goo');
        $words = collect($result->words);
        $this->assertTrue($words->contains('word', 'goose'));
        $this->assertFalse($words->contains('word', 'apple'));
    }

    // ==================== C. text matching reading ====================

    public function test_search_by_text_matches_reading(): void
    {
        $this->createWord('xword', 'xword', -7, 'specialreading');
        $this->createWord('yapple', 'yapple', 0, 'normread');

        $result = $this->search('special');
        $words = collect($result->words);
        $this->assertTrue($words->contains('word', 'xword'));
        $this->assertFalse($words->contains('word', 'yapple'));
    }

    // ==================== D. stage filter ====================

    public function test_search_filters_by_stage(): void
    {
        $this->createWord('learnword', 'learnword', -7);
        $this->createWord('knownword', 'knownword', 0);
        $this->createWord('ignoreword', 'ignoreword', 1);

        $result = $this->search('anytext', -1, -1, -7);
        $words = collect($result->words);
        $this->assertTrue($words->contains('word', 'learnword'));
        $this->assertFalse($words->contains('word', 'knownword'));
        $this->assertFalse($words->contains('word', 'ignoreword'));
    }

    // ==================== E. translation = not empty ====================

    public function test_search_filters_translation_not_empty(): void
    {
        $this->createWord('hasdef', 'hasdef', -7, '', 'a definition');
        $this->createWord('nodef', 'nodef', -7, '', '');

        $result = $this->search('anytext', -1, -1, -999, 'not empty');
        $words = collect($result->words);
        $this->assertTrue($words->contains('word', 'hasdef'));
        $this->assertFalse($words->contains('word', 'nodef'));
    }

    // ==================== F. only words ====================

    public function test_search_only_words(): void
    {
        $this->createWord('myword', 'myword', -7);
        $this->createPhrase('my phrase', 'my phrase');

        $result = $this->search('anytext', -1, -1, -999, 'any', 'only words');
        $words = collect($result->words);

        $this->assertTrue($words->contains('word', 'myword'));
        $this->assertTrue($words->where('type', 'word')->isNotEmpty());
        $this->assertTrue($words->where('type', 'phrase')->isEmpty());
    }

    // ==================== G. only phrases ====================

    public function test_search_only_phrases(): void
    {
        $this->createWord('myword', 'myword', -7);
        $this->createPhrase('my phrase', 'my phrase');

        $result = $this->search('anytext', -1, -1, -999, 'any', 'only phrases');
        $words = collect($result->words);

        $this->assertTrue($words->where('type', 'phrase')->isNotEmpty());
        $this->assertTrue($words->where('type', 'word')->isEmpty());
        $this->assertTrue($words->where('type', 'word')->isEmpty());
    }

    // ==================== H. words + phrases union ====================

    public function test_search_words_and_phrases_union(): void
    {
        $this->createWord('unionword', 'unionword', -7);
        $this->createPhrase('union phrase', 'union phrase');

        $result = $this->search('anytext', -1, -1, -999, 'any', 'both');
        $words = collect($result->words);

        $this->assertTrue($words->contains('word', 'unionword'));
        $this->assertTrue($words->where('type', 'word')->isNotEmpty(), 'should include words');
        $this->assertTrue($words->where('type', 'phrase')->isNotEmpty(), 'should include phrases');
        foreach ($result->words as $w) {
            $this->assertContains($w->type, ['word', 'phrase']);
        }
    }

    // ==================== I. ordering ====================

    public function test_order_by_words_asc(): void
    {
        $this->createWord('banana', 'banana', -7);
        $this->createWord('apple', 'apple', -7);
        $this->createWord('cherry', 'cherry', -7);

        $result = $this->search('anytext', -1, -1, -999, 'any', 'both', 'words');
        $w = collect($result->words);
        $order = $w->pluck('word')->values()->toArray();
        $sorted = $w->sortBy('word')->values()->pluck('word')->toArray();
        $this->assertSame($sorted, $order);
    }

    public function test_order_by_words_desc(): void
    {
        $this->createWord('banana', 'banana', -7);
        $this->createWord('apple', 'apple', -7);
        $this->createWord('cherry', 'cherry', -7);

        $result = $this->search('anytext', -1, -1, -999, 'any', 'both', 'words desc');
        $w = collect($result->words);
        $order = $w->pluck('word')->values()->toArray();
        $sorted = $w->sortByDesc('word')->values()->pluck('word')->toArray();
        $this->assertSame($sorted, $order);
    }

    public function test_order_by_stage_asc(): void
    {
        $this->createWord('stageA', 'stageA', 0);
        $this->createWord('stageB', 'stageB', -7);
        $this->createWord('stageC', 'stageC', 1);

        $result = $this->search('anytext', -1, -1, -999, 'any', 'both', 'stage');
        $w = collect($result->words);
        $order = $w->pluck('stage')->values()->toArray();
        $sorted = $w->sortBy('stage')->values()->pluck('stage')->toArray();
        $this->assertSame($sorted, $order);
    }

    public function test_order_by_stage_desc(): void
    {
        $this->createWord('stageA', 'stageA', 0);
        $this->createWord('stageB', 'stageB', -7);
        $this->createWord('stageC', 'stageC', 1);

        $result = $this->search('anytext', -1, -1, -999, 'any', 'both', 'stage desc');
        $w = collect($result->words);
        $order = $w->pluck('stage')->values()->toArray();
        $sorted = $w->sortByDesc('stage')->values()->pluck('stage')->toArray();
        $this->assertSame($sorted, $order);
    }

    // ==================== J. pagination ====================

    public function test_pagination_page_1_returns_30_items(): void
    {
        for ($i = 0; $i < 31; $i++) {
            $this->createWord('paginword' . $i, 'paginword' . $i, -7);
        }

        $result = $this->search('anytext', -1, -1, -999, 'any', 'only words', 'words', 1);
        $this->assertCount(30, $result->words);
        $this->assertSame(31, $result->wordCount);
        $this->assertEquals(2, $result->pageCount);
        $this->assertSame(1, $result->currentPage);
    }

    public function test_pagination_page_2_returns_remaining_item(): void
    {
        for ($i = 0; $i < 31; $i++) {
            $this->createWord('paginword' . $i, 'paginword' . $i, -7);
        }

        $result = $this->search('anytext', -1, -1, -999, 'any', 'only words', 'words', 2);
        $this->assertCount(1, $result->words);
        $this->assertSame(31, $result->wordCount);
        $this->assertEquals(2, $result->pageCount);
        $this->assertSame(2, $result->currentPage);
    }

    // ==================== K. exportToCsv ====================

    public function test_export_to_csv_uses_search_query(): void
    {
        $this->createWord('csvword', 'csvword', -7, '', 'csv definition');
        $this->createWord('csvother', 'csvother', 0, '', '');

        $fields = [
            ['export' => true, 'headerName' => 'Word', 'searchObjectProperty' => 'word'],
            ['export' => true, 'headerName' => 'Stage', 'searchObjectProperty' => 'stage'],
            ['export' => true, 'headerName' => 'Translation', 'searchObjectProperty' => 'translation'],
            ['export' => false, 'headerName' => 'Skip', 'searchObjectProperty' => 'id'],
        ];

        $csv = $this->service->exportToCsv($this->user->id, $this->language, 'anytext', -1, -1, -999, 'only words', 'words', 'any', $fields, []);

        $output = $csv->toString();
        $lines = explode("\n", trim($output));

        // Header: Word|Level|Translation (Stage → Level)
        $this->assertStringContainsString('Word|Level|Translation', $lines[0]);
        // Verify csvword is in the output somewhere (order may vary, words_to_skip might affect)
        $this->assertStringContainsString('csvword', $output);
        $this->assertStringContainsString('csv definition', $output);
        // csvother should also be in the output
        $this->assertStringContainsString('csvother', $output);
        // No "Skip" column in output
        $this->assertStringNotContainsString('Skip', $lines[0]);
    }

    // ==================== Helpers ====================

    private function search(
        string $text = 'anytext',
        int $bookId = -1,
        int $chapterId = -1,
        int $stage = -999,
        string $translation = 'any',
        string $phrases = 'both',
        string $orderBy = 'words',
        int $page = 1,
        array $languagesWithoutSpaces = [],
    ): object {
        return $this->service->searchVocabulary(
            $this->user->id,
            $this->language,
            $text,
            $bookId,
            $chapterId,
            $stage,
            $phrases,
            $orderBy,
            $translation,
            $page,
            $languagesWithoutSpaces,
        );
    }

    private function createWord(string $word, string $baseWord, int $stage, string $reading = '', string $translation = ''): \App\Models\EncounteredWord
    {
        return \App\Models\EncounteredWord::forceCreate([
            'user_id' => $this->user->id,
            'language' => $this->language,
            'word' => $word,
            'base_word' => $baseWord,
            'reading' => $reading,
            'base_word_reading' => $reading,
            'stage' => $stage,
            'translation' => $translation,
            'lookup_count' => 0,
            'read_count' => 0,
            'kanji' => '',
            'lemma' => $word,
            'study_base' => $word,
        ]);
    }

    private function createPhrase(string $words, string $wordsSearchable, int $stage = -7, string $reading = '', string $translation = ''): \App\Models\Phrase
    {
        return \App\Models\Phrase::forceCreate([
            'user_id' => $this->user->id,
            'language' => $this->language,
            'words' => json_encode([$words]),
            'words_searchable' => $wordsSearchable,
            'reading' => $reading,
            'stage' => $stage,
            'translation' => $translation,
            'lookup_count' => 0,
        ]);
    }
}
