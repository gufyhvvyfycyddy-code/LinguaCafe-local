<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\SenseReviewCardSerializerService;
use App\Services\WordSenseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewContextualUnderstandingTest
 *
 * GM52-SenseReviewContextualUnderstanding-1000-10
 *
 * Verifies the smart example selection + occurrence-level understanding aid:
 *  - When a preferred occurrence id is supplied (e.g. the currently displayed
 *    one), the serializer selects that occurrence as the question example.
 *  - When no preference is given, the linear rotation is used as fallback.
 *  - Occurrence-level evidence (context_hint / judgment_basis) is merged into
 *    the payload's understanding_aid, overriding sense-level values when both
 *    exist and falling back to sense-level when occurrence evidence is empty.
 *  - The smart selection + contextual understanding does NOT write ReviewLog
 *    or modify any FSRS field.
 *  - When the preferred occurrence id is not in the candidate pool, the
 *    serializer falls back to linear rotation without error.
 *  - When occurrence evidence is null/empty, sense-level understanding_aid
 *    is used as before (backward compatible).
 */
class SenseReviewContextualUnderstandingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WordSenseService $wordSenseService;
    private SenseReviewCardSerializerService $serializerService;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Setting::where('name', 'reviewIntervals')->exists()) {
            Setting::forceCreate([
                'name' => 'reviewIntervals',
                'value' => json_encode([
                    '-7' => [0], '-6' => [1], '-5' => [2], '-4' => [3],
                    '-3' => [7], '-2' => [15], '-1' => [30],
                ]),
            ]);
        }

        $this->user = $this->createUser('contextual-aid@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
        $this->serializerService = app(SenseReviewCardSerializerService::class);
    }

    /**
     * When a preferred occurrence id is supplied and it exists in the
     * candidate pool, the serializer must select it as the question example.
     */
    public function test_preferred_occurrence_is_selected_when_available(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $chapter = $this->createTestChapter('Chapter A');
        $occ1 = $this->createOccurrence($sense, $chapter, 's1', 'First example sentence.');
        $occ2 = $this->createOccurrence($sense, $chapter, 's2', 'Second example sentence.');
        $card = $this->createSenseCard($sense);

        // Without preference, reps=0 picks the linear-rotation index, which
        // lands on occ2 (candidates[0] due to id DESC ordering). With an
        // explicit preference for occ2, the serializer must still pick occ2.
        $payload = $this->serializerService->serialize(
            $card->fresh()->load('sense'),
            ['preferred_occurrence_id' => $occ2->id]
        );

        $this->assertSame($occ2->id, $payload['displayed_occurrence_id']);
        $this->assertSame('Second example sentence.', $payload['example_sentence_en']);
        $this->assertSame('occurrence', $payload['example_source_status']);
    }

    /**
     * When no preferred occurrence id is supplied, the serializer falls back
     * to the linear rotation (backward compatible with previous behavior).
     */
    public function test_falls_back_to_linear_rotation_when_no_preference(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $chapter = $this->createTestChapter('Chapter A');
        $occ1 = $this->createOccurrence($sense, $chapter, 's1', 'First example sentence.');
        $occ2 = $this->createOccurrence($sense, $chapter, 's2', 'Second example sentence.');
        $card = $this->createSenseCard($sense, ['fsrs_reps' => 1]);

        // No preferred_occurrence_id → linear rotation: (cardId + 1 + 0) % 2.
        // exampleCandidates orders by id DESC, so candidates[0] = occ2 (higher
        // id, created later) and candidates[1] = occ1.
        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $linearIndex = ($card->id + 1) % 2;
        $expectedOccId = $linearIndex === 0 ? $occ2->id : $occ1->id;
        $this->assertSame($expectedOccId, $payload['displayed_occurrence_id']);
    }

    /**
     * When the preferred occurrence id is NOT in the candidate pool (e.g.
     * it was deleted or belongs to another sense), the serializer must
     * fall back to linear rotation without error.
     */
    public function test_falls_back_when_preferred_occurrence_not_in_pool(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $chapter = $this->createTestChapter('Chapter A');
        $this->createOccurrence($sense, $chapter, 's1', 'First example sentence.');
        $card = $this->createSenseCard($sense);

        // 999999 is a non-existent occurrence id
        $payload = $this->serializerService->serialize(
            $card->fresh()->load('sense'),
            ['preferred_occurrence_id' => 999999]
        );

        // Should not crash, should still return a valid payload
        $this->assertNotNull($payload['example_sentence_en']);
        $this->assertContains($payload['example_source_status'], ['occurrence', 'card_fallback', 'empty']);
    }

    /**
     * Occurrence-level evidence (context_hint / judgment_basis) should be
     * merged into the payload's understanding_aid, overriding sense-level
     * values when both exist.
     */
    public function test_occurrence_evidence_overrides_sense_understanding_aid(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $sense->understanding_aid = [
            'explanation' => 'sense-level explanation',
            'meaning_boundary' => 'sense-level boundary',
            'context_hint' => 'sense-level hint',
            'usage_keywords' => ['sense-kw1'],
        ];
        $sense->save();

        $chapter = $this->createTestChapter('Chapter A');
        $occ = $this->createOccurrence($sense, $chapter, 's1', 'I went to the bank yesterday.');
        // Occurrence-level evidence provides occurrence-specific context
        $occ->evidence = [
            'context_hint' => 'occurrence-level: go to the bank = financial institution',
            'judgment_basis' => ['go to', 'money', 'account'],
            'related_collocations' => ['bank account', 'central bank'],
        ];
        $occ->save();

        $card = $this->createSenseCard($sense);

        $payload = $this->serializerService->serialize(
            $card->fresh()->load('sense'),
            ['preferred_occurrence_id' => $occ->id]
        );

        $aid = $payload['understanding_aid'];
        // Occurrence-level context_hint overrides sense-level
        $this->assertSame('occurrence-level: go to the bank = financial institution', $aid['context_hint']);
        // Occurrence-level judgment_basis becomes usage_keywords (occurrence-specific)
        $this->assertSame(['go to', 'money', 'account'], $aid['usage_keywords']);
        // Occurrence-level related_collocations becomes related_collocations
        $this->assertSame(['bank account', 'central bank'], $aid['related_collocations']);
        // Sense-level fields that occurrence doesn't override are preserved
        $this->assertSame('sense-level explanation', $aid['explanation']);
        $this->assertSame('sense-level boundary', $aid['meaning_boundary']);
    }

    /**
     * When occurrence evidence is null/empty, sense-level understanding_aid
     * is used as before (backward compatible).
     */
    public function test_empty_occurrence_evidence_falls_back_to_sense_level(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $sense->understanding_aid = [
            'explanation' => 'sense-level explanation',
            'meaning_boundary' => null,
            'context_hint' => 'sense-level hint',
            'usage_keywords' => ['sense-kw1'],
        ];
        $sense->save();

        $chapter = $this->createTestChapter('Chapter A');
        $occ = $this->createOccurrence($sense, $chapter, 's1', 'I went to the bank yesterday.');
        // evidence is ['source' => 'test'] from helper — no understanding fields
        $occ->evidence = null;
        $occ->save();

        $card = $this->createSenseCard($sense);

        $payload = $this->serializerService->serialize(
            $card->fresh()->load('sense'),
            ['preferred_occurrence_id' => $occ->id]
        );

        $aid = $payload['understanding_aid'];
        $this->assertSame('sense-level explanation', $aid['explanation']);
        $this->assertSame('sense-level hint', $aid['context_hint']);
        $this->assertSame(['sense-kw1'], $aid['usage_keywords']);
        $this->assertNull($aid['meaning_boundary']);
        // related_collocations defaults to empty when no occurrence evidence
        $this->assertSame([], $aid['related_collocations']);
    }

    /**
     * Smart selection + contextual understanding must NOT write ReviewLog.
     */
    public function test_contextual_understanding_does_not_write_review_log(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $chapter = $this->createTestChapter('Chapter A');
        $occ = $this->createOccurrence($sense, $chapter, 's1', 'I went to the bank yesterday.');
        $occ->evidence = ['context_hint' => 'hint'];
        $occ->save();
        $card = $this->createSenseCard($sense);

        $beforeCount = ReviewLog::where('review_card_id', $card->id)->count();

        // Serialize 5 times with preferred occurrence
        for ($i = 0; $i < 5; $i++) {
            $this->serializerService->serialize(
                $card->fresh()->load('sense'),
                ['preferred_occurrence_id' => $occ->id]
            );
        }

        $afterCount = ReviewLog::where('review_card_id', $card->id)->count();
        $this->assertSame($beforeCount, $afterCount, 'serialize must not write ReviewLog');
    }

    /**
     * Smart selection + contextual understanding must NOT modify FSRS fields.
     */
    public function test_contextual_understanding_does_not_change_fsrs_fields(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $chapter = $this->createTestChapter('Chapter A');
        $occ = $this->createOccurrence($sense, $chapter, 's1', 'I went to the bank yesterday.');
        $occ->evidence = ['context_hint' => 'hint'];
        $occ->save();
        $card = $this->createSenseCard($sense, [
            'fsrs_due_at' => Carbon::now()->addDays(3),
            'fsrs_stability' => 2.5,
            'fsrs_difficulty' => 4.5,
            'fsrs_reps' => 2,
        ]);

        $beforeDue = $card->fsrs_due_at->toIso8601String();
        $beforeStability = $card->fsrs_stability;
        $beforeDifficulty = $card->fsrs_difficulty;
        $beforeReps = $card->fsrs_reps;

        // Serialize 5 times with preferred occurrence
        for ($i = 0; $i < 5; $i++) {
            $this->serializerService->serialize(
                $card->fresh()->load('sense'),
                ['preferred_occurrence_id' => $occ->id]
            );
        }

        $after = $card->fresh();
        $this->assertSame($beforeDue, $after->fsrs_due_at->toIso8601String());
        $this->assertSame($beforeStability, $after->fsrs_stability);
        $this->assertSame($beforeDifficulty, $after->fsrs_difficulty);
        $this->assertSame($beforeReps, $after->fsrs_reps);
    }

    /**
     * When source context is available (chapter_id set), the occurrence is
     * considered "source-context-complete" and preferred over occurrences
     * without source context, even without an explicit preferred_occurrence_id.
     */
    public function test_source_context_complete_occurrence_preferred_when_no_preference(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $chapter = $this->createTestChapter('Chapter A');
        // occ1 has source context (chapter_id set)
        $occ1 = $this->createOccurrence($sense, $chapter, 's1', 'First with context.');
        // occ2 also has source context — both complete, so linear rotation decides
        $occ2 = $this->createOccurrence($sense, $chapter, 's2', 'Second with context.');
        $card = $this->createSenseCard($sense);

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        // Both have source context, so linear rotation applies.
        // exampleCandidates orders by id DESC, so candidates[0] = occ2.
        $linearIndex = ($card->id + 0 + 0) % 2;
        $expectedOccId = $linearIndex === 0 ? $occ2->id : $occ1->id;
        $this->assertSame($expectedOccId, $payload['displayed_occurrence_id']);
        $this->assertSame('occurrence', $payload['example_source_status']);
    }

    // ==================== Helpers ====================

    private function createConfirmedSense(string $lemma, string $exampleEn = ''): WordSense
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
            'example_sentence_en' => $exampleEn,
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);
        return $sense->fresh();
    }

    private function createTestChapter(string $name): Chapter
    {
        return Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => 1,
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

    private function createSenseCard(WordSense $sense, array $overrides = []): ReviewCard
    {
        $data = array_merge([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->subDay(),
            'fsrs_stability' => 1.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDay(),
        ], $overrides);

        return ReviewCard::forceCreate($data);
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
            'uuid' => (string) Str::uuid(),
        ]);
    }
}
