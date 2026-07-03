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

    private function createChapter(int $userId): Chapter
    {
        return Chapter::forceCreate([
            'user_id' => $userId,
            'book_id' => 0,
            'name' => 'test-chapter-' . Str::random(6),
            'read_count' => 0,
            'word_count' => 0,
            'language' => 'english',
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
