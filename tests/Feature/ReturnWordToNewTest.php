<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReturnWordToNewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Return To New Test',
            'email' => '__VG_EMAIL_return_to_new__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other User',
            'email' => '__VG_EMAIL_other_return_to_new__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    private function createEncounteredWord(
        string $word,
        int $stage,
        ?User $user = null
    ): EncounteredWord {
        $u = $user ?? $this->user;
        return EncounteredWord::forceCreate([
            'user_id' => $u->id,
            'language' => 'english',
            'word' => $word,
            'stage' => $stage,
            'translation' => 'test translation',
            'kanji' => '',
            'lemma' => $word,
            'base_word' => $word,
            'reading' => '',
            'base_word_reading' => '',
            'study_base' => $word,
            'lookup_count' => 0,
            'read_count' => 0,
            'relearning' => false,
        ]);
    }

    private function createWordSense(EncounteredWord $ew): WordSense
    {
        return WordSense::forceCreate([
            'user_id' => $ew->user_id,
            'language' => 'english',
            'language_id' => 'english',
            'sense_key' => hash('sha256', strtolower('english|' . $ew->word . '|noun|测试')),
            'lemma' => $ew->base_word,
            'surface_form' => $ew->word,
            'pos' => 'NOUN',
            'status' => WordSense::STATUS_CONFIRMED,
            'encountered_word_id' => $ew->id,
            'sense_zh' => '测试',
        ]);
    }

    private function createSenseReviewCard(WordSense $ws): ReviewCard
    {
        return ReviewCard::forceCreate([
            'user_id' => $ws->user_id,
            'language_id' => $ws->language_id,
            'language' => $ws->language_id,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $ws->id,
            'fsrs_state' => 'review',
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_due_at' => Carbon::now()->addDay(),
            'fsrs_reps' => 3,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
        ]);
    }

    private function createLegacyWordReviewCard(EncounteredWord $ew): ReviewCard
    {
        return ReviewCard::forceCreate([
            'user_id' => $ew->user_id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => $ew->id,
            'fsrs_state' => 'review',
            'fsrs_stability' => 8.0,
            'fsrs_difficulty' => 4.0,
            'fsrs_due_at' => Carbon::now()->addDay(),
            'fsrs_reps' => 5,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
        ]);
    }

    private function createReviewLog(ReviewCard $card, Carbon $when): ReviewLog
    {
        return ReviewLog::forceCreate([
            'user_id' => $card->user_id,
            'language_id' => $card->language_id,
            'language' => $card->language,
            'review_card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => $when,
            'source' => 'manual',
            'previous_state' => 1,
            'new_state' => 2,
            'previous_due_at' => Carbon::now()->subDay(),
            'new_due_at' => Carbon::now()->addDay(),
            'previous_stability' => 5.0,
            'new_stability' => 10.0,
            'previous_difficulty' => 5.0,
            'new_difficulty' => 5.0,
        ]);
    }

    private function createWordSenseOccurrence(WordSense $ws, ReviewCard $card): WordSenseOccurrence
    {
        return WordSenseOccurrence::forceCreate([
            'user_id' => $ws->user_id,
            'language' => $ws->language_id,
            'language_id' => $ws->language_id,
            'word_sense_id' => $ws->id,
            'review_card_id' => $card->id,
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => $ws->surface_form,
            'lemma' => $ws->lemma,
            'auto_fsrs_allowed' => true,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'sentence_id' => 1,
            'sentence_en' => 'test sentence',
            'decision' => 'accept',
        ]);
    }

    private function deleteWord(int $wordId): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->postJson('/vocabulary/word/delete', ['id' => $wordId]);
    }

    /** @test */
    public function api_requires_authentication(): void
    {
        $response = $this->postJson('/vocabulary/word/delete', ['id' => 1]);
        $response->assertUnauthorized();
    }

    /** @test */
    public function api_rejects_other_users_word(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', -7, $this->otherUser);

        $response = $this->deleteWord($ew->id);
        $response->assertStatus(500); // Word does not exist or belongs to different user
    }

    /** @test */
    public function deletes_encountered_word(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', -7);

        $this->deleteWord($ew->id)->assertOk();

        $this->assertNull(EncounteredWord::find($ew->id));
    }

    /** @test */
    public function rejects_sense_review_card_and_logs(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', -7);
        $ws = $this->createWordSense($ew);
        $card = $this->createSenseReviewCard($ws);

        $log1 = $this->createReviewLog($card, Carbon::now()->subDays(3));
        $log2 = $this->createReviewLog($card, Carbon::now()->subDays(1));
        $oc = $this->createWordSenseOccurrence($ws, $card);

        // Verify preconditions
        $this->assertNotNull(ReviewCard::find($card->id));

        $this->deleteWord($ew->id)->assertOk();

        // Sense WordSense rejected
        $ws->refresh();
        $this->assertEquals(WordSense::STATUS_REJECTED, $ws->status);

        // Sense ReviewCard deleted
        $this->assertNull(ReviewCard::find($card->id));

        // Sense ReviewLogs deleted
        $this->assertNull(ReviewLog::find($log1->id));
        $this->assertNull(ReviewLog::find($log2->id));

        // WordSenseOccurrence preserved but decoupled
        $oc->refresh();
        $this->assertNull($oc->review_card_id);
        $this->assertFalse($oc->auto_fsrs_allowed);
    }

    /** @test */
    public function deletes_legacy_word_review_card_and_logs(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', -7);
        $card = $this->createLegacyWordReviewCard($ew);
        $log = $this->createReviewLog($card, Carbon::now()->subDay());

        $this->deleteWord($ew->id)->assertOk();

        // Legacy word ReviewCard deleted
        $this->assertNull(ReviewCard::find($card->id));

        // Its ReviewLog deleted
        $this->assertNull(ReviewLog::find($log->id));
    }

    /** @test */
    public function protects_other_users_data(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', -7, $this->user);
        $otherEw = $this->createEncounteredWord('other', -7, $this->otherUser);
        $ws = $this->createWordSense($ew);
        $card = $this->createSenseReviewCard($ws);
        $log = $this->createReviewLog($card, Carbon::now()->subDay());

        $this->deleteWord($ew->id)->assertOk();

        // Other user's data untouched
        $otherEw->refresh();
        $this->assertEquals(-7, $otherEw->stage);
        $this->assertNotNull(EncounteredWord::find($otherEw->id));
    }

    /** @test */
    public function protects_other_language_data(): void
    {
        $otherLangEw = EncounteredWord::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'japanese',
            'word' => '現象学',
            'stage' => -7,
            'translation' => 'test',
            'kanji' => '',
            'lemma' => '現象学',
            'base_word' => '現象学',
            'reading' => 'げんしょうがく',
            'base_word_reading' => '',
            'study_base' => '現象学',
            'lookup_count' => 0,
            'read_count' => 0,
            'relearning' => false,
        ]);

        $ew = $this->createEncounteredWord('phenomenology', -7);
        $this->deleteWord($ew->id)->assertOk();

        // Other language data untouched
        $otherLangEw->refresh();
        $this->assertEquals(-7, $otherLangEw->stage);
        $this->assertNotNull(EncounteredWord::find($otherLangEw->id));
    }

    /** @test */
    public function protects_same_lemma_different_encountered_word(): void
    {
        $ew1 = $this->createEncounteredWord('phenomenology', -7);
        $ew2 = $this->createEncounteredWord('phenomenology', -5);
        $ws2 = $this->createWordSense($ew2);
        $card2 = $this->createSenseReviewCard($ws2);
        $log2 = $this->createReviewLog($card2, Carbon::now()->subDay());
        $oc2 = $this->createWordSenseOccurrence($ws2, $card2);

        // Delete only ew1
        $this->deleteWord($ew1->id)->assertOk();

        // ew2's data should be untouched
        $this->assertNotNull(EncounteredWord::find($ew2->id));
        $ws2->refresh();
        $this->assertEquals(WordSense::STATUS_CONFIRMED, $ws2->status);
        $this->assertNotNull(ReviewCard::find($card2->id));
        $this->assertNotNull(ReviewLog::find($log2->id));
    }

    /** @test */
    public function newly_loaded_article_shows_word_as_new(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', -7);

        $this->deleteWord($ew->id)->assertOk();

        // EncounteredWord is deleted, so on next page load the
        // reader will re-create it as stage 2 (new) via encountered word logic
        $this->assertNull(EncounteredWord::find($ew->id));
    }

    /** @test */
    public function does_not_create_review_log(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', -7);

        $originalLogCount = ReviewLog::count();
        $this->deleteWord($ew->id)->assertOk();
        $this->assertEquals($originalLogCount, ReviewLog::count());
    }

    /** @test */
    public function does_not_modify_other_cards_due_at(): void
    {
        $ew = $this->createEncounteredWord('phenomenology', -7);
        $otherEw = $this->createEncounteredWord('philosophy', -7);
        $ws = $this->createWordSense($otherEw);
        $otherCard = $this->createSenseReviewCard($ws);
        $originalDueAt = $otherCard->fsrs_due_at->copy();

        $this->deleteWord($ew->id)->assertOk();

        $otherCard->refresh();
        $this->assertEquals($originalDueAt->timestamp, $otherCard->fsrs_due_at->timestamp);
    }
}
