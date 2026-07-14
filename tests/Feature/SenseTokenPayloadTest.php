<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\SenseTokenPayloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * OpenCode-SenseTokenPayloadContractTests-1
 *
 * Contract tests for SenseTokenPayloadService.
 *
 * Covers syntheticSentenceTokens, flattenProcessedWords, exampleSentenceTokenPayload,
 * tokenMatchesSenseTarget, and the 3-layer fallback chain (occurrence → text match → synthetic).
 */
class SenseTokenPayloadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private SenseTokenPayloadService $service;
    private string $english = 'english';
    private string $spanish = 'spanish';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Token Payload User',
            'email' => 'tokenpayload@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->english,
            'password_changed' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Token Payload Other',
            'email' => 'tokenpayload.other@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->english,
            'password_changed' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->service = app(SenseTokenPayloadService::class);
    }

    // ==================== syntheticSentenceTokens ====================

    public function test_synthetic_tokens_split_ordinary_english_sentence(): void
    {
        $sense = $this->createSense('test', 'test');
        $tokens = $this->service->syntheticSentenceTokens('The quick brown fox jumps.', $sense);

        $this->assertGreaterThan(4, count($tokens), 'syntheticSentenceTokens must split an ordinary sentence.');
        $this->assertSame('The', $tokens[0]['word'], 'first token must be "The".');
        $this->assertSame('fox', $tokens[3]['word'], 'fourth token must be "fox".');
        $this->assertSame('jumps', $tokens[4]['word'], 'fifth token must be "jumps".');
    }

    public function test_synthetic_tokens_preserve_punctuation_and_space_after(): void
    {
        $sense = $this->createSense('test', 'test');
        $tokens = $this->service->syntheticSentenceTokens('Hello, world!', $sense);

        // Tokens: Hello , world !
        $this->assertCount(4, $tokens, 'must produce 4 tokens for "Hello, world!".');
        $this->assertSame('Hello', $tokens[0]['word']);
        $this->assertSame(',', $tokens[1]['word']);
        $this->assertSame('world', $tokens[2]['word']);
        $this->assertSame('!', $tokens[3]['word']);
        // Note: spaceAfter is determined by whether the gap to next token contains spaces.
        // For "Hello, world!": gap after "Hello" is ", " (comma+space) → spaceAfter=true.
        // The "," token's gap to "world" is " " (space) → spaceAfter=true.
        // The "world" token's gap to "!" is "" (no gap) → spaceAfter=false.
        $this->assertTrue($tokens[0]['spaceAfter'], 'Hello must have spaceAfter=true.');
        $this->assertTrue($tokens[1]['spaceAfter'], 'comma must have spaceAfter=true.');
        $this->assertTrue($tokens[2]['spaceAfter'], 'world must have spaceAfter=true (adjacent tokens get spaceAfter=true).');
    }

    public function test_synthetic_target_marked_by_lemma_match(): void
    {
        $sense = $this->createSense('goose', 'geese');
        $tokens = $this->service->syntheticSentenceTokens('I saw a goose yesterday.', $sense);

        // Lemma is 'goose' → the token 'goose' should be target
        $gooseToken = collect($tokens)->firstWhere('word', 'goose');
        $this->assertNotNull($gooseToken, 'token "goose" must exist.');
        $this->assertTrue($gooseToken['is_target'], 'token matching lemma must be marked target.');
        $this->assertSame(-7, $gooseToken['stage'], 'target token must have stage -7.');
    }

    public function test_synthetic_target_marked_by_surface_form_match(): void
    {
        $sense = $this->createSense('technology', 'technologies');
        $tokens = $this->service->syntheticSentenceTokens('Modern technologies evolve.', $sense);

        $technologiesToken = collect($tokens)->firstWhere('word', 'technologies');
        $this->assertNotNull($technologiesToken, 'token "technologies" must exist.');
        $this->assertTrue($technologiesToken['is_target'], 'token matching surface_form must be marked target.');
    }

    public function test_synthetic_target_marked_by_occurrence_surface(): void
    {
        $sense = $this->createSense('go', 'went');
        $occurrence = $this->createOccurrence($sense->id, 'went');
        $tokens = $this->service->syntheticSentenceTokens('He went to school.', $sense, $occurrence);

        $wentToken = collect($tokens)->firstWhere('word', 'went');
        $this->assertNotNull($wentToken, 'token "went" must exist.');
        $this->assertTrue($wentToken['is_target'], 'token matching occurrence surface must be marked target.');
    }

    public function test_synthetic_does_not_mark_unrelated_word_as_target(): void
    {
        $sense = $this->createSense('cat', 'cats');
        $tokens = $this->service->syntheticSentenceTokens('The dog runs quickly.', $sense);

        $targetTokens = array_filter($tokens, fn($t) => $t['is_target']);
        $this->assertCount(0, $targetTokens, 'no token should be target when sense word not in sentence.');
    }

    public function test_synthetic_non_target_tokens_have_default_stage(): void
    {
        $sense = $this->createSense('goose', 'geese');
        $tokens = $this->service->syntheticSentenceTokens('I saw a goose yesterday.', $sense);

        foreach ($tokens as $token) {
            if ($token['is_target']) {
                $this->assertSame(-7, $token['stage'], 'target token must have stage -7.');
            } else {
                $this->assertSame(2, $token['stage'], 'non-target token must have stage 2.');
            }
        }
    }

    // ==================== flattenProcessedWords ====================

    public function test_flatten_simple_words_structure(): void
    {
        $data = (object) ['words' => [
            (object) ['word' => 'Hello', 'stage' => 2],
            (object) ['word' => 'world', 'stage' => 0],
        ]];
        $result = $this->service->flattenProcessedWords($data);

        $this->assertCount(2, $result, 'must flatten 2 words.');
        $this->assertSame('Hello', $result[0]->word);
        $this->assertSame('world', $result[1]->word);
    }

    public function test_flatten_handles_nested_object_mixed_structures(): void
    {
        $data = (object) [
            'paragraphs' => [
                (object) ['sentences' => [
                    (object) ['words' => [
                        (object) ['word' => 'A', 'stage' => 2],
                        (object) ['word' => 'test', 'stage' => 0],
                    ]],
                ]],
            ],
        ];
        $result = $this->service->flattenProcessedWords($data);
        $this->assertCount(2, $result);
        $this->assertSame('A', $result[0]->word);
        $this->assertSame('test', $result[1]->word);
    }

    public function test_flatten_skips_structural_tokens_without_breaking(): void
    {
        $data = (object) ['words' => [
            (object) ['word' => 'Hello', 'stage' => 2],
            (object) ['word' => 'NEWLINE', 'stage' => 0],
            (object) ['word' => 'world', 'stage' => 0],
            (object) ['word' => 'PARAGRAPH_BREAK', 'stage' => 0],
            (object) ['word' => 'end', 'stage' => 0],
        ]];
        $result = $this->service->flattenProcessedWords($data);
        $this->assertCount(5, $result, 'structural tokens are still included at flatten stage.');
        $this->assertSame('NEWLINE', $result[1]->word, 'NEWLINE preserved at flatten stage.');
    }

    public function test_flatten_empty_structure(): void
    {
        $data = (object) ['words' => []];
        $result = $this->service->flattenProcessedWords($data);
        $this->assertCount(0, $result, 'must return empty array for empty input.');

        $result2 = $this->service->flattenProcessedWords([]);
        $this->assertCount(0, $result2, 'must return empty array for empty array.');
    }

    // ==================== exampleSentenceTokenPayload — Layer 1 (occurrence) ====================

    public function test_example_payload_uses_occurrence_real_tokens(): void
    {
        $sense = $this->createSense('newtest', 'newtest');
        $ptData = (object) [
            'words' => [
                $this->pst('This', 2, 0),
                $this->pst('is', 2, 0),
                $this->pst('a', 2, 0),
                $this->pst('newtest', 0, 0),
                $this->pst('sentence', 0, 0),
                $this->pst('.', 0, 0),
            ],
        ];
        $chapter = Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => 0,
            'name' => 'test-chapter-' . \Illuminate\Support\Str::random(6),
            'read_count' => 0,
            'word_count' => 0,
            'language' => $this->english,
            'raw_text' => 'This is a newtest sentence.',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode($ptData), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);

        $occurrence = $this->createOccurrence($sense->id, 'newtest', $chapter->id, 0);

        $result = $this->service->exampleSentenceTokenPayload($sense);

        $this->assertNotEmpty($result['tokens'], 'must resolve real tokens when occurrence has chapter_id and sentence_id.');
        $this->assertSame('occurrence', $result['source'], 'source must be "occurrence".');
    }

    public function test_example_payload_layer2_text_match_when_layer1_fails(): void
    {
        $sense = $this->createSense('matchtest', 'matchtest');
        // Set source_chapter_id but with wrong sentence_id
        $sense->source_chapter_id = 999;
        $sense->sentence_id = 777;
        $sense->example_sentence_en = 'This is a matchtest example.';
        $sense->save();

        // Create a real chapter with matching text
        $ptData = (object) [
            'words' => [
                $this->pst('This', 2, 0), $this->pst('is', 2, 0), $this->pst('a', 2, 0),
                $this->pst('matchtest', 0, 0), $this->pst('example', 0, 0), $this->pst('.', 0, 0),
            ],
        ];
        $chapter = Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => 0,
            'name' => 'matchtest-chapter-' . \Illuminate\Support\Str::random(6),
            'read_count' => 0,
            'word_count' => 0,
            'language' => $this->english,
            'raw_text' => 'This is a matchtest example.',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode($ptData), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);

        // Update sense to point to this chapter id
        $sense->source_chapter_id = $chapter->id;
        $sense->save();

        $result = $this->service->exampleSentenceTokenPayload($sense->fresh());

        $this->assertNotEmpty($result['tokens'], 'must fall back to text match when sentence_id mismatch.');
        $this->assertSame('sentence_text_match', $result['source'], 'source must be "sentence_text_match".');
    }

    public function test_preloaded_chapter_supports_text_match_without_sentence_identity(): void
    {
        $sense = $this->createSense('preloaded', 'preloaded');
        $chapter = $this->createChapter($this->user->id, [
            $this->pt('A', 2),
            $this->pt('preloaded', 0),
            $this->pt('example', 2),
            $this->pt('.', 2),
        ]);

        $result = $this->service->exampleSentenceTokenPayload(
            $sense,
            [
                'chapter_id' => $chapter->id,
                'sentence_id' => null,
                'sentence_hash' => null,
                'sentence_en' => 'A preloaded example.',
                'occurrence_id' => 123,
            ],
            [$chapter->id => $chapter],
        );

        $this->assertSame('sentence_text_match', $result['source']);
        $this->assertSame(['A', 'preloaded', 'example', '.'], array_column($result['tokens'], 'word'));
    }

    public function test_example_payload_layer3_synthetic_when_no_chapter(): void
    {
        $sense = $this->createSense('synth', 'synth');
        $sense->example_sentence_en = 'Purely synthetic test sentence.';
        $sense->save();
        // No chapter, no occurrence with chapter_id

        $result = $this->service->exampleSentenceTokenPayload($sense->fresh());

        $this->assertNotEmpty($result['tokens'], 'must fall back to synthetic when no chapter source.');
        $this->assertSame('synthetic', $result['source'], 'source must be "synthetic".');
    }

    public function test_ownership_prevents_other_users_chapter_text_match(): void
    {
        $sense = $this->createSense('securetest', 'securetest');
        $sense->example_sentence_en = 'Other user secure chapter text.';
        $sense->save();

        // Create a chapter owned by otherUser that contains the exact text
        $otherChapter = Chapter::forceCreate([
            'user_id' => $this->otherUser->id,
            'language' => $this->english,
            'name' => 'Secure Chapter',
            'book_id' => 0,
            'read_count' => 0,
            'word_count' => 0,
            'raw_text' => 'Other user secure chapter text.',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode((object) [
                'words' => [
                    $this->pt('Other', 2), $this->pt('user', 2), $this->pt('secure', 0),
                    $this->pt('chapter', 0), $this->pt('text', 0), $this->pt('.', 0),
                ],
            ]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
        $sense->source_chapter_id = $otherChapter->id;
        $sense->save();

        // The sense belongs to $this->user, not $this->otherUser
        // So the chapter query should filter by the sense's user_id ($this->user)
        // and not find $otherChapter → fallback to synthetic

        $result = $this->service->exampleSentenceTokenPayload($sense->fresh());

        // Should fall back to synthetic because the chapter belongs to otherUser
        $this->assertSame('synthetic', $result['source'],
            'must fall back to synthetic when chapter belongs to other user.');
        $this->assertNotEmpty($result['tokens'], 'must still produce tokens via synthetic fallback.');
    }

    // ==================== sentence_id / sentence_hash matching ====================

    public function test_sentence_id_zero_not_falsy(): void
    {
        $sense = $this->createSense('zerotest', 'zerotest');
        $chapter = $this->createChapter($this->user->id, [$this->pt('zerotest', 0)]);
        $ptData = (object) [
            'words' => [
                $this->pt('Zero', 2), $this->pt('sentence', 2),
                $this->pt('id', 2), $this->pt('here', 0), $this->pt('.', 0),
            ],
        ];
        $chapter->processed_text = gzcompress(json_encode($ptData), 1);
        $chapter->save();

        $occurrence = $this->createOccurrence($sense->id, 'zerotest', $chapter->id, 0);

        $result = $this->service->exampleSentenceTokenPayload($sense);

        // sentence_id=0 must NOT be treated as null/falsy
        $this->assertNotEmpty($result['tokens'], 'sentence_id 0 must be treated as valid, not falsy.');
    }

    // ==================== Helper methods ====================

    private function createSense(string $lemma, string $surface, string $senseZh = '测试释义'): WordSense
    {
        $senseKey = hash('sha256', strtolower("{$this->english}|{$lemma}|noun|{$senseZh}|test"));

        return WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => $this->english,
            'language_id' => $this->english,
            'lemma' => $lemma,
            'surface_form' => $surface,
            'pos' => 'noun',
            'sense_zh' => $senseZh,
            'sense_en' => 'test meaning',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Example sentence with ' . $surface . '.',
            'example_sentence_zh' => '包含' . $surface . '的例句。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => $senseKey,
        ]);
    }

    private function createOccurrence(int $senseId, string $surface, ?int $chapterId = null, ?int $sentenceId = null, int $userId = null): WordSenseOccurrence
    {
        $userId = $userId ?? $this->user->id;

        return WordSenseOccurrence::forceCreate([
            'word_sense_id' => $senseId,
            'user_id' => $userId,
            'language_id' => $this->english,
            'language' => $this->english,
            'lemma' => $surface,
            'surface' => $surface,
            'type' => 'vocabulary',
            'sentence_en' => 'Example sentence with ' . $surface . '.',
            'chapter_id' => $chapterId,
            'sentence_id' => $sentenceId ?? 0,
            'decision' => 'manual',
            'confidence' => 1.0,
            'source' => 'test',
            'auto_fsrs_allowed' => false,
            'status' => WordSenseOccurrence::STATUS_BOUND,
        ]);
    }

    private function createChapter(int $userId, array $tokens): Chapter
    {
        return Chapter::forceCreate([
            'user_id' => $userId,
            'book_id' => 0,
            'name' => 'test-chapter-' . \Illuminate\Support\Str::random(6),
            'read_count' => 0,
            'word_count' => 0,
            'language' => $this->english,
            'raw_text' => 'Sample chapter text.',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode((object) ['words' => $tokens]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }

    private function pt(string $word, int $stage): object
    {
        return (object) ['word' => $word, 'stage' => $stage];
    }

    /** processed_text word token with sentence_id */
    private function pst(string $word, int $stage, int $sentenceId): object
    {
        return (object) ['word' => $word, 'stage' => $stage, 'sentence_id' => $sentenceId];
    }
}
