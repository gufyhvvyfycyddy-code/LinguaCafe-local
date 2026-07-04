<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * OpenCode-ReviewCardManageDangerContractTests-1
 *
 * Contract tests for review card manage dangerous write operations.
 *
 * These tests lock in the safety semantics confirmed by
 * OpenCode-ReviewCardManageMutationBoundaryScout-800-1 and
 * OpenCode-ReviewCardManageDangerCopy-1.
 *
 * Design constraints:
 * - Do NOT modify business logic, controllers, or services.
 * - Do NOT modify frontend copy.
 * - Do NOT add migrations.
 * - All tests use PHP feature tests against the HTTP endpoints.
 */
class ReviewCardManageDangerContractTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private string $english = 'english';
    private string $spanish = 'spanish';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Danger Test User',
            'email' => 'danger@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->english,
            'password_changed' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Danger Other User',
            'email' => 'danger.other@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->english,
            'password_changed' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    // ==================== Helpers ====================

    private function createSense(int|string $userId, string $language, array $overrides = []): WordSense
    {
        $lemma = $overrides['lemma'] ?? 'test';
        $pos = $overrides['pos'] ?? 'noun';
        $senseZh = $overrides['sense_zh'] ?? '测试';
        $senseEn = $overrides['sense_en'] ?? 'test';

        return WordSense::forceCreate(array_merge([
            'user_id' => $userId,
            'language' => $language,
            'language_id' => $language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => $pos,
            'sense_zh' => $senseZh,
            'sense_en' => $senseEn,
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Example sentence.',
            'example_sentence_zh' => '例句。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower("{$language}|{$lemma}|{$pos}|{$senseZh}|{$senseEn}")),
        ], $overrides));
    }

    private function createSenseCard(WordSense $sense, array $overrides = []): ReviewCard
    {
        return ReviewCard::forceCreate(array_merge([
            'user_id' => $sense->user_id,
            'language_id' => $sense->language_id,
            'language' => $sense->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->addDay(),
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 1,
            'fsrs_last_reviewed_at' => now()->subDay(),
            'fsrs_enabled' => true,
        ], $overrides));
    }

    private function createWordCard(int $userId, string $language, int $wordId): ReviewCard
    {
        return ReviewCard::forceCreate([
            'user_id' => $userId,
            'language_id' => $language,
            'language' => $language,
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => $wordId,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->addDay(),
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 1,
            'fsrs_enabled' => true,
        ]);
    }

    private function createEncounteredWord(int $userId, string $language, string $word = 'testword', int $stage = -1): EncounteredWord
    {
        return EncounteredWord::forceCreate([
            'user_id' => $userId,
            'language' => $language,
            'word' => $word,
            'base_word' => $word,
            'lemma' => $word,
            'kanji' => '',
            'reading' => '',
            'translation' => '',
            'base_word_reading' => '',
            'relearning' => 0,
            'lookup_count' => 0,
            'read_count' => 0,
            'stage' => $stage,
        ]);
    }

    private function createReviewLog(ReviewCard $card): ReviewLog
    {
        return ReviewLog::forceCreate([
            'user_id' => $card->user_id,
            'language_id' => $card->language_id,
            'language' => $card->language,
            'review_card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => now()->subDay(),
            'previous_state' => 'new',
            'new_state' => 'review',
            'previous_due_at' => now()->subDays(2),
            'new_due_at' => now()->subDay(),
            'previous_stability' => 0.0,
            'new_stability' => 10.0,
            'previous_difficulty' => 0.0,
            'new_difficulty' => 5.0,
            'source' => 'review',
        ]);
    }

    private function createReviewLogWithState(ReviewCard $card, string $previousState, string $newState): ReviewLog
    {
        return ReviewLog::forceCreate([
            'user_id' => $card->user_id,
            'language_id' => $card->language_id,
            'language' => $card->language,
            'review_card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => now()->subDay(),
            'previous_state' => $previousState,
            'new_state' => $newState,
            'previous_due_at' => now()->subDays(2),
            'new_due_at' => now()->subDay(),
            'previous_stability' => 0.0,
            'new_stability' => 10.0,
            'previous_difficulty' => 0.0,
            'new_difficulty' => 5.0,
            'source' => 'review',
        ]);
    }

    // ==================== due-now ====================

    public function test_due_now_does_not_enable_archived_card_or_write_review_log(): void
    {
        $sense = $this->createSense($this->user->id, $this->english);
        $card = $this->createSenseCard($sense, ['fsrs_enabled' => false, 'fsrs_due_at' => now()->addDays(30)]);
        $oldDue = $card->fsrs_due_at->toISOString();

        $response = $this->actingAs($this->user)
            ->postJson("/review-cards/manage/{$card->id}/due-now");

        $response->assertStatus(200);
        $card->refresh();

        // Does NOT auto-enable
        $this->assertFalse($card->fsrs_enabled, 'due-now must NOT auto-enable an archived card.');

        // fsrs_due_at changed to now-ish
        $this->assertLessThanOrEqual(now()->addSecond(), $card->fsrs_due_at, 'due-now must set due_at to near-now.');
        $this->assertLessThan($oldDue, $card->fsrs_due_at->toISOString(), 'due-now must move due_at closer than before.');

        // No ReviewLog written
        $this->assertSame(0, ReviewLog::where('review_card_id', $card->id)->count(), 'due-now must NOT write ReviewLog.');
    }

    public function test_due_now_does_not_change_fsrs_fields_or_word_sense(): void
    {
        $sense = $this->createSense($this->user->id, $this->english);
        $card = $this->createSenseCard($sense, ['fsrs_state' => 'review', 'fsrs_stability' => 10.0, 'fsrs_difficulty' => 5.0]);

        $this->actingAs($this->user)->postJson("/review-cards/manage/{$card->id}/due-now");
        $card->refresh();
        $sense->refresh();

        $this->assertSame('review', $card->fsrs_state, 'due-now must NOT change fsrs_state.');
        $this->assertSame(10.0, $card->fsrs_stability, 'due-now must NOT change fsrs_stability.');
        $this->assertSame(5.0, $card->fsrs_difficulty, 'due-now must NOT change fsrs_difficulty.');
        $this->assertSame(WordSense::STATUS_CONFIRMED, $sense->status, 'due-now must NOT change WordSense.');
    }

    // ==================== reset ====================

    public function test_reset_preserves_existing_review_logs_and_adds_reset_log(): void
    {
        $sense = $this->createSense($this->user->id, $this->english);
        $card = $this->createSenseCard($sense, [
            'fsrs_state' => 'review',
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 5,
            'fsrs_lapses' => 2,
            'fsrs_last_reviewed_at' => now()->subDay(),
        ]);
        $this->createReviewLog($card); // existing log

        $response = $this->actingAs($this->user)->postJson("/review-cards/manage/{$card->id}/reset");
        $response->assertStatus(200);
        $card->refresh();

        // Existing ReviewLog preserved
        $this->assertSame(2, ReviewLog::where('review_card_id', $card->id)->count(),
            'reset must preserve old ReviewLog AND add a new reset log.');

        // New reset log
        $resetLog = ReviewLog::where('review_card_id', $card->id)->where('rating', 'reset')->first();
        $this->assertNotNull($resetLog, 'reset must create a ReviewLog with rating=reset.');
        $this->assertSame('reset', $resetLog->source, 'reset log must have source=reset.');

        // Card reset to new state
        $this->assertSame('new', $card->fsrs_state, 'reset must set state to new.');
        $this->assertNull($card->fsrs_stability, 'reset must clear stability.');
        $this->assertNull($card->fsrs_difficulty, 'reset must clear difficulty.');
        $this->assertSame(0, $card->fsrs_reps, 'reset must zero reps.');
        $this->assertSame(0, $card->fsrs_lapses, 'reset must zero lapses.');
        $this->assertNull($card->fsrs_last_reviewed_at, 'reset must clear last_reviewed_at.');
        $this->assertTrue($card->fsrs_enabled, 'reset must re-enable the card.');

        // WordSense unchanged
        $sense->refresh();
        $this->assertSame(WordSense::STATUS_CONFIRMED, $sense->status, 'reset must NOT change WordSense status.');
        $this->assertSame('noun', $sense->pos, 'reset must NOT change WordSense fields.');
    }

    public function test_reset_rejects_other_user_card(): void
    {
        $sense = $this->createSense($this->user->id, $this->english);
        $card = $this->createSenseCard($sense);

        $response = $this->actingAs($this->otherUser)->postJson("/review-cards/manage/{$card->id}/reset");
        $response->assertStatus(404, 'reset must reject other user card.');
    }

    public function test_reset_rejects_other_language_card(): void
    {
        $sense = $this->createSense($this->user->id, $this->spanish);
        $card = $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)->postJson("/review-cards/manage/{$card->id}/reset");
        $response->assertStatus(404, 'reset must reject other language card.');
    }

    public function test_reset_rejects_legacy_word_card(): void
    {
        $word = $this->createEncounteredWord($this->user->id, $this->english);
        $wordCard = $this->createWordCard($this->user->id, $this->english, $word->id);

        $response = $this->actingAs($this->user)->postJson("/review-cards/manage/{$wordCard->id}/reset");
        $response->assertStatus(404, 'reset must reject legacy word card.');
    }

    // ==================== destroy ====================

    public function test_destroy_preserves_review_logs_and_clears_occurrence_card_link(): void
    {
        $sense = $this->createSense($this->user->id, $this->english);
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card);

        // Create an occurrence linked to this card
        $occurrence = WordSenseOccurrence::forceCreate([
            'word_sense_id' => $sense->id,
            'user_id' => $this->user->id,
            'language_id' => $this->english,
            'language' => $this->english,
            'lemma' => 'test',
            'surface' => 'test',
            'pos' => 'noun',
            'type' => 'vocabulary',
            'sentence_en' => 'Example.',
            'sentence_id' => 0,
            'decision' => 'manual',
            'confidence' => 1.0,
            'source' => 'manage_test',
            'review_card_id' => $card->id,
            'auto_fsrs_allowed' => true,
            'status' => WordSenseOccurrence::STATUS_BOUND,
        ]);

        $response = $this->actingAs($this->user)->deleteJson("/review-cards/manage/{$card->id}");
        $response->assertStatus(200);

        // ReviewCard deleted
        $this->assertNull(ReviewCard::find($card->id), 'destroy must delete ReviewCard.');

        // WordSense rejected
        $sense->refresh();
        $this->assertSame(WordSense::STATUS_REJECTED, $sense->status, 'destroy must reject WordSense.');

        // ReviewLog preserved
        $this->assertSame(1, ReviewLog::count(), 'destroy must preserve ReviewLogs.');

        // Occurrence cleared
        $occurrence->refresh();
        $this->assertNull($occurrence->review_card_id, 'destroy must clear occurrence review_card_id.');
        $this->assertFalse($occurrence->auto_fsrs_allowed, 'destroy must set auto_fsrs_allowed to false.');
    }

    public function test_destroy_restores_encountered_word_only_when_last_confirmed_sense(): void
    {
        // Create an EncounteredWord in Learning stage
        $word = $this->createEncounteredWord($this->user->id, $this->english, 'testdestroy', -1);

        $sense = $this->createSense($this->user->id, $this->english, [
            'lemma' => 'testdestroy',
            'surface_form' => 'testdestroy',
            'encountered_word_id' => $word->id,
        ]);
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card);

        $this->actingAs($this->user)->deleteJson("/review-cards/manage/{$card->id}");
        $word->refresh();

        // Last sense deleted → EncounteredWord restored to New
        $this->assertSame(2, $word->stage, 'destroy must restore EncounteredWord to New (stage=2) when last confirmed sense removed.');
    }

    public function test_destroy_does_not_restore_encountered_word_when_another_confirmed_sense_exists(): void
    {
        $word = $this->createEncounteredWord($this->user->id, $this->english, 'testkeepexisting', -1);

        // First sense — will be deleted
        $sense1 = $this->createSense($this->user->id, $this->english, [
            'lemma' => 'testkeepexisting',
            'surface_form' => 'testkeepexisting',
            'encountered_word_id' => $word->id,
        ]);
        $card1 = $this->createSenseCard($sense1);

        // Second sense — stays confirmed
        $sense2 = $this->createSense($this->user->id, $this->english, [
            'lemma' => 'testkeepexisting',
            'surface_form' => 'testkeepexisting',
            'encountered_word_id' => $word->id,
            'pos' => 'verb',
            'sense_zh' => '测试动词',
            'sense_en' => 'test verb',
            'sense_key' => hash('sha256', strtolower("{$this->english}|testkeepexisting|verb|测试动词|test verb")),
        ]);

        $this->actingAs($this->user)->deleteJson("/review-cards/manage/{$card1->id}");
        $word->refresh();

        // Another confirmed sense exists → EncounteredWord remains Learning
        $this->assertSame(-1, $word->stage, 'destroy must NOT restore EncounteredWord when another confirmed sense exists.');
    }

    // ==================== bulkDestroy ====================

    public function test_bulk_destroy_skips_foreign_language_user_and_legacy_cards(): void
    {
        $sense1 = $this->createSense($this->user->id, $this->english);
        $card1 = $this->createSenseCard($sense1);
        $this->createReviewLog($card1);

        // Foreign user card
        $foreignSense = $this->createSense($this->otherUser->id, $this->english);
        $foreignCard = $this->createSenseCard($foreignSense);

        // Foreign language card
        $langSense = $this->createSense($this->user->id, $this->spanish);
        $langCard = $this->createSenseCard($langSense);

        // Legacy word card
        $word = $this->createEncounteredWord($this->user->id, $this->english);
        $wordCard = $this->createWordCard($this->user->id, $this->english, $word->id);

        $response = $this->actingAs($this->user)->postJson('/review-cards/manage/bulk-delete', [
            'ids' => [$card1->id, $foreignCard->id, $langCard->id, $wordCard->id],
        ]);
        $response->assertStatus(200);

        // Only card1 is deleted
        $this->assertNull(ReviewCard::find($card1->id), 'bulk-destroy must delete valid card.');
        $this->assertNotNull(ReviewCard::find($foreignCard->id), 'bulk-destroy must skip other user card.');
        $this->assertNotNull(ReviewCard::find($langCard->id), 'bulk-destroy must skip other language card.');
        $this->assertNotNull(ReviewCard::find($wordCard->id), 'bulk-destroy must skip legacy word card.');

        // deleted=1, skipped=3
        $response->assertJson(['deleted' => 1]);
        $response->assertJson(['skipped' => 3]);
    }

    public function test_bulk_destroy_preserves_review_logs_and_returns_counts(): void
    {
        $sense1 = $this->createSense($this->user->id, $this->english, ['lemma' => 'testbulk1', 'sense_zh' => '测试', 'sense_en' => 'test', 'pos' => 'noun']);
        $card1 = $this->createSenseCard($sense1);
        $this->createReviewLogWithState($card1, 'new', 'review');
        $logCountAfterFirst = ReviewLog::count();

        $sense2 = $this->createSense($this->user->id, $this->english, ['lemma' => 'testbulk2', 'sense_zh' => '其他', 'sense_en' => 'other', 'pos' => 'verb']);
        $card2 = $this->createSenseCard($sense2);
        $this->createReviewLogWithState($card2, 'new', 'review');
        $logCountAfterSecond = ReviewLog::count();

        $this->assertSame(2, $logCountAfterSecond, 'Pre-condition: 2 ReviewLogs must exist before bulk delete.');

        $response = $this->actingAs($this->user)->postJson('/review-cards/manage/bulk-delete', [
            'ids' => [$card1->id, $card2->id],
        ]);
        $response->assertStatus(200);
        $response->assertJson(['deleted' => 2, 'skipped' => 0]);

        // ReviewLogs preserved (1 per card)
        $this->assertSame(2, ReviewLog::count(), 'bulk-destroy must preserve all ReviewLogs.');
    }

    // ==================== bulkEnabled ====================

    public function test_bulk_enabled_skips_foreign_language_user_and_legacy_cards(): void
    {
        $sense1 = $this->createSense($this->user->id, $this->english);
        $card1 = $this->createSenseCard($sense1, ['fsrs_enabled' => true]);

        // Foreign user card
        $foreignSense = $this->createSense($this->otherUser->id, $this->english);
        $foreignCard = $this->createSenseCard($foreignSense, ['fsrs_enabled' => true]);

        // Foreign language card
        $langSense = $this->createSense($this->user->id, $this->spanish);
        $langCard = $this->createSenseCard($langSense, ['fsrs_enabled' => true]);

        // Legacy word card
        $word = $this->createEncounteredWord($this->user->id, $this->english);
        $wordCard = $this->createWordCard($this->user->id, $this->english, $word->id);

        // Bulk archive (disable)
        $response = $this->actingAs($this->user)->postJson('/review-cards/manage/bulk-enabled', [
            'ids' => [$card1->id, $foreignCard->id, $langCard->id, $wordCard->id],
            'enabled' => false,
        ]);
        $response->assertStatus(200);

        $card1->refresh();
        $foreignCard->refresh();
        $langCard->refresh();
        $wordCard->refresh();

        $this->assertFalse($card1->fsrs_enabled, 'bulk-enabled must archive valid card.');
        $this->assertTrue($foreignCard->fsrs_enabled, 'bulk-enabled must skip other user card.');
        $this->assertTrue($langCard->fsrs_enabled, 'bulk-enabled must skip other language card.');
        $this->assertTrue($wordCard->fsrs_enabled, 'bulk-enabled must skip legacy word card.');

        $response->assertJson(['affected' => 1, 'skipped' => 3]);
    }

    // ==================== update ====================

    public function test_update_ignores_non_editable_fields_and_does_not_touch_fsrs_or_review_log(): void
    {
        $sense = $this->createSense($this->user->id, $this->english);
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card);

        $response = $this->actingAs($this->user)->patchJson("/review-cards/manage/{$card->id}", [
            'pos' => 'verb',
            'sense_zh' => '新释义',
            'sense_en' => 'new meaning',
            'status' => 'rejected', // should be ignored
            'fsrs_enabled' => false, // should be ignored
            'target_type' => 'word', // should be ignored
        ]);
        $response->assertStatus(200);
        $sense->refresh();
        $card->refresh();

        // Editable fields updated
        $this->assertSame('verb', $sense->pos, 'update must change pos.');
        $this->assertSame('新释义', $sense->sense_zh, 'update must change sense_zh.');
        $this->assertSame('new meaning', $sense->sense_en, 'update must change sense_en.');

        // Non-editable fields NOT changed
        $this->assertSame(WordSense::STATUS_CONFIRMED, $sense->status, 'update must ignore status in payload.');
        $this->assertTrue($card->fsrs_enabled, 'update must ignore fsrs_enabled in payload.');
        $this->assertSame(ReviewCard::TARGET_SENSE, $card->target_type, 'update must ignore target_type in payload.');

        // FSRS and ReviewLog untouched
        $this->assertSame('review', $card->fsrs_state, 'update must NOT change fsrs_state.');
        $this->assertSame(10.0, $card->fsrs_stability, 'update must NOT change fsrs_stability.');
        $this->assertSame(1, ReviewLog::count(), 'update must NOT create ReviewLogs.');
    }
}
