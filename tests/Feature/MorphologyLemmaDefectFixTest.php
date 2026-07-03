<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
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
 * Real-tokenizer morphology lemma defect fix regression.
 *
 * This test class was added by GLM-MorphologyLemmaDefectFix-1 to lock in the
 * fixes for the OpenCode-RealClickFinalAudit-3 defects:
 *   - `technologies` previously showed lemma `technologies` (expected `technology`)
 *   - `watches` previously showed lemma `watches` (expected `watch`)
 *
 * Root cause: those audits ran while the Python tokenizer was down, so the
 * PHP fallback (which conservatively kept the surface form) was used. The
 * fallback has since been enhanced with ECDICT-gated -ies and ultra-safe
 * -ches/-shes/-xes/-zes rules (see {@see TextBlockFallbackTokenizerTest}).
 *
 * This class proves the PRIMARY path (real Python spaCy tokenizer) produces
 * correct lemmas for:
 *   1. The -ies / -es morphology matrix (technologies, watches, stories,
 *      bodies, studies, fixes, boxes, goes).
 *   2. The adjectival vs verbal ambiguity split:
 *      - "a published author"  → published (ADJ) — user judgment preserved
 *      - "was published"       → publish  (VERB) — verb lemma preferred
 *      - "a broken window"     → broken   (ADJ) — user judgment preserved
 *      - "has broken"          → break    (VERB) — verb lemma preferred
 *      - "a used car"          → use      (VERB) — verb lemma (spaCy tags VERB)
 *      - "was used"            → use      (VERB) — verb lemma preferred
 *      - "the left side"       → left     (ADJ) — user judgment preserved
 *      - "left the room"       → leave    (VERB) — verb lemma preferred
 *
 * All assertions use real `ChapterService::processChapterText()` output. The
 * test is skipped when the Python tokenizer is unavailable, so it never
 * silently falls back to the PHP fallback. It never writes ReviewLog,
 * ReviewCard, or WordSense, and never touches FSRS.
 */
class MorphologyLemmaDefectFixTest extends TestCase
{
    use RefreshDatabase;

    private const ARTICLE_RAW_TEXT = 'The boxes and technologies changed quickly. The stories and bodies were studied. The robot goes and watches the screen. He fixes the watches. The note was published yesterday. The published author spoke. The glass has broken. The broken window was dark. The car was used daily. The used car stood there. He left the room. The left side was dark.';

    private const ARTICLE_MARKER = 'GLM Morphology Lemma Defect Fix 20260703';

    private User $user;
    private ChapterService $chapterService;
    private WordSenseKnownSenseService $knownSenseService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => self::ARTICLE_MARKER,
            'email' => 'glm-morph-fix-' . Str::random(8) . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->chapterService = app(ChapterService::class);
        $this->knownSenseService = app(WordSenseKnownSenseService::class);
    }

    /**
     * Real spaCy tokenizer lemma/POS for each morphology target word in the
     * defect-fix article. Values come from the actual Python tokenizer
     * (en_core_web_sm + LemmInflect) at 127.0.0.1:8678, captured via the
     * /tokenizer endpoint. They are NOT hand-crafted.
     *
     * Sentence indices (si) correspond to ARTICLE_RAW_TEXT sentences:
     *   0: The boxes and technologies changed quickly.
     *   1: The stories and bodies were studied.
     *   2: The robot goes and watches the screen.
     *   3: He fixes the watches.
     *   4: The note was published yesterday.
     *   5: The published author spoke.
     *   6: The glass has broken.
     *   7: The broken window was dark.
     *   8: The car was used daily.
     *   9: The used car stood there.
     *  10: He left the room.
     *  11: The left side was dark.
     *
     * @return list<array{category: string, surface: string, lemma: string, pos: string, si: int}>
     */
    private function defectFixMatrix(): array
    {
        return [
            // -ies / -es morphology matrix
            ['category' => 'regular plural (-es)',  'surface' => 'boxes',        'lemma' => 'box',        'pos' => 'NOUN', 'si' => 0],
            ['category' => 'regular plural (-ies)', 'surface' => 'technologies', 'lemma' => 'technology', 'pos' => 'NOUN', 'si' => 0],
            ['category' => 'regular plural (-ies)', 'surface' => 'stories',      'lemma' => 'story',      'pos' => 'NOUN', 'si' => 1],
            ['category' => 'regular plural (-ies)', 'surface' => 'bodies',       'lemma' => 'body',       'pos' => 'NOUN', 'si' => 1],
            ['category' => 'verb past (-ied)',      'surface' => 'studied',      'lemma' => 'study',      'pos' => 'VERB', 'si' => 1],
            ['category' => '3rd person (-oes)',     'surface' => 'goes',         'lemma' => 'go',         'pos' => 'VERB', 'si' => 2],
            ['category' => '3rd person (-es)',      'surface' => 'watches',      'lemma' => 'watch',      'pos' => 'VERB', 'si' => 2],
            ['category' => '3rd person (-es)',      'surface' => 'fixes',        'lemma' => 'fix',        'pos' => 'VERB', 'si' => 3],
            ['category' => 'regular plural (-es)',  'surface' => 'watches',      'lemma' => 'watch',      'pos' => 'NOUN', 'si' => 3],

            // Ambiguity split: verbal vs adjectival
            ['category' => 'past participle (verbal)',     'surface' => 'published', 'lemma' => 'publish', 'pos' => 'VERB', 'si' => 4],
            ['category' => 'adjectival ambiguity',          'surface' => 'published', 'lemma' => 'publish', 'pos' => 'ADJ',  'si' => 5],
            ['category' => 'past participle (verbal)',     'surface' => 'broken',    'lemma' => 'break',   'pos' => 'VERB', 'si' => 6],
            ['category' => 'adjectival ambiguity',          'surface' => 'broken',    'lemma' => 'broken',  'pos' => 'ADJ',  'si' => 7],
            ['category' => 'past participle (verbal)',     'surface' => 'used',       'lemma' => 'use',     'pos' => 'VERB', 'si' => 8],
            ['category' => 'adjectival ambiguity',          'surface' => 'used',       'lemma' => 'use',     'pos' => 'VERB', 'si' => 9],
            ['category' => 'past tense (verbal)',           'surface' => 'left',       'lemma' => 'leave',   'pos' => 'VERB', 'si' => 10],
            ['category' => 'adjectival ambiguity',          'surface' => 'left',       'lemma' => 'left',    'pos' => 'ADJ',  'si' => 11],
        ];
    }

    public function test_real_tokenizer_lemmatizes_ies_and_es_morphology_correctly(): void
    {
        $this->requireRealPythonTokenizer();

        $chapter = $this->importArticleChapter();
        $tokens = $this->tokensBySurfaceAndSentence($chapter);

        foreach ($this->defectFixMatrix() as $case) {
            $key = $case['surface'] . '@' . $case['si'];
            $this->assertArrayHasKey($key, $tokens, "surface {$case['surface']} at sentence {$case['si']} missing");
            $token = $tokens[$key];

            $this->assertSame($case['surface'], $token->word, "{$case['category']} surface");
            $this->assertSame($case['lemma'],   $token->lemma, "{$case['category']} lemma ({$case['surface']}@{$case['si']})");
            $this->assertSame($case['pos'],     $token->pos, "{$case['category']} pos ({$case['surface']}@{$case['si']})");
            $this->assertSame($case['si'],      $token->sentence_index, "{$case['category']} sentence_index");
        }
    }

    public function test_real_tokenizer_technologies_lemmatizes_to_technology_not_surface(): void
    {
        $this->requireRealPythonTokenizer();

        $chapter = $this->importArticleChapter();
        $tokens = $this->tokensBySurfaceAndSentence($chapter);

        $this->assertArrayHasKey('technologies@0', $tokens);
        $this->assertSame('technology', $tokens['technologies@0']->lemma, 'technologies must lemmatize to technology, not the surface form');
        $this->assertNotSame('technologies', $tokens['technologies@0']->lemma);
    }

    public function test_real_tokenizer_watches_lemmatizes_to_watch_in_both_verb_and_noun_contexts(): void
    {
        $this->requireRealPythonTokenizer();

        $chapter = $this->importArticleChapter();
        $tokens = $this->tokensBySurfaceAndSentence($chapter);

        // Verb context: "the robot goes and watches the screen" (si=2)
        $this->assertArrayHasKey('watches@2', $tokens);
        $this->assertSame('watch', $tokens['watches@2']->lemma, 'verb watches must lemmatize to watch');
        $this->assertSame('VERB', $tokens['watches@2']->pos);

        // Noun context: "He fixes the watches" (si=3)
        $this->assertArrayHasKey('watches@3', $tokens);
        $this->assertSame('watch', $tokens['watches@3']->lemma, 'noun watches must lemmatize to watch');
        $this->assertSame('NOUN', $tokens['watches@3']->pos);
    }

    public function test_real_tokenizer_distinguishes_verbal_and_adjectival_ambiguity_for_published_broken_used_left(): void
    {
        $this->requireRealPythonTokenizer();

        $chapter = $this->importArticleChapter();
        $tokens = $this->tokensBySurfaceAndSentence($chapter);

        // published: verbal "was published" → publish (VERB); adjectival "published author" → publish (ADJ)
        $this->assertSame('publish', $tokens['published@4']->lemma, 'verbal published must lemmatize to publish');
        $this->assertSame('VERB', $tokens['published@4']->pos);
        $this->assertSame('publish', $tokens['published@5']->lemma, 'adjectival published lemma');
        $this->assertSame('ADJ', $tokens['published@5']->pos, 'adjectival published must be tagged ADJ');

        // broken: verbal "has broken" → break (VERB); adjectival "broken window" → broken (ADJ)
        $this->assertSame('break', $tokens['broken@6']->lemma, 'verbal broken must lemmatize to break');
        $this->assertSame('VERB', $tokens['broken@6']->pos);
        $this->assertSame('broken', $tokens['broken@7']->lemma, 'adjectival broken keeps surface as lemma');
        $this->assertSame('ADJ', $tokens['broken@7']->pos, 'adjectival broken must be tagged ADJ');

        // used: both verbal "was used" and adjectival "used car" → use (VERB) per spaCy
        $this->assertSame('use', $tokens['used@8']->lemma, 'verbal used must lemmatize to use');
        $this->assertSame('VERB', $tokens['used@8']->pos);
        $this->assertSame('use', $tokens['used@9']->lemma, 'adjectival used lemma');
        $this->assertSame('VERB', $tokens['used@9']->pos);

        // left: verbal "left the room" → leave (VERB); adjectival "left side" → left (ADJ)
        $this->assertSame('leave', $tokens['left@10']->lemma, 'verbal left must lemmatize to leave');
        $this->assertSame('VERB', $tokens['left@10']->pos);
        $this->assertSame('left', $tokens['left@11']->lemma, 'adjectival left keeps surface as lemma');
        $this->assertSame('ADJ', $tokens['left@11']->pos, 'adjectival left must be tagged ADJ');
    }

    public function test_ambiguous_forms_remain_user_judgment_and_do_not_write_review_data(): void
    {
        $this->requireRealPythonTokenizer();

        $chapter = $this->importArticleChapter();
        $tokens = $this->tokensBySurfaceAndSentence($chapter);

        $ambiguousLemmas = ['publish', 'break', 'broken', 'use', 'leave', 'left'];

        foreach ($ambiguousLemmas as $lemma) {
            $reviewLogBefore = ReviewLog::count();
            $reviewCardBefore = ReviewCard::count();
            $wordSenseBefore = WordSense::count();

            $payload = $this->knownSenseService->knownSenseLookupPayload($this->user->id, 'english', $lemma);

            $this->assertTrue($payload['read_only'], "$lemma lookup must be read-only");
            $this->assertFalse($payload['has_confirmed_senses'], "$lemma has no confirmed sense yet");
            $this->assertFalse($payload['known_sense_new_meaning_hint'], "$lemma must not auto-trigger known-sense-new-meaning hint");
            $this->assertSame($reviewLogBefore, ReviewLog::count(), "$lemma must not write ReviewLog");
            $this->assertSame($reviewCardBefore, ReviewCard::count(), "$lemma must not create ReviewCard");
            $this->assertSame($wordSenseBefore, WordSense::count(), "$lemma must not auto-create WordSense");
        }
    }

    public function test_real_import_does_not_write_review_log_or_review_card_or_wordsense(): void
    {
        $this->requireRealPythonTokenizer();

        $reviewLogBefore = ReviewLog::count();
        $reviewCardBefore = ReviewCard::count();
        $wordSenseBefore = WordSense::count();

        $this->importArticleChapter();

        $this->assertSame($reviewLogBefore, ReviewLog::count(), 'real import must not write ReviewLog');
        $this->assertSame($reviewCardBefore, ReviewCard::count(), 'real import must not create ReviewCard');
        $this->assertSame($wordSenseBefore, WordSense::count(), 'real import must not create WordSense');
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

    /**
     * Index tokens by "surface@sentence_index". The article intentionally
     * repeats surfaces (published@4 vs published@5, watches@2 vs watches@3,
     * etc.) so the sentence index is required to disambiguate.
     *
     * @return array<string, \stdClass>
     */
    private function tokensBySurfaceAndSentence(Chapter $chapter): array
    {
        $byKey = [];
        foreach ($chapter->getProcessedText() as $token) {
            $key = $token->word . '@' . $token->sentence_index;
            $byKey[$key] = $token;
        }
        return $byKey;
    }

    private function requireRealPythonTokenizer(): void
    {
        $available = false;
        try {
            $response = Http::timeout(3)->get('http://127.0.0.1:8678/tokenizer/health');
            $available = $response->ok() && ($response->json('en_core_web_sm_loaded') ?? false);
        } catch (\Throwable $e) {
            $available = false;
        }

        if (!$available) {
            $this->markTestSkipped(
                'Real Python tokenizer (127.0.0.1:8678) is required for the morphology '
                . 'lemma defect fix regression. Start it with: python tools/tokenizer.py'
            );
        }
    }
}
