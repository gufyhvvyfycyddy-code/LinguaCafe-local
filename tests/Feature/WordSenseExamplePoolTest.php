<?php

namespace Tests\Feature;

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
 * Verifies the multi-example pool built from real reading material for a
 * WordSense, plus the rotation / supplementary-example logic used by the
 * sense review page.
 *
 * Invariants enforced:
 *  - Examples only come from real WordSenseOccurrence rows or the card
 *    example_sentence_en fallback. No AI is invoked.
 *  - Duplicate sentences within the same chapter are collapsed.
 *  - The question example rotates by a stable seed and is not always the
 *    first candidate.
 *  - The supplementary example is always different from the question
 *    example, and is null when only one candidate exists.
 *  - The service is read-only: no ReviewLog, no new WordSense, no new
 *    ReviewCard, no FSRS field changes.
 */
class WordSenseExamplePoolTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WordSenseService $wordSenseService;
    private WordSenseExamplePoolService $poolService;

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

        $this->user = $this->createUser('example-pool@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
        $this->poolService = app(WordSenseExamplePoolService::class);
    }

    public function test_multiple_occurrences_produce_multiple_candidates(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        $chapter1 = $this->createTestChapter([], ['name' => 'Chapter A']);
        $chapter2 = $this->createTestChapter([], ['name' => 'Chapter B']);

        $this->createOccurrence($sense, $chapter1, 'The Census Bureau released data.');
        $this->createOccurrence($sense, $chapter2, 'A federal bureau handled the case.');

        $candidates = $this->poolService->exampleCandidates($sense);

        $this->assertCount(2, $candidates, 'should return one candidate per distinct chapter sentence');
        $sentences = array_column($candidates, 'sentence_en');
        $this->assertContains('The Census Bureau released data.', $sentences);
        $this->assertContains('A federal bureau handled the case.', $sentences);
    }

    public function test_duplicate_sentences_in_same_chapter_are_collapsed(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        $chapter = $this->createTestChapter([], ['name' => 'Same Chapter']);

        $this->createOccurrence($sense, $chapter, 'The Census Bureau released data.');
        $this->createOccurrence($sense, $chapter, 'The Census Bureau released data.');

        $candidates = $this->poolService->exampleCandidates($sense);

        $this->assertCount(1, $candidates, 'duplicate sentence in same chapter must be collapsed');
    }

    public function test_card_example_is_used_as_fallback_when_no_occurrences(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau', 'The bureau opened at noon.');

        $candidates = $this->poolService->exampleCandidates($sense);

        $this->assertCount(1, $candidates, 'fallback card example should be returned');
        $this->assertSame('card_example', $candidates[0]['source_label']);
        $this->assertTrue($candidates[0]['is_card_fallback']);
    }

    public function test_card_example_fallback_is_deduplicated_against_occurrences(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau', 'The bureau opened at noon.');
        $chapter = $this->createTestChapter([], ['name' => 'Card Match Chapter']);
        $this->createOccurrence($sense, $chapter, $sense->example_sentence_en);

        $candidates = $this->poolService->exampleCandidates($sense);

        $this->assertCount(1, $candidates, 'card fallback should be skipped when identical to occurrence sentence');
        $this->assertFalse($candidates[0]['is_card_fallback']);
    }

    public function test_question_rotation_is_not_always_first(): void
    {
        // With 5 candidates and varying review_card_id, the rotated index
        // must NOT be 0 for every card. Otherwise rotation is broken.
        $indices = [];
        for ($cardId = 1; $cardId <= 30; $cardId++) {
            $indices[] = $this->poolService->pickQuestionIndex(5, $cardId, 0, 100);
        }
        $unique = array_unique($indices);
        $this->assertGreaterThan(1, count($unique), 'rotation must produce more than one distinct index across cards');
        $this->assertContains(0, $unique, 'index 0 should appear sometimes (sanity)');
    }

    public function test_question_rotation_is_stable_for_same_seed(): void
    {
        $i1 = $this->poolService->pickQuestionIndex(5, 42, 3, 100);
        $i2 = $this->poolService->pickQuestionIndex(5, 42, 3, 100);
        $this->assertSame($i1, $i2, 'same seed must produce same index');
    }

    public function test_question_rotation_changes_with_reps(): void
    {
        // fsrs_reps incrementing should shift the index for at least one
        // card across a reasonable sample. Otherwise the seed is broken.
        $changed = false;
        for ($cardId = 1; $cardId <= 20; $cardId++) {
            $before = $this->poolService->pickQuestionIndex(5, $cardId, 0, 100);
            $after = $this->poolService->pickQuestionIndex(5, $cardId, 1, 100);
            if ($before !== $after) {
                $changed = true;
                break;
            }
        }
        $this->assertTrue($changed, 'incrementing fsrs_reps should shift question index for at least one card');
    }

    public function test_supplementary_example_is_different_from_question(): void
    {
        for ($cardId = 1; $cardId <= 30; $cardId++) {
            $q = $this->poolService->pickQuestionIndex(4, $cardId, 0, 100);
            $s = $this->poolService->pickSupplementaryIndex(4, $q, $cardId, 0, 100);
            $this->assertNotNull($s, 'supplementary must be non-null when total >= 2');
            $this->assertNotSame($q, $s, "supplementary must differ from question (cardId=$cardId, q=$q, s=$s)");
        }
    }

    public function test_supplementary_is_null_when_only_one_candidate(): void
    {
        $q = $this->poolService->pickQuestionIndex(1, 42, 0, 100);
        $s = $this->poolService->pickSupplementaryIndex(1, $q, 42, 0, 100);
        $this->assertNull($s, 'supplementary must be null when total < 2');
    }

    public function test_pool_does_not_write_reviewlog_or_create_senses_or_cards(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        $chapter = $this->createTestChapter([], ['name' => 'Chapter A']);
        $this->createOccurrence($sense, $chapter, 'The Census Bureau released data.');

        $reviewLogBefore = ReviewLog::count();
        $senseBefore = WordSense::count();
        $cardBefore = ReviewCard::count();

        $this->poolService->exampleCandidates($sense);

        $this->assertSame($reviewLogBefore, ReviewLog::count(), 'no ReviewLog must be written');
        $this->assertSame($senseBefore, WordSense::count(), 'no WordSense must be created');
        $this->assertSame($cardBefore, ReviewCard::count(), 'no ReviewCard must be created');
    }

    public function test_serializer_payload_includes_candidates_and_supplementary(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        $chapter1 = $this->createTestChapter([], ['name' => 'Chapter A']);
        $chapter2 = $this->createTestChapter([], ['name' => 'Chapter B']);
        $this->createOccurrence($sense, $chapter1, 'The Census Bureau released data.');
        $this->createOccurrence($sense, $chapter2, 'A federal bureau handled the case.');

        $card = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => 'sense',
            'target_id' => $sense->id,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subDay(),
            'fsrs_stability' => 1.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 2,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => now()->subDay(),
        ]);

        $serialized = app(\App\Services\SenseReviewCardSerializerService::class)->serialize($card->fresh()->load('sense'));

        $this->assertArrayHasKey('example_candidates', $serialized);
        $this->assertArrayHasKey('example_candidates_count', $serialized);
        $this->assertArrayHasKey('supplementary_example', $serialized);
        $this->assertSame(2, $serialized['example_candidates_count']);
        $this->assertNotNull($serialized['supplementary_example'], 'supplementary must be non-null when 2 candidates');
        $this->assertNotSame(
            $serialized['example_sentence_en'],
            $serialized['supplementary_example']['sentence_en'],
            'serialized question and supplementary examples must differ'
        );
    }

    public function test_serializer_payload_supplementary_is_null_for_single_candidate(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau', 'The bureau opened at noon.');
        // No occurrences — only card_example fallback, so single candidate.

        $card = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => 'sense',
            'target_id' => $sense->id,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subDay(),
            'fsrs_stability' => 1.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => now()->subDay(),
        ]);

        $serialized = app(\App\Services\SenseReviewCardSerializerService::class)->serialize($card->fresh()->load('sense'));

        $this->assertSame(1, $serialized['example_candidates_count']);
        $this->assertNull($serialized['supplementary_example'], 'supplementary must be null when only 1 candidate');
    }

    // ==================== Helpers ====================

    private function createConfirmedSense(string $lemma, string $surfaceForm, string $exampleEn = '', string $exampleZh = ''): WordSense
    {
        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $surfaceForm,
            'pos' => 'noun',
            'sense_zh' => '局',
            'sense_en' => 'an office',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => $exampleEn,
            'example_sentence_zh' => $exampleZh,
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);
        return $sense->fresh();
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

    private function createOccurrence(WordSense $sense, Chapter $chapter, string $sentenceEn): WordSenseOccurrence
    {
        return WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => (string) rand(1, 1000),
            'sentence_en' => $sentenceEn,
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
