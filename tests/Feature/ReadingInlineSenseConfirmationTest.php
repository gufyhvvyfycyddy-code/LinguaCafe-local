<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ReadingInlineSenseConfirmation;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\WordSenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GLM-ReadingInlineConfirmationPersistence-1000-1 (sub-stage 7, +150%)
 *
 * Safety guard tests for `POST /senses/inline-confirmation` and the
 * `ReadingInlineSenseConfirmationService` writer.
 *
 * Covers (per task spec):
 *   1.  POST confirmation saves `match`;
 *   2.  POST confirmation saves `not_match`;
 *   3.  repeat save for same occurrence + sense updates instead of duplicating;
 *   4.  preview endpoint echoes `persisted_choice` (covered in InlineSensePreviewTest);
 *   5.  cross-user isolation (no leak);
 *   6.  cross-language isolation (no leak);
 *   7.  non-confirmed WordSense is rejected;
 *   8.  WordSense not owned by current user is rejected;
 *   9.  Chapter not owned by current user is rejected;
 *   10. invalid choice value is rejected;
 *   11. empty lemma / surface / sense id fails safely;
 *   12. saving confirmation does NOT write ReviewLog;
 *   13. saving confirmation does NOT change FSRS fields;
 *   14. saving confirmation does NOT create WordSense;
 *   15. saving confirmation does NOT create ReviewCard;
 *   16. saving confirmation does NOT call AI (safety_flags.no_ai_called === true);
 *   17. (regression) ReviewFsrsTest remains green — covered by that test file;
 *   18. (regression) FsrsSchedulingServiceTest remains green — covered by that test file.
 */
class ReadingInlineSenseConfirmationTest extends TestCase
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

        $this->user = $this->createUser('inline-confirm@example.com', 'english');
        $this->otherUser = $this->createUser('other-inline-confirm@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
    }

    // ==================== 1. Saves match ====================

    public function test_post_confirmation_saves_match(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        $response = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose',
            'surface' => 'geese',
            'language' => 'english',
            'chapter_id' => $chapter->id,
            'sentence_index' => 2,
            'sentence_text' => 'The geese flew.',
            'word_sense_id' => $sense->id,
            'choice' => 'match',
        ]);

        $response->assertOk();
        $response->assertJsonPath('choice', 'match');
        $response->assertJsonPath('persisted', true);
        $response->assertJsonPath('safety_flags.not_a_review_rating', true);
        $response->assertJsonPath('safety_flags.no_review_log_created', true);
        $response->assertJsonPath('safety_flags.no_fsrs_changed', true);
        $response->assertJsonPath('safety_flags.no_review_card_created', true);
        $response->assertJsonPath('safety_flags.no_word_sense_created', true);
        $response->assertJsonPath('safety_flags.no_ai_called', true);

        $this->assertSame(1, ReadingInlineSenseConfirmation::count(), 'exactly one row should exist');
        $row = ReadingInlineSenseConfirmation::first();
        $this->assertSame('match', $row->choice);
        $this->assertSame('reading_inline_preview', $row->source);
        $this->assertSame($this->user->id, $row->user_id);
        $this->assertSame('english', $row->language);
        $this->assertSame($chapter->id, $row->chapter_id);
        $this->assertSame(2, $row->sentence_index);
        $this->assertSame('geese', $row->surface);
        $this->assertSame('goose', $row->lemma);
        $this->assertSame($sense->id, $row->word_sense_id);
    }

    // ==================== 2. Saves not_match ====================

    public function test_post_confirmation_saves_not_match(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');

        $response = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose',
            'surface' => 'geese',
            'chapter_id' => null,
            'sentence_index' => null,
            'word_sense_id' => $sense->id,
            'choice' => 'not_match',
        ]);

        $response->assertOk();
        $response->assertJsonPath('choice', 'not_match');
        $row = ReadingInlineSenseConfirmation::first();
        $this->assertSame('not_match', $row->choice);
    }

    // ==================== 3. Repeat save updates instead of duplicating ====================

    public function test_repeat_save_updates_choice_instead_of_duplicating(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        $payload = [
            'lemma' => 'goose',
            'surface' => 'geese',
            'chapter_id' => $chapter->id,
            'sentence_index' => 2,
            'word_sense_id' => $sense->id,
        ];

        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', array_merge($payload, ['choice' => 'match']))->assertOk();
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', array_merge($payload, ['choice' => 'not_match']))->assertOk();

        $this->assertSame(1, ReadingInlineSenseConfirmation::count(), 'only one row per occurrence + sense');
        $row = ReadingInlineSenseConfirmation::first();
        $this->assertSame('not_match', $row->choice, 'choice should be updated to not_match');
    }

    // ==================== 5. Cross-user isolation ====================

    public function test_cross_user_confirmation_does_not_leak(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        // user A saves match
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose',
            'surface' => 'geese',
            'chapter_id' => $chapter->id,
            'sentence_index' => 2,
            'word_sense_id' => $sense->id,
            'choice' => 'match',
        ])->assertOk();

        // Verify at the service layer that user B cannot see user A's confirmation.
        // (We don't switch users mid-test via actingAs because the
        // `auth.session` middleware invalidates the session on user switch
        // and returns a 302 redirect to /login. The cross-user isolation
        // guarantee is enforced at the SQL layer via `where('user_id', ...)`,
        // so we test it directly against the service.)
        $service = app(\App\Services\ReadingInlineSenseConfirmationService::class);

        // user B querying the same occurrence key — must return NO confirmations
        $userBResult = $service->listConfirmationsForOccurrence(
            $this->otherUser->id,
            'english',
            $chapter->id,
            2,
            'geese',
            'goose',
            [$sense->id]
        );
        $this->assertSame([], $userBResult, 'user B must not see user A confirmation');

        // user A querying the same occurrence key — must return the confirmation
        $userAResult = $service->listConfirmationsForOccurrence(
            $this->user->id,
            'english',
            $chapter->id,
            2,
            'geese',
            'goose',
            [$sense->id]
        );
        $this->assertCount(1, $userAResult, 'user A must see their own confirmation');
        $this->assertSame($sense->id, $userAResult[$sense->id]['word_sense_id']);
        $this->assertSame('match', $userAResult[$sense->id]['choice']);
    }

    // ==================== 6. Cross-language isolation ====================

    public function test_cross_language_confirmation_does_not_leak(): void
    {
        $englishSense = $this->createConfirmedSense('run', 'runs', '跑');

        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'run',
            'surface' => 'runs',
            'language' => 'english',
            'chapter_id' => null,
            'sentence_index' => 0,
            'word_sense_id' => $englishSense->id,
            'choice' => 'match',
        ])->assertOk();

        // Switch the user's selected_language to japanese and confirm no echo leaks.
        $this->user->selected_language = 'japanese';
        $this->user->save();

        $response = $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=run&language=japanese&surface=runs')
            ->assertOk();

        $this->assertSame(0, $response->json('candidate_count'));
    }

    // ==================== 7. Non-confirmed WordSense is rejected ====================

    public function test_non_confirmed_word_sense_is_rejected(): void
    {
        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'goose',
            'surface_form' => 'geese',
            'pos' => 'noun',
            'sense_zh' => '鹅',
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => '',
            'example_sentence_zh' => '',
            'status' => WordSense::STATUS_AI_SUGGESTED,
        ]);

        $response = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose',
            'surface' => 'geese',
            'word_sense_id' => $sense->id,
            'choice' => 'match',
        ]);

        $response->assertStatus(500); // DomainException → 500 by default
        $this->assertSame(0, ReadingInlineSenseConfirmation::count());
    }

    // ==================== 8. WordSense not owned by current user is rejected ====================

    public function test_word_sense_not_owned_by_current_user_is_rejected(): void
    {
        $otherSense = $this->createConfirmedSenseForUser($this->otherUser, 'goose', 'geese', 'other-鹅');

        $response = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose',
            'surface' => 'geese',
            'word_sense_id' => $otherSense->id,
            'choice' => 'match',
        ]);

        $response->assertStatus(500);
        $this->assertSame(0, ReadingInlineSenseConfirmation::count());
    }

    // ==================== 9. Chapter not owned by current user is rejected ====================

    public function test_chapter_not_owned_by_current_user_is_rejected(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $otherChapter = $this->createChapter($this->otherUser->id);

        $response = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose',
            'surface' => 'geese',
            'chapter_id' => $otherChapter->id,
            'word_sense_id' => $sense->id,
            'choice' => 'match',
        ]);

        $response->assertStatus(500);
        $this->assertSame(0, ReadingInlineSenseConfirmation::count());
    }

    // ==================== 9b. Chapter language mismatch (same user, wrong language) ====================

    public function test_chapter_language_mismatch_is_rejected(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        // Chapter belongs to the current user BUT has a different language.
        $spanishChapter = $this->createChapter($this->user->id, 'spanish');

        $response = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose',
            'surface' => 'geese',
            'language' => 'english',
            'chapter_id' => $spanishChapter->id,
            'sentence_index' => 0,
            'word_sense_id' => $sense->id,
            'choice' => 'match',
        ]);

        $response->assertStatus(500);
        $this->assertStringContainsString('Chapter not found for current user/language.', $response->json('message') ?? '');
        $this->assertSame(0, ReadingInlineSenseConfirmation::count());
    }

    // ==================== 10. Invalid choice is rejected ====================

    public function test_invalid_choice_is_rejected(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');

        $response = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose',
            'surface' => 'geese',
            'word_sense_id' => $sense->id,
            'choice' => 'maybe',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, ReadingInlineSenseConfirmation::count());
    }

    // ==================== 11. Empty lemma / surface / sense id fails safely ====================

    public function test_empty_lemma_fails_safely(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');

        $response = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => '',
            'surface' => 'geese',
            'word_sense_id' => $sense->id,
            'choice' => 'match',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, ReadingInlineSenseConfirmation::count());
    }

    public function test_empty_surface_fails_safely(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');

        $response = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose',
            'surface' => '',
            'word_sense_id' => $sense->id,
            'choice' => 'match',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, ReadingInlineSenseConfirmation::count());
    }

    public function test_missing_word_sense_id_fails_safely(): void
    {
        $response = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose',
            'surface' => 'geese',
            'choice' => 'match',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, ReadingInlineSenseConfirmation::count());
    }

    // ==================== 12. Does NOT write ReviewLog ====================

    public function test_post_confirmation_does_not_write_review_log(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');

        $before = ReviewLog::count();

        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose',
            'surface' => 'geese',
            'word_sense_id' => $sense->id,
            'choice' => 'match',
        ])->assertOk();

        $this->assertSame($before, ReviewLog::count(), 'confirmation must not write ReviewLog');
    }

    // ==================== 13. Does NOT change FSRS fields ====================

    public function test_post_confirmation_does_not_change_fsrs_fields(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
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
            'fsrs_due_at' => optional($card->fsrs_due_at)->toIso8601String(),
        ];

        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose',
            'surface' => 'geese',
            'word_sense_id' => $sense->id,
            'choice' => 'match',
        ])->assertOk();

        $card->refresh();

        $this->assertSame($before['fsrs_state'], $card->fsrs_state);
        $this->assertSame($before['fsrs_reps'], $card->fsrs_reps);
        $this->assertSame($before['fsrs_stability'], $card->fsrs_stability);
        $this->assertSame($before['fsrs_difficulty'], $card->fsrs_difficulty);
        $this->assertSame($before['fsrs_lapses'], $card->fsrs_lapses);
        $this->assertSame($before['fsrs_enabled'], $card->fsrs_enabled);
        $this->assertSame($before['fsrs_due_at'], optional($card->fsrs_due_at)->toIso8601String());
    }

    // ==================== 14. Does NOT create WordSense ====================

    public function test_post_confirmation_does_not_create_word_sense(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $before = WordSense::count();

        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose',
            'surface' => 'geese',
            'word_sense_id' => $sense->id,
            'choice' => 'match',
        ])->assertOk();

        $this->assertSame($before, WordSense::count(), 'confirmation must not create WordSense');
    }

    // ==================== 15. Does NOT create ReviewCard ====================

    public function test_post_confirmation_does_not_create_review_card(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $before = ReviewCard::count();

        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose',
            'surface' => 'geese',
            'word_sense_id' => $sense->id,
            'choice' => 'match',
        ])->assertOk();

        $this->assertSame($before, ReviewCard::count(), 'confirmation must not create ReviewCard');
    }

    // ==================== 16. Does NOT call AI ====================

    public function test_post_confirmation_safety_flags_assert_no_ai_called(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');

        $response = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose',
            'surface' => 'geese',
            'word_sense_id' => $sense->id,
            'choice' => 'match',
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('safety_flags.no_ai_called'));
        $this->assertTrue($response->json('safety_flags.not_a_review_rating'));
    }

    // ==================== Language mismatch returns 403 ====================

    public function test_post_confirmation_rejects_language_mismatch(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');

        $response = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose',
            'surface' => 'geese',
            'language' => 'japanese',
            'word_sense_id' => $sense->id,
            'choice' => 'match',
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, ReadingInlineSenseConfirmation::count());
    }

    // ==================== Requires authentication ====================

    public function test_post_confirmation_requires_authentication(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');

        $this->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose',
            'surface' => 'geese',
            'word_sense_id' => $sense->id,
            'choice' => 'match',
        ])->assertStatus(401);
    }

    // ====================================================================
    // GLM-ReadingInlineConfirmationUsageSurface-AndMorphology-1000-1
    // Sub-stage 7 (+150%): read-only `summaryForSenseCandidates` guards.
    // ====================================================================

    /**
     * The summary method must aggregate match / not_match counts across ALL
     * occurrences for the requested sense ids (not just one occurrence).
     */
    public function test_summary_aggregates_counts_across_occurrences(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        // Save 3 matches at different occurrences + 2 not_match.
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'language' => 'english',
            'chapter_id' => $chapter->id, 'sentence_index' => 1,
            'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'language' => 'english',
            'chapter_id' => $chapter->id, 'sentence_index' => 2,
            'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'goose', 'language' => 'english',
            'chapter_id' => $chapter->id, 'sentence_index' => 3,
            'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'language' => 'english',
            'chapter_id' => $chapter->id, 'sentence_index' => 4,
            'word_sense_id' => $sense->id, 'choice' => 'not_match',
        ])->assertOk();
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'goose', 'language' => 'english',
            'chapter_id' => $chapter->id, 'sentence_index' => 5,
            'word_sense_id' => $sense->id, 'choice' => 'not_match',
        ])->assertOk();

        $service = app(\App\Services\ReadingInlineSenseConfirmationService::class);
        $summary = $service->summaryForSenseCandidates($this->user->id, 'english', [$sense->id]);

        $this->assertArrayHasKey($sense->id, $summary);
        $entry = $summary[$sense->id];
        $this->assertSame(3, $entry['match_count'], 'match_count should aggregate across occurrences');
        $this->assertSame(2, $entry['not_match_count'], 'not_match_count should aggregate across occurrences');
        $this->assertTrue($entry['has_any_confirmation']);
        $this->assertContains($entry['last_choice'], ['match', 'not_match']);
        $this->assertNotNull($entry['last_confirmed_at']);
        $this->assertCount(3, $entry['recent_examples'], 'recent_examples capped at 3');
    }

    /**
     * The summary must isolate by user — user B must not see user A's
     * confirmations even if they share the same sense id (impossible in
     * practice due to user-scoped sense ownership, but the summary must
     * still enforce user isolation at the SQL layer).
     */
    public function test_summary_isolates_by_user(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'language' => 'english',
            'chapter_id' => $chapter->id, 'sentence_index' => 1,
            'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $service = app(\App\Services\ReadingInlineSenseConfirmationService::class);
        $userBSummary = $service->summaryForSenseCandidates($this->otherUser->id, 'english', [$sense->id]);

        $this->assertArrayHasKey($sense->id, $userBSummary);
        $this->assertSame(0, $userBSummary[$sense->id]['match_count'], 'user B must not see user A match count');
        $this->assertSame(0, $userBSummary[$sense->id]['not_match_count']);
        $this->assertFalse($userBSummary[$sense->id]['has_any_confirmation']);
        $this->assertNull($userBSummary[$sense->id]['last_choice']);
        $this->assertEmpty($userBSummary[$sense->id]['recent_examples']);
    }

    /**
     * The summary must isolate by language — querying with a different
     * language must return empty even for the same user.
     */
    public function test_summary_isolates_by_language(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'language' => 'english',
            'chapter_id' => $chapter->id, 'sentence_index' => 1,
            'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $service = app(\App\Services\ReadingInlineSenseConfirmationService::class);
        $japaneseSummary = $service->summaryForSenseCandidates($this->user->id, 'japanese', [$sense->id]);

        $this->assertSame(0, $japaneseSummary[$sense->id]['match_count'], 'japanese summary must be empty');
        $this->assertFalse($japaneseSummary[$sense->id]['has_any_confirmation']);
    }

    /**
     * The summary is strictly read-only — it must NOT write any table.
     * We verify by counting rows before / after.
     */
    public function test_summary_is_strictly_read_only(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'language' => 'english',
            'chapter_id' => $chapter->id, 'sentence_index' => 1,
            'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $service = app(\App\Services\ReadingInlineSenseConfirmationService::class);

        $before = [
            'confirmations' => ReadingInlineSenseConfirmation::count(),
            'review_logs' => ReviewLog::count(),
            'word_senses' => WordSense::count(),
            'review_cards' => ReviewCard::count(),
        ];

        $service->summaryForSenseCandidates($this->user->id, 'english', [$sense->id]);

        $after = [
            'confirmations' => ReadingInlineSenseConfirmation::count(),
            'review_logs' => ReviewLog::count(),
            'word_senses' => WordSense::count(),
            'review_cards' => ReviewCard::count(),
        ];

        $this->assertSame($before, $after, 'summary must not write any table');
    }

    /**
     * The summary must NOT write ReviewLog even when there are confirmations.
     */
    public function test_summary_does_not_write_review_log(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'language' => 'english',
            'chapter_id' => $chapter->id, 'sentence_index' => 1,
            'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $service = app(\App\Services\ReadingInlineSenseConfirmationService::class);
        $before = ReviewLog::count();
        $service->summaryForSenseCandidates($this->user->id, 'english', [$sense->id]);
        $this->assertSame($before, ReviewLog::count(), 'summary must not write ReviewLog');
    }

    /**
     * The summary must NOT change any ReviewCard FSRS field.
     */
    public function test_summary_does_not_change_fsrs_fields(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);
        $card = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->addDay(),
            'fsrs_stability' => 2.0,
            'fsrs_difficulty' => 4.5,
            'fsrs_reps' => 5,
            'fsrs_lapses' => 1,
            'fsrs_last_reviewed_at' => now()->subDay(),
        ]);

        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'language' => 'english',
            'chapter_id' => $chapter->id, 'sentence_index' => 1,
            'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $before = [
            'fsrs_state' => $card->fsrs_state,
            'fsrs_reps' => $card->fsrs_reps,
            'fsrs_stability' => $card->fsrs_stability,
            'fsrs_difficulty' => $card->fsrs_difficulty,
            'fsrs_lapses' => $card->fsrs_lapses,
            'fsrs_enabled' => $card->fsrs_enabled,
        ];

        $service = app(\App\Services\ReadingInlineSenseConfirmationService::class);
        $service->summaryForSenseCandidates($this->user->id, 'english', [$sense->id]);

        $card->refresh();
        $this->assertSame($before['fsrs_state'], $card->fsrs_state);
        $this->assertSame($before['fsrs_reps'], $card->fsrs_reps);
        $this->assertSame($before['fsrs_stability'], $card->fsrs_stability);
        $this->assertSame($before['fsrs_difficulty'], $card->fsrs_difficulty);
        $this->assertSame($before['fsrs_lapses'], $card->fsrs_lapses);
        $this->assertSame($before['fsrs_enabled'], $card->fsrs_enabled);
    }

    /**
     * The summary must NOT create WordSense or ReviewCard.
     */
    public function test_summary_does_not_create_word_sense_or_review_card(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');

        $service = app(\App\Services\ReadingInlineSenseConfirmationService::class);
        $beforeSense = WordSense::count();
        $beforeCard = ReviewCard::count();

        $service->summaryForSenseCandidates($this->user->id, 'english', [$sense->id]);

        $this->assertSame($beforeSense, WordSense::count(), 'summary must not create WordSense');
        $this->assertSame($beforeCard, ReviewCard::count(), 'summary must not create ReviewCard');
    }

    /**
     * recent_examples must NOT leak other users' confirmations.
     * Already covered by test_summary_isolates_by_user, but this test
     * explicitly checks the recent_examples array content.
     */
    public function test_summary_recent_examples_does_not_leak_other_users(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        // User A saves a confirmation.
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'language' => 'english',
            'chapter_id' => $chapter->id, 'sentence_index' => 1,
            'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        // User A's summary should see their own recent_examples.
        $service = app(\App\Services\ReadingInlineSenseConfirmationService::class);
        $userASummary = $service->summaryForSenseCandidates($this->user->id, 'english', [$sense->id]);
        $this->assertCount(1, $userASummary[$sense->id]['recent_examples']);
        $this->assertSame('geese', $userASummary[$sense->id]['recent_examples'][0]['surface']);

        // User B's summary recent_examples must be empty.
        $userBSummary = $service->summaryForSenseCandidates($this->otherUser->id, 'english', [$sense->id]);
        $this->assertEmpty($userBSummary[$sense->id]['recent_examples'], 'user B must not see user A recent_examples');
    }

    /**
     * When called with an empty sense id list, the summary returns an empty
     * array (defensive — must not error).
     */
    public function test_summary_returns_empty_array_for_empty_sense_ids(): void
    {
        $service = app(\App\Services\ReadingInlineSenseConfirmationService::class);
        $result = $service->summaryForSenseCandidates($this->user->id, 'english', []);
        $this->assertSame([], $result);
    }

    /**
     * When called with sense ids that have NO confirmations, the summary
     * returns a zeroed entry per sense id (not missing).
     */
    public function test_summary_returns_zeroed_entry_for_sense_without_confirmations(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $service = app(\App\Services\ReadingInlineSenseConfirmationService::class);
        $result = $service->summaryForSenseCandidates($this->user->id, 'english', [$sense->id]);

        $this->assertArrayHasKey($sense->id, $result);
        $this->assertSame(0, $result[$sense->id]['match_count']);
        $this->assertSame(0, $result[$sense->id]['not_match_count']);
        $this->assertFalse($result[$sense->id]['has_any_confirmation']);
        $this->assertNull($result[$sense->id]['last_choice']);
        $this->assertNull($result[$sense->id]['last_confirmed_at']);
        $this->assertEmpty($result[$sense->id]['recent_examples']);
    }

    /**
     * The GET /senses/inline-preview payload must include usage_summary
     * fields per candidate (match_count / not_match_count / last_choice /
     * last_confirmed_at).
     */
    public function test_inline_preview_payload_includes_usage_summary(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        // Save one match + one not_match.
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'language' => 'english',
            'chapter_id' => $chapter->id, 'sentence_index' => 1,
            'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'goose', 'language' => 'english',
            'chapter_id' => $chapter->id, 'sentence_index' => 2,
            'word_sense_id' => $sense->id, 'choice' => 'not_match',
        ])->assertOk();

        $response = $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=goose&language=english&surface=geese&chapter_id=' . $chapter->id . '&sentence_index=1')
            ->assertOk();

        $candidates = $response->json('candidates');
        $this->assertNotEmpty($candidates);
        $candidate = $candidates[0];
        $this->assertSame($sense->id, $candidate['sense_id']);
        $this->assertArrayHasKey('usage_summary', $candidate);
        $this->assertSame(1, $candidate['usage_match_count'], 'usage_match_count should be 1');
        $this->assertSame(1, $candidate['usage_not_match_count'], 'usage_not_match_count should be 1');
        $this->assertNotNull($candidate['usage_last_choice']);
        $this->assertNotNull($candidate['usage_last_confirmed_at']);
    }

    /**
     * For a lemma with NO confirmed WordSense candidates (unknown lemma),
     * the preview payload returns candidate_count=0 and no usage summary.
     */
    public function test_inline_preview_returns_empty_summary_for_unknown_lemma(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=zzzunknown&language=english&surface=zzzunknown')
            ->assertOk();

        $this->assertSame(0, $response->json('candidate_count'));
        $this->assertSame([], $response->json('candidates'));
    }

    // ================================================================
    // GLM-ReadingInlineConfirmationManagementSurface-1000-1 (sub-stage 7)
    // Management surface guard tests: list + revoke endpoints.
    // ================================================================

    /**
     * GET /senses/inline-confirmations returns only the current user's rows.
     *
     * Note: we cannot switch users mid-test via actingAs because the
     * `auth.session` middleware invalidates the session on user switch
     * and returns a 401. The cross-user isolation guarantee is enforced
     * at the SQL layer via `where('user_id', ...)`, so we insert user B's
     * row directly via the model and verify user A's list does not see it.
     */
    public function test_list_endpoint_returns_only_current_user_rows(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        // user A saves a match
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        // user B saves a different confirmation directly via model
        // (avoids actingAs user-switch which would invalidate the session)
        $otherSense = $this->createConfirmedSenseForUser($this->otherUser, 'goose', 'geese', 'other-鹅');
        $otherChapter = $this->createChapter($this->otherUser->id);
        ReadingInlineSenseConfirmation::forceCreate([
            'user_id' => $this->otherUser->id, 'language' => 'english',
            'chapter_id' => $otherChapter->id, 'sentence_index' => 1,
            'sentence_hash' => null, 'sentence_text' => 'Other geese.',
            'surface' => 'geese', 'lemma' => 'goose',
            'word_sense_id' => $otherSense->id,
            'choice' => 'not_match', 'source' => 'reading_inline_preview',
        ]);

        // user A lists: should only see their own row
        $response = $this->actingAs($this->user)
            ->getJson('/senses/inline-confirmations?language=english')
            ->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows, 'list endpoint only returns current user rows');
        $this->assertSame('match', $rows[0]['choice']);
        $this->assertSame($chapter->id, $rows[0]['chapter_id']);
    }

    /**
     * GET /senses/inline-confirmations isolates by language.
     */
    public function test_list_endpoint_isolates_by_language(): void
    {
        $user = $this->createUser('lang-isolate@example.com', 'english');
        // create an English confirmation
        $englishSense = $this->createConfirmedSenseForUser($user, 'goose', 'geese', '鹅');
        $englishChapter = $this->createChapter($user->id, 'english');
        $this->actingAs($user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $englishChapter->id,
            'sentence_index' => 1, 'word_sense_id' => $englishSense->id, 'choice' => 'match',
        ])->assertOk();

        // create a Spanish confirmation (different language)
        // sense_key is required by the DB schema; WordSenseService::createSense
        // generates it via generateSenseKey(), so we mirror that format here.
        $spanishSense = WordSense::forceCreate([
            'user_id' => $user->id, 'language' => 'spanish', 'language_id' => 'spanish',
            'lemma' => 'ganso', 'surface_form' => 'gansos', 'pos' => 'noun',
            'sense_zh' => '鹅', 'sense_en' => '', 'aliases_zh' => '[]', 'collocations' => '[]',
            'example_sentence_en' => '', 'example_sentence_zh' => '',
            'sense_key' => 'spanish-ganso-' . Str::random(6),
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
        $spanishChapter = $this->createChapter($user->id, 'spanish');
        ReadingInlineSenseConfirmation::forceCreate([
            'user_id' => $user->id, 'language' => 'spanish', 'chapter_id' => $spanishChapter->id,
            'sentence_index' => 1, 'sentence_hash' => null, 'sentence_text' => 'Los gansos.',
            'surface' => 'gansos', 'lemma' => 'ganso', 'word_sense_id' => $spanishSense->id,
            'choice' => 'match', 'source' => 'reading_inline_preview',
        ]);

        // English listing: should only see English row
        $response = $this->actingAs($user)
            ->getJson('/senses/inline-confirmations?language=english')
            ->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows, 'only english rows returned for english user');
        $this->assertSame('goose', $rows[0]['lemma']);
    }

    /**
     * GET /senses/inline-confirmations supports choice filter.
     */
    public function test_list_endpoint_supports_choice_filter(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 2, 'word_sense_id' => $sense->id, 'choice' => 'not_match',
        ])->assertOk();

        $all = $this->actingAs($this->user)->getJson('/senses/inline-confirmations?language=english&choice=all')->assertOk()->json('data');
        $this->assertCount(2, $all);

        $matchOnly = $this->actingAs($this->user)->getJson('/senses/inline-confirmations?language=english&choice=match')->assertOk()->json('data');
        $this->assertCount(1, $matchOnly);
        $this->assertSame('match', $matchOnly[0]['choice']);

        $notMatchOnly = $this->actingAs($this->user)->getJson('/senses/inline-confirmations?language=english&choice=not_match')->assertOk()->json('data');
        $this->assertCount(1, $notMatchOnly);
        $this->assertSame('not_match', $notMatchOnly[0]['choice']);
    }

    /**
     * GET /senses/inline-confirmations supports lemma filter.
     */
    public function test_list_endpoint_supports_lemma_filter(): void
    {
        $gooseSense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $runSense = $this->createConfirmedSense('run', 'runs', '跑');
        $chapter = $this->createChapter($this->user->id);

        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $gooseSense->id, 'choice' => 'match',
        ])->assertOk();
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'run', 'surface' => 'runs', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $runSense->id, 'choice' => 'match',
        ])->assertOk();

        $filtered = $this->actingAs($this->user)
            ->getJson('/senses/inline-confirmations?language=english&lemma=goose')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $filtered);
        $this->assertSame('goose', $filtered[0]['lemma']);
    }

    /**
     * GET /senses/inline-confirmations returns WordSense summary + chapter + sentence.
     */
    public function test_list_endpoint_returns_sense_summary_chapter_sentence(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'sentence_text' => 'The geese went to the lake.',
            'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $row = $this->actingAs($this->user)
            ->getJson('/senses/inline-confirmations?language=english')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('鹅', $row['sense_zh']);
        $this->assertSame('noun', $row['pos']);
        $this->assertSame($chapter->name, $row['chapter_name']);
        $this->assertSame('The geese went to the lake.', $row['sentence_text']);
        $this->assertSame(1, $row['sentence_index']);
        $this->assertTrue($row['can_revoke']);
    }

    /**
     * DELETE /senses/inline-confirmations/{id} revokes the user's own confirmation.
     */
    public function test_revoke_endpoint_deletes_current_user_confirmation(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $confirmationId = ReadingInlineSenseConfirmation::first()->id;

        $response = $this->actingAs($this->user)
            ->deleteJson('/senses/inline-confirmations/' . $confirmationId)
            ->assertOk();

        $this->assertTrue($response->json('revoked'));
        $this->assertSame($confirmationId, $response->json('confirmation_id'));
        $this->assertSame(0, ReadingInlineSenseConfirmation::count(), 'row is deleted');
        $this->assertSame(1, WordSense::count(), 'WordSense is NOT deleted');
    }

    /**
     * DELETE /senses/inline-confirmations/{id} cannot revoke another user's row.
     *
     * Note: we insert user B's confirmation directly via the model to avoid
     * actingAs user-switch (which would invalidate the session). The
     * cross-user isolation is enforced at the SQL layer via
     * `where('user_id', ...)` in revokeConfirmation().
     */
    public function test_revoke_endpoint_cannot_delete_other_user_confirmation(): void
    {
        $sense = $this->createConfirmedSenseForUser($this->otherUser, 'goose', 'geese', 'other-鹅');
        $chapter = $this->createChapter($this->otherUser->id);

        // user B saves a confirmation directly via model
        // (avoids actingAs user-switch which would invalidate the session)
        ReadingInlineSenseConfirmation::forceCreate([
            'user_id' => $this->otherUser->id, 'language' => 'english',
            'chapter_id' => $chapter->id, 'sentence_index' => 1,
            'sentence_hash' => null, 'sentence_text' => 'Other geese.',
            'surface' => 'geese', 'lemma' => 'goose',
            'word_sense_id' => $sense->id,
            'choice' => 'match', 'source' => 'reading_inline_preview',
        ]);

        $confirmationId = ReadingInlineSenseConfirmation::first()->id;

        // user A attempts to revoke user B's confirmation → 404
        $this->actingAs($this->user)
            ->deleteJson('/senses/inline-confirmations/' . $confirmationId)
            ->assertNotFound();

        // row is still present
        $this->assertSame(1, ReadingInlineSenseConfirmation::count());
    }

    /**
     * DELETE /senses/inline-confirmations/{id} cannot revoke another language's row.
     */
    public function test_revoke_endpoint_cannot_delete_other_language_confirmation(): void
    {
        // create a spanish confirmation owned by $this->user (same user, different language)
        // sense_key is required by the DB schema; WordSenseService::createSense
        // generates it via generateSenseKey(), so we mirror that format here.
        $spanishSense = WordSense::forceCreate([
            'user_id' => $this->user->id, 'language' => 'spanish', 'language_id' => 'spanish',
            'lemma' => 'ganso', 'surface_form' => 'gansos', 'pos' => 'noun',
            'sense_zh' => '鹅', 'sense_en' => '', 'aliases_zh' => '[]', 'collocations' => '[]',
            'example_sentence_en' => '', 'example_sentence_zh' => '',
            'sense_key' => 'spanish-ganso-' . Str::random(6),
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
        $spanishChapter = $this->createChapter($this->user->id, 'spanish');
        ReadingInlineSenseConfirmation::forceCreate([
            'user_id' => $this->user->id, 'language' => 'spanish', 'chapter_id' => $spanishChapter->id,
            'sentence_index' => 1, 'sentence_hash' => null, 'sentence_text' => 'Los gansos.',
            'surface' => 'gansos', 'lemma' => 'ganso', 'word_sense_id' => $spanishSense->id,
            'choice' => 'match', 'source' => 'reading_inline_preview',
        ]);

        $confirmationId = ReadingInlineSenseConfirmation::first()->id;

        // user is english-selected, attempts to revoke spanish confirmation → 404
        $this->actingAs($this->user)
            ->deleteJson('/senses/inline-confirmations/' . $confirmationId)
            ->assertNotFound();

        $this->assertSame(1, ReadingInlineSenseConfirmation::count(), 'row is NOT deleted');
    }

    /**
     * Revoke does NOT write ReviewLog.
     */
    public function test_revoke_does_not_write_review_log(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $logBefore = ReviewLog::count();
        $confirmationId = ReadingInlineSenseConfirmation::first()->id;

        $this->actingAs($this->user)
            ->deleteJson('/senses/inline-confirmations/' . $confirmationId)
            ->assertOk();

        $this->assertSame($logBefore, ReviewLog::count(), 'no review log written by revoke');
    }

    /**
     * Revoke does NOT change ReviewCard FSRS fields.
     */
    public function test_revoke_does_not_change_fsrs_fields(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        // create a ReviewCard to verify it is untouched
        // Note: review_cards schema does not have `state` or `due_at` columns;
        // the FSRS fields are fsrs_state / fsrs_due_at / fsrs_reps / etc.
        $card = ReviewCard::forceCreate([
            'user_id' => $this->user->id, 'language' => 'english', 'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => $sense->id, 'fsrs_state' => 'Review', 'fsrs_reps' => 5,
            'fsrs_due_at' => now()->addDay(), 'fsrs_stability' => 1.2, 'fsrs_difficulty' => 0.3,
            'fsrs_lapses' => 0, 'fsrs_enabled' => true,
        ]);

        $confirmationId = ReadingInlineSenseConfirmation::first()->id;
        $this->actingAs($this->user)
            ->deleteJson('/senses/inline-confirmations/' . $confirmationId)
            ->assertOk();

        $card->refresh();
        $this->assertSame(5, $card->fsrs_reps);
        $this->assertSame('Review', $card->fsrs_state);
        $this->assertTrue($card->fsrs_enabled);
        $this->assertSame(1, ReviewCard::count(), 'ReviewCard is NOT deleted by revoke');
    }

    /**
     * Revoke does NOT delete WordSense.
     */
    public function test_revoke_does_not_delete_word_sense(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $senseId = $sense->id;
        $confirmationId = ReadingInlineSenseConfirmation::first()->id;

        $this->actingAs($this->user)
            ->deleteJson('/senses/inline-confirmations/' . $confirmationId)
            ->assertOk();

        $this->assertNotNull(WordSense::find($senseId), 'WordSense is NOT deleted');
    }

    /**
     * Revoke does NOT delete ReviewCard.
     */
    public function test_revoke_does_not_delete_review_card(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $card = ReviewCard::forceCreate([
            'user_id' => $this->user->id, 'language' => 'english', 'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => $sense->id, 'fsrs_state' => 'Review', 'fsrs_reps' => 5,
            'fsrs_due_at' => now()->addDay(), 'fsrs_stability' => 1.2, 'fsrs_difficulty' => 0.3,
            'fsrs_lapses' => 0, 'fsrs_enabled' => true,
        ]);
        $cardId = $card->id;

        $confirmationId = ReadingInlineSenseConfirmation::first()->id;
        $this->actingAs($this->user)
            ->deleteJson('/senses/inline-confirmations/' . $confirmationId)
            ->assertOk();

        $this->assertNotNull(ReviewCard::find($cardId), 'ReviewCard is NOT deleted');
    }

    /**
     * Revoke returns safety_flags proving the safety contract.
     */
    public function test_revoke_returns_safety_flags(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $confirmationId = ReadingInlineSenseConfirmation::first()->id;
        $response = $this->actingAs($this->user)
            ->deleteJson('/senses/inline-confirmations/' . $confirmationId)
            ->assertOk();

        $flags = $response->json('safety_flags');
        $this->assertTrue($flags['no_review_log_created'] ?? false);
        $this->assertTrue($flags['no_fsrs_changed'] ?? false);
        $this->assertTrue($flags['no_review_card_changed'] ?? false);
        $this->assertTrue($flags['no_word_sense_deleted'] ?? false);
        $this->assertTrue($flags['no_review_card_deleted'] ?? false);
        $this->assertTrue($flags['not_a_review_rating'] ?? false);
    }

    /**
     * After revoking a confirmation, the preview endpoint no longer echoes
     * it as persisted_choice for that occurrence + candidate.
     */
    public function test_revoke_updates_preview_summary(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'sentence_text' => 'The geese went to the lake.',
            'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        // before revoke: preview echoes persisted_choice = match
        $before = $this->actingAs($this->user)
            ->getJson('/senses/inline-preview?language=english&lemma=goose&surface=geese&chapter_id=' . $chapter->id . '&sentence_index=1')
            ->assertOk()
            ->json('candidates.0');
        $this->assertSame('match', $before['persisted_choice']);

        // revoke
        $confirmationId = ReadingInlineSenseConfirmation::first()->id;
        $this->actingAs($this->user)
            ->deleteJson('/senses/inline-confirmations/' . $confirmationId)
            ->assertOk();

        // after revoke: preview no longer echoes persisted_choice
        $after = $this->actingAs($this->user)
            ->getJson('/senses/inline-preview?language=english&lemma=goose&surface=geese&chapter_id=' . $chapter->id . '&sentence_index=1')
            ->assertOk()
            ->json('candidates.0');
        $this->assertNull($after['persisted_choice']);
        $this->assertSame(0, $after['usage_match_count']);
    }

    /**
     * Unknown confirmation id results in 404 safe failure.
     */
    public function test_revoke_unknown_id_safely_fails(): void
    {
        $this->actingAs($this->user)
            ->deleteJson('/senses/inline-confirmations/999999')
            ->assertNotFound();
    }

    /**
     * GET /senses/inline-confirmations requires authentication.
     */
    public function test_list_endpoint_requires_authentication(): void
    {
        $this->getJson('/senses/inline-confirmations')->assertUnauthorized();
    }

    /**
     * GET /senses/inline-confirmations rejects language mismatch.
     */
    public function test_list_endpoint_rejects_language_mismatch(): void
    {
        $this->actingAs($this->user)
            ->getJson('/senses/inline-confirmations?language=spanish')
            ->assertForbidden();
    }

    // ================================================================
    // OpenCode-ReadingInlineConfirmationUndoHotkey-800-1 (sub-stage 6)
    // Ctrl+Z undo safety guard tests.
    // ================================================================

    /**
     * 1. POST /senses/inline-confirmation returns a backend-signed undo_token.
     */
    public function test_store_confirmation_returns_undo_token(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        $response = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $response->assertJsonPath('undo_token', fn ($v) => is_string($v) && $v !== '');
        $response->assertJsonPath('undo_expires_at', fn ($v) => is_string($v) && $v !== '');
        $this->assertSame('按 Ctrl+Z 可撤销刚才的阅读判断。', $response->json('undo_hint'));
    }

    /**
     * 2. DELETE /senses/inline-confirmations/{id} returns a backend-signed undo_token.
     */
    public function test_revoke_confirmation_returns_undo_token(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $confirmationId = ReadingInlineSenseConfirmation::first()->id;
        $response = $this->actingAs($this->user)
            ->deleteJson('/senses/inline-confirmations/' . $confirmationId)
            ->assertOk();

        $response->assertJsonPath('undo_token', fn ($v) => is_string($v) && $v !== '');
        $response->assertJsonPath('undo_expires_at', fn ($v) => is_string($v) && $v !== '');
        $this->assertSame('按 Ctrl+Z 可恢复。', $response->json('undo_hint'));
    }

    /**
     * 3. Undo a fresh store (before_state=null) deletes the just-created confirmation.
     */
    public function test_undo_store_from_none_deletes_confirmation(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        $storeResp = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();
        $undoToken = $storeResp->json('undo_token');
        $confirmationId = $storeResp->json('confirmation_id');

        $this->assertSame(1, ReadingInlineSenseConfirmation::count());

        $undoResp = $this->actingAs($this->user)->postJson('/senses/inline-confirmations/undo', [
            'undo_token' => $undoToken,
        ])->assertOk();

        $undoResp->assertJsonPath('undone', true);
        $undoResp->assertJsonPath('action_type', 'store');
        $undoResp->assertJsonPath('confirmation_id', $confirmationId);
        $undoResp->assertJsonPath('restored_choice', null);
        $undoResp->assertJsonPath('persisted_choice', null);
        $this->assertSame(0, ReadingInlineSenseConfirmation::count(), 'row deleted by undo');
    }

    /**
     * 4. Undo a choice switch (match → not_match) restores match.
     */
    public function test_undo_store_from_match_to_not_match_restores_match(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        // First save: match
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        // Second save: switch to not_match → returns undo token with before_state=match
        $switchResp = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'not_match',
        ])->assertOk();
        $undoToken = $switchResp->json('undo_token');

        $this->assertSame('not_match', ReadingInlineSenseConfirmation::first()->choice);

        // Undo: should restore match
        $undoResp = $this->actingAs($this->user)->postJson('/senses/inline-confirmations/undo', [
            'undo_token' => $undoToken,
        ])->assertOk();

        $undoResp->assertJsonPath('undone', true);
        $undoResp->assertJsonPath('restored_choice', 'match');
        $undoResp->assertJsonPath('persisted_choice', 'match');
        $this->assertSame('match', ReadingInlineSenseConfirmation::first()->choice, 'choice restored to match');
    }

    /**
     * 5. Undo a choice switch (not_match → match) restores not_match.
     */
    public function test_undo_store_from_not_match_to_match_restores_not_match(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        // First save: not_match
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'not_match',
        ])->assertOk();

        // Second save: switch to match → returns undo token with before_state=not_match
        $switchResp = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();
        $undoToken = $switchResp->json('undo_token');

        $this->assertSame('match', ReadingInlineSenseConfirmation::first()->choice);

        // Undo: should restore not_match
        $undoResp = $this->actingAs($this->user)->postJson('/senses/inline-confirmations/undo', [
            'undo_token' => $undoToken,
        ])->assertOk();

        $undoResp->assertJsonPath('restored_choice', 'not_match');
        $this->assertSame('not_match', ReadingInlineSenseConfirmation::first()->choice, 'choice restored to not_match');
    }

    /**
     * 6. Undo a revoke re-inserts the deleted confirmation row.
     */
    public function test_undo_revoke_restores_deleted_confirmation(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $confirmationId = ReadingInlineSenseConfirmation::first()->id;
        $revokeResp = $this->actingAs($this->user)
            ->deleteJson('/senses/inline-confirmations/' . $confirmationId)
            ->assertOk();
        $undoToken = $revokeResp->json('undo_token');

        $this->assertSame(0, ReadingInlineSenseConfirmation::count(), 'row deleted by revoke');

        $undoResp = $this->actingAs($this->user)->postJson('/senses/inline-confirmations/undo', [
            'undo_token' => $undoToken,
        ])->assertOk();

        $undoResp->assertJsonPath('undone', true);
        $undoResp->assertJsonPath('action_type', 'revoke');
        $undoResp->assertJsonPath('restored_choice', 'match');
        $undoResp->assertJsonPath('persisted_choice', 'match');
        $this->assertSame(1, ReadingInlineSenseConfirmation::count(), 'row re-inserted by undo');
        $row = ReadingInlineSenseConfirmation::first();
        $this->assertSame('match', $row->choice);
        $this->assertSame('goose', $row->lemma);
        $this->assertSame('geese', $row->surface);
    }

    /**
     * 7. An invalid (non-encrypted / tampered) undo token is rejected with 422.
     */
    public function test_undo_invalid_token_rejected(): void
    {
        $this->actingAs($this->user)->postJson('/senses/inline-confirmations/undo', [
            'undo_token' => 'not-a-valid-encrypted-token',
        ])->assertStatus(422);
    }

    /**
     * 8. An expired undo token is rejected with 422.
     */
    public function test_undo_expired_token_rejected(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        $storeResp = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        // Forge an expired token by re-encrypting a payload with past expires_at.
        $confirmationId = $storeResp->json('confirmation_id');
        $expiredToken = \Illuminate\Support\Facades\Crypt::encryptString(json_encode([
            'v' => 1,
            'action_type' => 'store',
            'user_id' => $this->user->id,
            'language' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_index' => 1,
            'surface' => 'geese',
            'lemma' => 'goose',
            'confirmation_id' => $confirmationId,
            'before_state' => null,
            'after_state' => 'match',
            'expires_at' => now()->subSeconds(10)->getTimestamp(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->actingAs($this->user)->postJson('/senses/inline-confirmations/undo', [
            'undo_token' => $expiredToken,
        ])->assertStatus(422);

        // Row still exists (undo did not execute)
        $this->assertSame(1, ReadingInlineSenseConfirmation::count());
    }

    /**
     * 9. A cross-user undo token is rejected with 422.
     */
    public function test_undo_cross_user_token_rejected(): void
    {
        $sense = $this->createConfirmedSenseForUser($this->otherUser, 'goose', 'geese', 'other-鹅');
        $chapter = $this->createChapter($this->otherUser->id);

        // user B saves a confirmation directly via model (avoids actingAs switch)
        $row = ReadingInlineSenseConfirmation::forceCreate([
            'user_id' => $this->otherUser->id, 'language' => 'english',
            'chapter_id' => $chapter->id, 'sentence_index' => 1,
            'sentence_hash' => null, 'sentence_text' => 'Other geese.',
            'surface' => 'geese', 'lemma' => 'goose',
            'word_sense_id' => $sense->id,
            'choice' => 'match', 'source' => 'reading_inline_preview',
        ]);

        // Forge a token owned by user B
        $crossUserToken = \Illuminate\Support\Facades\Crypt::encryptString(json_encode([
            'v' => 1,
            'action_type' => 'store',
            'user_id' => $this->otherUser->id,
            'language' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_index' => 1,
            'surface' => 'geese',
            'lemma' => 'goose',
            'confirmation_id' => $row->id,
            'before_state' => null,
            'after_state' => 'match',
            'expires_at' => now()->addSeconds(60)->getTimestamp(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // user A attempts to undo user B's token → 422
        $this->actingAs($this->user)->postJson('/senses/inline-confirmations/undo', [
            'undo_token' => $crossUserToken,
        ])->assertStatus(422);

        // user B's row is untouched
        $this->assertSame(1, ReadingInlineSenseConfirmation::count());
    }

    /**
     * 10. A cross-language undo token is rejected with 422.
     */
    public function test_undo_cross_language_token_rejected(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);

        $storeResp = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();
        $confirmationId = $storeResp->json('confirmation_id');

        // Forge a token with language=japanese (mismatch)
        $crossLangToken = \Illuminate\Support\Facades\Crypt::encryptString(json_encode([
            'v' => 1,
            'action_type' => 'store',
            'user_id' => $this->user->id,
            'language' => 'japanese',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_index' => 1,
            'surface' => 'geese',
            'lemma' => 'goose',
            'confirmation_id' => $confirmationId,
            'before_state' => null,
            'after_state' => 'match',
            'expires_at' => now()->addSeconds(60)->getTimestamp(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->actingAs($this->user)->postJson('/senses/inline-confirmations/undo', [
            'undo_token' => $crossLangToken,
        ])->assertStatus(422);

        $this->assertSame(1, ReadingInlineSenseConfirmation::count(), 'row untouched by cross-language undo');
    }

    /**
     * 11. Undo revoke is rejected when the WordSense no longer belongs to the current user.
     */
    public function test_undo_revoke_rejects_when_word_sense_owned_by_other_user(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $confirmationId = ReadingInlineSenseConfirmation::first()->id;
        $revokeResp = $this->actingAs($this->user)
            ->deleteJson('/senses/inline-confirmations/' . $confirmationId)
            ->assertOk();
        $undoToken = $revokeResp->json('undo_token');

        // Simulate: WordSense ownership transferred to another user after revoke.
        $sense->user_id = $this->otherUser->id;
        $sense->save();

        $this->actingAs($this->user)->postJson('/senses/inline-confirmations/undo', [
            'undo_token' => $undoToken,
        ])->assertStatus(422);

        // Row NOT re-inserted
        $this->assertSame(0, ReadingInlineSenseConfirmation::count());
    }

    /**
     * 12. Undo revoke is rejected when the Chapter no longer belongs to the current user.
     */
    public function test_undo_revoke_rejects_when_chapter_owned_by_other_user(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);
        $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $confirmationId = ReadingInlineSenseConfirmation::first()->id;
        $revokeResp = $this->actingAs($this->user)
            ->deleteJson('/senses/inline-confirmations/' . $confirmationId)
            ->assertOk();
        $undoToken = $revokeResp->json('undo_token');

        // Simulate: Chapter ownership transferred to another user after revoke.
        $chapter->user_id = $this->otherUser->id;
        $chapter->save();

        $this->actingAs($this->user)->postJson('/senses/inline-confirmations/undo', [
            'undo_token' => $undoToken,
        ])->assertStatus(422);

        $this->assertSame(0, ReadingInlineSenseConfirmation::count(), 'row NOT re-inserted');
    }

    /**
     * 13. Undo does NOT write ReviewLog.
     */
    public function test_undo_does_not_write_review_log(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);
        $storeResp = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $logBefore = ReviewLog::count();
        $this->actingAs($this->user)->postJson('/senses/inline-confirmations/undo', [
            'undo_token' => $storeResp->json('undo_token'),
        ])->assertOk();
        $this->assertSame($logBefore, ReviewLog::count(), 'undo must not write ReviewLog');
    }

    /**
     * 14. Undo does NOT change ReviewCard FSRS fields.
     */
    public function test_undo_does_not_change_fsrs_fields(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);
        $card = ReviewCard::forceCreate([
            'user_id' => $this->user->id, 'language' => 'english', 'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE, 'target_id' => $sense->id,
            'fsrs_enabled' => true, 'fsrs_state' => 'review',
            'fsrs_due_at' => now()->addDay(), 'fsrs_stability' => 2.5,
            'fsrs_difficulty' => 4.0, 'fsrs_reps' => 7, 'fsrs_lapses' => 1,
            'fsrs_last_reviewed_at' => now()->subDay(),
        ]);

        $storeResp = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $before = [
            'fsrs_state' => $card->fsrs_state, 'fsrs_reps' => $card->fsrs_reps,
            'fsrs_stability' => $card->fsrs_stability, 'fsrs_difficulty' => $card->fsrs_difficulty,
            'fsrs_lapses' => $card->fsrs_lapses, 'fsrs_enabled' => $card->fsrs_enabled,
        ];

        $this->actingAs($this->user)->postJson('/senses/inline-confirmations/undo', [
            'undo_token' => $storeResp->json('undo_token'),
        ])->assertOk();

        $card->refresh();
        $this->assertSame($before['fsrs_state'], $card->fsrs_state);
        $this->assertSame($before['fsrs_reps'], $card->fsrs_reps);
        $this->assertSame($before['fsrs_stability'], $card->fsrs_stability);
        $this->assertSame($before['fsrs_difficulty'], $card->fsrs_difficulty);
        $this->assertSame($before['fsrs_lapses'], $card->fsrs_lapses);
        $this->assertSame($before['fsrs_enabled'], $card->fsrs_enabled);
    }

    /**
     * 15. Undo does NOT create WordSense.
     */
    public function test_undo_does_not_create_word_sense(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);
        $storeResp = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $before = WordSense::count();
        $this->actingAs($this->user)->postJson('/senses/inline-confirmations/undo', [
            'undo_token' => $storeResp->json('undo_token'),
        ])->assertOk();
        $this->assertSame($before, WordSense::count(), 'undo must not create WordSense');
    }

    /**
     * 16. Undo does NOT create ReviewCard.
     */
    public function test_undo_does_not_create_review_card(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);
        $storeResp = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $before = ReviewCard::count();
        $this->actingAs($this->user)->postJson('/senses/inline-confirmations/undo', [
            'undo_token' => $storeResp->json('undo_token'),
        ])->assertOk();
        $this->assertSame($before, ReviewCard::count(), 'undo must not create ReviewCard');
    }

    /**
     * 17. Undo does NOT delete WordSense / ReviewCard / ReviewLog.
     */
    public function test_undo_does_not_delete_word_sense_or_review_card_or_review_log(): void
    {
        $sense = $this->createConfirmedSense('goose', 'geese', '鹅');
        $chapter = $this->createChapter($this->user->id);
        $card = ReviewCard::forceCreate([
            'user_id' => $this->user->id, 'language' => 'english', 'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE, 'target_id' => $sense->id,
            'fsrs_enabled' => true, 'fsrs_state' => 'review',
            'fsrs_due_at' => now()->addDay(), 'fsrs_stability' => 1.0,
            'fsrs_difficulty' => 5.0, 'fsrs_reps' => 1, 'fsrs_lapses' => 0,
        ]);
        $log = ReviewLog::forceCreate([
            'user_id' => $this->user->id,
            'review_card_id' => $card->id, 'rating' => 'good',
            'previous_state' => 'review', 'new_state' => 'review',
            'previous_stability' => 1.0, 'new_stability' => 1.5,
            'previous_difficulty' => 5.0, 'new_difficulty' => 4.5,
            'reviewed_at' => now(),
        ]);

        $storeResp = $this->actingAs($this->user)->postJson('/senses/inline-confirmation', [
            'lemma' => 'goose', 'surface' => 'geese', 'chapter_id' => $chapter->id,
            'sentence_index' => 1, 'word_sense_id' => $sense->id, 'choice' => 'match',
        ])->assertOk();

        $beforeSense = WordSense::count();
        $beforeCard = ReviewCard::count();
        $beforeLog = ReviewLog::count();

        $this->actingAs($this->user)->postJson('/senses/inline-confirmations/undo', [
            'undo_token' => $storeResp->json('undo_token'),
        ])->assertOk();

        $this->assertSame($beforeSense, WordSense::count(), 'WordSense not deleted by undo');
        $this->assertSame($beforeCard, ReviewCard::count(), 'ReviewCard not deleted by undo');
        $this->assertSame($beforeLog, ReviewLog::count(), 'ReviewLog not deleted by undo');
        $this->assertNotNull(WordSense::find($sense->id));
        $this->assertNotNull(ReviewCard::find($card->id));
        $this->assertNotNull(ReviewLog::find($log->id));
    }

    // ==================== Helpers ====================


    private function createConfirmedSense(string $lemma, string $surfaceForm, string $senseZh): WordSense
    {
        return $this->createConfirmedSenseForUser($this->user, $lemma, $surfaceForm, $senseZh);
    }

    private function createConfirmedSenseForUser(User $user, string $lemma, string $surfaceForm, string $senseZh): WordSense
    {
        $sense = $this->wordSenseService->createSense([
            'user_id' => $user->id,
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

    private function createChapter(int $userId, string $language = 'english'): Chapter
    {
        return Chapter::forceCreate([
            'user_id' => $userId,
            'book_id' => 0,
            'name' => 'test-chapter-' . Str::random(6),
            'read_count' => 0,
            'word_count' => 0,
            'language' => $language,
            'raw_text' => '',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
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
