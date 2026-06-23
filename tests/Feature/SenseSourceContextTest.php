<?php

namespace Tests\Feature;

use App\Models\Chapter;
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
