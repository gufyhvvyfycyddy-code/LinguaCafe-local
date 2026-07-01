<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\User;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReaderFsrsHighlightTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private Chapter $chapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'FSRS Highlight Test',
            'email' => '__VG_EMAIL_fsrs_highlight__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other User',
            'email' => '__VG_EMAIL_other__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $book = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'FSRS Highlight Book',
            'language' => 'english',
        ]);

        $processedWords = [
            (object) ['word' => 'Phenomenology', 'lemma' => 'phenomenology', 'pos' => 'NOUN', 'sentence_index' => 0, 'phrase_ids' => []],
            (object) ['word' => 'is', 'lemma' => 'be', 'pos' => 'AUX', 'sentence_index' => 0, 'phrase_ids' => []],
            (object) ['word' => 'a', 'lemma' => 'a', 'pos' => 'DET', 'sentence_index' => 0, 'phrase_ids' => []],
            (object) ['word' => 'philosophical', 'lemma' => 'philosophical', 'pos' => 'ADJ', 'sentence_index' => 0, 'phrase_ids' => []],
            (object) ['word' => 'tradition', 'lemma' => 'tradition', 'pos' => 'NOUN', 'sentence_index' => 0, 'phrase_ids' => []],
        ];

        $this->chapter = Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'name' => 'FSRS Highlight Chapter',
            'language' => 'english',
            'raw_text' => "Phenomenology is a philosophical tradition.",
            'word_count' => 5,
            'read_count' => 0,
            'unique_words' => '["phenomenology"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode($processedWords), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }

    private function createEncounteredWord(string $word, string $lemma, int $stage): EncounteredWord
    {
        $ew = EncounteredWord::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'word' => $word,
            'lemma' => $lemma,
            'stage' => $stage,
            'lookup_count' => 0,
            'read_count' => 0,
            'kanji' => '',
            'reading' => '',
            'base_word' => '',
            'study_base' => '',
            'base_word' => '',
            'base_word_reading' => '',
            'translation' => '',
            'relearning' => false,
        ]);
        return $ew;
    }

    private function createWordSense(EncounteredWord $ew, string $status = WordSense::STATUS_CONFIRMED): WordSense
    {
        return WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 1,
            'lemma' => $ew->lemma,
            'surface_form' => $ew->word,
            'encountered_word_id' => $ew->id,
            'sense_zh' => '测试',
            'status' => $status,
            'sense_key' => hash('sha256', strtolower("english|{$ew->lemma}|noun|测试")),
        ]);
    }

    private function createReviewCard(WordSense $ws, float $stability, ?Carbon $dueAt = null, string $state = 'review'): ReviewCard
    {
        return ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 1,
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $ws->id,
            'fsrs_state' => $state,
            'fsrs_due_at' => $dueAt ?? Carbon::now()->addDay(),
            'fsrs_stability' => $stability,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 1,
            'fsrs_lapses' => 0,
        ]);
    }

    private function loadReader(string $endpoint = '/chapters/get/reader'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson($endpoint, [
            'chapterId' => $this->chapter->id,
        ]);
    }

    // ── Tests ────────────────────────────────────

    public function test_reader_returns_fsrs_familiarity_for_learning_word(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', 'phenomenology', -1);
        $ws = $this->createWordSense($ew);
        $this->createReviewCard($ws, stability: 10.0, state: 'review');

        $response = $this->loadReader();
        $response->assertOk();

        // Words array has the FSRS-overridden stage
        $words = $response->json('words');
        $phenWord = collect($words)->firstWhere('word', 'Phenomenology');
        $this->assertNotNull($phenWord, 'Phenomenology should be in words array');

        // Stability 10 → score 0.33 → level_10 = ceil(0.33 * 10) = 4 → stage -4
        $this->assertEquals(-4, $phenWord['stage'], 'stage should be FSRS-based (10-tier)');
        $this->assertEquals(4, $phenWord['fsrs_familiarity_level_10'], 'level_10 should be 4');
        $this->assertEquals(40, $phenWord['fsrs_familiarity_percent'], 'percent should be 40');
        $this->assertNotNull($phenWord['fsrs_familiarity_score']);

        // uniqueWords has the raw EncounteredWord stage + FSRS familiarity fields
        $uniqueWords = $response->json('uniqueWords');
        $phen = collect($uniqueWords)->firstWhere('word', 'phenomenology');
        $this->assertNotNull($phen, 'phenomenology should be in uniqueWords');
        $this->assertEquals(-1, $phen['stage'], 'stage is raw EncounteredWord data');
        $this->assertEquals(4, $phen['fsrs_familiarity_level_10'], 'uniqueWord should have FSRS level_10');
        $this->assertEquals(40, $phen['fsrs_familiarity_percent'], 'uniqueWord should have FSRS percent');
        $this->assertTrue($phen['fsrs_familiarity_has_data'], 'uniqueWord should mark has_data');
    }

    public function test_fsrs_familiarity_level_10_range(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', 'phenomenology', -1);
        $ws = $this->createWordSense($ew);
        $this->createReviewCard($ws, stability: 0.1, state: 'review');

        $response = $this->loadReader();
        $response->assertOk();
        $word = collect($response->json('words'))->firstWhere('word', 'Phenomenology');
        $this->assertGreaterThanOrEqual(1, $word['fsrs_familiarity_level_10']);
        $this->assertLessThanOrEqual(10, $word['fsrs_familiarity_level_10']);
        $this->assertGreaterThanOrEqual(10, $word['fsrs_familiarity_percent']);
        $this->assertLessThanOrEqual(100, $word['fsrs_familiarity_percent']);
    }

    public function test_high_stability_cards_get_higher_highlight_level(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', 'phenomenology', -7);
        $ws = $this->createWordSense($ew);
        $this->createReviewCard($ws, stability: 40.0, state: 'review', dueAt: Carbon::now()->addDays(30));

        $response = $this->loadReader();
        $response->assertOk();

        $phen = collect($response->json('words'))->firstWhere('word', 'Phenomenology');
        $this->assertEquals(-10, $phen['stage']); // highest level (10)
        $this->assertEquals(10, $phen['fsrs_familiarity_level_10']);
    }

    public function test_low_stability_cards_get_lower_highlight_level(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', 'phenomenology', -7);
        $ws = $this->createWordSense($ew);
        $this->createReviewCard($ws, stability: 0.5, state: 'review');

        $response = $this->loadReader();
        $response->assertOk();

        $phen = collect($response->json('words'))->firstWhere('word', 'Phenomenology');
        // Stability 0.5 → score = 0.017 → level_10 = ceil(0.017 * 10) = 1 → stage -1
        $this->assertEquals(-1, $phen['stage']);
        $this->assertEquals(1, $phen['fsrs_familiarity_level_10']);
    }

    public function test_overdue_cards_get_penalty(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', 'phenomenology', -7);
        $ws = $this->createWordSense($ew);
        // Stability 10 → score 0.33 → level_10 4, but overdue → level_10 3
        $this->createReviewCard($ws, stability: 10.0, dueAt: Carbon::now()->subDay());

        $response = $this->loadReader();
        $response->assertOk();

        $phen = collect($response->json('words'))->firstWhere('word', 'Phenomenology');
        $this->assertEquals(-3, $phen['stage']); // penalized by 1 → level_10 = 3
        $this->assertEquals(3, $phen['fsrs_familiarity_level_10']);
    }

    public function test_new_state_cards_get_level_1(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', 'phenomenology', -7);
        $ws = $this->createWordSense($ew);
        $this->createReviewCard($ws, stability: 0, state: 'new');

        $response = $this->loadReader();
        $response->assertOk();

        $phen = collect($response->json('words'))->firstWhere('word', 'Phenomenology');
        $this->assertEquals(-1, $phen['stage']); // new → level 1
    }

    public function test_word_without_word_sense_uses_old_stage(): void
    {
        // Has EncounteredWord with old SRS stage, but no WordSense → keep old stage
        $ew = $this->createEncounteredWord('phenomenology', 'phenomenology', -7);
        // No WordSense created

        $response = $this->loadReader();
        $response->assertOk();

        $phen = collect($response->json('words'))->firstWhere('word', 'Phenomenology');
        // Old SRS -7 should be kept (no FSRS override since no WordSense)
        $this->assertEquals(-7, $phen['stage']);
    }

    public function test_word_without_review_card_keeps_old_stage(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', 'phenomenology', -7);
        $ws = $this->createWordSense($ew);
        // No ReviewCard created — keep old SRS stage since there's no FSRS data

        $response = $this->loadReader();
        $response->assertOk();

        $phen = collect($response->json('words'))->firstWhere('word', 'Phenomenology');
        $this->assertEquals(-7, $phen['stage']); // unchanged (no FSRS card to compute from)
    }

    public function test_new_word_stage_2_is_not_affected(): void
    {
        $this->createEncounteredWord('phenomenology', 'phenomenology', 2);

        $response = $this->loadReader();
        $response->assertOk();

        $phen = collect($response->json('words'))->firstWhere('word', 'Phenomenology');
        $this->assertEquals(2, $phen['stage']); // unchanged
    }

    public function test_known_word_stage_0_is_not_affected(): void
    {
        $this->createEncounteredWord('phenomenology', 'phenomenology', 0);

        $response = $this->loadReader();
        $response->assertOk();

        $phen = collect($response->json('words'))->firstWhere('word', 'Phenomenology');
        $this->assertEquals(0, $phen['stage']); // unchanged
    }

    public function test_word_sense_without_review_card_keeps_old_stage(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', 'phenomenology', -7);
        $this->createWordSense($ew);
        // WordSense exists but no ReviewCard

        $response = $this->loadReader();
        $response->assertOk();

        $phen = collect($response->json('words'))->firstWhere('word', 'Phenomenology');
        $this->assertEquals(-7, $phen['stage']); // unchanged — no FSRS card
        $this->assertArrayNotHasKey('fsrs_familiarity_score', $phen);
    }

    public function test_reader_does_not_create_review_log(): void
    {
        $originalLogCount = \App\Models\ReviewLog::count();
        $this->createEncounteredWord('phenomenology', 'phenomenology', -7);

        $this->loadReader();

        $this->assertEquals($originalLogCount, \App\Models\ReviewLog::count());
    }

    public function test_reader_does_not_modify_encountered_word_stage(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', 'phenomenology', -7);

        $this->loadReader();

        $ew->refresh();
        $this->assertEquals(-7, $ew->stage); // unchanged in DB
    }

    public function test_reader_does_not_modify_review_card_due_at(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', 'phenomenology', -7);
        $ws = $this->createWordSense($ew);
        $rc = $this->createReviewCard($ws, stability: 10.0, dueAt: Carbon::now()->addDay());
        $originalDue = $rc->fsrs_due_at;

        $this->loadReader();

        $rc->refresh();
        $this->assertEquals($originalDue, $rc->fsrs_due_at);
    }

    public function test_words_array_contains_fsrs_familiarity_fields(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', 'phenomenology', -1);
        $ws = $this->createWordSense($ew);
        $this->createReviewCard($ws, stability: 10.0, state: 'review');

        $response = $this->loadReader();
        $response->assertOk();

        $words = $response->json('words');
        $phen = collect($words)->firstWhere('word', 'Phenomenology');
        $this->assertArrayHasKey('fsrs_familiarity_score', $phen);
        $this->assertArrayHasKey('fsrs_familiarity_level_10', $phen);
        $this->assertArrayHasKey('fsrs_familiarity_percent', $phen);
        $this->assertIsFloat($phen['fsrs_familiarity_score']);
        $this->assertIsInt($phen['fsrs_familiarity_level_10']);
        $this->assertEquals($phen['fsrs_familiarity_level_10'] * 10, $phen['fsrs_familiarity_percent']);
    }

    public function test_language_isolation(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', 'phenomenology', -7);
        $ws = $this->createWordSense($ew);
        $this->createReviewCard($ws, stability: 10.0);

        // Other user's chapter with the same word — should not see the FSRS mapping
        $otherBook = Book::forceCreate([
            'user_id' => $this->otherUser->id,
            'name' => 'Other Book',
            'language' => 'english',
        ]);
        Chapter::forceCreate([
            'user_id' => $this->otherUser->id,
            'book_id' => $otherBook->id,
            'name' => 'Other Chapter',
            'language' => 'english',
            'raw_text' => 'Phenomenology is a philosophical tradition.',
            'word_count' => 5,
            'read_count' => 0,
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);

        $response = $this->actingAs($this->otherUser)->postJson('/chapters/get/reader', [
            'chapterId' => $this->chapter->id,
        ]);
        $response->assertStatus(500); // chapter belongs to the test user
    }

    public function test_ai_reading_assist_endpoints_are_untouched(): void
    {
        $response = $this->actingAs($this->user)->postJson('/chapters/ai-assist/source', ['chapterId' => $this->chapter->id]);
        $response->assertOk();
        $this->assertStringContainsString('ARTICLE_TEXT_START', $response->json('prompt'));
    }

    // ==================== Reader Data Output Structure Characterization ====================

    public function test_reader_data_contains_core_top_level_fields(): void
    {
        $response = $this->loadReader();
        $response->assertOk();

        $data = $response->json();
        $this->assertArrayHasKey('type', $data);
        $this->assertArrayHasKey('subtitleTimestamps', $data);
        $this->assertArrayHasKey('words', $data);
        $this->assertArrayHasKey('uniqueWords', $data);
        $this->assertArrayHasKey('phrases', $data);
        $this->assertArrayHasKey('bookName', $data);
        $this->assertArrayHasKey('chapterId', $data);
        $this->assertArrayHasKey('chapterName', $data);
        $this->assertArrayHasKey('bookId', $data);
        $this->assertArrayHasKey('language', $data);
        $this->assertArrayHasKey('languageSpaces', $data);
        $this->assertArrayHasKey('chapters', $data);
        $this->assertArrayHasKey('wordCount', $data);
    }

    public function test_reader_words_object_has_expected_fields(): void
    {
        $this->createEncounteredWord('phenomenology', 'phenomenology', -1);
        $response = $this->loadReader();
        $response->assertOk();

        $words = $response->json('words');
        $word = $words[0] ?? [];
        $this->assertNotEmpty($word, 'words array should not be empty');

        // Core word fields (from tokenizer output)
        $this->assertArrayHasKey('word', $word);
        $this->assertArrayHasKey('lemma', $word);
        $this->assertArrayHasKey('pos', $word);
        $this->assertArrayHasKey('sentence_index', $word);
        $this->assertArrayHasKey('phrase_ids', $word);

        // Reader-dedicated fields (set by prepareTextForReader)
        $this->assertArrayHasKey('id', $word);
        $this->assertArrayHasKey('stage', $word);
        $this->assertArrayHasKey('lookup_count', $word);
        $this->assertArrayHasKey('furigana', $word);
        $this->assertArrayHasKey('selected', $word);
        $this->assertArrayHasKey('hover', $word);
        $this->assertArrayHasKey('spaceAfter', $word);
        $this->assertArrayHasKey('phraseStage', $word);
        $this->assertArrayHasKey('phraseStart', $word);
        $this->assertArrayHasKey('phraseEnd', $word);
        $this->assertArrayHasKey('phraseIndexes', $word);
        $this->assertArrayHasKey('subtitleIndex', $word);
    }

    public function test_reader_unique_words_has_expected_fields(): void
    {
        $this->createEncounteredWord('phenomenology', 'phenomenology', -1);
        $response = $this->loadReader();
        $response->assertOk();

        $uniqueWords = $response->json('uniqueWords');
        $uw = $uniqueWords[0] ?? [];
        $this->assertNotEmpty($uw, 'uniqueWords array should not be empty');

        $this->assertArrayHasKey('id', $uw);
        $this->assertArrayHasKey('word', $uw);
        $this->assertArrayHasKey('stage', $uw);
        $this->assertArrayHasKey('lookup_count', $uw);
        $this->assertArrayHasKey('definitions_checked', $uw);
    }

    public function test_reader_does_not_create_review_cards_or_senses(): void
    {
        $originalReviewCount = ReviewCard::count();
        $originalSenseCount = WordSense::count();
        $originalEncounteredCount = EncounteredWord::count();

        $this->createEncounteredWord('phenomenology', 'phenomenology', -1);
        $this->loadReader();

        // Reader should not create any review cards or word senses
        $this->assertEquals($originalEncounteredCount + 1, EncounteredWord::count(), 'one encountered word created in test setup');
        $this->assertEquals($originalReviewCount, ReviewCard::count(), 'reader should not create review cards');
        $this->assertEquals($originalSenseCount, WordSense::count(), 'reader should not create word senses');
    }

    public function test_reader_does_not_modify_chapters(): void
    {
        $originalChapterCount = Chapter::count();
        $originalName = $this->chapter->name;

        $this->loadReader();

        $this->assertEquals($originalChapterCount, Chapter::count());
        $this->chapter->refresh();
        $this->assertEquals($originalName, $this->chapter->name);
    }

    public function test_archived_card_behavior(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', 'phenomenology', -7);
        $ws = $this->createWordSense($ew);
        $rc = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 1,
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $ws->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->addDay(),
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 1,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => false, // archived
        ]);

        $response = $this->loadReader();
        $response->assertOk();

        // Archived cards should still contribute FSRS familiarity (loadFsrsFamiliarityLookup
        // does NOT filter by fsrs_enabled — it only checks target_type, status, stage < 0)
        $phen = collect($response->json('words'))->firstWhere('word', 'Phenomenology');
        $this->assertNotNull($phen['fsrs_familiarity_score'] ?? null,
            'archived card should still have familiarity score (loadFsrsFamiliarityLookup does not exclude disabled)');
    }

    public function test_legacy_word_card_does_not_affect_fsrs_familiarity(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', 'phenomenology', -7);
        $ws = $this->createWordSense($ew);
        // Create a TARGET_WORD (legacy) card instead of TARGET_SENSE
        ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 1,
            'language' => 'english',
            'target_type' => 'word', // NOT TARGET_SENSE
            'target_id' => $ew->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->addDay(),
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 1,
            'fsrs_lapses' => 0,
        ]);

        $response = $this->loadReader();
        $response->assertOk();

        // Legacy word card should be excluded from FSRS familiarity lookups
        $phen = collect($response->json('words'))->firstWhere('word', 'Phenomenology');
        $this->assertEquals(-7, $phen['stage'], 'legacy word card should not affect FSRS familiarity');
        $this->assertArrayNotHasKey('fsrs_familiarity_score', $phen);
    }

    // ==================== Direct TextBlockService Contract Tests ====================

    public function test_textblock_get_reader_data_returns_stdclass_with_core_properties(): void
    {
        $processedWords = [
            (object) ['word' => 'direct', 'lemma' => 'direct', 'pos' => 'ADJ', 'sentence_index' => 0, 'phrase_ids' => []],
        ];

        $tbs = new \App\Services\TextBlockService($this->user->id, 'english');
        $tbs->setProcessedWords($processedWords);
        $tbs->collectUniqueWords();
        $tbs->prepareTextForReader();

        $data = $tbs->getReaderData();

        $this->assertTrue(property_exists($data, 'words'), 'getReaderData must have words');
        $this->assertTrue(property_exists($data, 'uniqueWords'), 'getReaderData must have uniqueWords');
        $this->assertTrue(property_exists($data, 'phrases'), 'getReaderData must have phrases');
        $this->assertIsArray($data->words);
        $this->assertIsArray($data->uniqueWords);
        $this->assertIsArray($data->phrases);
    }

    public function test_textblock_prepare_text_for_reader_is_read_only(): void
    {
        $originalReviewCount = ReviewCard::count();
        $originalSenseCount = WordSense::count();
        $originalEncounteredCount = EncounteredWord::count();

        $processedWords = [
            (object) ['word' => 'test', 'lemma' => 'test', 'pos' => 'NOUN', 'sentence_index' => 0, 'phrase_ids' => []],
        ];

        $tbs = new \App\Services\TextBlockService($this->user->id, 'english');
        $tbs->setProcessedWords($processedWords);
        $tbs->collectUniqueWords();
        $tbs->prepareTextForReader();

        // prepareTextForReader should not create/modify any DB records
        $this->assertEquals($originalReviewCount, ReviewCard::count());
        $this->assertEquals($originalSenseCount, WordSense::count());
        $this->assertEquals($originalEncounteredCount, EncounteredWord::count());
    }
}
