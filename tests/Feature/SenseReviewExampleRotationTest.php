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
use App\Services\WordSenseExamplePoolService;
use App\Services\WordSenseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewExampleRotationTest
 *
 * GM52-SenseMultiExampleBindingAndReviewRotation-1000-6
 *
 * Verifies the sense review page example rotation contract:
 *  - With 1 occurrence the displayed example is that occurrence.
 *  - With 3 occurrences consecutive serialization does not always
 *    show the first candidate.
 *  - After a rating (fsrs_reps increment) the displayed occurrence
 *    shifts for at least one card.
 *  - Payload includes displayed_occurrence_id and occurrence_count.
 *  - Without occurrences, the card example_sentence_en fallback is
 *    used (example_source_status = 'card_fallback').
 *  - Without any example, example_source_status = 'empty'.
 *  - Rotation does not write an extra ReviewLog.
 *  - Rotation does not change FSRS due_at / stability / difficulty.
 *  - Rotation does not affect the daily limit summary.
 *
 * The rotation is deterministic (stable seed: review_card_id +
 * fsrs_reps + day-of-year), so these tests assert structural
 * invariants rather than a specific sequence.
 */
class SenseReviewExampleRotationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WordSenseService $wordSenseService;
    private WordSenseExamplePoolService $poolService;
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

        $this->user = $this->createUser('rotation@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
        $this->poolService = app(WordSenseExamplePoolService::class);
        $this->serializerService = app(SenseReviewCardSerializerService::class);
    }

    public function test_single_occurrence_displays_that_example(): void
    {
        $sense = $this->createConfirmedSense('bureau');
        $chapter = $this->createTestChapter('Single Occurrence Chapter');
        $this->createOccurrence($sense, $chapter, 'sent-1', 'The bureau opened at noon.');

        $card = $this->createSenseCard($sense);

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $this->assertSame('The bureau opened at noon.', $payload['example_sentence_en']);
        $this->assertNotNull($payload['displayed_occurrence_id'], 'displayed_occurrence_id must be set when an occurrence exists');
        $this->assertSame('occurrence', $payload['example_source_status']);
        $this->assertSame(1, $payload['occurrence_count']);
    }

    public function test_three_occurrences_do_not_always_show_first(): void
    {
        $sense = $this->createConfirmedSense('bureau');
        $chapter1 = $this->createTestChapter('Chapter A');
        $chapter2 = $this->createTestChapter('Chapter B');
        $chapter3 = $this->createTestChapter('Chapter C');

        $sentences = [
            'The Census Bureau released data.',
            'A federal bureau handled the case.',
            'The bureau is closed today.',
        ];
        $this->createOccurrence($sense, $chapter1, 's1', $sentences[0]);
        $this->createOccurrence($sense, $chapter2, 's2', $sentences[1]);
        $this->createOccurrence($sense, $chapter3, 's3', $sentences[2]);

        // A single sense can only have one ReviewCard (unique constraint).
        // The rotation seed depends on review_card_id + fsrs_reps + day-of-year,
        // so we simulate "consecutive reviews" by incrementing fsrs_reps on the
        // same card. Across reps 0..29 the displayed example must not always be
        // the first candidate.
        $card = $this->createSenseCard($sense);

        $shownSentences = [];
        for ($reps = 0; $reps <= 29; $reps++) {
            $card->fsrs_reps = $reps;
            $card->save();
            $payload = $this->serializerService->serialize($card->fresh()->load('sense'));
            $shownSentences[] = $payload['example_sentence_en'];
        }

        $unique = array_unique($shownSentences);
        $this->assertGreaterThan(1, count($unique), 'rotation must produce more than one distinct example across reps');
        $this->assertContains($sentences[0], $unique, 'first sentence should appear sometimes (sanity)');
    }

    public function test_rating_changes_displayed_occurrence(): void
    {
        // With 3 occurrences, incrementing fsrs_reps must shift the
        // displayed_occurrence_id for at least one reps value on the same card.
        // The rotation seed depends on review_card_id + fsrs_reps + day-of-year,
        // so changing reps on the same card is the correct way to simulate
        // "the next review shows a different example".
        $sense = $this->createConfirmedSense('bureau');
        $chapter1 = $this->createTestChapter('Chapter A');
        $chapter2 = $this->createTestChapter('Chapter B');
        $chapter3 = $this->createTestChapter('Chapter C');

        $this->createOccurrence($sense, $chapter1, 's1', 'The Census Bureau released data.');
        $this->createOccurrence($sense, $chapter2, 's2', 'A federal bureau handled the case.');
        $this->createOccurrence($sense, $chapter3, 's3', 'The bureau is closed today.');

        $card = $this->createSenseCard($sense, ['fsrs_reps' => 0]);

        $changed = false;
        $previousOccurrenceId = null;
        for ($reps = 0; $reps <= 29; $reps++) {
            $card->fsrs_reps = $reps;
            $card->save();
            $payload = $this->serializerService->serialize($card->fresh()->load('sense'));
            $currentOccurrenceId = $payload['displayed_occurrence_id'];
            if ($previousOccurrenceId !== null && $previousOccurrenceId !== $currentOccurrenceId) {
                $changed = true;
                break;
            }
            $previousOccurrenceId = $currentOccurrenceId;
        }
        $this->assertTrue($changed, 'incrementing fsrs_reps should shift displayed_occurrence_id at least once across 30 reps');
    }

    public function test_displayed_occurrence_id_in_payload(): void
    {
        $sense = $this->createConfirmedSense('bureau');
        $chapter = $this->createTestChapter('Payload Chapter');
        $this->createOccurrence($sense, $chapter, 's1', 'The bureau opened at noon.');

        $card = $this->createSenseCard($sense);

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $this->assertArrayHasKey('displayed_occurrence_id', $payload);
        $this->assertNotNull($payload['displayed_occurrence_id'], 'displayed_occurrence_id must be non-null when an occurrence exists');
    }

    public function test_occurrence_count_in_payload(): void
    {
        $sense = $this->createConfirmedSense('bureau');
        $chapter1 = $this->createTestChapter('Chapter A');
        $chapter2 = $this->createTestChapter('Chapter B');

        $this->createOccurrence($sense, $chapter1, 's1', 'First sentence.');
        $this->createOccurrence($sense, $chapter2, 's2', 'Second sentence.');

        $card = $this->createSenseCard($sense);

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $this->assertArrayHasKey('occurrence_count', $payload);
        $this->assertSame(2, $payload['occurrence_count'], 'occurrence_count must equal the number of distinct source examples');
    }

    public function test_no_occurrence_falls_back_to_card_example(): void
    {
        // No occurrences, but the sense has its own example_sentence_en.
        $sense = $this->createConfirmedSense('bureau', 'The bureau opened at noon.');

        $card = $this->createSenseCard($sense);

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $this->assertSame('The bureau opened at noon.', $payload['example_sentence_en']);
        $this->assertSame('card_fallback', $payload['example_source_status']);
        $this->assertNull($payload['displayed_occurrence_id'], 'no occurrence id when falling back to card example');
        $this->assertSame(1, $payload['occurrence_count'], 'card fallback counts as 1 candidate');
    }

    public function test_no_example_at_all_shows_empty_status(): void
    {
        // No occurrences and no card example_sentence_en.
        $sense = $this->createConfirmedSense('bureau');

        $card = $this->createSenseCard($sense);

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $this->assertSame('empty', $payload['example_source_status']);
        $this->assertNull($payload['displayed_occurrence_id']);
        $this->assertSame(0, $payload['occurrence_count']);
        // The legacy example_sentence_en field is null/empty when nothing exists.
        $this->assertEmpty($payload['example_sentence_en']);
    }

    public function test_rotation_does_not_write_extra_review_log(): void
    {
        $sense = $this->createConfirmedSense('bureau');
        $chapter1 = $this->createTestChapter('Chapter A');
        $chapter2 = $this->createTestChapter('Chapter B');
        $chapter3 = $this->createTestChapter('Chapter C');

        $this->createOccurrence($sense, $chapter1, 's1', 'First sentence.');
        $this->createOccurrence($sense, $chapter2, 's2', 'Second sentence.');
        $this->createOccurrence($sense, $chapter3, 's3', 'Third sentence.');

        $card = $this->createSenseCard($sense);

        $reviewLogBefore = ReviewLog::count();

        // Serialize multiple times — this must not write any ReviewLog.
        for ($i = 0; $i < 5; $i++) {
            $this->serializerService->serialize($card->fresh()->load('sense'));
        }

        $reviewLogAfter = ReviewLog::count();
        $this->assertSame($reviewLogBefore, $reviewLogAfter, 'rotation must not write extra ReviewLog entries');
    }

    public function test_rotation_does_not_change_fsrs_fields(): void
    {
        $sense = $this->createConfirmedSense('bureau');
        $chapter1 = $this->createTestChapter('Chapter A');
        $chapter2 = $this->createTestChapter('Chapter B');
        $chapter3 = $this->createTestChapter('Chapter C');

        $this->createOccurrence($sense, $chapter1, 's1', 'First sentence.');
        $this->createOccurrence($sense, $chapter2, 's2', 'Second sentence.');
        $this->createOccurrence($sense, $chapter3, 's3', 'Third sentence.');

        $card = $this->createSenseCard($sense, [
            'fsrs_stability' => 12.34,
            'fsrs_difficulty' => 5.67,
        ]);

        $dueBefore = $card->fsrs_due_at->toIso8601String();
        $stabilityBefore = $card->fsrs_stability;
        $difficultyBefore = $card->fsrs_difficulty;
        $repsBefore = $card->fsrs_reps;

        // Serialize multiple times — FSRS fields must not change.
        for ($i = 0; $i < 5; $i++) {
            $this->serializerService->serialize($card->fresh()->load('sense'));
        }

        $card->refresh();
        $this->assertSame($dueBefore, $card->fsrs_due_at->toIso8601String(), 'fsrs_due_at must not change');
        $this->assertSame($stabilityBefore, $card->fsrs_stability, 'fsrs_stability must not change');
        $this->assertSame($difficultyBefore, $card->fsrs_difficulty, 'fsrs_difficulty must not change');
        $this->assertSame($repsBefore, $card->fsrs_reps, 'fsrs_reps must not change');
    }

    public function test_rotation_does_not_affect_daily_limits(): void
    {
        // Set a tight daily review limit.
        Setting::forceCreate(['name' => 'daily_review_limit', 'user_id' => -1, 'value' => json_encode(2)]);
        Setting::forceCreate(['name' => 'daily_review_limit_enabled', 'user_id' => -1, 'value' => json_encode(true)]);

        $sense = $this->createConfirmedSense('bureau');
        $chapter1 = $this->createTestChapter('Chapter A');
        $chapter2 = $this->createTestChapter('Chapter B');
        $chapter3 = $this->createTestChapter('Chapter C');

        $this->createOccurrence($sense, $chapter1, 's1', 'First sentence.');
        $this->createOccurrence($sense, $chapter2, 's2', 'Second sentence.');
        $this->createOccurrence($sense, $chapter3, 's3', 'Third sentence.');

        $card = $this->createSenseCard($sense);

        // Snapshot the summary before any rotation.
        $responseBefore = $this->actingAs($this->user)->getJson('/reviews/senses');
        $responseBefore->assertOk();
        $summaryBefore = $responseBefore->json('summary');

        // Run rotation multiple times — must not affect the daily limit summary.
        for ($i = 0; $i < 5; $i++) {
            $this->serializerService->serialize($card->fresh()->load('sense'));
        }

        $responseAfter = $this->actingAs($this->user)->getJson('/reviews/senses');
        $responseAfter->assertOk();
        $summaryAfter = $responseAfter->json('summary');

        $this->assertSame(
            $summaryBefore['total_due_count'],
            $summaryAfter['total_due_count'],
            'rotation must not change total_due_count'
        );
        $this->assertSame(
            $summaryBefore['visible_count'],
            $summaryAfter['visible_count'],
            'rotation must not change visible_count'
        );
        $this->assertSame(
            $summaryBefore['remaining_review_slots'],
            $summaryAfter['remaining_review_slots'],
            'rotation must not change remaining_review_slots'
        );
        $this->assertSame(
            $summaryBefore['reviewed_today_count'],
            $summaryAfter['reviewed_today_count'],
            'rotation must not change reviewed_today_count'
        );
        $this->assertSame(
            $summaryBefore['limit_reached'],
            $summaryAfter['limit_reached'],
            'rotation must not change limit_reached'
        );
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

    private function createSenseCard(WordSense $sense, array $overrides = [], int $cardId = 0): ReviewCard
    {
        // When a specific id is needed for rotation seed testing, force it.
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

        $card = ReviewCard::forceCreate($data);

        // Allow tests to pin a specific id (used for stable-seed rotation tests).
        if ($cardId > 0 && $card->id !== $cardId) {
            $card->update(['id' => $cardId]);
            $card = $card->fresh();
        }

        return $card;
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
