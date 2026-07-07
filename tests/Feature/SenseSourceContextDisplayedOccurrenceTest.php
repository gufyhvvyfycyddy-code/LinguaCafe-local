<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\WordSenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseSourceContextDisplayedOccurrenceTest
 *
 * GM52-SenseSourceContextFollowDisplayedOccurrence-1000-7
 *
 * Verifies that /senses/{id}/source-context-list honors the optional
 * ?preferred_occurrence_id= query parameter so the source dialog opens
 * on the example the user is currently looking at on the review card.
 *
 * Coverage (14 invariants required by the task spec):
 *  1.  Valid preferred occurrence -> sources[0].occurrence_id === preferred id.
 *  2.  Preferred occurrence is not duplicated later in sources.
 *  3.  Preferred occurrence that belongs to another user -> dropped, fallback.
 *  4.  Preferred occurrence tagged with another language -> dropped, fallback.
 *  5.  Preferred occurrence bound to a different sense -> dropped, fallback.
 *  6.  Preferred occurrence whose status is not 'bound' -> dropped, fallback.
 *  7.  Preferred occurrence with no chapter_id -> no 500, fallback.
 *  8.  No preferred_occurrence_id param -> original multi-source behavior.
 *  9.  Payload always contains preferred_occurrence_status.
 *  10. Endpoint writes no ReviewLog.
 *  11. Endpoint does not modify FSRS fields.
 *  12. Endpoint does not create legacy word ReviewCard.
 *  13. Endpoint does not create new WordSenseOccurrence rows.
 *  14. Source-context fallback chain still serves card_example / unavailable
 *      when no chapter-based sources exist.
 */
class SenseSourceContextDisplayedOccurrenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private WordSenseService $wordSenseService;

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

        $this->user = $this->createUser('displayed-occ@example.com', 'english');
        $this->otherUser = $this->createUser('other-displayed-occ@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
    }

    public function test_valid_preferred_occurrence_becomes_first_source(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        $chapter1 = $this->createTestChapter($this->wordsFor('Bureau opened.'), ['name' => 'Chapter A']);
        $chapter2 = $this->createTestChapter($this->wordsFor('Federal Bureau acted.'), ['name' => 'Chapter B']);
        $chapter3 = $this->createTestChapter($this->wordsFor('The Bureau closed.'), ['name' => 'Chapter C']);

        $occ1 = $this->createOccurrence($sense, $chapter1, '0', 'Bureau opened.');
        $occ2 = $this->createOccurrence($sense, $chapter2, '0', 'Federal Bureau acted.');
        $occ3 = $this->createOccurrence($sense, $chapter3, '0', 'The Bureau closed.');

        // Without preferred, orderByDesc('id') would put occ3 first. Pass occ1
        // as preferred to force it to sources[0].
        $response = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list?preferred_occurrence_id=' . $occ1->id);

        $response->assertOk();
        $json = $response->json();

        $this->assertSame('matched', $json['preferred_occurrence_status']);
        $this->assertNotEmpty($json['sources']);
        $this->assertSame($occ1->id, $json['sources'][0]['occurrence_id'], 'sources[0] must be the preferred occurrence');
        $this->assertSame($chapter1->id, $json['sources'][0]['chapter_id']);
    }

    public function test_preferred_occurrence_not_duplicated_in_sources(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        $chapter1 = $this->createTestChapter($this->wordsFor('Bureau opened.'), ['name' => 'Chapter A']);
        $chapter2 = $this->createTestChapter($this->wordsFor('Federal Bureau acted.'), ['name' => 'Chapter B']);

        $occ1 = $this->createOccurrence($sense, $chapter1, '0', 'Bureau opened.');
        $occ2 = $this->createOccurrence($sense, $chapter2, '0', 'Federal Bureau acted.');

        $response = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list?preferred_occurrence_id=' . $occ1->id);

        $response->assertOk();
        $json = $response->json();

        $ids = array_column($json['sources'], 'occurrence_id');
        $counts = array_count_values($ids);
        $this->assertSame(1, $counts[$occ1->id], 'preferred occurrence must not appear more than once');
    }

    public function test_preferred_occurrence_belongs_to_other_user_is_dropped(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        $myChapter = $this->createTestChapter($this->wordsFor('Bureau opened.'), ['name' => 'My Chapter']);
        $myOcc = $this->createOccurrence($sense, $myChapter, '0', 'Bureau opened.');

        // Other user's occurrence bound to a different sense they own.
        $otherSense = $this->createConfirmedSenseForUser($this->otherUser, 'bureau', 'Bureau');
        $otherChapter = $this->createTestChapter($this->wordsFor('Other Bureau.'), [
            'user_id' => $this->otherUser->id,
            'name' => 'Other Chapter',
        ]);
        $otherOcc = $this->createOccurrenceForUser($this->otherUser, $otherSense, $otherChapter, '0', 'Other Bureau.');

        $response = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list?preferred_occurrence_id=' . $otherOcc->id);

        $response->assertOk();
        $json = $response->json();

        // other user's occurrence must NOT be the first source.
        $this->assertNotSame('matched', $json['preferred_occurrence_status']);
        $this->assertNotSame($otherOcc->id, $json['sources'][0]['occurrence_id'] ?? null);
        foreach ($json['sources'] as $source) {
            if (isset($source['occurrence_id'])) {
                $this->assertNotSame($otherOcc->id, $source['occurrence_id'], 'other user occurrence must not leak');
            }
        }
    }

    public function test_preferred_occurrence_with_wrong_language_is_dropped(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        $chapter = $this->createTestChapter($this->wordsFor('Bureau opened.'), ['name' => 'English Chapter']);
        $occ = $this->createOccurrence($sense, $chapter, '0', 'Bureau opened.');

        // Same user, same sense, but occurrence tagged with a different language.
        $foreignOcc = WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'japanese',
            'language_id' => 'japanese',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => '0',
            'sentence_en' => 'Japanese Bureau.',
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

        $response = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list?preferred_occurrence_id=' . $foreignOcc->id);

        $response->assertOk();
        $json = $response->json();

        $this->assertNotSame('matched', $json['preferred_occurrence_status']);
        foreach ($json['sources'] as $source) {
            if (isset($source['occurrence_id'])) {
                $this->assertNotSame($foreignOcc->id, $source['occurrence_id'], 'wrong-language occurrence must not leak');
            }
        }
    }

    public function test_preferred_occurrence_bound_to_different_sense_is_dropped(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        $chapter = $this->createTestChapter($this->wordsFor('Bureau opened.'), ['name' => 'Chapter A']);
        $occ = $this->createOccurrence($sense, $chapter, '0', 'Bureau opened.');

        // Another sense owned by the same user.
        $otherSense = $this->createConfirmedSense('agency', 'Agency');
        $otherChapter = $this->createTestChapter($this->wordsFor('Agency opened.'), ['name' => 'Chapter B']);
        $otherOcc = $this->createOccurrence($otherSense, $otherChapter, '0', 'Agency opened.');

        $response = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list?preferred_occurrence_id=' . $otherOcc->id);

        $response->assertOk();
        $json = $response->json();

        $this->assertNotSame('matched', $json['preferred_occurrence_status']);
        foreach ($json['sources'] as $source) {
            if (isset($source['occurrence_id'])) {
                $this->assertNotSame($otherOcc->id, $source['occurrence_id'], 'other-sense occurrence must not appear');
            }
        }
    }

    public function test_preferred_occurrence_not_bound_is_dropped(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        $chapter = $this->createTestChapter($this->wordsFor('Bureau opened.'), ['name' => 'Chapter A']);
        $bound = $this->createOccurrence($sense, $chapter, '0', 'Bureau opened.');

        // Pending occurrence for the same sense — must be rejected as preferred.
        $pending = WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => '0',
            'sentence_en' => 'Pending Bureau.',
            'sentence_zh' => '',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => $sense->surface_form,
            'lemma' => $sense->lemma,
            'pos' => $sense->pos,
            'decision' => 'match_existing_sense',
            'confidence' => 1.0,
            'status' => WordSenseOccurrence::STATUS_PENDING,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list?preferred_occurrence_id=' . $pending->id);

        $response->assertOk();
        $json = $response->json();

        $this->assertNotSame('matched', $json['preferred_occurrence_status']);
        foreach ($json['sources'] as $source) {
            if (isset($source['occurrence_id'])) {
                $this->assertNotSame($pending->id, $source['occurrence_id'], 'pending occurrence must not appear');
            }
        }
    }

    public function test_preferred_occurrence_without_chapter_does_not_500_and_falls_back(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        $chapter = $this->createTestChapter($this->wordsFor('Bureau opened.'), ['name' => 'Chapter A']);
        $withChapter = $this->createOccurrence($sense, $chapter, '0', 'Bureau opened.');

        // Bound occurrence for the same sense but with chapter_id = null.
        $noChapter = WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => null,
            'sentence_id' => '0',
            'sentence_en' => 'Bureau without chapter.',
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

        $response = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list?preferred_occurrence_id=' . $noChapter->id);

        $response->assertOk();
        $json = $response->json();

        $this->assertNotSame('matched', $json['preferred_occurrence_status']);
        // The fallback chapter-based occurrence should still be served.
        $ids = array_column($json['sources'], 'occurrence_id');
        $this->assertContains($withChapter->id, $ids);
    }

    public function test_no_preferred_param_preserves_original_behavior(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        $chapter1 = $this->createTestChapter($this->wordsFor('Bureau opened.'), ['name' => 'Chapter A']);
        $chapter2 = $this->createTestChapter($this->wordsFor('Federal Bureau acted.'), ['name' => 'Chapter B']);
        $occ1 = $this->createOccurrence($sense, $chapter1, '0', 'Bureau opened.');
        $occ2 = $this->createOccurrence($sense, $chapter2, '0', 'Federal Bureau acted.');

        $response = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list');

        $response->assertOk();
        $json = $response->json();

        // Without preferred, status must be 'unavailable' and the first source
        // must NOT be forced to any specific occurrence — original ordering
        // (orderByDesc id, unique chapter_id) applies.
        $this->assertSame('unavailable', $json['preferred_occurrence_status']);
        $this->assertGreaterThanOrEqual(1, $json['count']);
        $this->assertArrayHasKey('occurrence_id', $json['sources'][0]);
    }

    public function test_payload_contains_preferred_occurrence_status(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        $chapter = $this->createTestChapter($this->wordsFor('Bureau opened.'), ['name' => 'Chapter A']);
        $occ = $this->createOccurrence($sense, $chapter, '0', 'Bureau opened.');

        // Matched case.
        $matched = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list?preferred_occurrence_id=' . $occ->id)
            ->assertOk()
            ->json();
        $this->assertSame('matched', $matched['preferred_occurrence_status']);

        // No-param case.
        $none = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list')
            ->assertOk()
            ->json();
        $this->assertSame('unavailable', $none['preferred_occurrence_status']);

        // Invalid case (non-existent id).
        $invalid = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list?preferred_occurrence_id=99999999')
            ->assertOk()
            ->json();
        $this->assertSame('invalid', $invalid['preferred_occurrence_status']);
    }

    public function test_endpoint_writes_no_review_log(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        $chapter = $this->createTestChapter($this->wordsFor('Bureau opened.'), ['name' => 'Chapter A']);
        $occ = $this->createOccurrence($sense, $chapter, '0', 'Bureau opened.');

        $before = ReviewLog::count();

        $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list?preferred_occurrence_id=' . $occ->id)
            ->assertOk();

        $this->assertSame($before, ReviewLog::count(), 'no ReviewLog may be written');
    }

    public function test_endpoint_does_not_modify_fsrs_fields(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        $chapter = $this->createTestChapter($this->wordsFor('Bureau opened.'), ['name' => 'Chapter A']);
        $occ = $this->createOccurrence($sense, $chapter, '0', 'Bureau opened.');

        $card = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => 'sense',
            'target_id' => $sense->id,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->addDay(),
            'fsrs_stability' => 5.5,
            'fsrs_difficulty' => 4.4,
            'fsrs_reps' => 7,
            'fsrs_lapses' => 1,
            'fsrs_last_reviewed_at' => now()->subDay(),
        ]);

        $before = [
            'fsrs_state' => $card->fsrs_state,
            'fsrs_stability' => $card->fsrs_stability,
            'fsrs_difficulty' => $card->fsrs_difficulty,
            'fsrs_reps' => $card->fsrs_reps,
            'fsrs_lapses' => $card->fsrs_lapses,
            'fsrs_enabled' => $card->fsrs_enabled,
        ];

        $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list?preferred_occurrence_id=' . $occ->id)
            ->assertOk();

        $card->refresh();
        $this->assertSame($before['fsrs_state'], $card->fsrs_state);
        $this->assertSame($before['fsrs_stability'], $card->fsrs_stability);
        $this->assertSame($before['fsrs_difficulty'], $card->fsrs_difficulty);
        $this->assertSame($before['fsrs_reps'], $card->fsrs_reps);
        $this->assertSame($before['fsrs_lapses'], $card->fsrs_lapses);
        $this->assertSame($before['fsrs_enabled'], $card->fsrs_enabled);
    }

    public function test_endpoint_does_not_create_legacy_word_review_card(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        $chapter = $this->createTestChapter($this->wordsFor('Bureau opened.'), ['name' => 'Chapter A']);
        $occ = $this->createOccurrence($sense, $chapter, '0', 'Bureau opened.');

        $beforeSense = ReviewCard::where('target_type', 'sense')->count();
        $beforeWord = ReviewCard::where('target_type', 'word')->count();

        $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list?preferred_occurrence_id=' . $occ->id)
            ->assertOk();

        $this->assertSame($beforeSense, ReviewCard::where('target_type', 'sense')->count(), 'no new sense ReviewCard');
        $this->assertSame($beforeWord, ReviewCard::where('target_type', 'word')->count(), 'no new legacy word ReviewCard');
    }

    public function test_endpoint_does_not_create_new_word_sense_occurrence(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        $chapter = $this->createTestChapter($this->wordsFor('Bureau opened.'), ['name' => 'Chapter A']);
        $occ = $this->createOccurrence($sense, $chapter, '0', 'Bureau opened.');

        $before = WordSenseOccurrence::count();

        $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list?preferred_occurrence_id=' . $occ->id)
            ->assertOk();

        $this->assertSame($before, WordSenseOccurrence::count(), 'no new WordSenseOccurrence may be created');
    }

    public function test_fallback_chain_serves_card_example_or_unavailable_when_no_chapter_sources(): void
    {
        // Sense with no occurrences at all — fallback should still produce a
        // valid source (card_example or unavailable), and the status field
        // must be present.
        $sense = $this->createConfirmedSense('bureau', 'Bureau');

        $response = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list?preferred_occurrence_id=99999999');

        $response->assertOk();
        $json = $response->json();

        $this->assertArrayHasKey('preferred_occurrence_status', $json);
        $this->assertSame('invalid', $json['preferred_occurrence_status']);
        $this->assertCount(1, $json['sources']);
        $this->assertContains(
            $json['sources'][0]['source_kind'],
            ['card_example', 'unavailable', null],
            'fallback chain must serve card_example / unavailable when no chapter sources exist'
        );
    }

    // ==================== Helpers ====================

    private function wordsFor(string $sentence): array
    {
        $parts = preg_split('/\s+/', $sentence) ?: [];
        $words = [];
        foreach ($parts as $i => $part) {
            $isLast = $i === count($parts) - 1;
            $words[] = (object) [
                'word' => $part,
                'sentence_index' => '0',
                'spaceAfter' => !$isLast || !preg_match('/[.!?]$/', $part),
            ];
        }
        return $words;
    }

    private function createConfirmedSense(string $lemma, string $surfaceForm): WordSense
    {
        return $this->createConfirmedSenseForUser($this->user, $lemma, $surfaceForm);
    }

    private function createConfirmedSenseForUser(User $user, string $lemma, string $surfaceForm): WordSense
    {
        $sense = $this->wordSenseService->createSense([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $surfaceForm,
            'pos' => 'noun',
            'sense_zh' => '局',
            'sense_en' => 'an office',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'The bureau opened at noon.',
            'example_sentence_zh' => '',
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

    private function createOccurrence(WordSense $sense, Chapter $chapter, string $sentenceId, string $sentenceEn): WordSenseOccurrence
    {
        return $this->createOccurrenceForUser($this->user, $sense, $chapter, $sentenceId, $sentenceEn);
    }

    private function createOccurrenceForUser(User $user, WordSense $sense, Chapter $chapter, string $sentenceId, string $sentenceEn): WordSenseOccurrence
    {
        return WordSenseOccurrence::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => $sentenceId,
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
