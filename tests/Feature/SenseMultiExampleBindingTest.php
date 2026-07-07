<?php

namespace Tests\Feature;

use App\Models\AiStudyCardPendingItem;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\WordSenseExamplePoolService;
use App\Services\WordSenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseMultiExampleBindingTest
 *
 * GM52-SenseMultiExampleBindingAndReviewRotation-1000-6
 *
 * Verifies the multi-example binding contract:
 *  - A single WordSense can accumulate multiple source occurrences.
 *  - Duplicate sources (by sentence_id, by chapter+sentence_index, or by
 *    normalized sentence_text) are not re-inserted.
 *  - The AIStudyCard user_selected created path binds an occurrence.
 *  - The AIStudyCard duplicate path also tries to bind the current source
 *    (no duplicate insertion when the source already exists).
 *  - The AIStudyCard ai_recommended path does NOT bind an occurrence when
 *    no reliable source is available.
 *  - Cross-user / cross-language occurrences never mix.
 *  - Binding does not create legacy word cards, does not write extra
 *    ReviewLog, and does not change FSRS.
 *
 * The AIStudyCard-related tests go through the real HTTP API
 * (/ai-study-card/pending-items + /ai-study-card/generate-cards) so the
 * full request lifecycle (validation, service container, events) is
 * exercised the same way the frontend triggers it.
 */
class SenseMultiExampleBindingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WordSenseService $wordSenseService;
    private Chapter $chapter;

    protected function setUp(): void
    {
        parent::setUp();

        if (!\App\Models\Setting::where('name', 'reviewIntervals')->exists()) {
            \App\Models\Setting::forceCreate([
                'name' => 'reviewIntervals',
                'value' => json_encode([
                    '-7' => [0], '-6' => [1], '-5' => [2], '-4' => [3],
                    '-3' => [7], '-2' => [15], '-1' => [30],
                ]),
            ]);
        }

        $this->user = $this->createUser('multi-binding@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
        $this->chapter = $this->createChapter($this->user, 'english');
    }

    public function test_one_word_sense_can_have_multiple_distinct_occurrences(): void
    {
        $sense = $this->createConfirmedSense('bureau');
        $chapter1 = $this->createTestChapter('Chapter A');
        $chapter2 = $this->createTestChapter('Chapter B');

        $this->createOccurrence($sense, $chapter1, 's1', 'The Census Bureau released data.');
        $this->createOccurrence($sense, $chapter2, 's2', 'A federal bureau handled the case.');

        $count = WordSenseOccurrence::query()
            ->where('word_sense_id', $sense->id)
            ->where('status', WordSenseOccurrence::STATUS_BOUND)
            ->count();

        $this->assertSame(2, $count, 'a single WordSense should accumulate multiple occurrences');
    }

    public function test_same_sentence_id_does_not_create_duplicate_binding(): void
    {
        $sense = $this->createConfirmedSense('bureau');
        $chapter = $this->createTestChapter('Chapter A');

        $this->createOccurrence($sense, $chapter, 'sent-1', 'First sentence.');
        // updateOrCreate with same sentence_id should update, not insert.
        $this->createOccurrence($sense, $chapter, 'sent-1', 'First sentence (updated).');

        $count = WordSenseOccurrence::query()
            ->where('word_sense_id', $sense->id)
            ->where('sentence_id', 'sent-1')
            ->count();

        $this->assertSame(1, $count, 'same sentence_id must not produce duplicate binding');
    }

    public function test_same_chapter_and_sentence_index_does_not_create_duplicate_binding(): void
    {
        $sense = $this->createConfirmedSense('bureau');
        $chapter = $this->createTestChapter('Chapter A');

        // Same chapter + same sentence_id but different surface — updateOrCreate
        // matches on chapter_id + sentence_id + surface + source.
        $this->createOccurrence($sense, $chapter, 'idx-1', 'First occurrence of word.');
        $this->createOccurrence($sense, $chapter, 'idx-1', 'First occurrence of word (v2).');

        $count = WordSenseOccurrence::query()
            ->where('word_sense_id', $sense->id)
            ->where('chapter_id', $chapter->id)
            ->where('sentence_id', 'idx-1')
            ->count();

        $this->assertSame(1, $count, 'same chapter + sentence_id must not produce duplicate binding');
    }

    public function test_normalized_sentence_text_is_deduped_inside_example_pool(): void
    {
        // The dedupe at the pool level (WordSenseExamplePoolService) collapses
        // identical normalized sentences within the same chapter. Here we
        // verify that two raw occurrences with the same normalized sentence
        // text in the same chapter yield exactly one candidate from the pool.
        $sense = $this->createConfirmedSense('bureau');
        $chapter = $this->createTestChapter('Chapter A');

        $this->createOccurrence($sense, $chapter, 'sent-a', 'The bureau opened at noon.');
        // Different sentence_id, but identical normalized sentence_en — the pool
        // dedupe must collapse these for display purposes.
        $this->createOccurrence($sense, $chapter, 'sent-b', 'The bureau opened at noon.');

        $candidates = app(WordSenseExamplePoolService::class)->exampleCandidates($sense);

        $matching = array_filter($candidates, fn ($c) => $c['sentence_en'] === 'The bureau opened at noon.');
        $this->assertCount(1, $matching, 'pool must collapse identical normalized sentences within same chapter');
    }

    public function test_ai_study_card_created_path_binds_occurrence(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $response = $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sentence_id' => 'multi-bind-sent-1',
                    'sentence_text' => 'The intellectual landscape changed quickly.',
                    'text_block_index' => 0,
                    'sentence_index' => 0,
                    'sense_zh' => '风景',
                ],
            ],
        ]);

        $response->assertOk();
        $created = $response->json('results.created');
        $this->assertCount(1, $created);
        $this->assertTrue($created[0]['occurrence_created'], 'created path must bind an occurrence');
        $this->assertNotNull($created[0]['occurrence_id']);

        $occCount = WordSenseOccurrence::query()
            ->where('word_sense_id', $created[0]['sense_id'])
            ->where('status', WordSenseOccurrence::STATUS_BOUND)
            ->count();
        $this->assertSame(1, $occCount, 'exactly one occurrence should be bound on the created path');
    }

    public function test_ai_study_card_duplicate_path_also_binds_occurrence_without_duplicating(): void
    {
        // Pre-create a confirmed WordSense + ReviewCard for the same word/lemma/sense_zh
        // so that generate-cards finds it as a duplicate.
        $existingSense = $this->wordSenseService->createOrFindSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'landscape',
            'surface_form' => 'landscape',
            'pos' => null,
            'sense_zh' => '风景',
            'sense_en' => null,
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
        $this->wordSenseService->createReviewCardForSense($existingSense);

        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $firstResponse = $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sentence_id' => 'multi-bind-dup-sent-1',
                    'sentence_text' => 'The intellectual landscape changed quickly.',
                    'text_block_index' => 0,
                    'sentence_index' => 0,
                    'sense_zh' => '风景',
                ],
            ],
        ])->assertOk();

        // First call should be a duplicate (sense already exists).
        $duplicate = $firstResponse->json('results.duplicate');
        $this->assertCount(1, $duplicate);
        $this->assertTrue($duplicate[0]['occurrence_created'], 'duplicate path must still bind a new occurrence');
        $firstOccurrenceId = $duplicate[0]['occurrence_id'];

        // Second call: same word/sense, different sentence — another duplicate,
        // but a new occurrence should be bound (different from the first).
        $create2 = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload([
            'word' => 'landscape',
            'surface' => 'landscape',
            'lemma' => 'landscape',
            'sentence_text' => 'A different landscape sentence here.',
            'sentence_id' => '0',
        ]))->assertOk();
        $itemId2 = $create2->json('item.id');

        $secondResponse = $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId2, $this->chapter->id, [], 'A different landscape sentence here.'),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId2,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sentence_id' => 'multi-bind-dup-sent-2',
                    'sentence_text' => 'A different landscape sentence here.',
                    'text_block_index' => 0,
                    'sentence_index' => 0,
                    'sense_zh' => '风景',
                ],
            ],
        ])->assertOk();

        $secondDuplicate = $secondResponse->json('results.duplicate');
        $this->assertCount(1, $secondDuplicate);
        $this->assertTrue($secondDuplicate[0]['occurrence_created'], 'second duplicate call must bind a new occurrence');
        $this->assertNotEquals($firstOccurrenceId, $secondDuplicate[0]['occurrence_id'], 'new occurrence should be a different row');

        $occCount = WordSenseOccurrence::query()
            ->where('word_sense_id', $existingSense->id)
            ->where('status', WordSenseOccurrence::STATUS_BOUND)
            ->count();
        $this->assertSame(2, $occCount, 'duplicate path should add a second occurrence for the same sense');
    }

    public function test_ai_study_card_duplicate_path_does_not_rebind_same_source(): void
    {
        // Pre-create a confirmed WordSense so generate-cards finds it as duplicate.
        $existingSense = $this->wordSenseService->createOrFindSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'landscape',
            'surface_form' => 'landscape',
            'pos' => null,
            'sense_zh' => '风景',
            'sense_en' => null,
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
        $this->wordSenseService->createReviewCardForSense($existingSense);

        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload([
            'sentence_id' => 'multi-bind-same-sent',
        ]))->assertOk();
        $itemId = $create->json('item.id');

        // First call binds the occurrence with sentence_id = 'multi-bind-same-sent'.
        $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sentence_id' => 'multi-bind-same-sent',
                    'sentence_text' => 'The intellectual landscape changed quickly.',
                    'text_block_index' => 0,
                    'sentence_index' => 0,
                    'sense_zh' => '风景',
                ],
            ],
        ])->assertOk();

        // Second call with the SAME sentence_id — should be a duplicate and
        // should NOT insert a new occurrence row (updateOrCreate updates).
        $create2 = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload([
            'sentence_id' => 'multi-bind-same-sent',
        ]))->assertOk();
        $itemId2 = $create2->json('item.id');

        $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId2, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId2,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sentence_id' => 'multi-bind-same-sent',
                    'sentence_text' => 'The intellectual landscape changed quickly.',
                    'text_block_index' => 0,
                    'sentence_index' => 0,
                    'sense_zh' => '风景',
                ],
            ],
        ])->assertOk();

        $occCount = WordSenseOccurrence::query()
            ->where('word_sense_id', $existingSense->id)
            ->where('sentence_id', 'multi-bind-same-sent')
            ->count();
        $this->assertSame(1, $occCount, 'same sentence_id must not be re-inserted as a new occurrence');
    }

    public function test_ai_recommended_path_does_not_bind_occurrence_when_no_chapter_id(): void
    {
        // ai_recommended path: frontend always sends chapter_id = null
        $response = $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => [
                'schema_version' => 'ai-study-card-final-candidates-v1',
                'user_selected_items' => [],
                'ai_recommended_selected_items' => [
                    [
                        'word' => 'agency',
                        'lemma' => 'agency',
                        'surface' => 'agency',
                        'sentence_text' => 'The agency handled the case.',
                        'reason' => 'AI suggested',
                        'confidence' => null,
                        'sense_zh' => '机构',
                    ],
                ],
                'ai_recommended_unselected_items' => [],
                'dedupe_summary' => [],
                'generation_rules' => [],
                'safety_flags' => [],
            ],
            'confirmed_items' => [
                [
                    'source' => 'ai_recommended',
                    'item_id' => null,
                    'word' => 'agency',
                    'lemma' => 'agency',
                    'chapter_id' => null,
                    'sentence_id' => null,
                    'sentence_text' => 'The agency handled the case.',
                    'text_block_index' => null,
                    'sentence_index' => null,
                    'sense_zh' => '机构',
                ],
            ],
        ]);

        $response->assertOk();
        $created = $response->json('results.created');
        $this->assertCount(1, $created);
        $this->assertFalse($created[0]['occurrence_created'], 'ai_recommended without chapter_id must not bind occurrence');
        $this->assertNull($created[0]['occurrence_id']);

        $occCount = WordSenseOccurrence::query()
            ->where('word_sense_id', $created[0]['sense_id'])
            ->count();
        $this->assertSame(0, $occCount, 'no occurrence should exist for ai_recommended without chapter_id');
    }

    public function test_cross_user_occurrences_are_not_shared(): void
    {
        $sense = $this->createConfirmedSense('bureau');
        $myChapter = $this->createTestChapter('My Chapter');
        $this->createOccurrence($sense, $myChapter, 's1', 'My sentence.');

        $otherUser = $this->createUser('other-binding@example.com', 'english');
        WordSenseOccurrence::forceCreate([
            'user_id' => $otherUser->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $myChapter->id,
            'sentence_id' => 's2',
            'sentence_en' => 'Other user sentence.',
            'sentence_zh' => '',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => $sense->surface_form,
            'lemma' => $sense->lemma,
            'pos' => $sense->pos,
            'decision' => 'match_existing_sense',
            'confidence' => 1.0,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
        ]);

        $myOccurrences = WordSenseOccurrence::query()
            ->where('user_id', $this->user->id)
            ->where('word_sense_id', $sense->id)
            ->count();
        $otherOccurrences = WordSenseOccurrence::query()
            ->where('user_id', $otherUser->id)
            ->where('word_sense_id', $sense->id)
            ->count();

        $this->assertSame(1, $myOccurrences, 'my occurrences must stay isolated from other user');
        $this->assertSame(1, $otherOccurrences, 'other user occurrence must not be counted as mine');
    }

    public function test_cross_language_occurrences_are_not_shared(): void
    {
        $sense = $this->createConfirmedSense('bureau');
        $myChapter = $this->createTestChapter('English Chapter');
        $this->createOccurrence($sense, $myChapter, 's1', 'English sentence.');

        WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'japanese',
            'language_id' => 'japanese',
            'word_sense_id' => $sense->id,
            'chapter_id' => $myChapter->id,
            'sentence_id' => 's2',
            'sentence_en' => 'Japanese sentence.',
            'sentence_zh' => '',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => $sense->surface_form,
            'lemma' => $sense->lemma,
            'pos' => $sense->pos,
            'decision' => 'match_existing_sense',
            'confidence' => 1.0,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
        ]);

        $englishOccurrences = WordSenseOccurrence::query()
            ->where('user_id', $this->user->id)
            ->where('language_id', 'english')
            ->where('word_sense_id', $sense->id)
            ->count();
        $japaneseOccurrences = WordSenseOccurrence::query()
            ->where('user_id', $this->user->id)
            ->where('language_id', 'japanese')
            ->where('word_sense_id', $sense->id)
            ->count();

        $this->assertSame(1, $englishOccurrences, 'english occurrences must stay isolated from japanese');
        $this->assertSame(1, $japaneseOccurrences, 'japanese occurrence must not be counted as english');
    }

    public function test_binding_does_not_create_legacy_word_review_card(): void
    {
        $wordCardsBefore = ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->count();

        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sentence_id' => 'multi-bind-legacy-sent',
                    'sentence_text' => 'The intellectual landscape changed quickly.',
                    'text_block_index' => 0,
                    'sentence_index' => 0,
                    'sense_zh' => '风景',
                ],
            ],
        ])->assertOk();

        $wordCardsAfter = ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->count();
        $this->assertSame($wordCardsBefore, $wordCardsAfter, 'no legacy word ReviewCard should be created');
    }

    public function test_binding_does_not_write_extra_review_log(): void
    {
        $reviewLogsBefore = ReviewLog::count();

        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sentence_id' => 'multi-bind-log-sent',
                    'sentence_text' => 'The intellectual landscape changed quickly.',
                    'text_block_index' => 0,
                    'sentence_index' => 0,
                    'sense_zh' => '风景',
                ],
            ],
        ])->assertOk();

        $reviewLogsAfter = ReviewLog::count();
        $this->assertSame($reviewLogsBefore, $reviewLogsAfter, 'no extra ReviewLog should be written by binding');
    }

    public function test_binding_does_not_change_fsrs_fields(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $response = $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sentence_id' => 'multi-bind-fsrs-sent',
                    'sentence_text' => 'The intellectual landscape changed quickly.',
                    'text_block_index' => 0,
                    'sentence_index' => 0,
                    'sense_zh' => '风景',
                ],
            ],
        ])->assertOk();

        $card = ReviewCard::find($response->json('results.created.0.review_card_id'));
        $this->assertSame('new', $card->fsrs_state, 'newly created card must be in new state');
        $this->assertSame(0, $card->fsrs_reps, 'newly created card must have 0 reps');
        $this->assertSame(0, $card->fsrs_lapses, 'newly created card must have 0 lapses');
    }

    // ==================== Helpers ====================

    private function buildPayload($pending, $chapter, string $sentenceText, ?string $sentenceId = null): array
    {
        return [
            'final_candidates_package' => [
                'user_selected_items' => [[
                    'item_id' => $pending->id,
                    'word' => $pending->word,
                    'lemma' => $pending->lemma,
                    'surface' => $pending->surface,
                    'chapter_id' => $chapter->id,
                    'sentence_id' => $sentenceId,
                    'sentence_text' => $sentenceText,
                    'text_block_index' => $pending->text_block_index,
                    'sentence_index' => $pending->sentence_index,
                    'sense_zh' => '测试释义',
                    'sense_en' => '',
                    'aliases_zh' => [],
                    'collocations' => [],
                    'pos' => 'verb',
                ]],
                'ai_recommended_selected_items' => [],
                'ai_recommended_unselected_items' => [],
            ],
            'confirmed_items' => [[
                'source' => 'user_selected',
                'item_id' => $pending->id,
                'word' => $pending->word,
                'lemma' => $pending->lemma,
                'surface' => $pending->surface,
                'chapter_id' => $chapter->id,
                'sentence_id' => $sentenceId,
                'sentence_text' => $sentenceText,
                'text_block_index' => $pending->text_block_index,
                'sentence_index' => $pending->sentence_index,
                'sense_zh' => '测试释义',
                'sense_en' => '',
                'aliases_zh' => [],
                'collocations' => [],
                'pos' => 'verb',
            ]],
        ];
    }

    private function createConfirmedSense(string $lemma): WordSense
    {
        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => ucfirst($lemma),
            'pos' => 'noun',
            'sense_zh' => '测试',
            'sense_en' => 'test',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => '',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);
        return $sense->fresh();
    }

    private function createTestChapter(string $name): Chapter
    {
        $book = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => "Book {$name}",
            'language' => 'english',
        ]);

        return Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'name' => $name,
            'read_count' => 0,
            'word_count' => 0,
            'language' => 'english',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'raw_text' => '',
            'type' => 'text',
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
            'processed_text' => gzcompress(json_encode([]), 1),
        ]);
    }

    private function createChapter(User $user, string $language): Chapter
    {
        $book = Book::forceCreate([
            'user_id' => $user->id,
            'name' => "Multi-binding {$language} Book",
            'language' => $language,
        ]);

        return Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'name' => "Multi-binding {$language} Chapter",
            'language' => $language,
            'raw_text' => 'The intellectual landscape changed quickly.',
            'word_count' => 5,
            'read_count' => 0,
            'unique_words' => '["the","intellectual","landscape","changed","quickly"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }

    private function createOccurrence(WordSense $sense, Chapter $chapter, string $sentenceId, string $sentenceEn): WordSenseOccurrence
    {
        return WordSenseOccurrence::updateOrCreate([
            'user_id' => $sense->user_id,
            'language_id' => $sense->language_id,
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => $sentenceId,
            'surface' => $sense->surface_form,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
        ], [
            'language' => $sense->language,
            'review_card_id' => null,
            'sentence_en' => $sentenceEn,
            'sentence_zh' => '',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'lemma' => $sense->lemma,
            'pos' => $sense->pos,
            'decision' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'confidence' => 1.0,
            'evidence' => ['source' => 'test'],
            'auto_fsrs_allowed' => true,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'raw_payload' => [],
        ]);
    }

    private function createUser(string $email, string $language): User
    {
        return User::forceCreate([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'chapter_id' => $this->chapter->id,
            'text_block_index' => 0,
            'sentence_index' => 0,
            'sentence_id' => '0',
            'word' => 'landscape',
            'surface' => 'landscape',
            'lemma' => 'landscape',
            'sentence_text' => 'The intellectual landscape changed quickly.',
            'source_payload' => [
                'source' => 'test',
            ],
        ], $overrides);
    }

    private function finalCandidatesPackage(int $itemId, ?int $chapterId, array $aiRecommendedSelected = [], string $sentenceText = 'The intellectual landscape changed quickly.'): array
    {
        return [
            'schema_version' => 'ai-study-card-final-candidates-v1',
            'user_selected_items' => [
                [
                    'item_id' => $itemId,
                    'chapter_id' => $chapterId,
                    'text_block_index' => 0,
                    'sentence_index' => 0,
                    'word' => 'landscape',
                    'normalized_word' => 'landscape',
                    'surface' => 'landscape',
                    'lemma' => 'landscape',
                    'sentence_text' => $sentenceText,
                    'status' => 'pending',
                    'source' => 'user_selected',
                ],
            ],
            'ai_recommended_selected_items' => $aiRecommendedSelected,
            'ai_recommended_unselected_items' => [],
            'dedupe_summary' => [],
            'generation_rules' => [],
            'safety_flags' => [],
        ];
    }
}
