<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\RescheduleSnapshot;
use App\Models\RescheduleSnapshotItem;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\FsrsReschedulePreviewService;
use App\Services\FsrsRescheduleSnapshotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FsrsRescheduleUndoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Undo User',
            'email' => '__VG_EMAIL_a1b2c3d4e5f6__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other Undo User',
            'email' => '__VG_EMAIL_f6e5d4c3b2a1__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        // Create a non-admin user for auth tests
        $this->nonAdminUser = User::forceCreate([
            'name' => 'Non Admin',
            'email' => '__VG_EMAIL_na1__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    // ═══════════════════════════════════════════════
    //  Tests
    // ═══════════════════════════════════════════════

    public function test_missing_confirm_returns_422(): void
    {
        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', []);
        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'The confirm field is required.']);
    }

    public function test_no_snapshot_returns_422(): void
    {
        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', [
            'confirm' => true,
        ]);
        $response->assertStatus(422);
        $response->assertJsonFragment(['undo_available' => false]);
    }

    public function test_successful_undo_restores_fields(): void
    {
        $this->injectThresholdService(10, 10);
        $card = $this->createEligibleReviewCard();
        $card->refresh();
        $origDue = $card->fsrs_due_at;
        $origStab = $card->fsrs_stability;
        $origDiff = $card->fsrs_difficulty;

        $this->runReschedule();

        // Verify card changed after reschedule
        $card->refresh();
        $this->assertNotEquals($origDue->toIso8601String(), $card->fsrs_due_at->toIso8601String());

        // Undo
        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', [
            'confirm' => true,
        ]);
        $response->assertOk();
        $this->assertEquals(1, $response->json('restored_count'));

        // Verify restored
        $card->refresh();
        $this->assertEquals($origDue->toIso8601String(), $card->fsrs_due_at->toIso8601String());
        $this->assertEquals($origStab, $card->fsrs_stability);
        $this->assertEquals($origDiff, $card->fsrs_difficulty);
    }

    public function test_undo_does_not_change_reps_lapses_last_reviewed(): void
    {
        $this->injectThresholdService(10, 10);
        $card = $this->createEligibleReviewCard('noreps', [
            'fsrs_reps' => 5,
            'fsrs_lapses' => 2,
            'fsrs_last_reviewed_at' => now()->subDays(3),
        ]);
        $origReps = $card->fsrs_reps;
        $origLapses = $card->fsrs_lapses;
        $origLastReviewed = $card->fsrs_last_reviewed_at->toIso8601String();

        $this->runReschedule();
        $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true])->assertOk();

        $card->refresh();
        $this->assertEquals($origReps, $card->fsrs_reps);
        $this->assertEquals($origLapses, $card->fsrs_lapses);
        $this->assertEquals($origLastReviewed, $card->fsrs_last_reviewed_at->toIso8601String());
    }

    public function test_undo_does_not_write_review_log(): void
    {
        $this->injectThresholdService(10, 10);
        $this->createEligibleReviewCard();
        $beforeCount = ReviewLog::count();

        $this->runReschedule();
        $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true])->assertOk();

        $this->assertEquals($beforeCount, ReviewLog::count());
        $this->assertEquals(0, ReviewLog::where('source', 'reschedule')->count());
    }

    public function test_undo_marks_snapshot_undone_at(): void
    {
        $this->injectThresholdService(10, 10);
        $this->createEligibleReviewCard();
        $this->runReschedule();

        $snapshot = RescheduleSnapshot::first();
        $this->assertNull($snapshot->undone_at);

        $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true])->assertOk();

        $snapshot->refresh();
        $this->assertNotNull($snapshot->undone_at);
    }

    public function test_undo_marks_item_undone(): void
    {
        $this->injectThresholdService(10, 10);
        $this->createEligibleReviewCard();
        $this->runReschedule();

        $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true])->assertOk();

        $item = RescheduleSnapshotItem::first();
        $this->assertTrue((bool) $item->undone);
        $this->assertNotNull($item->undone_at);
    }

    public function test_undo_twice_returns_no_snapshot(): void
    {
        $this->injectThresholdService(10, 10);
        $this->createEligibleReviewCard();
        $this->runReschedule();

        // First undo
        $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true])->assertOk();

        // Second undo
        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true]);
        $response->assertStatus(422);
        $response->assertJsonFragment(['undo_available' => false]);
    }

    public function test_multiple_snapshots_only_undo_latest(): void
    {
        $this->injectThresholdService(10, 10);
        $cardA = $this->createEligibleReviewCard('cardA');
        $this->runReschedule();
        $snapshotFirst = RescheduleSnapshot::first()->id;

        // Second eligible card + reschedule
        $cardB = $this->createEligibleReviewCard('cardB');
        $this->injectThresholdService(10, 10);
        $this->runReschedule();
        $snapshotSecond = RescheduleSnapshot::where('id', '!=', $snapshotFirst)->first()->id;

        // Undo should target the latest snapshot
        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true]);
        $response->assertOk();

        $snapshots = RescheduleSnapshot::orderBy('id')->get();
        $this->assertNull($snapshots->where('id', $snapshotFirst)->first()->undone_at);
        $this->assertNotNull($snapshots->where('id', $snapshotSecond)->first()->undone_at);
    }

    public function test_other_user_cannot_undo(): void
    {
        $this->injectThresholdService(10, 10);
        $this->createEligibleReviewCard();
        $this->runReschedule();

        // A different admin user has no snapshot, so undo_available is false
        $response = $this->actingAs($this->otherUser)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true]);
        $this->assertTrue(in_array($response->status(), [401, 403, 422]), 'Expected 401, 403, or 422, got ' . $response->status());
        if ($response->status() === 422) {
            $response->assertJsonFragment(['undo_available' => false]);
        }
    }

    public function test_word_cards_skipped(): void
    {
        $this->injectThresholdService(10, 10);
        $eligible = $this->createEligibleReviewCard('eligible');
        $wordCard = $this->createWordCard();
        $this->runReschedule();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true]);
        $response->assertOk();
        // Only the eligible card should be restored (word card skipped)
        $this->assertEquals(1, $response->json('restored_count'));
    }

    public function test_reviewed_cards_skipped(): void
    {
        $this->injectThresholdService(10, 10);
        $card = $this->createEligibleReviewCard();
        $this->runReschedule();

        // Simulate review after reschedule by updating last_reviewed_at
        $card->refresh();
        $card->fsrs_last_reviewed_at = now()->addHour();
        $card->save();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true]);
        $response->assertStatus(422);
        $this->assertEquals(0, $response->json('restored_count'));
        $this->assertEquals(1, $response->json('skipped_count'));
        $this->assertStringContainsString('均已被复习', $response->json('message'));
    }

    public function test_all_skipped_returns_error_no_undone(): void
    {
        $this->injectThresholdService(10, 10);
        $this->createEligibleReviewCard();
        $this->runReschedule();

        // Make the card unrecoverable (delete it)
        $card = ReviewCard::first();
        $card->delete();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true]);
        $response->assertStatus(422);

        $snapshot = RescheduleSnapshot::first();
        $this->assertNull($snapshot->undone_at);
    }

    public function test_expired_snapshot_cannot_undo(): void
    {
        $this->injectThresholdService(10, 10);
        $this->createEligibleReviewCard();
        $this->runReschedule();

        // Manually expire the snapshot
        $snapshot = RescheduleSnapshot::first();
        $snapshot->expires_at = now()->subHour();
        $snapshot->save();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true]);
        $response->assertStatus(422);
        $response->assertJsonFragment(['undo_available' => false]);
        $response->assertJsonFragment(['message' => '重排操作已超过可撤销期限。']);
        // Snapshot should NOT be marked undone
        $snapshot->refresh();
        $this->assertNull($snapshot->undone_at);
    }

    public function test_expired_snapshot_returns_distinct_message(): void
    {
        // Verify no-snapshot case returns different message from expired case
        $noSnapshotResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true]);
        $noSnapshotResponse->assertStatus(422);
        $noSnapshotMsg = $noSnapshotResponse->json('message');
        $this->assertStringNotContainsString('已超过可撤销期限', $noSnapshotMsg);
        $this->assertStringContainsString('没有可撤销', $noSnapshotMsg);

        // Create reschedule then expire it
        $this->injectThresholdService(10, 10);
        $this->createEligibleReviewCard();
        $this->runReschedule();
        $snapshot = RescheduleSnapshot::first();
        $snapshot->expires_at = now()->subHour();
        $snapshot->save();

        $expiredResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true]);
        $expiredResponse->assertStatus(422);
        $this->assertStringContainsString('已超过可撤销期限', $expiredResponse->json('message'));
    }

    public function test_undo_skips_when_target_type_changed(): void
    {
        $this->injectThresholdService(10, 10);
        $card = $this->createEligibleReviewCard();
        $this->runReschedule();

        // Change target_type to word before undo
        $card->refresh();
        $card->target_type = ReviewCard::TARGET_WORD;
        $card->save();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true]);
        $response->assertStatus(422);
        $this->assertEquals(0, $response->json('restored_count'));
        $this->assertEquals(1, $response->json('skipped_count'));

        // Snapshot should NOT be marked undone
        $snapshot = RescheduleSnapshot::first();
        $this->assertNull($snapshot->undone_at);

        // Card should NOT have been restored
        $card->refresh();
        $this->assertEquals(ReviewCard::TARGET_WORD, $card->target_type);
    }

    public function test_undo_skips_when_fsrs_enabled_false(): void
    {
        $this->injectThresholdService(10, 10);
        $card = $this->createEligibleReviewCard();
        $this->runReschedule();

        // Disable the card before undo
        $card->refresh();
        $card->fsrs_enabled = false;
        $card->save();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true]);
        $response->assertStatus(422);
        $this->assertEquals(0, $response->json('restored_count'));
        $this->assertEquals(1, $response->json('skipped_count'));

        $snapshot = RescheduleSnapshot::first();
        $this->assertNull($snapshot->undone_at);
    }

    public function test_undo_skips_when_item_already_undone(): void
    {
        $this->injectThresholdService(10, 10);
        $this->createEligibleReviewCard();
        $this->runReschedule();

        // Manually mark item as undone
        $item = RescheduleSnapshotItem::first();
        $item->undone = true;
        $item->save();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true]);
        $response->assertStatus(422);
        $this->assertEquals(0, $response->json('restored_count'));
        $this->assertStringContainsString('均已被复习', $response->json('message'));

        // Snapshot should NOT be marked undone
        $snapshot = RescheduleSnapshot::first();
        $this->assertNull($snapshot->undone_at);
    }

    public function test_undo_non_english_returns_message(): void
    {
        $user = User::forceCreate([
            'name' => 'NonEnglish',
            'email' => '__VG_EMAIL_ne1__',
            'password' => Hash::make('password'),
            'selected_language' => 'japanese',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $response = $this->actingAs($user)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true]);
        $response->assertOk();
        $this->assertEquals(false, $response->json('undo_available'));
    }

    public function test_undo_preserves_new_due_at_fields(): void
    {
        $this->injectThresholdService(10, 10);
        $card = $this->createEligibleReviewCard('preserve');
        $card->refresh();
        $origDue = $card->fsrs_due_at;

        $this->runReschedule();
        $card->refresh();
        $afterRescheduleDue = $card->fsrs_due_at;

        // Undo
        $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true])->assertOk();
        $card->refresh();

        // Should be restored to original, not stay at rescheduled
        $this->assertEquals($origDue->toIso8601String(), $card->fsrs_due_at->toIso8601String());
        $this->assertNotEquals($afterRescheduleDue->toIso8601String(), $card->fsrs_due_at->toIso8601String());
    }

    public function test_other_language_cannot_undo(): void
    {
        $service = app(FsrsRescheduleSnapshotService::class);
        $card = $this->createEligibleReviewCard();

        // Create a snapshot for a different language using the same user
        $service->createSnapshotForAppliedCards(
            $this->user->id,
            'japanese',
            'fake_hash',
            ['total_cards' => 1, 'applied_count' => 1, 'skipped_count' => 0, 'newly_due_today' => 0],
            [[
                'review_card_id' => $card->id,
                'previous_due_at' => now()->subDays(2),
                'previous_stability' => 3.0,
                'previous_difficulty' => 3.0,
                'new_due_at' => now()->addDay(),
                'new_stability' => 6.0,
                'new_difficulty' => 5.0,
                'skipped' => false,
            ]]
        );

        // User's selected_language is english, so japanese snapshot is invisible
        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true]);
        $response->assertStatus(422);
        $this->assertFalse($response->json('undo_available'));
    }

    public function test_undo_restores_only_previous_fields_present(): void
    {
        $service = app(FsrsRescheduleSnapshotService::class);
        $sense = $this->createSense('partial', '部分', 'partial');
        $card = $this->createSenseCard($sense, [
            'fsrs_due_at' => now()->subDay(),
            'fsrs_stability' => 5.0,
            'fsrs_difficulty' => 4.0,
        ]);

        $service->createSnapshotForAppliedCards(
            $this->user->id,
            'english',
            'partial_hash',
            ['total_cards' => 1, 'applied_count' => 1, 'skipped_count' => 0, 'newly_due_today' => 0],
            [[
                'review_card_id' => $card->id,
                'previous_due_at' => now()->subDays(3),
                'previous_stability' => null,
                'previous_difficulty' => 3.0,
                'new_due_at' => now()->addDay(),
                'new_stability' => 6.0,
                'new_difficulty' => 5.0,
                'skipped' => false,
            ]]
        );

        // Change card so undo has something to restore
        $card->fsrs_due_at = now()->addDays(5);
        $card->fsrs_stability = 10.0;
        $card->fsrs_difficulty = 8.0;
        $card->save();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true]);
        $response->assertOk();
        $this->assertTrue($response->json('success'));
        $this->assertEquals(1, $response->json('restored_count'));

        $card->refresh();
        // due_at should be restored
        $this->assertEquals(now()->subDays(3)->startOfSecond()->toIso8601String(), $card->fsrs_due_at->startOfSecond()->toIso8601String());
        // stability was null in snapshot, so it should NOT be restored
        $this->assertEquals(10.0, $card->fsrs_stability);
        // difficulty should be restored
        $this->assertEquals(3.0, $card->fsrs_difficulty);
    }

    public function test_unauthenticated_user_cannot_undo(): void
    {
        $response = $this->postJson('/settings/fsrs/reschedule-undo', ['confirm' => true]);
        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════════
    //  Helpers
    // ═══════════════════════════════════════════════

    private function injectThresholdService(int $maxNewlyDue = 10, int $maxTotalChanged = 10): void
    {
        $service = new class($maxNewlyDue, $maxTotalChanged) extends FsrsReschedulePreviewService {
            private int $maxNewlyDue;
            private int $maxTotalChanged;
            public function __construct(int $maxNewlyDue, int $maxTotalChanged)
            {
                parent::__construct();
                $this->maxNewlyDue = $maxNewlyDue;
                $this->maxTotalChanged = $maxTotalChanged;
            }
            protected function getMaxNewlyDueToday(): int { return $this->maxNewlyDue; }
            protected function getMaxTotalChanged(): int { return $this->maxTotalChanged; }
        };
        app()->instance(FsrsReschedulePreviewService::class, $service);
    }

    private function runReschedule(): void
    {
        $preview = $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-preview');
        $hash = $preview->json('preview_hash');

        $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash, 'confirm' => true,
        ])->assertOk();

        $this->actingAs($this->user)->postJson('/settings/fsrs/reschedule-confirm', [
            'preview_hash' => $hash, 'confirm' => true, 'apply' => true,
        ])->assertOk();
    }

    private function createSense(string $lemma, string $senseZh, string $senseEn, array $overrides = [], ?User $user = null): WordSense
    {
        $user = $user ?? $this->user;
        return WordSense::forceCreate(array_merge([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => $senseZh,
            'sense_en' => $senseEn,
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Test.',
            'example_sentence_zh' => '测试。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', "english|{$lemma}|noun|{$senseZh}|{$senseEn}"),
        ], $overrides));
    }

    private function createSenseCard(WordSense $sense, array $overrides = [], ?User $user = null): ReviewCard
    {
        $user = $user ?? $this->user;
        return ReviewCard::forceCreate(array_merge([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subDay(),
            'fsrs_stability' => 5.0,
            'fsrs_difficulty' => 4.0,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => now()->subDays(3),
            'fsrs_enabled' => true,
        ], $overrides));
    }

    private function createWordCard(?User $user = null): ReviewCard
    {
        $user = $user ?? $this->user;
        $word = EncounteredWord::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'stage' => -1,
            'word' => 'apple',
            'lemma' => 'apple',
            'kanji' => '',
            'study_base' => 'apple',
            'reading' => '',
            'base_word' => 'apple',
            'base_word_reading' => '',
            'translation' => '',
            'lookup_count' => 0,
            'read_count' => 0,
            'relearning' => false,
        ]);
        return ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => $word->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subDay(),
            'fsrs_stability' => 5.0,
            'fsrs_difficulty' => 4.0,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => now()->subDays(3),
            'fsrs_enabled' => true,
        ]);
    }

    private function addReviewLog(ReviewCard $card, array $overrides = []): ReviewLog
    {
        return ReviewLog::forceCreate(array_merge([
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
        ], $overrides));
    }

    private function createEligibleReviewCard(string $lemma = 'eligible', array $cardOverrides = []): ReviewCard
    {
        $sense = $this->createSense($lemma, "{$lemma}释义", $lemma);
        $card = $this->createSenseCard($sense, $cardOverrides);
        $this->addReviewLog($card);
        return $card;
    }
}
