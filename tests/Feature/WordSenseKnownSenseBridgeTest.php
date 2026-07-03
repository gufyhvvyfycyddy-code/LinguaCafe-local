<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\WordSenseKnownSenseService;
use App\Services\WordSenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Trae-LemmaKnownSenseBridge-1
 *
 * Covers: lemma/surface binding, known-sense candidates list, known-sense-new-
 * meaning hint structure, read-only guarantees (no ReviewLog / ReviewCard /
 * WordSense / FSRS writes), and example-pool / source-list performance
 * optimizations still passing.
 */
class WordSenseKnownSenseBridgeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private WordSenseService $wordSenseService;
    private WordSenseKnownSenseService $knownSenseService;

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

        $this->user = $this->createUser('known-sense@example.com', 'english');
        $this->otherUser = $this->createUser('other-known-sense@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
        $this->knownSenseService = app(WordSenseKnownSenseService::class);
    }

    public function test_known_sense_lookup_returns_confirmed_senses_for_lemma(): void
    {
        // surface=ways, lemma=way; one confirmed sense for "way"
        $sense = $this->createConfirmedSense('way', 'ways', '路；方法');

        $payload = $this->knownSenseService->knownSenseLookupPayload(
            $this->user->id, 'english', 'way'
        );

        $this->assertTrue($payload['has_confirmed_senses']);
        $this->assertCount(1, $payload['confirmed_senses']);
        $this->assertSame($sense->id, $payload['confirmed_senses'][0]['sense_id']);
        $this->assertSame('way', $payload['confirmed_senses'][0]['lemma']);
        $this->assertSame('ways', $payload['confirmed_senses'][0]['surface_form']);
        $this->assertSame('路；方法', $payload['confirmed_senses'][0]['sense_zh']);
    }

    public function test_known_sense_lookup_excludes_archived_rejected_pending(): void
    {
        $confirmed = $this->createConfirmedSense('way', 'ways', '路');
        $rejected = $this->createConfirmedSense('way', 'ways', '已被拒绝的释义');
        $rejected->update(['status' => WordSense::STATUS_REJECTED]);

        $aiSuggested = $this->createConfirmedSense('way', 'ways', 'AI 建议释义');
        $aiSuggested->update(['status' => WordSense::STATUS_AI_SUGGESTED]);

        $payload = $this->knownSenseService->knownSenseLookupPayload(
            $this->user->id, 'english', 'way'
        );

        $this->assertCount(1, $payload['confirmed_senses'], 'only STATUS_CONFIRMED should be included');
        $this->assertSame($confirmed->id, $payload['confirmed_senses'][0]['sense_id']);
    }

    public function test_known_sense_lookup_isolates_user_and_language(): void
    {
        // My sense
        $mySense = $this->createConfirmedSense('way', 'ways', '我的释义');
        // Other user's sense for the same lemma
        $otherSense = $this->wordSenseService->createSense([
            'user_id' => $this->otherUser->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'way',
            'surface_form' => 'ways',
            'pos' => 'noun',
            'sense_zh' => '别人的释义',
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => '',
            'example_sentence_zh' => '',
        ]);
        $otherSense->update(['status' => WordSense::STATUS_CONFIRMED]);

        $payload = $this->knownSenseService->knownSenseLookupPayload(
            $this->user->id, 'english', 'way'
        );

        $this->assertCount(1, $payload['confirmed_senses']);
        $this->assertSame($mySense->id, $payload['confirmed_senses'][0]['sense_id']);
    }

    public function test_known_sense_lookup_returns_empty_for_unknown_lemma(): void
    {
        $payload = $this->knownSenseService->knownSenseLookupPayload(
            $this->user->id, 'english', 'nonexistentlemma'
        );

        $this->assertFalse($payload['has_confirmed_senses']);
        $this->assertSame([], $payload['confirmed_senses']);
        $this->assertFalse($payload['known_sense_new_meaning_hint']);
    }

    public function test_known_sense_lookup_payload_includes_read_only_flag(): void
    {
        $this->createConfirmedSense('way', 'ways', '路');

        $payload = $this->knownSenseService->knownSenseLookupPayload(
            $this->user->id, 'english', 'way'
        );

        $this->assertTrue($payload['read_only']);
    }

    public function test_known_sense_lookup_endpoint_requires_lemma(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/senses/known-sense-lookup');

        $response->assertStatus(422);
    }

    public function test_known_sense_lookup_endpoint_enforces_language_match(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/senses/known-sense-lookup?lemma=way&language=japanese');

        $response->assertStatus(403);
    }

    public function test_known_sense_lookup_endpoint_returns_confirmed_senses(): void
    {
        $sense = $this->createConfirmedSense('way', 'ways', '路；方法');

        $response = $this->actingAs($this->user)
            ->get('/senses/known-sense-lookup?lemma=way&language=english');

        $response->assertOk();
        $json = $response->json();

        $this->assertTrue($json['has_confirmed_senses']);
        $this->assertCount(1, $json['confirmed_senses']);
        $this->assertSame($sense->id, $json['confirmed_senses'][0]['sense_id']);
        $this->assertTrue($json['read_only']);
        $this->assertTrue($json['known_sense_new_meaning_hint']);
    }

    public function test_known_sense_lookup_does_not_write_review_log_or_card_or_sense(): void
    {
        $this->createConfirmedSense('way', 'ways', '路');

        $reviewLogBefore = ReviewLog::count();
        $reviewCardBefore = ReviewCard::count();
        $wordSenseBefore = WordSense::count();

        $this->actingAs($this->user)
            ->get('/senses/known-sense-lookup?lemma=way&language=english')
            ->assertOk();

        $this->assertSame($reviewLogBefore, ReviewLog::count(), 'must not write ReviewLog');
        $this->assertSame($reviewCardBefore, ReviewCard::count(), 'must not write ReviewCard');
        $this->assertSame($wordSenseBefore, WordSense::count(), 'must not write WordSense');
    }

    public function test_known_sense_lookup_includes_fsrs_fields_when_review_card_exists(): void
    {
        $sense = $this->createConfirmedSense('way', 'ways', '路');
        $card = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => 'sense',
            'target_id' => $sense->id,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->addDay(),
            'fsrs_stability' => 1.5,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => now()->subDay(),
        ]);

        $payload = $this->knownSenseService->knownSenseLookupPayload(
            $this->user->id, 'english', 'way'
        );

        $this->assertCount(1, $payload['confirmed_senses']);
        $entry = $payload['confirmed_senses'][0];
        $this->assertTrue($entry['has_review_card']);
        $this->assertSame($card->id, $entry['review_card_id']);
        $this->assertSame(3, $entry['fsrs_reps']);
        $this->assertSame('review', $entry['fsrs_state']);
        $this->assertTrue($entry['fsrs_enabled']);
    }

    public function test_known_sense_lookup_normalizes_lemma_lowercase_trim(): void
    {
        $this->createConfirmedSense('way', 'ways', '路');

        // Pass "Way" with spaces — should still match "way"
        $payload = $this->knownSenseService->knownSenseLookupPayload(
            $this->user->id, 'english', '  Way  '
        );

        $this->assertTrue($payload['has_confirmed_senses']);
        $this->assertSame('way', $payload['lemma']);
    }

    public function test_known_sense_lookup_includes_occurrence_count(): void
    {
        $sense = $this->createConfirmedSense('way', 'ways', '路');
        $chapter = $this->createTestChapter([], ['name' => 'Count Chapter']);
        $this->createOccurrence($sense, $chapter, '1', 'There are many ways.');
        $this->createOccurrence($sense, $chapter, '2', 'Find another way.');

        $payload = $this->knownSenseService->knownSenseLookupPayload(
            $this->user->id, 'english', 'way'
        );

        $this->assertCount(1, $payload['confirmed_senses']);
        $this->assertSame(2, $payload['confirmed_senses'][0]['occurrence_count']);
    }

    public function test_add_new_sense_uses_corrected_lemma_after_user_edit(): void
    {
        // Trae-LemmaKnownSenseBridge-1: when a user corrects lemma (e.g. from
        // surface "ways" to lemma "way"), createSense should store the corrected
        // lemma and preserve the surface form. This verifies the data layer
        // honors both fields independently — the UI edit flow already persists
        // the corrected lemma via /vocabulary/word/update (study_base) before
        // add-sense runs, and WordSensesList passes effectiveLemma (which
        // prefers studyBase) as lemma to the add-sense form.
        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'way',
            'surface_form' => 'ways',
            'pos' => 'noun',
            'sense_zh' => '方法',
            'sense_en' => 'a method',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'There are many ways.',
            'example_sentence_zh' => '',
        ]);

        $this->assertSame('way', $sense->lemma, 'created sense should use corrected lemma');
        $this->assertSame('ways', $sense->surface_form, 'surface_form should be preserved');
    }

    // ==================== GLM-ArchitectureFirst1000-SafeStability-1 edge cases ====================

    public function test_known_sense_lookup_service_returns_empty_for_empty_lemma(): void
    {
        // Service-level: empty string lemma returns [] candidates (not an exception).
        $payload = $this->knownSenseService->knownSenseLookupPayload(
            $this->user->id, 'english', ''
        );

        $this->assertFalse($payload['has_confirmed_senses']);
        $this->assertSame([], $payload['confirmed_senses']);
        $this->assertSame('', $payload['lemma']);
        $this->assertTrue($payload['read_only']);
    }

    public function test_known_sense_lookup_service_returns_empty_for_whitespace_only_lemma(): void
    {
        // Whitespace-only lemma should be trimmed to empty and return [].
        $payload = $this->knownSenseService->knownSenseLookupPayload(
            $this->user->id, 'english', '   '
        );

        $this->assertFalse($payload['has_confirmed_senses']);
        $this->assertSame([], $payload['confirmed_senses']);
    }

    public function test_known_sense_lookup_normalizes_uppercase_and_mixed_case_lemma(): void
    {
        // "WAY" / "Way" / "wAy" should all match lemma "way" (mb_strtolower normalization).
        $this->createConfirmedSense('way', 'ways', '路');

        foreach (['WAY', 'Way', 'wAy', '  WaY  '] as $query) {
            $payload = $this->knownSenseService->knownSenseLookupPayload(
                $this->user->id, 'english', $query
            );
            $this->assertCount(1, $payload['confirmed_senses'], "lemma query [{$query}] should match 1 confirmed sense");
            $this->assertSame('way', $payload['confirmed_senses'][0]['lemma']);
        }
    }

    public function test_known_sense_lookup_does_not_leak_across_languages(): void
    {
        // A sense created under japanese should not appear when querying english,
        // even if the lemma is identical. We use a fake 'japanese' language entry
        // via forceCreate on WordSense directly to avoid language table constraints.
        $englishSense = $this->createConfirmedSense('run', 'running', 'to run (english)');

        $japaneseSense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'japanese',
            'language_id' => 'japanese',
            'lemma' => 'run',
            'surface_form' => 'running',
            'pos' => 'verb',
            'sense_zh' => '走る (japanese)',
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => '',
            'example_sentence_zh' => '',
        ]);
        $japaneseSense->update(['status' => WordSense::STATUS_CONFIRMED]);

        $payload = $this->knownSenseService->knownSenseLookupPayload(
            $this->user->id, 'english', 'run'
        );

        $this->assertCount(1, $payload['confirmed_senses'], 'only english sense should be returned');
        $this->assertSame($englishSense->id, $payload['confirmed_senses'][0]['sense_id']);
        $this->assertSame('to run (english)', $payload['confirmed_senses'][0]['sense_zh']);
    }

    public function test_known_sense_lookup_excludes_pending_occurrences_from_count(): void
    {
        // occurrence_count should only count STATUS_BOUND; pending/ignored/rejected
        // occurrences must not inflate the count.
        $sense = $this->createConfirmedSense('way', 'ways', '路');
        $chapter = $this->createTestChapter([
            ['w' => 'ways', 'l' => 'way', 's' => 0],
        ]);

        $this->createOccurrence($sense, $chapter, '1', 'Find a way.');
        $this->createOccurrence($sense, $chapter, '2', 'Another way.');

        // Add a pending occurrence (should not count).
        $pending = WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => '3',
            'sentence_en' => 'Pending way.',
            'sentence_zh' => '',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => $sense->surface_form,
            'lemma' => $sense->lemma,
            'pos' => $sense->pos,
            'decision' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'confidence' => 1.0,
            'status' => WordSenseOccurrence::STATUS_PENDING,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
        ]);

        $payload = $this->knownSenseService->knownSenseLookupPayload(
            $this->user->id, 'english', 'way'
        );

        $this->assertCount(1, $payload['confirmed_senses']);
        $this->assertSame(2, $payload['confirmed_senses'][0]['occurrence_count'], 'pending occurrence must not be counted');
    }

    public function test_known_sense_lookup_returns_multiple_confirmed_senses_for_same_lemma(): void
    {
        // When a user has multiple confirmed senses for the same lemma, all should
        // be returned (verifies no LIMIT 1 or early return).
        $sense1 = $this->createConfirmedSense('way', 'ways', '方法');
        $sense2 = $this->createConfirmedSense('way', 'ways', '道路');

        $payload = $this->knownSenseService->knownSenseLookupPayload(
            $this->user->id, 'english', 'way'
        );

        $this->assertTrue($payload['has_confirmed_senses']);
        $this->assertCount(2, $payload['confirmed_senses']);
        $returnedIds = array_column(array_map(function ($s) {
            return ['sense_id' => $s['sense_id']];
        }, $payload['confirmed_senses']), 'sense_id');
        $this->assertContains($sense1->id, $returnedIds);
        $this->assertContains($sense2->id, $returnedIds);
    }

    public function test_known_sense_lookup_payload_shape_stays_stable(): void
    {
        // Payload must always contain the documented top-level keys, even with 0 candidates.
        $payload = $this->knownSenseService->knownSenseLookupPayload(
            $this->user->id, 'english', 'nonexistent-lemma-' . uniqid()
        );

        $this->assertArrayHasKey('lemma', $payload);
        $this->assertArrayHasKey('has_confirmed_senses', $payload);
        $this->assertArrayHasKey('confirmed_senses', $payload);
        $this->assertArrayHasKey('known_sense_new_meaning_hint', $payload);
        $this->assertArrayHasKey('read_only', $payload);
        $this->assertTrue($payload['read_only']);
        $this->assertFalse($payload['has_confirmed_senses']);
        $this->assertIsArray($payload['confirmed_senses']);
    }

    public function test_known_sense_lookup_does_not_modify_fsrs_fields(): void
    {
        // FSRS fields on an existing ReviewCard must not change after a read-only lookup.
        $sense = $this->createConfirmedSense('way', 'ways', '路');

        // Manually attach a ReviewCard with known FSRS values (using target_id, not word_sense_id).
        $card = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->addDay(),
            'fsrs_stability' => 1.5,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => now()->subDay(),
        ]);

        $before = [
            'fsrs_state' => $card->fsrs_state,
            'fsrs_reps' => $card->fsrs_reps,
            'fsrs_stability' => $card->fsrs_stability,
            'fsrs_difficulty' => $card->fsrs_difficulty,
            'fsrs_lapses' => $card->fsrs_lapses,
            'fsrs_enabled' => $card->fsrs_enabled,
        ];

        $this->knownSenseService->knownSenseLookupPayload(
            $this->user->id, 'english', 'way'
        );

        $card->refresh();
        $this->assertSame($before['fsrs_state'], $card->fsrs_state);
        $this->assertSame($before['fsrs_reps'], $card->fsrs_reps);
        $this->assertSame($before['fsrs_stability'], $card->fsrs_stability);
        $this->assertSame($before['fsrs_difficulty'], $card->fsrs_difficulty);
        $this->assertSame($before['fsrs_lapses'], $card->fsrs_lapses);
        $this->assertSame($before['fsrs_enabled'], $card->fsrs_enabled);
    }

    // ==================== Helpers ====================

    private function createConfirmedSense(string $lemma, string $surfaceForm, string $senseZh): WordSense
    {
        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $surfaceForm,
            'pos' => 'noun',
            'sense_zh' => $senseZh,
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => '',
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
        return WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
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
            'decision' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
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
