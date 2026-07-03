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
 * GLM-ReadingInlinePreview-First-1
 *
 * Safety guard tests for the read-only inline preview endpoint and service.
 *
 * Covers (sub-stage 4, +150%):
 *  1.  preview endpoint does not write ReviewLog;
 *  2.  preview endpoint does not change FSRS fields;
 *  3.  preview endpoint does not create WordSense;
 *  4.  preview endpoint does not create ReviewCard;
 *  5.  preview endpoint does not call AI (safety_flags.no_ai_called === true);
 *  6.  preview endpoint only returns current user + language confirmed senses;
 *  7.  pending / ignored / rejected senses are not returned;
 *  8.  cross-user isolation;
 *  9.  cross-language isolation;
 *  10. empty lemma / unknown lemma returns safe empty state.
 *
 * Also covers:
 *  - safety_flags hard contract (all 6 flags true);
 *  - payload shape (surface/sentence/candidates/candidate_count/safety_flags/ui_hint);
 *  - 422 for empty lemma;
 *  - 403 for language mismatch;
 *  - read_only === true.
 */
class InlineSensePreviewTest extends TestCase
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

        $this->user = $this->createUser('inline-preview@example.com', 'english');
        $this->otherUser = $this->createUser('other-inline-preview@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
        $this->knownSenseService = app(WordSenseKnownSenseService::class);
    }

    // ==================== 1. Read-only: no ReviewLog ====================

    public function test_inline_preview_endpoint_does_not_write_review_log(): void
    {
        $this->createConfirmedSense('goose', 'geese', '鹅');

        $reviewLogBefore = ReviewLog::count();

        $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=goose&language=english&surface=geese&sentence=The%20geese%20flew.')
            ->assertOk();

        $this->assertSame($reviewLogBefore, ReviewLog::count(), 'inline preview must not write ReviewLog');
    }

    // ==================== 2. Read-only: no FSRS change ====================

    public function test_inline_preview_endpoint_does_not_change_fsrs_fields(): void
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
        ];

        $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=goose&language=english')
            ->assertOk();

        $card->refresh();
        $this->assertSame($before['fsrs_state'], $card->fsrs_state, 'fsrs_state must not change');
        $this->assertSame($before['fsrs_reps'], $card->fsrs_reps, 'fsrs_reps must not change');
        $this->assertSame($before['fsrs_stability'], $card->fsrs_stability, 'fsrs_stability must not change');
        $this->assertSame($before['fsrs_difficulty'], $card->fsrs_difficulty, 'fsrs_difficulty must not change');
        $this->assertSame($before['fsrs_lapses'], $card->fsrs_lapses, 'fsrs_lapses must not change');
        $this->assertSame($before['fsrs_enabled'], $card->fsrs_enabled, 'fsrs_enabled must not change');
    }

    // ==================== 3. Read-only: no WordSense created ====================

    public function test_inline_preview_endpoint_does_not_create_word_sense(): void
    {
        $this->createConfirmedSense('goose', 'geese', '鹅');

        $wordSenseBefore = WordSense::count();

        $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=goose&language=english')
            ->assertOk();

        $this->assertSame($wordSenseBefore, WordSense::count(), 'inline preview must not create WordSense');
    }

    // ==================== 4. Read-only: no ReviewCard created ====================

    public function test_inline_preview_endpoint_does_not_create_review_card(): void
    {
        $this->createConfirmedSense('goose', 'geese', '鹅');

        $reviewCardBefore = ReviewCard::count();

        $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=goose&language=english')
            ->assertOk();

        $this->assertSame($reviewCardBefore, ReviewCard::count(), 'inline preview must not create ReviewCard');
    }

    // ==================== 5. No AI called (safety flag) ====================

    public function test_inline_preview_safety_flags_include_no_ai_called(): void
    {
        $this->createConfirmedSense('goose', 'geese', '鹅');

        $response = $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=goose&language=english')
            ->assertOk();

        $flags = $response->json('safety_flags');
        $this->assertIsArray($flags);
        $this->assertTrue($flags['no_ai_called'] ?? false, 'safety_flags.no_ai_called must be true');
    }

    public function test_inline_preview_safety_flags_all_six_present_and_true(): void
    {
        $this->createConfirmedSense('goose', 'geese', '鹅');

        $response = $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=goose&language=english')
            ->assertOk();

        $flags = $response->json('safety_flags');
        $expected = [
            'read_only',
            'no_review_log_created',
            'no_fsrs_changed',
            'no_review_card_created',
            'no_word_sense_created',
            'no_ai_called',
        ];
        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $flags, "safety_flags must contain [{$key}]");
            $this->assertTrue($flags[$key], "safety_flags[{$key}] must be true");
        }
    }

    // ==================== 6. Only current user + language confirmed senses ====================

    public function test_inline_preview_returns_only_confirmed_senses_for_current_user_and_language(): void
    {
        $mySense = $this->createConfirmedSense('goose', 'geese', '我的鹅');

        $response = $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=goose&language=english')
            ->assertOk();

        $candidates = $response->json('candidates');
        $this->assertCount(1, $candidates);
        $this->assertSame($mySense->id, $candidates[0]['sense_id']);
        $this->assertSame('我的鹅', $candidates[0]['sense_zh']);
    }

    // ==================== 7. pending / ignored / rejected not returned ====================

    public function test_inline_preview_excludes_rejected_and_ai_suggested_senses(): void
    {
        $confirmed = $this->createConfirmedSense('goose', 'geese', '已确认的鹅');
        $rejected = $this->createConfirmedSense('goose', 'geese', '已被拒绝的释义');
        $rejected->update(['status' => WordSense::STATUS_REJECTED]);

        $aiSuggested = $this->createConfirmedSense('goose', 'geese', 'AI 建议释义');
        $aiSuggested->update(['status' => WordSense::STATUS_AI_SUGGESTED]);

        $response = $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=goose&language=english')
            ->assertOk();

        $candidates = $response->json('candidates');
        $this->assertCount(1, $candidates, 'only STATUS_CONFIRMED should be returned');
        $this->assertSame($confirmed->id, $candidates[0]['sense_id']);
    }

    // ==================== 8. Cross-user isolation ====================

    public function test_inline_preview_does_not_leak_other_users_senses(): void
    {
        $mySense = $this->createConfirmedSense('goose', 'geese', '我的鹅');
        $otherSense = $this->wordSenseService->createSense([
            'user_id' => $this->otherUser->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'goose',
            'surface_form' => 'geese',
            'pos' => 'noun',
            'sense_zh' => '别人的鹅',
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => '',
            'example_sentence_zh' => '',
        ]);
        $otherSense->update(['status' => WordSense::STATUS_CONFIRMED]);

        $response = $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=goose&language=english')
            ->assertOk();

        $candidates = $response->json('candidates');
        $this->assertCount(1, $candidates, 'must not leak other user senses');
        $this->assertSame($mySense->id, $candidates[0]['sense_id']);
    }

    // ==================== 9. Cross-language isolation ====================

    public function test_inline_preview_does_not_leak_across_languages(): void
    {
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

        $response = $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=run&language=english')
            ->assertOk();

        $candidates = $response->json('candidates');
        $this->assertCount(1, $candidates, 'only english sense should be returned');
        $this->assertSame($englishSense->id, $candidates[0]['sense_id']);
    }

    // ==================== 10. Empty lemma / unknown lemma returns safe empty state ====================

    public function test_inline_preview_endpoint_returns_422_for_empty_lemma(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=&language=english');

        $response->assertStatus(422);
    }

    public function test_inline_preview_returns_safe_empty_state_for_unknown_lemma(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=nonexistentlemma&language=english')
            ->assertOk();

        $json = $response->json();
        $this->assertFalse($json['has_confirmed_senses']);
        $this->assertSame(0, $json['candidate_count']);
        $this->assertSame([], $json['candidates']);
        $this->assertTrue($json['safety_flags']['read_only']);
        $this->assertTrue($json['safety_flags']['no_review_log_created']);
    }

    public function test_inline_preview_service_returns_empty_for_empty_lemma(): void
    {
        $payload = $this->knownSenseService->previewInlineSenseCandidates(
            $this->user->id, 'english', ''
        );

        $this->assertFalse($payload['has_confirmed_senses']);
        $this->assertSame(0, $payload['candidate_count']);
        $this->assertSame([], $payload['candidates']);
        $this->assertSame('', $payload['lemma']);
    }

    // ==================== Payload shape ====================

    public function test_inline_preview_payload_includes_all_documented_top_level_keys(): void
    {
        $this->createConfirmedSense('goose', 'geese', '鹅');

        $response = $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=goose&language=english&surface=geese&sentence=The%20geese%20flew.')
            ->assertOk();

        $json = $response->json();
        $this->assertArrayHasKey('lemma', $json);
        $this->assertArrayHasKey('surface', $json);
        $this->assertArrayHasKey('sentence', $json);
        $this->assertArrayHasKey('has_confirmed_senses', $json);
        $this->assertArrayHasKey('candidates', $json);
        $this->assertArrayHasKey('candidate_count', $json);
        $this->assertArrayHasKey('safety_flags', $json);
        $this->assertArrayHasKey('ui_hint', $json);
    }

    public function test_inline_preview_passes_surface_and_sentence_through_for_display(): void
    {
        $this->createConfirmedSense('goose', 'geese', '鹅');

        $response = $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=goose&language=english&surface=geese&sentence=The%20geese%20flew%20south.')
            ->assertOk();

        $json = $response->json();
        $this->assertSame('goose', $json['lemma']);
        $this->assertSame('geese', $json['surface']);
        $this->assertSame('The geese flew south.', $json['sentence']);
    }

    public function test_inline_preview_includes_fsrs_summary_per_candidate(): void
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

        $response = $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=goose&language=english')
            ->assertOk();

        $candidate = $response->json('candidates.0');
        $this->assertTrue($candidate['has_review_card']);
        $this->assertSame($card->id, $candidate['review_card_id']);
        $this->assertSame('review', $candidate['fsrs_state']);
        $this->assertSame(3, $candidate['fsrs_reps']);
        $this->assertTrue($candidate['fsrs_enabled']);
    }

    public function test_inline_preview_endpoint_enforces_language_match(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=goose&language=japanese');

        $response->assertStatus(403);
    }

    public function test_inline_preview_endpoint_requires_authentication(): void
    {
        $response = $this->get('/senses/inline-preview?lemma=goose&language=english');
        $response->assertRedirect('/login');
    }

    public function test_inline_preview_normalizes_lemma_lowercase_trim(): void
    {
        $this->createConfirmedSense('goose', 'geese', '鹅');

        $response = $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=%20%20GOOSE%20%20&language=english')
            ->assertOk();

        $this->assertSame('goose', $response->json('lemma'));
        $this->assertTrue($response->json('has_confirmed_senses'));
    }

    public function test_inline_preview_read_only_flag_is_true(): void
    {
        $this->createConfirmedSense('goose', 'geese', '鹅');

        $response = $this->actingAs($this->user)
            ->get('/senses/inline-preview?lemma=goose&language=english')
            ->assertOk();

        $this->assertTrue($response->json('safety_flags.read_only'));
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
