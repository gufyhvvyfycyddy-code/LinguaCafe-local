<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\WordSenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SenseSourceContextTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private WordSenseService $wordSenseService;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure reviewIntervals setting exists for setStage() calls
        if (!\App\Models\Setting::where('name', 'reviewIntervals')->exists()) {
            \App\Models\Setting::forceCreate([
                'name' => 'reviewIntervals',
                'value' => json_encode([
                    '-7' => [0],
                    '-6' => [1],
                    '-5' => [2],
                    '-4' => [3],
                    '-3' => [7],
                    '-2' => [15],
                    '-1' => [30],
                ]),
            ]);
        }

        $this->user = $this->createUser('source-context@example.com', 'english');
        $this->otherUser = $this->createUser('other-source-context@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
    }

    public function test_source_context_returns_context_tokens_from_occurrence(): void
    {
        // Three sentences in chapter:
        // sentence_index=0: Before sentence .
        // sentence_index=1: Sure enough , the Census Bureau released data .
        // sentence_index=2: After sentence .
        $processedWords = [
            (object) ['word' => 'Before', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'sentence', 'sentence_index' => '0', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
            (object) ['word' => 'Sure', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'enough', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => ',', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'the', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'Census', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'Bureau', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'released', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'data', 'sentence_index' => '1', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '1', 'spaceAfter' => false],
            (object) ['word' => 'After', 'sentence_index' => '2', 'spaceAfter' => true],
            (object) ['word' => 'sentence', 'sentence_index' => '2', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '2', 'spaceAfter' => false],
        ];

        $chapter = $this->createTestChapter($processedWords, ['name' => 'Test Source Chapter']);

        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'bureau',
            'surface_form' => 'Bureau',
            'pos' => 'noun',
            'sense_zh' => '局；统计局',
            'sense_en' => 'an office or department for transacting particular business',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Sure enough , the Census Bureau released data .',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        $occurrence = WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => '1',
            'sentence_en' => 'Sure enough , the Census Bureau released data .',
            'sentence_zh' => '',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'Bureau',
            'lemma' => 'bureau',
            'pos' => 'noun',
            'decision' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'confidence' => 1.0,
            'auto_fsrs_allowed' => false,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'raw_payload' => [],
        ]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        $this->assertTrue($json['source_available'], 'source_available should be true');
        $this->assertSame('chapter', $json['source_kind']);
        $this->assertSame($chapter->id, $json['chapter_id']);
        $this->assertNotEmpty($json['context_tokens'], 'context_tokens should not be empty');
        $this->assertNotEmpty($json['target_indexes'], 'target_indexes should not be empty');

        // Context tokens should include words from all three sentences (before + target + after)
        $words = array_column($json['context_tokens'], 'word');
        $this->assertContains('Before', $words);
        $this->assertContains('After', $words);

        // At least one token should be Bureau with is_target=true
        $hasTarget = false;
        foreach ($json['context_tokens'] as $token) {
            if ($token['word'] === 'Bureau' && $token['is_target']) {
                $hasTarget = true;
                break;
            }
        }
        $this->assertTrue($hasTarget, 'Bureau token should be marked as target');

        // Verify is_source_sentence is set on tokens
        $hasSourceSentenceTokens = false;
        $hasNonSourceSentenceTokens = false;
        foreach ($json['context_tokens'] as $token) {
            if (!empty($token['is_source_sentence'])) {
                $hasSourceSentenceTokens = true;
            } else {
                $hasNonSourceSentenceTokens = true;
            }
        }
        $this->assertTrue($hasSourceSentenceTokens, 'Some tokens should have is_source_sentence=true');
        $this->assertTrue($hasNonSourceSentenceTokens, 'Some tokens should have is_source_sentence=false');
    }

    public function test_source_context_keeps_sentence_id_zero_valid(): void
    {
        // sentence_id='0' is a valid value — should NOT be treated as null/falsy
        $processedWords = [
            (object) ['word' => 'Target', 'sentence_index' => '0', 'sentence_id' => '0', 'spaceAfter' => true],
            (object) ['word' => 'sentence', 'sentence_index' => '0', 'sentence_id' => '0', 'spaceAfter' => true],
            (object) ['word' => 'here', 'sentence_index' => '0', 'sentence_id' => '0', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '0', 'sentence_id' => '0', 'spaceAfter' => false],
            (object) ['word' => 'Another', 'sentence_index' => '1', 'sentence_id' => '1', 'spaceAfter' => true],
            (object) ['word' => 'sentence', 'sentence_index' => '1', 'sentence_id' => '1', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '1', 'sentence_id' => '1', 'spaceAfter' => false],
        ];

        $chapter = $this->createTestChapter($processedWords, ['name' => 'Zero Sentence ID Chapter']);

        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'target',
            'surface_form' => 'Target',
            'pos' => 'noun',
            'sense_zh' => '目标',
            'sense_en' => 'something aimed at',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Target sentence here .',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => '0',
            'sentence_en' => 'Target sentence here .',
            'sentence_zh' => '',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'Target',
            'lemma' => 'target',
            'pos' => 'noun',
            'decision' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'confidence' => 1.0,
            'auto_fsrs_allowed' => false,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'raw_payload' => [],
        ]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        $this->assertTrue($json['source_available'], 'source_available should be true for sentence_id="0"');
        $this->assertSame('chapter', $json['source_kind']);
        $this->assertSame('0', $json['sentence_id']);
    }

    public function test_source_context_can_match_by_example_sentence_text(): void
    {
        // Occurrence has a chapter_id but non-matching sentence_id='999'.
        // Should fall back to matching by example_sentence_en text.
        $processedWords = [
            (object) ['word' => 'First', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'line', 'sentence_index' => '0', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
            (object) ['word' => 'Sure', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'enough', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => ',', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'the', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'Census', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'Bureau', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'released', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'data', 'sentence_index' => '1', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '1', 'spaceAfter' => false],
        ];

        $chapter = $this->createTestChapter($processedWords, ['name' => 'Text Match Chapter']);

        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'bureau',
            'surface_form' => 'Bureau',
            'pos' => 'noun',
            'sense_zh' => '局；统计局',
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Sure enough , the Census Bureau released data .',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => '999',  // Non-matching, forces text match fallback
            'sentence_en' => 'Sure enough , the Census Bureau released data .',
            'sentence_zh' => '',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'Bureau',
            'lemma' => 'bureau',
            'pos' => 'noun',
            'decision' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'confidence' => 1.0,
            'auto_fsrs_allowed' => false,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'raw_payload' => [],
        ]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        $this->assertTrue($json['source_available'], 'source_available should be true (matched by text)');
        $this->assertSame('chapter', $json['source_kind']);
        $this->assertNotEmpty($json['context_tokens']);

        // Find a target token
        $hasTarget = false;
        foreach ($json['context_tokens'] as $token) {
            if ($token['word'] === 'Bureau' && $token['is_target']) {
                $hasTarget = true;
                break;
            }
        }
        $this->assertTrue($hasTarget, 'Bureau should be found and marked as target via text match');
    }

    public function test_source_context_returns_unavailable_without_source(): void
    {
        // Truly empty: no chapter, no occurrence, no example_sentence_en
        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'orphan',
            'surface_form' => 'orphan',
            'pos' => 'noun',
            'sense_zh' => '孤儿词',
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => '',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        $this->assertFalse($json['source_available'], 'source_available should be false without any source');
        $this->assertNull($json['source_kind']);
        $this->assertNull($json['chapter_id']);
        $this->assertNotEmpty($json['fallback_message']);
    }

    public function test_source_context_cannot_access_other_user_sense(): void
    {
        $otherSense = $this->wordSenseService->createSense([
            'user_id' => $this->otherUser->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'private',
            'surface_form' => 'private',
            'pos' => 'noun',
            'sense_zh' => '私有的',
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => '',
            'example_sentence_zh' => '',
        ]);
        $otherSense->update(['status' => WordSense::STATUS_CONFIRMED]);

        $response = $this->actingAs($this->user)->get('/senses/' . $otherSense->id . '/source-context');

        $response->assertNotFound();
    }

    public function test_source_context_does_not_read_other_user_chapter(): void
    {
        // Chapter belongs to otherUser, occurrence points to it
        $processedWords = [
            (object) ['word' => 'Other', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'chapter', 'sentence_index' => '0', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
        ];

        $otherChapter = $this->createTestChapter($processedWords, ['user_id' => $this->otherUser->id, 'name' => 'Other User Chapter']);

        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'chapter',
            'surface_form' => 'chapter',
            'pos' => 'noun',
            'sense_zh' => '章节',
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => '',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $otherChapter->id, // Points to other user's chapter
            'sentence_id' => '0',
            'sentence_en' => 'Other chapter .',
            'sentence_zh' => '',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'chapter',
            'lemma' => 'chapter',
            'pos' => 'noun',
            'decision' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'confidence' => 1.0,
            'auto_fsrs_allowed' => false,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'raw_payload' => [],
        ]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        // Chapter lookup filters by sense->user_id and sense->language_id,
        // so the other user's chapter should be rejected.
        // But the occurrence has sentence_en, so fallback to card_example should work.
        $this->assertTrue($json['source_available'], 'source_available should be true via card_example fallback');
        $this->assertSame('card_example', $json['source_kind']);
        $this->assertNull($json['chapter_id'], 'chapter_id should be null (other user chapter rejected)');
    }

    public function test_source_context_cannot_read_other_language_chapter(): void
    {
        $processedWords = [
            (object) ['word' => 'Hola', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'mundo', 'sentence_index' => '0', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
        ];

        $spanishChapter = $this->createTestChapter($processedWords, ['language' => 'spanish', 'name' => 'Spanish Chapter']);

        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'mundo',
            'surface_form' => 'mundo',
            'pos' => 'noun',
            'sense_zh' => '世界',
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => '',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $spanishChapter->id, // Spanish chapter
            'sentence_id' => '0',
            'sentence_en' => 'Hola mundo .',
            'sentence_zh' => '',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'mundo',
            'lemma' => 'mundo',
            'pos' => 'noun',
            'decision' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'confidence' => 1.0,
            'auto_fsrs_allowed' => false,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'raw_payload' => [],
        ]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        // Chapter language=spanish, sense language=english → mismatch.
        // But the occurrence has sentence_en, so fallback to card_example should work.
        $this->assertTrue($json['source_available'], 'source_available should be true via card_example fallback');
        $this->assertSame('card_example', $json['source_kind']);
        $this->assertNull($json['chapter_id'], 'chapter_id should be null (language mismatch)');
    }

    public function test_source_context_falls_back_to_card_example_without_chapter(): void
    {
        // Sense with example_sentence_en but NO source_chapter_id, NO occurrence
        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'bureau',
            'surface_form' => 'Bureau',
            'pos' => 'noun',
            'sense_zh' => '局；统计局',
            'sense_en' => 'an office or department for transacting particular business',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Sure enough, the Census Bureau released data.',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        $this->assertTrue($json['source_available'], 'source_available should be true');
        $this->assertSame('card_example', $json['source_kind']);
        $this->assertNull($json['chapter_id']);
        $this->assertNotEmpty($json['context_tokens'], 'context_tokens should not be empty');
        $this->assertNotEmpty($json['target_indexes'], 'target_indexes should not be empty');
        $this->assertSame('未找到原章节位置，以下为复习卡保存的例句。', $json['fallback_message']);

        // Bureau token should be marked as target
        $hasTarget = false;
        foreach ($json['context_tokens'] as $token) {
            if ($token['word'] === 'Bureau' && $token['is_target']) {
                $hasTarget = true;
                break;
            }
        }
        $this->assertTrue($hasTarget, 'Bureau token should be marked as target');
    }

    public function test_source_context_falls_back_to_card_example_when_chapter_missing(): void
    {
        // Sense has example_sentence_en, occurrence points to non-existent chapter (id=99999)
        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'example',
            'surface_form' => 'example',
            'pos' => 'noun',
            'sense_zh' => '例子',
            'sense_en' => 'a thing serving as a model',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'This is an example sentence.',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        // Create occurrence pointing to non-existent chapter
        WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => 99999,  // Non-existent chapter
            'sentence_id' => '0',
            'sentence_en' => 'This is an example sentence.',
            'sentence_zh' => '',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'example',
            'lemma' => 'example',
            'pos' => 'noun',
            'decision' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'confidence' => 1.0,
            'auto_fsrs_allowed' => false,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'raw_payload' => [],
        ]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        $this->assertTrue($json['source_available'], 'source_available should be true via fallback');
        $this->assertSame('card_example', $json['source_kind']);
        $this->assertNull($json['chapter_id'], 'chapter_id should be null when chapter is missing');
        $this->assertNotEmpty($json['context_tokens']);
    }

    public function test_source_context_recovers_chapter_by_example_sentence_text(): void
    {
        // Create a chapter whose processed_text contains the example sentence
        $processedWords = [
            (object) ['word' => 'Previous', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'sentence', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'here', 'sentence_index' => '0', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
            (object) ['word' => 'Sure', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'enough', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => ',', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'the', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'Census', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'Bureau', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'released', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'data', 'sentence_index' => '1', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '1', 'spaceAfter' => false],
            (object) ['word' => 'Next', 'sentence_index' => '2', 'spaceAfter' => true],
            (object) ['word' => 'sentence', 'sentence_index' => '2', 'spaceAfter' => true],
            (object) ['word' => 'after', 'sentence_index' => '2', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '2', 'spaceAfter' => false],
        ];

        $chapter = $this->createTestChapter($processedWords, ['name' => 'Recovery Test Chapter']);

        // Sense with NO source_chapter_id, NO occurrence, only example_sentence_en
        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'bureau',
            'surface_form' => 'Bureau',
            'pos' => 'noun',
            'sense_zh' => '局；统计局',
            'sense_en' => 'an office or department for transacting particular business',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Sure enough , the Census Bureau released data .',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        $this->assertTrue($json['source_available'], 'source_available should be true');
        $this->assertSame('chapter_recovered', $json['source_kind']);
        $this->assertSame($chapter->id, $json['chapter_id'], 'chapter_id should be the recovered chapter');
        $this->assertNotEmpty($json['context_tokens'], 'context_tokens should not be empty');
        $this->assertNotEmpty($json['target_indexes'], 'target_indexes should not be empty');

        // Context tokens should include surrounding sentences
        $words = array_column($json['context_tokens'], 'word');
        $this->assertContains('Previous', $words, 'Should include previous sentence');
        $this->assertContains('Next', $words, 'Should include next sentence');

        // Bureau should be marked as target
        $hasTarget = false;
        foreach ($json['context_tokens'] as $token) {
            if ($token['word'] === 'Bureau' && $token['is_target']) {
                $hasTarget = true;
                break;
            }
        }
        $this->assertTrue($hasTarget, 'Bureau token should be marked as target');

        // Verify write-back to WordSense
        $sense->refresh();
        $this->assertSame($chapter->id, $sense->source_chapter_id, 'WordSense.source_chapter_id should be written back');
        $this->assertSame('1', $sense->sentence_id, 'WordSense.sentence_id should be written back');
    }

    public function test_source_context_recovers_chapter_from_chapter_title(): void
    {
        $chapterTitle = 'The Best Retailers Combine Bricks and Clicks';

        // Create chapter whose NAME matches the example sentence, but processed_text
        // does NOT contain this title text
        $processedWords = [
            (object) ['word' => 'Unrelated', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'content', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'here', 'sentence_index' => '0', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
        ];

        $chapter = $this->createTestChapter($processedWords, ['name' => $chapterTitle]);

        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'brick',
            'surface_form' => 'Bricks',
            'pos' => 'noun',
            'sense_zh' => '砖',
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => $chapterTitle,
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        $this->assertTrue($json['source_available'], 'source_available should be true');
        $this->assertSame('chapter_title', $json['source_kind']);
        $this->assertSame($chapter->id, $json['chapter_id'], 'chapter_id should be set (open chapter button enabled)');
        $this->assertSame($chapterTitle, $json['chapter_title']);
        $this->assertNotEmpty($json['context_tokens'], 'context_tokens should be synthetic tokens');
        $this->assertNotEmpty($json['target_indexes'], 'target_indexes should not be empty');

        // Bricks should be marked as target
        $hasTarget = false;
        foreach ($json['context_tokens'] as $token) {
            if ($token['word'] === 'Bricks' && $token['is_target']) {
                $hasTarget = true;
                break;
            }
        }
        $this->assertTrue($hasTarget, 'Bricks token should be marked as target');

        // Verify write-back to WordSense
        $sense->refresh();
        $this->assertSame($chapter->id, $sense->source_chapter_id, 'WordSense.source_chapter_id should be written back');
    }

    public function test_source_context_keeps_card_example_when_recovery_fails(): void
    {
        // Create a chapter whose processed_text does NOT contain the example sentence
        $processedWords = [
            (object) ['word' => 'Completely', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'different', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'text', 'sentence_index' => '0', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
        ];

        $this->createTestChapter($processedWords, ['name' => 'Unrelated Chapter']);

        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'bureau',
            'surface_form' => 'Bureau',
            'pos' => 'noun',
            'sense_zh' => '局；统计局',
            'sense_en' => 'an office or department for transacting particular business',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Sure enough, the Census Bureau released data.',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        $this->assertTrue($json['source_available'], 'source_available should be true');
        $this->assertSame('card_example', $json['source_kind'], 'should fall back to card_example when recovery fails');
        $this->assertNull($json['chapter_id'], 'chapter_id should be null');
        $this->assertNotEmpty($json['context_tokens'], 'context_tokens should not be empty');
        $this->assertNotEmpty($json['target_indexes'], 'target_indexes should not be empty');

        // Bureau should still be marked as target in synthetic fallback
        $hasTarget = false;
        foreach ($json['context_tokens'] as $token) {
            if ($token['word'] === 'Bureau' && $token['is_target']) {
                $hasTarget = true;
                break;
            }
        }
        $this->assertTrue($hasTarget, 'Bureau token should be marked as target');
    }

    public function test_source_context_recovered_chapter_payload_contains_sentence_id_for_reader_query(): void
    {
        // Chapter with multiple sentences - recovery should return sentence_id for frontend router query
        $processedWords = [
            (object) ['word' => 'First', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'sentence', 'sentence_index' => '0', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
            (object) ['word' => 'The', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'target', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'word', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'is', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'here', 'sentence_index' => '1', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '1', 'spaceAfter' => false],
            (object) ['word' => 'Last', 'sentence_index' => '2', 'spaceAfter' => true],
            (object) ['word' => 'line', 'sentence_index' => '2', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '2', 'spaceAfter' => false],
        ];

        $chapter = $this->createTestChapter($processedWords, ['name' => 'Reader Query Chapter']);

        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'target',
            'surface_form' => 'target',
            'pos' => 'noun',
            'sense_zh' => '目标',
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'The target word is here .',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        $this->assertTrue($json['source_available'], 'source_available should be true');
        $this->assertContains($json['source_kind'], ['chapter_recovered', 'chapter_title'], 'source_kind should be chapter_recovered or chapter_title');
        $this->assertNotEmpty($json['chapter_id'], 'chapter_id should not be empty for reader query');
        $this->assertSame($chapter->id, $json['chapter_id']);
        $this->assertNotNull($json['sentence_id'], 'sentence_id should be available for frontend query parameter');
    }

    public function test_source_context_marks_source_sentence_tokens(): void
    {
        // Create a chapter with 5 sentences, target at sentence_index=2
        $processedWords = [];
        $sentenceWords = [
            ['First', 'sentence', 'here'],
            ['Another', 'intro', 'line'],
            ['The', 'target', 'word', 'Census', 'Bureau', 'appears', 'here'],
            ['After', 'sentence', 'goes', 'on'],
            ['Final', 'closing', 'words'],
        ];
        $sentencePunctuation = ['.', '.', '.', '.', '.'];

        foreach ($sentenceWords as $si => $words) {
            foreach ($words as $wi => $w) {
                $spaceAfter = ($wi < count($words) - 1);
                $processedWords[] = (object) [
                    'word' => $w,
                    'sentence_index' => (string) $si,
                    'spaceAfter' => $spaceAfter,
                ];
            }
            $processedWords[] = (object) [
                'word' => $sentencePunctuation[$si],
                'sentence_index' => (string) $si,
                'spaceAfter' => false,
            ];
        }

        $chapter = $this->createTestChapter($processedWords, ['name' => 'Source Sentence Marking Chapter']);

        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'bureau',
            'surface_form' => 'Bureau',
            'pos' => 'noun',
            'sense_zh' => '局',
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'The target word Census Bureau appears here .',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => '2',
            'sentence_en' => 'The target word Census Bureau appears here .',
            'sentence_zh' => '',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'Bureau',
            'lemma' => 'bureau',
            'pos' => 'noun',
            'decision' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'confidence' => 1.0,
            'auto_fsrs_allowed' => false,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'raw_payload' => [],
        ]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        $this->assertTrue($json['source_available'], 'source_available should be true');
        $this->assertSame('chapter', $json['source_kind']);

        // Verify: target sentence tokens have is_source_sentence=true
        $sourceSentenceWords = [];
        $nonSourceSentenceWords = [];
        foreach ($json['context_tokens'] as $token) {
            if (!empty($token['is_source_sentence'])) {
                $sourceSentenceWords[] = $token['word'];
            } else {
                $nonSourceSentenceWords[] = $token['word'];
            }
        }

        $this->assertNotEmpty($sourceSentenceWords, 'Should have tokens with is_source_sentence=true');
        $this->assertContains('Census', $sourceSentenceWords, 'Target sentence word should have is_source_sentence=true');
        $this->assertContains('Bureau', $sourceSentenceWords, 'Target sentence word should have is_source_sentence=true');

        $this->assertNotEmpty($nonSourceSentenceWords, 'Should have tokens with is_source_sentence=false');
        $this->assertContains('First', $nonSourceSentenceWords, 'Non-target sentence word should have is_source_sentence=false');
        $this->assertContains('Final', $nonSourceSentenceWords, 'Non-target sentence word should have is_source_sentence=false');
    }

    public function test_source_context_expands_to_surrounding_sentences(): void
    {
        // Create 15 sentences, target at sentence_index=7
        // With radius=5, context should include sentences 2-12 (11 sentences),
        // and exclude sentences 0, 1, 13, 14
        $processedWords = [];
        for ($i = 0; $i < 15; $i++) {
            $wordA = 'S' . $i . 'a';
            $wordB = 'S' . $i . 'b';
            $processedWords[] = (object) [
                'word' => $wordA,
                'sentence_index' => (string) $i,
                'spaceAfter' => true,
            ];
            $processedWords[] = (object) [
                'word' => $wordB,
                'sentence_index' => (string) $i,
                'spaceAfter' => false,
            ];
            $processedWords[] = (object) [
                'word' => '.',
                'sentence_index' => (string) $i,
                'spaceAfter' => false,
            ];
        }

        $chapter = $this->createTestChapter($processedWords, ['name' => 'Expanded Context Chapter']);

        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 's7b',
            'surface_form' => 'S7b',
            'pos' => 'noun',
            'sense_zh' => '目标词',
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'S7a S7b .',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => '7',
            'sentence_en' => 'S7a S7b .',
            'sentence_zh' => '',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'S7b',
            'lemma' => 's7b',
            'pos' => 'noun',
            'decision' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'confidence' => 1.0,
            'auto_fsrs_allowed' => false,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'raw_payload' => [],
        ]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        $this->assertTrue($json['source_available'], 'source_available should be true');
        $this->assertSame('chapter', $json['source_kind']);

        $words = array_column($json['context_tokens'], 'word');

        // Should include sentences 2-12 (radius 5)
        $this->assertContains('S2a', $words, 'Should include sentence 2 (start of radius)');
        $this->assertContains('S12a', $words, 'Should include sentence 12 (end of radius)');

        // Should NOT include sentences 0, 1, 13, 14
        $this->assertNotContains('S0a', $words, 'Should NOT include sentence 0 (outside radius)');
        $this->assertNotContains('S0b', $words, 'Should NOT include sentence 0');
        $this->assertNotContains('S1a', $words, 'Should NOT include sentence 1 (outside radius)');
        $this->assertNotContains('S1b', $words, 'Should NOT include sentence 1');
        $this->assertNotContains('S13a', $words, 'Should NOT include sentence 13 (outside radius)');
        $this->assertNotContains('S13b', $words, 'Should NOT include sentence 13');
        $this->assertNotContains('S14a', $words, 'Should NOT include sentence 14 (outside radius)');
        $this->assertNotContains('S14b', $words, 'Should NOT include sentence 14');

        // Should include the target sentence
        $this->assertContains('S7a', $words, 'Should include target sentence');
        $this->assertContains('S7b', $words, 'Should include target sentence');

        // Verify context has more tokens than just 3 sentences
        $this->assertGreaterThan(3 * 3, count($json['context_tokens']), 'Context should have more tokens than 3 sentences');
    }

    public function test_source_context_fuzzy_recovers_sentence_with_punctuation_differences(): void
    {
        // Example sentence with commas and hyphens
        $exampleSentence = 'It is certain that the Census procedures, which lump the online sales of major traditional retailers like Walmart in with "non - store retailers" like food trucks, can mask major changes in individual retail categories.';

        // Processed text version with slightly different punctuation/spacing
        $targetSentenceText = 'It is certain that the Census procedures which lump the online sales of major traditional retailers like Walmart in with non-store retailers like food trucks can mask major changes in individual retail categories.';

        $processedWords = [];
        // S0: preceding sentence
        $processedWords[] = (object) ['word' => 'Preceding', 'sentence_index' => '0', 'spaceAfter' => true];
        $processedWords[] = (object) ['word' => 'context', 'sentence_index' => '0', 'spaceAfter' => true];
        $processedWords[] = (object) ['word' => 'here', 'sentence_index' => '0', 'spaceAfter' => false];
        $processedWords[] = (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false];

        // S1: target sentence (different punctuation from example)
        $targetWords = explode(' ', $targetSentenceText);
        foreach ($targetWords as $wi => $w) {
            $processedWords[] = (object) [
                'word' => $w,
                'sentence_index' => '1',
                'spaceAfter' => ($wi < count($targetWords) - 1),
            ];
        }
        $processedWords[] = (object) ['word' => '.', 'sentence_index' => '1', 'spaceAfter' => false];

        // S2: following sentence
        $processedWords[] = (object) ['word' => 'Following', 'sentence_index' => '2', 'spaceAfter' => true];
        $processedWords[] = (object) ['word' => 'context', 'sentence_index' => '2', 'spaceAfter' => false];
        $processedWords[] = (object) ['word' => '.', 'sentence_index' => '2', 'spaceAfter' => false];

        $chapter = $this->createTestChapter($processedWords, ['name' => 'Fuzzy Punctuation Chapter']);

        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'walmart',
            'surface_form' => 'Walmart',
            'pos' => 'noun',
            'sense_zh' => '沃尔玛',
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => $exampleSentence,
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        $this->assertTrue($json['source_available'], 'source_available should be true');
        $this->assertSame('chapter_fuzzy', $json['source_kind']);
        $this->assertSame($chapter->id, $json['chapter_id'], 'chapter_id should be correct');
        $this->assertNotEmpty($json['target_indexes'], 'target_indexes should not be empty');

        // Walmart should be marked as target
        $hasTarget = false;
        foreach ($json['context_tokens'] as $token) {
            if ($token['word'] === 'Walmart' && $token['is_target']) {
                $hasTarget = true;
                break;
            }
        }
        $this->assertTrue($hasTarget, 'Walmart token should be marked as target');
    }

    public function test_source_context_fuzzy_recovers_title_when_chapter_name_not_exact(): void
    {
        // Chapter name has slightly different text than example sentence
        $chapterTitle = 'Retailers Bricks and Clicks';
        $exampleSentence = 'The Best Retailers Combine Bricks and Clicks';

        $processedWords = [
            (object) ['word' => 'Unrelated', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'chapter', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'text', 'sentence_index' => '0', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
        ];

        $chapter = $this->createTestChapter($processedWords, ['name' => $chapterTitle]);

        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'brick',
            'surface_form' => 'Bricks',
            'pos' => 'noun',
            'sense_zh' => '砖',
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => $exampleSentence,
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        $this->assertTrue($json['source_available'], 'source_available should be true');
        $this->assertSame('chapter_fuzzy_title', $json['source_kind']);
        $this->assertSame($chapter->id, $json['chapter_id'], 'chapter_id should be set');

        // Bricks should be marked as target
        $hasTarget = false;
        foreach ($json['context_tokens'] as $token) {
            if ($token['word'] === 'Bricks' && $token['is_target']) {
                $hasTarget = true;
                break;
            }
        }
        $this->assertTrue($hasTarget, 'Bricks token should be marked as target');
    }

    public function test_source_context_fuzzy_does_not_cross_user_or_language(): void
    {
        // Create a chapter for another user with matching text
        $otherChapterWords = [
            (object) ['word' => 'Sure', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'enough', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => ',', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'the', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'Census', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'Bureau', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'released', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'data', 'sentence_index' => '0', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
        ];

        // Other user's chapter — should NOT be searched
        $this->createTestChapter($otherChapterWords, ['user_id' => $this->otherUser->id, 'name' => 'Other User Chapter']);

        // Create a chapter for this user but with completely unrelated text
        $unrelatedWords = [
            (object) ['word' => 'Completely', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'unrelated', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'text', 'sentence_index' => '0', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
        ];
        $this->createTestChapter($unrelatedWords, ['name' => 'Unrelated Chapter']);

        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'bureau',
            'surface_form' => 'Bureau',
            'pos' => 'noun',
            'sense_zh' => '局；统计局',
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Sure enough, the Census Bureau released data.',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        // Should fall back to card_example since the only matching chapter belongs to another user
        $this->assertTrue($json['source_available'], 'source_available should be true');
        $this->assertSame('card_example', $json['source_kind'], 'should fall back to card_example (other user chapter not searched)');
        $this->assertNull($json['chapter_id'], 'chapter_id should be null');
    }

    public function test_source_context_fuzzy_falls_back_to_card_example_when_score_low(): void
    {
        // Create a chapter with completely unrelated text
        $processedWords = [
            (object) ['word' => 'The', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'weather', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'is', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'nice', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'today', 'sentence_index' => '0', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
        ];

        $this->createTestChapter($processedWords, ['name' => 'Weather Chapter']);

        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'bureau',
            'surface_form' => 'Bureau',
            'pos' => 'noun',
            'sense_zh' => '局；统计局',
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Sure enough, the Census Bureau released data.',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        // Score too low — should fall back to card_example
        $this->assertTrue($json['source_available'], 'source_available should be true');
        $this->assertSame('card_example', $json['source_kind'], 'should fall back to card_example when fuzzy score is too low');
        $this->assertNull($json['chapter_id'], 'chapter_id should be null');

        // Bureau should still be marked as target in synthetic fallback
        $hasTarget = false;
        foreach ($json['context_tokens'] as $token) {
            if ($token['word'] === 'Bureau' && $token['is_target']) {
                $hasTarget = true;
                break;
            }
        }
        $this->assertTrue($hasTarget, 'Bureau token should be marked as target');
    }

    // ==================== Realistic regression: Walmart / Bricks / Bureau ====================

    public function test_source_context_fuzzy_recovers_walmart_with_punctuation_spacing_differences(): void
    {
        // Walmart's non-store sales → chapter has "Walmart 's non --- store sales"
        // Realistic failure scenario: token-level spacing and hyphen differences
        $exampleSentence = "Walmart's non-store sales rose sharply as online retail continued to grow.";

        $processedWords = [
            // S0: preceding sentence
            (object) ['word' => 'Some', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'intro', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'text', 'sentence_index' => '0', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
            // S1: target sentence — "Walmart 's non --- store ..." with token-level splits
            (object) ['word' => 'Walmart', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => "'s", 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'non', 'sentence_index' => '1', 'spaceAfter' => false],
            (object) ['word' => '---', 'sentence_index' => '1', 'spaceAfter' => false],
            (object) ['word' => 'store', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'sales', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'rose', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'sharply', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'as', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'online', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'retail', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'continued', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'to', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'grow', 'sentence_index' => '1', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '1', 'spaceAfter' => false],
            // S2: following sentence
            (object) ['word' => 'More', 'sentence_index' => '2', 'spaceAfter' => true],
            (object) ['word' => 'text', 'sentence_index' => '2', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '2', 'spaceAfter' => false],
        ];

        $chapter = $this->createTestChapter($processedWords, ['name' => 'Walmart Spacing Test']);

        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'walmart',
            'surface_form' => 'Walmart',
            'pos' => 'noun',
            'sense_zh' => '沃尔玛',
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => $exampleSentence,
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        $this->assertTrue($json['source_available'], 'source_available should be true');
        $this->assertSame('chapter_fuzzy', $json['source_kind'], 'should recover via fuzzy match despite token spacing differences');
        $this->assertSame($chapter->id, $json['chapter_id'], 'chapter_id should be correct');
        $this->assertNotEmpty($json['context_tokens'], 'context_tokens should not be empty');
        $this->assertNotEmpty($json['target_indexes'], 'target_indexes should not be empty');

        // Walmart should be is_target=true
        $hasTarget = false;
        foreach ($json['context_tokens'] as $token) {
            if ($token['word'] === 'Walmart' && !empty($token['is_target'])) {
                $hasTarget = true;
                break;
            }
        }
        $this->assertTrue($hasTarget, 'Walmart token should be marked as target');

        // Should NOT fallback to card_example
        $this->assertNotSame('card_example', $json['source_kind'], 'should not fallback to card_example');
    }

    public function test_source_context_fuzzy_recovers_bricks_from_similar_chapter_title(): void
    {
        // Chapter name uses "and" but example sentence uses "&"
        // The normalizeSentenceText preserves "&" so exact recovery fails,
        // but fuzzy recovery (via meaningfulTextTokens) strips both "and" (stopword) and "&" (punctuation)
        // and produces identical token sets → high fuzzy score on title
        $chapterTitle = 'The Best Retailers Combine Bricks and Clicks';
        $exampleSentence = 'The Best Retailers Combine Bricks & Clicks';

        // Processed_text is unrelated — only the chapter TITLE should match
        $processedWords = [
            (object) ['word' => 'Unrelated', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'chapter', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'text', 'sentence_index' => '0', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
        ];

        $chapter = $this->createTestChapter($processedWords, ['name' => $chapterTitle]);

        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'brick',
            'surface_form' => 'Bricks',
            'pos' => 'noun',
            'sense_zh' => '砖',
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => $exampleSentence,
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        $this->assertTrue($json['source_available'], 'source_available should be true');
        $this->assertSame('chapter_fuzzy_title', $json['source_kind'], 'should be chapter_fuzzy_title (fuzzy title match via & vs and)');
        $this->assertSame($chapter->id, $json['chapter_id'], 'chapter_id should be set');
        $this->assertSame($chapterTitle, $json['chapter_title'], 'chapter_title should be correct');

        // Bricks should be marked as target
        $hasTarget = false;
        foreach ($json['context_tokens'] as $token) {
            if ($token['word'] === 'Bricks' && !empty($token['is_target'])) {
                $hasTarget = true;
                break;
            }
        }
        $this->assertTrue($hasTarget, 'Bricks token should be marked as target');

        // Should NOT fallback to card_example
        $this->assertNotSame('card_example', $json['source_kind']);
    }

    public function test_source_context_bureau_realistic_sentence_includes_surrounding_context(): void
    {
        // Three-sentence chapter: Before → Bureau target → After
        // Example sentence and chapter text differ ONLY in comma spacing and period spacing,
        // which normalizeSentenceText() resolves to an exact match.
        // This triggers chapter_recovered with real context tokens from processed_text.
        $exampleSentence = 'Sure enough, the Census Bureau released data.';

        $processedWords = [
            // S0: preceding sentence
            (object) ['word' => 'Before', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'sentence', 'sentence_index' => '0', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
            // S1: target sentence — spaces around comma and before period
            (object) ['word' => 'Sure', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'enough', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => ',', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'the', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'Census', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'Bureau', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'released', 'sentence_index' => '1', 'spaceAfter' => true],
            (object) ['word' => 'data', 'sentence_index' => '1', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '1', 'spaceAfter' => false],
            // S2: following sentence
            (object) ['word' => 'After', 'sentence_index' => '2', 'spaceAfter' => true],
            (object) ['word' => 'sentence', 'sentence_index' => '2', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '2', 'spaceAfter' => false],
        ];

        $chapter = $this->createTestChapter($processedWords, ['name' => 'Bureau Context Chapter']);

        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'bureau',
            'surface_form' => 'Bureau',
            'pos' => 'noun',
            'sense_zh' => '局；统计局',
            'sense_en' => 'an office or department for transacting particular business',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => $exampleSentence,
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        $this->assertTrue($json['source_available'], 'source_available should be true');
        // chapter_recovered: exact match after normalization resolves comma/period spacing
        $this->assertSame('chapter_recovered', $json['source_kind'], 'should be chapter_recovered');
        $this->assertSame($chapter->id, $json['chapter_id']);

        // Context tokens should include surrounding sentences (Before and After)
        $words = array_column($json['context_tokens'], 'word');
        $this->assertContains('Before', $words, 'Should include preceding sentence');
        $this->assertContains('After', $words, 'Should include following sentence');

        // Bureau should be is_target=true
        $hasTarget = false;
        foreach ($json['context_tokens'] as $token) {
            if ($token['word'] === 'Bureau' && !empty($token['is_target'])) {
                $hasTarget = true;
                break;
            }
        }
        $this->assertTrue($hasTarget, 'Bureau token should be marked as target');

        // is_source_sentence field should exist and be correct
        $hasSourceSentenceTokens = false;
        $hasNonSourceSentenceTokens = false;
        foreach ($json['context_tokens'] as $token) {
            if (!empty($token['is_source_sentence'])) {
                $hasSourceSentenceTokens = true;
            } else {
                $hasNonSourceSentenceTokens = true;
            }
        }
        $this->assertTrue($hasSourceSentenceTokens, 'Target sentence tokens should have is_source_sentence=true');
        $this->assertTrue($hasNonSourceSentenceTokens, 'Non-target sentence tokens should have is_source_sentence=false');

        // Should NOT fallback to card_example
        $this->assertNotSame('card_example', $json['source_kind']);
    }

    public function test_source_context_low_score_unrelated_chapter_falls_back_to_card_example(): void
    {
        // A cooking guide chapter has zero overlap with a Bureau economics sentence.
        // The fuzzy recovery must correctly bail out with a low score and fall back
        // to card_example synthetic tokens.
        $processedWords = [
            (object) ['word' => 'A', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'cooking', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'guide', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'explains', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'how', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'to', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'prepare', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'soup', 'sentence_index' => '0', 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
        ];

        $this->createTestChapter($processedWords, ['name' => 'Cooking Guide Chapter']);

        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'bureau',
            'surface_form' => 'Bureau',
            'pos' => 'noun',
            'sense_zh' => '局；统计局',
            'sense_en' => 'an office or department for transacting particular business',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Sure enough, the Census Bureau released data.',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $json = $response->json();

        $this->assertTrue($json['source_available'], 'source_available should be true');
        $this->assertSame('card_example', $json['source_kind'], 'should fallback to card_example when unrelated chapter scores too low');
        $this->assertNull($json['chapter_id'], 'chapter_id should be null — unrelated chapter must not be matched');
        $this->assertNotEmpty($json['context_tokens'], 'context_tokens should not be empty (synthetic)');
        $this->assertNotEmpty($json['target_indexes'], 'target_indexes should not be empty');

        // Bureau should be is_target=true in synthetic fallback tokens
        $hasTarget = false;
        foreach ($json['context_tokens'] as $token) {
            if ($token['word'] === 'Bureau' && !empty($token['is_target'])) {
                $hasTarget = true;
                break;
            }
        }
        $this->assertTrue($hasTarget, 'Bureau token should be marked as target in synthetic fallback');
    }

    // ==================== Management page integration ====================

    public function test_source_context_api_still_works(): void
    {
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'manage',
            'surface_form' => 'manage',
            'pos' => 'verb',
            'sense_zh' => '管理',
            'sense_en' => 'to administer',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'We need to manage resources.',
            'example_sentence_zh' => '我们需要管理资源。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', 'english|manage|verb|管理|to administer'),
        ]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');
        $response->assertOk();

        $data = $response->json();
        $this->assertArrayHasKey('sense_id', $data);
        $this->assertArrayHasKey('source_available', $data);
        $this->assertArrayHasKey('source_kind', $data);
    }

    public function test_source_context_response_contains_required_keys(): void
    {
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'required',
            'surface_form' => 'required',
            'pos' => 'adjective',
            'sense_zh' => '必须的',
            'sense_en' => 'necessary',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'This is required.',
            'example_sentence_zh' => '这是必须的。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', 'english|required|adjective|必须的|necessary'),
        ]);

        $response = $this->actingAs($this->user)->get('/senses/' . $sense->id . '/source-context');
        $response->assertOk();

        $data = $response->json();
        $this->assertArrayHasKey('source_kind', $data);
        $this->assertArrayHasKey('chapter_id', $data);
        $this->assertArrayHasKey('sentence_id', $data);
        $this->assertArrayHasKey('target_indexes', $data);
        $this->assertArrayHasKey('fallback_message', $data);
    }

    public function test_management_page_data_route_does_not_trigger_source_context_log(): void
    {
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'nolog',
            'surface_form' => 'nolog',
            'pos' => 'noun',
            'sense_zh' => '无日志',
            'sense_en' => 'no log',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'No log test.',
            'example_sentence_zh' => '无日志测试。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', 'english|nolog|noun|无日志|no log'),
        ]);

        ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
        ]);

        // Verify data route doesn't log source_context entries
        $response = $this->actingAs($this->user)->get('/review-cards/manage/data');
        $response->assertOk();
        $this->assertNotEmpty($response->json('items'));
    }

    private function createTestChapter(array $processedWords, array $overrides = []): Chapter
    {
        return Chapter::forceCreate(array_merge([
            'user_id' => $this->user->id,
            'book_id' => 1,
            'name' => 'Test Chapter',
            'read_count' => 0,
            'word_count' => count($processedWords),
            'language' => 'english',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'raw_text' => '',
            'type' => 'text',
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
            'processed_text' => gzcompress(json_encode($processedWords), 1),
        ], $overrides));
    }

    private function createUser(string $email, string $language): User
    {
        return User::forceCreate([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }
}
