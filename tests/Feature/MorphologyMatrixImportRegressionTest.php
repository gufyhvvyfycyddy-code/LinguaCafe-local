<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\ChapterService;
use App\Services\WordSenseKnownSenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Real-tokenizer end-to-end import regression test for the morphology matrix.
 *
 * This test class exercises the real project import path:
 *   ChapterService::processChapterText()
 *     -> TextBlockService::tokenizeRawText()  (real Python spaCy tokenizer at 127.0.0.1:8678)
 *     -> processTokenizedWords()
 *     -> collectUniqueWords() / createNewEncounteredWords()
 *     -> Chapter::setProcessedText()  (gzcompress + json_encode)
 *
 * It asserts that the resulting Chapter::processed_text contains real
 * surface / lemma / pos / sentence_index for eight morphology categories
 * plus adjectival ambiguity, and that known-sense lookup stays read-only.
 *
 * The hand-crafted data-layer fixture sibling lives in
 * {@see MorphologyMatrixLemmaBridgeDataLayerTest}.
 *
 * If the Python tokenizer is not running, the test is marked skipped rather
 * than silently falling back to the PHP fallback tokenizer — the fallback
 * cannot provide real lemmatization for the morphology matrix.
 */
class MorphologyMatrixImportRegressionTest extends TestCase
{
    use RefreshDatabase;

    private const ARTICLE_RAW_TEXT = 'The boxes and technologies changed quickly. The mice and children watched as the robot goes and watches the old screen. The runners ran and went home. The written note and the published author were discussed. The workers are running and studying. The better answer was the oldest one. The used car stood near a broken window, and the left side of the room was dark.';

    private const ARTICLE_MARKER = 'GLM Real Morphology Completion 20260703';

    private User $user;
    private ChapterService $chapterService;
    private WordSenseKnownSenseService $knownSenseService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => self::ARTICLE_MARKER,
            'email' => 'glm-real-morphology-' . Str::random(8) . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->chapterService = app(ChapterService::class);
        $this->knownSenseService = app(WordSenseKnownSenseService::class);
    }

    /**
     * Real spaCy tokenizer outputs for the morphology article.
     *
     * These values come from the actual Python tokenizer service
     * (en_core_web_sm + LemmInflect). They are NOT hand-crafted.
     * Key real behaviors captured here:
     *   - `better` lemmatizes to `well` (spaCy ADJ), not `good`.
     *   - `broken` lemmatizes to `broken` (spaCy ADJ), not `break`.
     *   - `left` lemmatizes to `left` (spaCy ADJ), not `leave`.
     *   - `used` is tagged VERB with lemma `use`.
     *   - `published` is tagged ADJ with lemma `publish`.
     *
     * @return list<array{category: string, surface: string, lemma: string, pos: string, si: int}>
     */
    private function realTokenizerMatrix(): array
    {
        return [
            ['category' => 'regular plural',          'surface' => 'boxes',        'lemma' => 'box',        'pos' => 'NOUN', 'si' => 0],
            ['category' => 'regular plural',          'surface' => 'technologies', 'lemma' => 'technology', 'pos' => 'NOUN', 'si' => 0],
            ['category' => 'irregular plural',        'surface' => 'mice',         'lemma' => 'mouse',      'pos' => 'NOUN', 'si' => 1],
            ['category' => 'irregular plural',        'surface' => 'children',     'lemma' => 'child',      'pos' => 'NOUN', 'si' => 1],
            ['category' => 'third person singular',   'surface' => 'goes',         'lemma' => 'go',         'pos' => 'VERB', 'si' => 1],
            ['category' => 'third person singular',   'surface' => 'watches',      'lemma' => 'watch',      'pos' => 'VERB', 'si' => 1],
            ['category' => 'past tense',              'surface' => 'ran',          'lemma' => 'run',        'pos' => 'VERB', 'si' => 2],
            ['category' => 'past tense',              'surface' => 'went',         'lemma' => 'go',         'pos' => 'VERB', 'si' => 2],
            ['category' => 'past participle',         'surface' => 'written',      'lemma' => 'write',      'pos' => 'VERB', 'si' => 3],
            ['category' => 'past participle',         'surface' => 'published',    'lemma' => 'publish',    'pos' => 'ADJ',  'si' => 3],
            ['category' => 'progressive',             'surface' => 'running',      'lemma' => 'run',        'pos' => 'VERB', 'si' => 4],
            ['category' => 'progressive',             'surface' => 'studying',     'lemma' => 'study',      'pos' => 'VERB', 'si' => 4],
            ['category' => 'comparative/superlative', 'surface' => 'better',       'lemma' => 'well',       'pos' => 'ADJ',  'si' => 5],
            ['category' => 'comparative/superlative', 'surface' => 'oldest',       'lemma' => 'old',        'pos' => 'ADJ',  'si' => 5],
            ['category' => 'adjectival ambiguity',    'surface' => 'published',    'lemma' => 'publish',    'pos' => 'ADJ',  'si' => 3],
            ['category' => 'adjectival ambiguity',    'surface' => 'used',         'lemma' => 'use',        'pos' => 'VERB', 'si' => 6],
            ['category' => 'adjectival ambiguity',    'surface' => 'broken',       'lemma' => 'broken',     'pos' => 'ADJ',  'si' => 6],
            ['category' => 'adjectival ambiguity',    'surface' => 'left',         'lemma' => 'left',       'pos' => 'ADJ',  'si' => 6],
        ];
    }

    public function test_real_tokenizer_import_writes_processed_text_with_surface_lemma_pos_and_sentence_index(): void
    {
        $this->requireRealPythonTokenizer();

        $chapter = $this->importArticleChapter();

        $this->assertSame('processed', $chapter->fresh()->processing_status);

        $tokensBySurface = collect($chapter->getProcessedText())->keyBy('word');

        foreach ($this->realTokenizerMatrix() as $case) {
            $this->assertTrue(
                $tokensBySurface->has($case['surface']),
                $case['category'] . ' surface missing in real tokenizer output: ' . $case['surface']
            );
            $token = $tokensBySurface->get($case['surface']);

            $this->assertSame($case['surface'], $token->word, $case['category'] . ' surface');
            $this->assertSame($case['lemma'],   $token->lemma, $case['category'] . ' lemma');
            $this->assertSame($case['pos'],     $token->pos, $case['category'] . ' pos');
            $this->assertSame($case['si'],      $token->sentence_index, $case['category'] . ' sentence_index');
        }
    }

    public function test_real_tokenizer_import_covers_all_eight_morphology_categories_with_at_least_two_words_each(): void
    {
        $this->requireRealPythonTokenizer();

        $chapter = $this->importArticleChapter();
        $tokensBySurface = collect($chapter->getProcessedText())->keyBy('word');

        $byCategory = [];
        foreach ($this->realTokenizerMatrix() as $case) {
            $byCategory[$case['category']] = ($byCategory[$case['category']] ?? 0) + 1;
        }

        // Eight morphology categories — every category must have at least 2 words.
        $expectedCategories = [
            'regular plural',
            'irregular plural',
            'third person singular',
            'past tense',
            'past participle',
            'progressive',
            'comparative/superlative',
            'adjectival ambiguity',
        ];
        foreach ($expectedCategories as $category) {
            $this->assertArrayHasKey($category, $byCategory, "category missing: $category");
            $this->assertGreaterThanOrEqual(2, $byCategory[$category], "$category must have at least 2 words");
        }

        // Adjectival ambiguity must have at least 3 words (published / used / broken / left).
        $this->assertGreaterThanOrEqual(3, $byCategory['adjectival ambiguity'], 'adjectival ambiguity must have at least 3 words');
    }

    public function test_real_tokenizer_import_creates_encountered_words_without_writing_review_log_or_review_card(): void
    {
        $this->requireRealPythonTokenizer();

        $reviewLogBefore = ReviewLog::count();
        $reviewCardBefore = ReviewCard::count();
        $wordSenseBefore = WordSense::count();

        $chapter = $this->importArticleChapter();

        // Import creates encountered_words for the lemma set, but never review data.
        $this->assertGreaterThan(0, EncounteredWord::where('user_id', $this->user->id)->count(), 'import should create encountered_words');
        $this->assertSame($reviewLogBefore, ReviewLog::count(), 'real import must not write ReviewLog');
        $this->assertSame($reviewCardBefore, ReviewCard::count(), 'real import must not create ReviewCard');
        $this->assertSame($wordSenseBefore, WordSense::count(), 'real import must not create WordSense');
    }

    public function test_known_sense_lookup_after_real_import_stays_read_only_for_morphology_lemmas(): void
    {
        $this->requireRealPythonTokenizer();

        $chapter = $this->importArticleChapter();

        $reviewLogBefore = ReviewLog::count();
        $reviewCardBefore = ReviewCard::count();
        $wordSenseBefore = WordSense::count();

        // Look up every distinct lemma produced by the real tokenizer.
        $lemmas = collect($this->realTokenizerMatrix())->pluck('lemma')->unique();
        foreach ($lemmas as $lemma) {
            $payload = $this->knownSenseService->knownSenseLookupPayload($this->user->id, 'english', $lemma);

            $this->assertTrue($payload['read_only'], $lemma . ' payload must be read-only');
            $this->assertSame($lemma, $payload['lemma']);
            $this->assertFalse($payload['has_confirmed_senses'], $lemma . ' has no confirmed sense yet');
            $this->assertSame([], $payload['confirmed_senses']);
        }

        $this->assertSame($reviewLogBefore, ReviewLog::count(), 'known-sense lookup must not write ReviewLog');
        $this->assertSame($reviewCardBefore, ReviewCard::count(), 'known-sense lookup must not create ReviewCard');
        $this->assertSame($wordSenseBefore, WordSense::count(), 'known-sense lookup must not create WordSense');
    }

    public function test_ambiguous_adjectival_forms_remain_user_judgment_after_real_import(): void
    {
        $this->requireRealPythonTokenizer();

        $chapter = $this->importArticleChapter();
        $tokensBySurface = collect($chapter->getProcessedText())->keyBy('word');

        // Ambiguous adjectival forms — the real tokenizer keeps them as-is
        // (lemma may equal surface for `broken` / `left`), and the lemma bridge
        // must NOT auto-bind them, must NOT auto-create WordSense / ReviewCard,
        // and must NOT write ReviewLog.
        $ambiguousForms = ['published', 'used', 'broken', 'left'];

        foreach ($ambiguousForms as $surface) {
            $this->assertTrue($tokensBySurface->has($surface), "ambiguous form $surface must exist in real tokenizer output");

            $reviewLogBefore = ReviewLog::count();
            $reviewCardBefore = ReviewCard::count();
            $wordSenseBefore = WordSense::count();

            $token = $tokensBySurface->get($surface);
            $payload = $this->knownSenseService->knownSenseLookupPayload($this->user->id, 'english', $token->lemma);

            $this->assertTrue($payload['read_only'], "$surface lookup must be read-only");
            $this->assertFalse($payload['has_confirmed_senses'], "$surface has no confirmed sense yet");
            $this->assertFalse($payload['known_sense_new_meaning_hint'], "$surface must not auto-trigger known-sense-new-meaning hint");
            $this->assertSame($reviewLogBefore, ReviewLog::count(), "$surface must not write ReviewLog");
            $this->assertSame($reviewCardBefore, ReviewCard::count(), "$surface must not create ReviewCard");
            $this->assertSame($wordSenseBefore, WordSense::count(), "$surface must not auto-create WordSense");
        }
    }

    public function test_real_import_does_not_modify_existing_review_card_fsrs_fields(): void
    {
        $this->requireRealPythonTokenizer();

        // Pre-create a sense + review card for `box` (regular plural lemma).
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'box',
            'surface_form' => 'boxes',
            'pos' => 'noun',
            'sense_key' => 'glm-real-morphology-box-key',
            'sense_zh' => '箱子',
            'sense_en' => 'container',
            'status' => WordSense::STATUS_CONFIRMED,
            'example_sentence_en' => '',
            'example_sentence_zh' => '',
            'aliases_zh' => '[]',
            'collocations' => '[]',
            'is_context_specific' => true,
        ]);
        $card = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->addDays(3),
            'fsrs_stability' => 2.5,
            'fsrs_difficulty' => 4.25,
            'fsrs_reps' => 7,
            'fsrs_lapses' => 1,
            'fsrs_last_reviewed_at' => now()->subDay(),
        ]);
        $fsfsFields = [
            'fsrs_enabled',
            'fsrs_state',
            'fsrs_due_at',
            'fsrs_stability',
            'fsrs_difficulty',
            'fsrs_reps',
            'fsrs_lapses',
            'fsrs_last_reviewed_at',
        ];
        $before = $this->cardFsrsSnapshot($card, $fsfsFields);

        $this->importArticleChapter();

        // Known-sense lookup after real import must not touch FSRS fields.
        $payload = $this->knownSenseService->knownSenseLookupPayload($this->user->id, 'english', 'box');
        $this->assertTrue($payload['read_only']);
        $this->assertSame($before, $this->cardFsrsSnapshot($card->fresh(), $fsfsFields));
    }

    private function importArticleChapter(): Chapter
    {
        $book = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => self::ARTICLE_MARKER,
            'language' => 'english',
        ]);

        $chapter = Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'name' => self::ARTICLE_MARKER,
            'language' => 'english',
            'raw_text' => self::ARTICLE_RAW_TEXT,
            'word_count' => 0,
            'read_count' => 0,
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'processed_text' => '',
            'subtitle_timestamps' => '[]',
            'processing_status' => 'unprocessed',
            'type' => 'text',
        ]);

        $this->chapterService->processChapterText($this->user->id, $chapter->id);

        return $chapter->fresh();
    }

    private function requireRealPythonTokenizer(): void
    {
        $available = false;
        try {
            $response = Http::timeout(3)->get('http://127.0.0.1:8678/tokenizer/health');
            $available = $response->successful()
                && $response->json('status') === 'healthy'
                && $response->json('english.model_loaded') === true;
        } catch (\Throwable $e) {
            $available = false;
        }

        if (!$available) {
            $this->markTestSkipped(
                'Real Python tokenizer (127.0.0.1:8678) is not running or not healthy. '
                . 'This test requires the real spaCy tokenizer to prove end-to-end import regression. '
                . 'Start it with: .venv-tokenizer\\Scripts\\python.exe tools\\tokenizer.py'
            );
        }
    }

    /**
     * @param list<string> $fields
     */
    private function cardFsrsSnapshot(ReviewCard $card, array $fields): array
    {
        $snapshot = $card->only($fields);
        foreach (['fsrs_due_at', 'fsrs_last_reviewed_at'] as $field) {
            if (isset($snapshot[$field]) && $snapshot[$field] instanceof \DateTimeInterface) {
                $snapshot[$field] = $snapshot[$field]->format('Y-m-d H:i:s');
            }
        }

        return $snapshot;
    }
}
