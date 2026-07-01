<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\WordSenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WordSenseDestroyRestoreTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private WordSenseService $wordSenseService;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure reviewIntervals setting exists for setStage() calls
        if (!Setting::where('name', 'reviewIntervals')->exists()) {
            Setting::forceCreate([
                'name' => 'reviewIntervals',
                'value' => json_encode([
                    '-7' => [0],
                    '-6' => [1],
                    '-5' => [2],
                    '-4' => [3],
                    '-3' => [7],
                    '-2' => [15],
                    '-1' => [30],
                ]),
            ]);
        }

        $this->user = User::forceCreate([
            'name' => 'Sense Destroy User',
            'email' => '__VG_EMAIL_sdr_1__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other Sense User',
            'email' => '__VG_EMAIL_sdr_2__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->wordSenseService = app(WordSenseService::class);
    }

    // ════════════════════════════════════════════════════════════════
    //  Helpers
    // ════════════════════════════════════════════════════════════════

    private function createSense(string $lemma = 'test', array $overrides = [], ?User $user = null): WordSense
    {
        $u = $user ?? $this->user;
        return WordSense::forceCreate(array_merge([
            'user_id' => $u->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => '释义_' . $lemma,
            'sense_en' => $lemma,
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'This is a test.',
            'example_sentence_zh' => '这是一个测试。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower("english|{$lemma}|noun|释义_{$lemma}|{$lemma}")),
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
            'fsrs_stability' => 5.0,
            'fsrs_difficulty' => 4.0,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => now()->subDays(1),
            'fsrs_enabled' => true,
        ], $overrides));
    }

    private function createReviewLog(ReviewCard $card): ReviewLog
    {
        return ReviewLog::forceCreate([
            'user_id' => $card->user_id,
            'language_id' => $card->language_id,
            'language' => $card->language_id,
            'review_card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => now()->subDay(),
            'previous_state' => 'new',
            'new_state' => 'review',
            'previous_due_at' => now()->subDays(2),
            'new_due_at' => now()->subDay(),
            'previous_stability' => null,
            'new_stability' => 5.0,
            'previous_difficulty' => null,
            'new_difficulty' => 4.0,
            'source' => 'sense_review',
        ]);
    }

    private function createOccurrence(WordSense $sense, ReviewCard $card): WordSenseOccurrence
    {
        return WordSenseOccurrence::forceCreate([
            'user_id' => $sense->user_id,
            'language' => $sense->language,
            'language_id' => $sense->language_id,
            'word_sense_id' => $sense->id,
            'review_card_id' => $card->id,
            'sentence_id' => (string) $card->id,
            'surface' => $sense->lemma,
            'lemma' => $sense->lemma,
            'pos' => $sense->pos,
            'sentence_en' => $sense->example_sentence_en,
            'type' => WordSenseOccurrence::TYPE_WORD,
            'decision' => 'confirmed',
            'confidence' => 1.0,
            'evidence' => [],
            'auto_fsrs_allowed' => true,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_VOCAB_BRIDGE,
            'raw_payload' => [],
        ]);
    }

    private function createEncounteredWord(WordSense $sense, int $stage = -7): EncounteredWord
    {
        return EncounteredWord::forceCreate([
            'user_id' => $sense->user_id,
            'language' => $sense->language_id,
            'word' => $sense->lemma,
            'lemma' => $sense->lemma,
            'base_word' => $sense->lemma,
            'study_base' => $sense->lemma,
            'reading' => '',
            'kanji' => '',
            'base_word_reading' => '',
            'stage' => $stage,
            'translation' => '',
        ]);
    }

    private function createFullSenseWithCard(string $lemma = 'test', int $encounteredStage = -7, ?User $user = null): array
    {
        $sense = $this->createSense($lemma, [], $user);
        $card = $this->createSenseCard($sense);
        $log = $this->createReviewLog($card);
        $occurrence = $this->createOccurrence($sense, $card);
        $encountered = $this->createEncounteredWord($sense, $encounteredStage);

        // Link encountered_word_id back to sense
        $sense->encountered_word_id = $encountered->id;
        $sense->save();

        return compact('sense', 'card', 'log', 'occurrence', 'encountered');
    }

    // ════════════════════════════════════════════════════════════════
    //  A. archiveSense rejects sense and disables card
    // ════════════════════════════════════════════════════════════════

    public function test_archive_sense_rejects_and_disables_card(): void
    {
        $data = $this->createFullSenseWithCard('archive_test');
        $sense = $data['sense'];
        $card = $data['card'];
        $originalLogCount = ReviewLog::count();
        $originalOccCount = WordSenseOccurrence::count();

        $this->wordSenseService->archiveSense($sense);

        $sense->refresh();
        $this->assertEquals(WordSense::STATUS_REJECTED, $sense->status);

        $card->refresh();
        $this->assertDatabaseHas('review_cards', ['id' => $card->id]);
        $this->assertFalse((bool) $card->fsrs_enabled);

        // ReviewLog preserved
        $this->assertEquals($originalLogCount, ReviewLog::count());

        // Occurrence row preserved (current real behavior: archiveSense does NOT clear refs)
        $this->assertEquals($originalOccCount, WordSenseOccurrence::count());

        // EncounteredWord not restored to New
        $data['encountered']->refresh();
        $this->assertLessThan(0, $data['encountered']->stage);
    }

    // ════════════════════════════════════════════════════════════════
    //  B. removeSenseFromReviewSystem deleteReviewCard=false
    // ════════════════════════════════════════════════════════════════

    public function test_remove_sense_no_delete_card_disables_and_clears_occurrence(): void
    {
        $data = $this->createFullSenseWithCard('no_del_card');
        $sense = $data['sense'];
        $card = $data['card'];
        $originalLogCount = ReviewLog::count();

        $this->wordSenseService->removeSenseFromReviewSystem($sense, false);

        $sense->refresh();
        $this->assertEquals(WordSense::STATUS_REJECTED, $sense->status);

        $card->refresh();
        $this->assertDatabaseHas('review_cards', ['id' => $card->id]);
        $this->assertFalse((bool) $card->fsrs_enabled);

        // ReviewLog preserved
        $this->assertEquals($originalLogCount, ReviewLog::count());

        // Occurrence row preserved but review_card_id cleared and auto_fsrs_allowed=false
        $this->assertDatabaseHas('word_sense_occurrences', [
            'id' => $data['occurrence']->id,
            'review_card_id' => null,
            'auto_fsrs_allowed' => false,
        ]);

        // EncounteredWord not restored (not permanent delete)
        $data['encountered']->refresh();
        $this->assertLessThan(0, $data['encountered']->stage);
    }

    // ════════════════════════════════════════════════════════════════
    //  C. removeSenseFromReviewSystem deleteReviewCard=true
    // ════════════════════════════════════════════════════════════════

    public function test_remove_sense_delete_card_deletes_card_and_clears_occurrence(): void
    {
        $data = $this->createFullSenseWithCard('del_card');
        $sense = $data['sense'];
        $originalLogCount = ReviewLog::count();

        $this->wordSenseService->removeSenseFromReviewSystem($sense, true);

        $sense->refresh();
        $this->assertEquals(WordSense::STATUS_REJECTED, $sense->status);

        // ReviewCard deleted
        $this->assertDatabaseMissing('review_cards', ['id' => $data['card']->id]);

        // ReviewLog preserved by default
        $this->assertEquals($originalLogCount, ReviewLog::count());

        // Occurrence row preserved but cleared
        $this->assertDatabaseHas('word_sense_occurrences', [
            'id' => $data['occurrence']->id,
            'review_card_id' => null,
            'auto_fsrs_allowed' => false,
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //  D. deleteReviewLogs=false preserves ReviewLog by default
    // ════════════════════════════════════════════════════════════════

    public function test_delete_review_logs_false_preserves_review_log(): void
    {
        $data = $this->createFullSenseWithCard('log_preserve');
        $sense = $data['sense'];
        $log = $data['log'];

        $this->wordSenseService->removeSenseFromReviewSystem($sense, true, false);

        $this->assertDatabaseHas('review_logs', ['id' => $log->id]);
    }

    // ════════════════════════════════════════════════════════════════
    //  E. deleteReviewLogs=true deletes only matching user/language/card logs
    // ════════════════════════════════════════════════════════════════

    public function test_delete_review_logs_true_deletes_only_matching_logs(): void
    {
        // Current user's sense card + log
        $data = $this->createFullSenseWithCard('logs_main');
        $sense = $data['sense'];
        $log = $data['log'];

        // Another user's log (different user, same language, same card shape)
        $otherSense = $this->createSense('other_logs', [], $this->otherUser);
        $otherCard = $this->createSenseCard($otherSense, ['user_id' => $this->otherUser->id]);
        $otherLog = $this->createReviewLog($otherCard);

        // Same user, different card (different target)
        $otherSense2 = $this->createSense('other_card', []);
        $otherCard2 = $this->createSenseCard($otherSense2);
        $otherLog2 = $this->createReviewLog($otherCard2);

        $this->wordSenseService->removeSenseFromReviewSystem($sense, true, true);

        // Current user's log for this card deleted
        $this->assertDatabaseMissing('review_logs', ['id' => $log->id]);

        // Other user's log preserved
        $this->assertDatabaseHas('review_logs', ['id' => $otherLog->id]);

        // Different card's log preserved
        $this->assertDatabaseHas('review_logs', ['id' => $otherLog2->id]);
    }

    // ════════════════════════════════════════════════════════════════
    //  F. permanent delete restores Learning EncounteredWord to New
    // ════════════════════════════════════════════════════════════════

    public function test_permanent_delete_restores_learning_word_to_new(): void
    {
        $data = $this->createFullSenseWithCard('restore_learn', -7);
        $encountered = $data['encountered'];

        $this->wordSenseService->removeSenseFromReviewSystem($data['sense'], true);

        $encountered->refresh();
        $this->assertEquals(2, $encountered->stage);
        $this->assertFalse((bool) $encountered->relearning);
        $this->assertNull($encountered->next_review);
    }

    // ════════════════════════════════════════════════════════════════
    //  G. permanent delete does not restore if another confirmed sense remains
    // ════════════════════════════════════════════════════════════════

    public function test_permanent_delete_no_restore_when_other_confirmed_sense_exists(): void
    {
        $encountered = $this->createEncounteredWord(
            $this->createSense('multi', []),
            -7
        );

        // Two confirmed senses linked to the same EncounteredWord
        $sense1 = $this->createSense('multi_1', ['encountered_word_id' => $encountered->id]);
        $card1 = $this->createSenseCard($sense1);
        $this->createReviewLog($card1);

        $sense2 = $this->createSense('multi_2', ['encountered_word_id' => $encountered->id]);
        $card2 = $this->createSenseCard($sense2);
        $this->createReviewLog($card2);

        $originalStage = $encountered->stage;

        // Delete only sense1
        $this->wordSenseService->removeSenseFromReviewSystem($sense1, true);

        // EncounteredWord should NOT be restored (stage unchanged)
        $encountered->refresh();
        $this->assertEquals($originalStage, $encountered->stage);
    }

    // ════════════════════════════════════════════════════════════════
    //  H. permanent delete does not restore Known / Ignored / New
    // ════════════════════════════════════════════════════════════════

    /** @dataProvider provideNonLearningStages */
    public function test_permanent_delete_does_not_restore_non_learning_stages(int $stage): void
    {
        $data = $this->createFullSenseWithCard("stage_{$stage}", $stage);

        $this->wordSenseService->removeSenseFromReviewSystem($data['sense'], true);

        $data['encountered']->refresh();
        $this->assertEquals($stage, $data['encountered']->stage);
    }

    public static function provideNonLearningStages(): array
    {
        return [
            'Known (0)' => [0],
            'Ignored (1)' => [1],
            'New (2)' => [2],
        ];
    }

    // ════════════════════════════════════════════════════════════════
    //  I. restore uses encountered_word_id, not lemma
    // ════════════════════════════════════════════════════════════════

    public function test_restore_uses_encountered_word_id_not_lemma(): void
    {
        // Two EncounteredWords with same lemma but different id
        $ew1 = $this->createEncounteredWord(
            $this->createSense('same_lemma', []),
            -7
        );
        $ew2 = $this->createEncounteredWord(
            $this->createSense('same_lemma', []),
            -7
        );

        // sense1 linked to ew1 (first EncounteredWord)
        $sense1 = $this->createSense('same_lemma_1', ['encountered_word_id' => $ew1->id]);
        $card1 = $this->createSenseCard($sense1);
        $this->createReviewLog($card1);

        // sense2 linked to ew2 (second EncounteredWord) — same lemma!
        $sense2 = $this->createSense('same_lemma_2', ['encountered_word_id' => $ew2->id]);
        $card2 = $this->createSenseCard($sense2);
        $this->createReviewLog($card2);

        // Delete sense1 — restore should check ew1's remaining senses, not ew2's
        $this->wordSenseService->removeSenseFromReviewSystem($sense1, true);

        // ew1 should be restored (no other sense links to ew1)
        $ew1->refresh();
        $this->assertEquals(2, $ew1->stage);

        // ew2 should NOT be restored (sense2 still links to it)
        $ew2->refresh();
        $this->assertLessThan(0, $ew2->stage);
    }

    // ════════════════════════════════════════════════════════════════
    //  J. legacy word review card not affected
    // ════════════════════════════════════════════════════════════════

    public function test_sense_delete_does_not_affect_legacy_word_card(): void
    {
        $data = $this->createFullSenseWithCard('legacy_sense');

        // Create a legacy word card for the same user
        $wordCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => 'word',
            'target_id' => 9999,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->addDay(),
            'fsrs_stability' => 5.0,
            'fsrs_difficulty' => 4.0,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => now()->subDays(1),
            'fsrs_enabled' => true,
        ]);

        $this->wordSenseService->removeSenseFromReviewSystem($data['sense'], true);

        $this->assertDatabaseHas('review_cards', ['id' => $wordCard->id, 'fsrs_enabled' => true]);
    }

    // ════════════════════════════════════════════════════════════════
    //  K. rejectSense current behavior (low priority — legacy method)
    // ════════════════════════════════════════════════════════════════

    public function test_reject_sense_only_changes_status(): void
    {
        $data = $this->createFullSenseWithCard('reject_test');

        $this->wordSenseService->rejectSense($data['sense']);

        $data['sense']->refresh();
        $this->assertEquals(WordSense::STATUS_REJECTED, $data['sense']->status);

        // ReviewCard unchanged
        $data['card']->refresh();
        $this->assertEquals(true, (bool) $data['card']->fsrs_enabled);

        // ReviewLog unchanged
        $this->assertDatabaseHas('review_logs', ['id' => $data['log']->id]);

        // Occurrence unchanged
        $this->assertDatabaseHas('word_sense_occurrences', [
            'id' => $data['occurrence']->id,
            'review_card_id' => $data['card']->id,
        ]);

        // EncounteredWord unchanged
        $data['encountered']->refresh();
        $this->assertLessThan(0, $data['encountered']->stage);
    }

    // ════════════════════════════════════════════════════════════════
    //  L. route/controller ownership check — /senses/{id}/archive
    // ════════════════════════════════════════════════════════════════

    public function test_archive_route_enforces_ownership(): void
    {
        $sense = $this->createSense('other_archive', [], $this->otherUser);

        $response = $this->actingAs($this->user)
            ->putJson("/senses/{$sense->id}/archive");

        // Should fail 404 because sense belongs to otherUser
        $response->assertStatus(404);
    }

    // ════════════════════════════════════════════════════════════════
    //  M. archiveSense from route actually writes (happy path via HTTP)
    // ════════════════════════════════════════════════════════════════

    public function test_archive_route_happy_path(): void
    {
        $data = $this->createFullSenseWithCard('route_archive');
        $sense = $data['sense'];

        $response = $this->actingAs($this->user)
            ->putJson("/senses/{$sense->id}/archive");

        $response->assertOk();
        $response->assertJsonPath('status', WordSense::STATUS_REJECTED);

        $sense->refresh();
        $this->assertEquals(WordSense::STATUS_REJECTED, $sense->status);

        $data['card']->refresh();
        $this->assertFalse((bool) $data['card']->fsrs_enabled);
    }
}
