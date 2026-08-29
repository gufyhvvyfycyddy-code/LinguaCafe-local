<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\Goal;
use App\Models\GoalAchievement;
use App\Models\ReadingOccurrenceSenseEvidence;
use App\Models\ReadingSession;
use App\Models\ReadingSessionCardSettlement;
use App\Models\ReadingSessionCompletion;
use App\Models\ReadingSessionInteraction;
use App\Models\ReadingUnfamiliarTarget;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\GoalService;
use App\Services\ReadingOccurrenceSenseEvidenceService;
use App\Services\ReadingSessionService;
use App\Services\ReadingTargetCatalogService;
use App\Services\ReviewCardFsrsSnapshotService;
use App\Services\ReviewCardService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * True process/barrier tests for the R3 reading contracts. Integration owns
 * execution under the exclusive testing-DB lease. No sleep-based scheduling.
 */
class ReadingReviewConcurrencyContractTest extends TestCase
{
    private ?User $user = null;
    private Chapter $chapter;
    private WordSense $sense;
    private ReviewCard $card;
    private string $occurrenceId;
    private ReadingSession $session;
    /** @var array<int, int> */
    private array $extraUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'PAB R3 Concurrency',
            'email' => 'pab-r3-concurrency-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        app(GoalService::class)->createGoalsForLanguage($this->user->id, 'english');
        $book = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'PAB R3 Concurrent Book',
            'language' => 'english',
        ]);
        $processed = [[
            'word_index' => 0,
            'word' => 'bank',
            'lemma' => 'bank',
            'pos' => 'NOUN',
            'sentence_index' => 0,
            'spaceAfter' => false,
        ]];
        $this->chapter = Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'name' => 'PAB R3 Concurrent Chapter',
            'language' => 'english',
            'raw_text' => 'bank',
            'word_count' => 1,
            'read_count' => 0,
            'unique_words' => '["bank"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode($processed), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
        $this->sense = $this->makeSense('bank', '银行');
        $this->card = app(ReviewCardService::class)->ensureSenseCard($this->sense);
        $anchor = now()->subDays(2)->startOfSecond();
        $this->card->forceFill([
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_step_index' => null,
            'fsrs_due_at' => now()->addDays(30),
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 4,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => $anchor,
        ])->save();
        ReviewLog::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'review_card_id' => $this->card->id,
            'rating' => 'good',
            'reviewed_at' => $anchor,
            'previous_state' => 'review',
            'new_state' => 'review',
            'source' => ReviewLog::SOURCE_SENSE_REVIEW,
        ]);

        $catalog = app(ReadingTargetCatalogService::class)->build($this->user->id, 'english', $this->chapter->id);
        $this->assertCount(1, $catalog['targets']);
        $this->occurrenceId = $catalog['targets'][0]['occurrence_id'];
        $session = app(ReadingSessionService::class)->startSession($this->user->id, 'english', $this->chapter->id);
        $this->session = ReadingSession::where('uuid', $session['reading_session_id'])->firstOrFail();
    }

    protected function tearDown(): void
    {
        if ($this->user !== null) {
            $this->deleteTestUserData($this->user->id);
        }
        foreach ($this->extraUserIds as $userId) {
            $this->deleteTestUserData($userId);
        }
        parent::tearDown();
    }

    #[DataProvider('ratings')]
    public function test_canonical_reading_rating_endpoint_records_each_rating_with_exact_source_and_snapshot(
        string $rating,
        int $actionSequence,
    ): void {
        $before = app(ReviewCardFsrsSnapshotService::class)->capture($this->card->fresh());
        $actionId = $this->readingActionId($actionSequence);
        $response = $this->actingAs($this->user)->postJson(
            '/reviews/senses/'.$this->card->id.'/rate',
            $this->explicitRequestPayload($rating, $actionId),
        );

        $response->assertOk()
            ->assertJsonPath('action.rating', $rating)
            ->assertJsonPath('action.reading_action_id', $actionId);
        $log = ReviewLog::where('review_card_id', $this->card->id)->where('source', ReviewLog::SOURCE_READING_EXPLICIT)->sole();
        $this->assertSame($rating, $log->rating);
        $this->assertSame($this->session->uuid, $log->review_session_id);
        $this->assertEquals($before, $log->before_card_snapshot);
        $this->assertSame($log->id, $response->json('action.review_log_id'));
    }

    public static function ratings(): array
    {
        return [
            ['again', 1],
            ['good', 2],
        ];
    }

    public function test_new_reader_actions_reject_hard_and_easy_without_formal_write(): void
    {
        foreach (['hard', 'easy'] as $rating) {
            $this->actingAs($this->user)->postJson(
                '/reviews/senses/'.$this->card->id.'/rate',
                $this->explicitRequestPayload($rating, $this->readingActionId($rating === 'hard' ? 3 : 4)),
            )->assertStatus(422)
                ->assertJsonPath('error_code', ReadingSessionService::ERROR_EXPLICIT_CONTEXT_INVALID);
        }

        $this->assertSame(0, $this->explicitLogs()->count());
    }

    public function test_manual_new_sense_binding_remains_first_learning_and_cannot_write_reader_rating(): void
    {
        $existingCardBefore = app(ReviewCardFsrsSnapshotService::class)->capture($this->card->fresh());

        $manual = $this->actingAs($this->user)->postJson('/senses/manual', [
            'lemma' => 'bank',
            'surface_form' => 'bank',
            'pos' => 'noun',
            'sense_zh' => '河岸',
            'sense_en' => 'the land beside a river',
            'chapter_id' => $this->chapter->id,
            'sentence_id' => '0',
            'reading_session_id' => $this->session->uuid,
            'source_revision' => $this->session->source_revision,
            'occurrence_id' => $this->occurrenceId,
        ])->assertOk();

        $manualSenseId = (int) $manual->json('sense_id');
        $manualCardId = (int) $manual->json('review_card_id');
        $this->assertGreaterThan(0, $manualSenseId);
        $this->assertGreaterThan(0, $manualCardId);
        $manualCard = ReviewCard::findOrFail($manualCardId);
        $manualCardBefore = app(ReviewCardFsrsSnapshotService::class)->capture($manualCard);
        $this->assertSame(0, $this->explicitLogs()->count());

        $this->postJson('/chapters/'.$this->chapter->id.'/reading-occurrence-evidence', [
            'occurrence_id' => $this->occurrenceId,
            'resolution' => ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
            'word_sense_id' => $manualSenseId,
        ])->assertOk();
        $this->postJson('/chapters/'.$this->chapter->id.'/reading-sessions', [
            'resume_reading_session_id' => $this->session->uuid,
        ])->assertOk();

        foreach (['good', 'again'] as $offset => $rating) {
            $actionId = $this->readingActionId(5 + $offset);
            $this->postJson('/reviews/senses/'.$manualCardId.'/rate', [
                'rating' => $rating,
                'reading_session_id' => $this->session->uuid,
                'occurrence_id' => $this->occurrenceId,
                'reading_action_id' => $actionId,
                'ignoreDailyLimits' => true,
            ])->assertStatus(422)
                ->assertJsonPath('error_code', ReadingSessionService::ERROR_EXPLICIT_CONTEXT_INVALID);
            $this->assertSame(0, $this->explicitActionCount($actionId));
        }

        $this->assertSame(0, $this->explicitLogs()->count());
        $this->assertSame($existingCardBefore, app(ReviewCardFsrsSnapshotService::class)->capture($this->card->fresh()));
        $this->assertSame($manualCardBefore, app(ReviewCardFsrsSnapshotService::class)->capture($manualCard->fresh()));
    }

    public function test_reader_manual_sense_rejects_invalid_scope_before_any_business_write(): void
    {
        $foreignUser = User::forceCreate([
            'name' => 'PAB R3 Manual Foreign',
            'email' => 'pab-r3-manual-foreign-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $this->extraUserIds[] = $foreignUser->id;
        $otherChapter = $this->chapter->replicate();
        $otherChapter->name = 'PAB R3 Manual Other Chapter';
        $otherChapter->save();
        $inactiveSession = ReadingSession::forceCreate([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'chapter_id' => $this->chapter->id,
            'source_revision' => $this->session->source_revision,
            'status' => ReadingSession::STATUS_COMPLETED,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
        ReadingUnfamiliarTarget::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'chapter_id' => $this->chapter->id,
            'source_revision' => $this->session->source_revision,
            'occurrence_id' => 'occ2_phrase_manual_guard',
            'kind' => ReadingUnfamiliarTarget::KIND_PHRASE,
            'start_word_index' => 0,
            'end_word_index' => 0,
            'sentence_index' => 0,
            'surface' => 'bank',
            'lemma' => 'bank',
            'pos' => 'phrase',
            'source_sentence' => 'bank',
        ]);

        $counts = fn (): array => [
            'senses' => WordSense::count(),
            'cards' => ReviewCard::count(),
            'logs' => ReviewLog::count(),
            'interactions' => ReadingSessionInteraction::count(),
            'completions' => ReadingSessionCompletion::count(),
            'settlements' => ReadingSessionCardSettlement::count(),
            'evidence' => ReadingOccurrenceSenseEvidence::count(),
            'sessions' => ReadingSession::count(),
        ];
        $beforeCounts = $counts();
        $beforeCard = app(ReviewCardFsrsSnapshotService::class)->capture($this->card->fresh());
        $payload = $this->readerManualSensePayload();

        $this->actingAs($foreignUser)
            ->postJson('/senses/manual', $payload)
            ->assertNotFound()
            ->assertJsonPath('error_code', ReadingSessionService::ERROR_SESSION_NOT_FOUND);
        $this->flushSession();

        $this->actingAs($this->user->fresh())->postJson('/senses/manual', array_merge($payload, [
            'chapter_id' => $otherChapter->id,
        ]))->assertStatus(409)
            ->assertJsonPath('error_code', ReadingSessionService::ERROR_SESSION_CHAPTER_MISMATCH);

        $this->actingAs($this->user->fresh())->postJson('/senses/manual', array_merge($payload, [
            'reading_session_id' => $inactiveSession->uuid,
        ]))->assertStatus(409)
            ->assertJsonPath('error_code', ReadingSessionService::ERROR_SESSION_NOT_ACTIVE);

        $this->actingAs($this->user->fresh())->postJson('/senses/manual', array_merge($payload, [
            'source_revision' => 'sha256:stale-client-revision',
        ]))->assertStatus(409)
            ->assertJsonPath('error_code', ReadingSessionService::ERROR_SESSION_STALE_SOURCE);

        $this->actingAs($this->user->fresh())->postJson('/senses/manual', array_merge($payload, [
            'occurrence_id' => 'occ2_phrase_manual_guard',
        ]))->assertStatus(409)
            ->assertJsonPath('error_code', ReadingSessionService::ERROR_OCCURRENCE_STALE);

        $this->actingAs($this->user->fresh())->postJson('/senses/manual', array_merge($payload, [
            'occurrence_id' => 'occ2_unknown_manual_guard',
        ]))->assertStatus(409)
            ->assertJsonPath('error_code', ReadingSessionService::ERROR_OCCURRENCE_STALE);

        $this->actingAs($this->user->fresh())->postJson('/senses/manual', array_merge($payload, [
            'lemma' => 'shore',
        ]))->assertStatus(422)
            ->assertJsonPath('error_code', ReadingSessionService::ERROR_EXPLICIT_CONTEXT_INVALID);

        $this->user->forceFill(['selected_language' => 'german'])->save();
        $this->actingAs($this->user->fresh())
            ->postJson('/senses/manual', $payload)
            ->assertNotFound()
            ->assertJsonPath('error_code', ReadingSessionService::ERROR_SESSION_NOT_FOUND);
        $this->flushSession();
        $this->user->forceFill(['selected_language' => 'english'])->save();
        $this->actingAs($this->user->fresh());

        $originalRawText = $this->chapter->raw_text;
        $this->chapter->forceFill(['raw_text' => 'bank changed'])->save();
        $this->actingAs($this->user->fresh())->postJson('/senses/manual', $payload)
            ->assertStatus(409)
            ->assertJsonPath('error_code', ReadingSessionService::ERROR_SESSION_STALE_SOURCE);
        $this->chapter->forceFill(['raw_text' => $originalRawText])->save();

        $this->assertSame($beforeCounts, $counts());
        $this->assertSame($beforeCard, app(ReviewCardFsrsSnapshotService::class)->capture($this->card->fresh()));
    }

    public function test_sequential_duplicate_explicit_rating_returns_same_action_and_one_formal_log(): void
    {
        $actionId = $this->readingActionId(10);
        $payload = $this->explicitRequestPayload('good', $actionId);
        $first = $this->actingAs($this->user)->postJson('/reviews/senses/'.$this->card->id.'/rate', $payload)->assertOk();
        $second = $this->actingAs($this->user)->postJson('/reviews/senses/'.$this->card->id.'/rate', $payload)->assertOk();

        $this->assertSame(1, ReviewLog::where('review_card_id', $this->card->id)->where('source', ReviewLog::SOURCE_READING_EXPLICIT)->count());
        $this->assertSame(1, $this->explicitActionCount($actionId));
        $this->assertJsonStringEqualsJsonString(
            json_encode($first->json(), JSON_THROW_ON_ERROR),
            json_encode($second->json(), JSON_THROW_ON_ERROR),
            'Same reading_action_id must replay the same stored JSON response payload.',
        );
        $this->assertSame($actionId, $second->json('action.reading_action_id'));
    }

    public function test_same_action_uuid_replays_original_payload_before_retry_inputs_are_revalidated(): void
    {
        $actionId = $this->readingActionId(19);
        $first = $this->actingAs($this->user)
            ->postJson('/reviews/senses/'.$this->card->id.'/rate', $this->explicitRequestPayload('good', $actionId))
            ->assertOk();

        $retry = $this->actingAs($this->user)->postJson('/reviews/senses/999999/rate', [
            'rating' => 'hard',
            'reading_session_id' => $this->session->uuid,
            'occurrence_id' => 'occ2_retry_payload_drift',
            'reading_action_id' => $actionId,
            'ignoreDailyLimits' => true,
        ]);

        $retry->assertOk();
        $this->assertJsonStringEqualsJsonString(
            json_encode($first->json(), JSON_THROW_ON_ERROR),
            json_encode($retry->json(), JSON_THROW_ON_ERROR),
            'Exact action UUID replay must win before later card/rating/occurrence drift is revalidated.',
        );
        $this->assertSame(1, $this->explicitLogs()->count());
        $this->assertSame(1, $this->explicitActionCount($actionId));
    }

    public function test_again_then_later_same_session_good_keeps_positive_zero(): void
    {
        $againActionId = $this->readingActionId(94);
        $goodActionId = $this->readingActionId(95);
        $this->actingAs($this->user)
            ->postJson('/reviews/senses/'.$this->card->id.'/rate', $this->explicitRequestPayload('again', $againActionId))
            ->assertOk()
            ->assertJsonPath('action.rating', 'again');

        $this->actingAs($this->user)
            ->postJson('/reviews/senses/'.$this->card->id.'/rate', $this->explicitRequestPayload('good', $goodActionId))
            ->assertStatus(409)
            ->assertJsonPath('error_code', ReadingSessionService::ERROR_EXPLICIT_ACTION_ACTIVE);

        $this->assertSame(1, ReviewLog::where('review_card_id', $this->card->id)->where('source', ReviewLog::SOURCE_READING_EXPLICIT)->where('rating', 'again')->count());
        $this->assertSame(0, ReviewLog::where('review_card_id', $this->card->id)->where('source', ReviewLog::SOURCE_READING_EXPLICIT)->where('rating', 'good')->count());
    }

    public function test_different_action_uuid_is_rejected_while_previous_explicit_rating_is_active(): void
    {
        $firstActionId = $this->readingActionId(11);
        $secondActionId = $this->readingActionId(12);
        $this->actingAs($this->user)
            ->postJson('/reviews/senses/'.$this->card->id.'/rate', $this->explicitRequestPayload('good', $firstActionId))
            ->assertOk();
        $afterFirst = app(ReviewCardFsrsSnapshotService::class)->capture($this->card->fresh());

        $second = $this->actingAs($this->user)
            ->postJson('/reviews/senses/'.$this->card->id.'/rate', $this->explicitRequestPayload('hard', $secondActionId));

        $second->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', ReadingSessionService::ERROR_EXPLICIT_ACTION_ACTIVE);
        $this->assertSame(1, $this->explicitLogs()->count());
        $this->assertSame(1, $this->activeExplicitLogs()->count());
        $this->assertSame(1, $this->explicitActionCount($firstActionId));
        $this->assertSame(0, $this->explicitActionCount($secondActionId));
        $this->assertTrue(app(ReviewCardFsrsSnapshotService::class)->matches($this->card->fresh(), $afterFirst));
    }

    public function test_undo_then_replay_old_action_uuid_is_stable_conflict_without_new_formal_write(): void
    {
        $snapshotService = app(ReviewCardFsrsSnapshotService::class);
        $before = $snapshotService->capture($this->card->fresh());
        $oldActionId = $this->readingActionId(13);
        $rate = $this->actingAs($this->user)
            ->postJson('/reviews/senses/'.$this->card->id.'/rate', $this->explicitRequestPayload('good', $oldActionId))
            ->assertOk();
        $reviewLogId = (int) $rate->json('action.review_log_id');
        $this->undoExplicit($reviewLogId, $this->readingActionId(14))->assertOk();
        $this->assertTrue($snapshotService->matches($this->card->fresh(), $before));

        $replay = $this->actingAs($this->user)
            ->postJson('/reviews/senses/'.$this->card->id.'/rate', $this->explicitRequestPayload('good', $oldActionId));

        $replay->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', ReadingSessionService::ERROR_EXPLICIT_ACTION_UNDONE);
        $this->assertSame(1, $this->explicitLogs()->count());
        $this->assertSame(0, $this->activeExplicitLogs()->count());
        $this->assertSame(1, $this->explicitActionCount($oldActionId));
        $this->assertNotNull(ReviewLog::findOrFail($reviewLogId)->undone_at);
        $this->assertTrue($snapshotService->matches($this->card->fresh(), $before));
    }

    public function test_undo_then_new_action_uuid_creates_one_new_active_explicit_rating(): void
    {
        $oldActionId = $this->readingActionId(15);
        $newActionId = $this->readingActionId(16);
        $first = $this->actingAs($this->user)
            ->postJson('/reviews/senses/'.$this->card->id.'/rate', $this->explicitRequestPayload('good', $oldActionId))
            ->assertOk();
        $this->undoExplicit((int) $first->json('action.review_log_id'), $this->readingActionId(17))->assertOk();

        $second = $this->actingAs($this->user)
            ->postJson('/reviews/senses/'.$this->card->id.'/rate', $this->explicitRequestPayload('good', $newActionId));

        $second->assertOk()->assertJsonPath('action.reading_action_id', $newActionId);
        $this->assertSame(2, $this->explicitLogs()->count());
        $this->assertSame(1, $this->activeExplicitLogs()->count());
        $this->assertSame(1, $this->explicitActionCount($oldActionId));
        $this->assertSame(1, $this->explicitActionCount($newActionId));
        $this->assertSame(2, $this->explicitActionCount());
        $this->assertSame((int) $second->json('action.review_log_id'), (int) $this->activeExplicitLogs()->sole()->id);
    }

    public function test_reading_explicit_rating_requires_reading_action_id(): void
    {
        $before = app(ReviewCardFsrsSnapshotService::class)->capture($this->card->fresh());
        $response = $this->actingAs($this->user)->postJson('/reviews/senses/'.$this->card->id.'/rate', [
            'rating' => 'good',
            'reading_session_id' => $this->session->uuid,
            'occurrence_id' => $this->occurrenceId,
            'ignoreDailyLimits' => true,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, $this->explicitLogs()->count());
        $this->assertTrue(app(ReviewCardFsrsSnapshotService::class)->matches($this->card->fresh(), $before));
    }

    public function test_non_reading_sense_review_does_not_require_reading_action_id(): void
    {
        $reviewSessionId = $this->readingActionId(18);
        $response = $this->actingAs($this->user)->postJson('/reviews/senses/'.$this->card->id.'/rate', [
            'rating' => 'good',
            'review_session_id' => $reviewSessionId,
            'ignoreDailyLimits' => true,
        ]);

        $response->assertOk();
        $log = ReviewLog::where('review_card_id', $this->card->id)
            ->where('source', ReviewLog::SOURCE_SENSE_REVIEW)
            ->where('review_session_id', $reviewSessionId)
            ->sole();
        $this->assertSame(ReviewLog::SOURCE_SENSE_REVIEW, $log->source);
        $this->assertSame($reviewSessionId, $log->review_session_id);
        $this->assertSame(
            0,
            ReadingSessionInteraction::where('reading_session_id', $this->session->id)
                ->where('interaction_type', ReadingSessionInteraction::TYPE_EXPLICIT_RATED)
                ->count(),
        );
    }

    public function test_explicit_rating_rejects_unrelated_owned_card_outside_current_occurrence_candidates(): void
    {
        $unrelatedSense = $this->makeSense('shore', '岸');
        $unrelatedCard = app(ReviewCardService::class)->ensureSenseCard($unrelatedSense);
        $this->activateCard($unrelatedCard);

        $this->assertExplicitPairingRejected(
            $unrelatedCard,
            $this->occurrenceId,
            422,
            ReadingSessionService::ERROR_EXPLICIT_CONTEXT_INVALID,
        );
    }

    public function test_explicit_rating_rejects_stale_non_current_occurrence_identity(): void
    {
        $this->assertExplicitPairingRejected(
            $this->card,
            'occ2_stale_non_current',
            409,
            ReadingSessionService::ERROR_OCCURRENCE_STALE,
        );
    }

    public function test_explicit_rating_rejects_cross_user_card_pairing(): void
    {
        $foreignUser = User::forceCreate([
            'name' => 'PAB R3 Foreign Rating Owner',
            'email' => 'pab-r3-foreign-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $this->extraUserIds[] = $foreignUser->id;
        $foreignSense = $this->makeSense('bank', '外部用户词义', $foreignUser);
        $foreignCard = app(ReviewCardService::class)->ensureSenseCard($foreignSense);
        $this->activateCard($foreignCard);

        $this->assertExplicitPairingRejected(
            $foreignCard,
            $this->occurrenceId,
            422,
            ReadingSessionService::ERROR_EXPLICIT_CONTEXT_INVALID,
        );
    }

    public function test_explicit_rating_rejects_cross_language_card_pairing(): void
    {
        $foreignLanguageSense = $this->makeSense('bank', '德语词义', $this->user, 'german');
        $foreignLanguageCard = app(ReviewCardService::class)->ensureSenseCard($foreignLanguageSense);
        $this->activateCard($foreignLanguageCard);

        $this->assertExplicitPairingRejected(
            $foreignLanguageCard,
            $this->occurrenceId,
            422,
            ReadingSessionService::ERROR_EXPLICIT_CONTEXT_INVALID,
        );
    }

    public function test_explicit_outer_transaction_rolls_back_action_identity_log_and_replay_payload_after_ledger_write(): void
    {
        $snapshotService = app(ReviewCardFsrsSnapshotService::class);
        $beforeSnapshot = $snapshotService->capture($this->card->fresh());
        $beforeLogs = ReviewLog::where('review_card_id', $this->card->id)->count();
        $beforeLedger = ReadingSessionInteraction::where('reading_session_id', $this->session->id)->count();
        $actionId = $this->readingActionId(30);
        $ledgerWriteReached = false;

        $realSessionService = app(ReadingSessionService::class);
        $sessionService = Mockery::mock(ReadingSessionService::class);
        $sessionService->shouldReceive('lockOwnedSessionForExplicitAction')->once()->andReturnUsing(
            fn (...$arguments) => $realSessionService->lockOwnedSessionForExplicitAction(...$arguments),
        );
        $sessionService->shouldReceive('explicitRatingReplay')->once()->andReturnUsing(
            fn (...$arguments) => $realSessionService->explicitRatingReplay(...$arguments),
        );
        $sessionService->shouldReceive('lockExplicitRatingContext')->once()->andReturnUsing(
            fn (...$arguments) => $realSessionService->lockExplicitRatingContext(...$arguments),
        );
        $sessionService->shouldReceive('assertExplicitRatingEvidenceAllowed')->once()->andReturnUsing(
            fn (...$arguments) => $realSessionService->assertExplicitRatingEvidenceAllowed(...$arguments),
        );
        $sessionService->shouldReceive('assertNoActiveExplicitRating')->once()->andReturnUsing(
            fn (...$arguments) => $realSessionService->assertNoActiveExplicitRating(...$arguments),
        );
        $sessionService->shouldReceive('recordReadingSettlementLocked')->once()->andReturnUsing(
            fn (...$arguments) => $realSessionService->recordReadingSettlementLocked(...$arguments),
        );
        $sessionService->shouldReceive('recordExplicitRatingLocked')->once()->andReturnUsing(
            function (...$arguments) use ($realSessionService, $actionId, &$ledgerWriteReached) {
                $beforeWrite = ReadingSessionInteraction::where('reading_session_id', $this->session->id)->count();
                $realSessionService->recordExplicitRatingLocked(...$arguments);
                $ledger = ReadingSessionInteraction::query()
                    ->where('reading_session_id', $this->session->id)
                    ->where('reading_action_id', $actionId)
                    ->first();
                $metadata = is_array($ledger?->metadata) ? $ledger->metadata : [];
                $ledgerWriteReached = ReadingSessionInteraction::where('reading_session_id', $this->session->id)->count() > $beforeWrite
                    && $ledger !== null
                    && $ledger->review_log_id !== null
                    && ($metadata['response_payload']['action']['reading_action_id'] ?? null) === $actionId
                    && (int) ($metadata['response_payload']['action']['review_log_id'] ?? 0) === (int) $ledger->review_log_id;
                if (!$ledgerWriteReached) {
                    throw new RuntimeException('PAB_R3_EXPLICIT_LEDGER_INJECTION_FAILED');
                }

                throw new RuntimeException('PAB_R3_EXPLICIT_LEDGER_ROLLBACK');
            },
        );
        $this->app->instance(ReadingSessionService::class, $sessionService);

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->user)->postJson(
                '/reviews/senses/'.$this->card->id.'/rate',
                $this->explicitRequestPayload('good', $actionId),
            );
            $this->fail('Injected post-ledger failure must escape the request and roll back the outer transaction.');
        } catch (RuntimeException $e) {
            $this->assertSame('PAB_R3_EXPLICIT_LEDGER_ROLLBACK', $e->getMessage());
        } finally {
            $this->app->instance(ReadingSessionService::class, $realSessionService);
            $this->withExceptionHandling();
        }

        $this->assertTrue($ledgerWriteReached, 'Failure injection must happen only after action identity and replay payload were visible inside the transaction.');
        $this->assertTrue($snapshotService->matches($this->card->fresh(), $beforeSnapshot));
        $this->assertSame($beforeLogs, ReviewLog::where('review_card_id', $this->card->id)->count());
        $this->assertSame($beforeLedger, ReadingSessionInteraction::where('reading_session_id', $this->session->id)->count());
    }

    public function test_explicit_worker_preserves_422_status_and_body_for_invalid_pairing(): void
    {
        $unrelatedSense = $this->makeSense('shore-worker', '岸');
        $unrelatedCard = $this->activateCard(app(ReviewCardService::class)->ensureSenseCard($unrelatedSense));
        $payload = $this->explicitWorkerPayload('good', $this->readingActionId(35));
        $payload['review_card_id'] = $unrelatedCard->id;

        $results = $this->runConcurrent([
            ['explicit-rate', $payload],
        ]);
        $result = $results[0];

        $this->assertSame(0, $result['exitCode'], $this->workerDiagnostics($results));
        $this->assertSame(422, $result['json']['http_status'] ?? null, $this->workerDiagnostics($results));
        $this->assertSame(
            ReadingSessionService::ERROR_EXPLICIT_CONTEXT_INVALID,
            $result['json']['body']['error_code'] ?? null,
            $this->workerDiagnostics($results),
        );
        $this->assertWorkerOutcome($result, [ReadingSessionService::ERROR_EXPLICIT_CONTEXT_INVALID]);
        $this->assertSame(0, ReviewLog::where('review_card_id', $unrelatedCard->id)->count());
    }

    public function test_cross_article_session_good_inside_24_hours_is_silent_non_scoring(): void
    {
        [$secondChapter, $secondSession, $secondOccurrenceId] = $this->createSecondReadingSessionForSense('Cross Session Silent');
        $firstAt = Carbon::parse('2026-08-18T08:00:00Z');
        $this->setMainCardAnchor($firstAt->copy()->subDays(2));
        Carbon::setTestNow($firstAt);

        try {
            $first = $this->actingAs($this->user)
                ->postJson('/reviews/senses/'.$this->card->id.'/rate', $this->explicitRequestPayload('good', $this->readingActionId(90)))
                ->assertOk();
            $this->assertIsInt($first->json('action.review_log_id'));

            $afterFirst = app(ReviewCardFsrsSnapshotService::class)->capture($this->card->fresh());
            Carbon::setTestNow($firstAt->copy()->addSeconds(30));
            $secondActionId = $this->readingActionId(91);
            $second = $this->actingAs($this->user)->postJson('/reviews/senses/'.$this->card->id.'/rate', [
                'rating' => 'good',
                'reading_session_id' => $secondSession->uuid,
                'occurrence_id' => $secondOccurrenceId,
                'reading_action_id' => $secondActionId,
                'ignoreDailyLimits' => true,
            ])->assertOk()
                ->assertJsonPath('action.review_log_id', null)
                ->assertJsonPath('action.scored', false);

            $this->assertSame(1, $this->activeExplicitLogs()->count());
            $this->assertTrue(app(ReviewCardFsrsSnapshotService::class)->matches($this->card->fresh(), $afterFirst));
            $this->assertSame(0, ReadingSessionCardSettlement::where('reading_session_id', $secondSession->id)->where('review_card_id', $this->card->id)->count());
            $this->assertSame(1, ReadingSessionInteraction::where('reading_session_id', $secondSession->id)
                ->where('interaction_type', ReadingSessionInteraction::TYPE_EXPLICIT_NONSCORED)
                ->where('reading_action_id', $secondActionId)
                ->count());

            $retry = $this->actingAs($this->user)->postJson('/reviews/senses/'.$this->card->id.'/rate', [
                'rating' => 'hard',
                'reading_session_id' => $secondSession->uuid,
                'occurrence_id' => 'retry-input-drift',
                'reading_action_id' => $secondActionId,
                'ignoreDailyLimits' => true,
            ])->assertOk();
            $this->assertJsonStringEqualsJsonString(
                json_encode($second->json(), JSON_THROW_ON_ERROR),
                json_encode($retry->json(), JSON_THROW_ON_ERROR),
                'Silent non-scoring action replay must win before retry input drift is revalidated.',
            );
            $this->assertSame(1, $this->activeExplicitLogs()->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_two_reading_sessions_concurrent_good_create_at_most_one_positive(): void
    {
        [, $secondSession, $secondOccurrenceId] = $this->createSecondReadingSessionForSense('Concurrent Cross Session');
        $this->setMainCardAnchor(now()->subDays(2)->startOfSecond());
        $results = $this->runConcurrent([
            ['explicit-rate', $this->explicitWorkerPayload('good', $this->readingActionId(92))],
            ['explicit-rate', $this->explicitWorkerPayloadFor($secondSession, $secondOccurrenceId, 'good', $this->readingActionId(93))],
        ]);

        $this->assertAllWorkersSucceeded($results);
        $positive = ReviewLog::query()
            ->where('review_card_id', $this->card->id)
            ->where('source', ReviewLog::SOURCE_READING_EXPLICIT)
            ->where('rating', 'good')
            ->whereNull('undone_at')
            ->get();
        $this->assertCount(1, $positive, $this->workerDiagnostics($results));
        $reviewLogIds = array_map(fn (array $result) => $result['json']['body']['action']['review_log_id'] ?? null, $results);
        $this->assertSame(1, count(array_filter($reviewLogIds, fn ($value) => is_int($value))), $this->workerDiagnostics($results));
        $this->assertSame(1, count(array_filter($results, fn (array $result) => ($result['json']['body']['action']['scored'] ?? null) === false)), $this->workerDiagnostics($results));
    }

    public function test_true_concurrent_duplicate_explicit_rating_creates_one_formal_log(): void
    {
        $actionId = $this->readingActionId(31);
        $payload = $this->explicitWorkerPayload('good', $actionId);
        $results = $this->runConcurrent([
            ['explicit-rate', $payload],
            ['explicit-rate', $payload],
        ]);

        $this->assertAllWorkersSucceeded($results);
        $logs = ReviewLog::where('review_card_id', $this->card->id)->where('source', ReviewLog::SOURCE_READING_EXPLICIT)->get();
        $this->assertCount(1, $logs);
        $this->assertSame(1, $this->explicitActionCount($actionId));
        $this->assertSame($logs[0]->id, $results[0]['json']['body']['action']['review_log_id']);
        $this->assertSame($logs[0]->id, $results[1]['json']['body']['action']['review_log_id']);
        $this->assertSame($actionId, $results[0]['json']['body']['action']['reading_action_id']);
        $this->assertSame($actionId, $results[1]['json']['body']['action']['reading_action_id']);
    }

    public function test_true_concurrent_old_action_retry_vs_undo_cannot_resurrect_undone_rating(): void
    {
        $snapshotService = app(ReviewCardFsrsSnapshotService::class);
        $before = $snapshotService->capture($this->card->fresh());
        $actionId = $this->readingActionId(32);
        $first = $this->actingAs($this->user)
            ->postJson('/reviews/senses/'.$this->card->id.'/rate', $this->explicitRequestPayload('good', $actionId))
            ->assertOk();
        $reviewLogId = (int) $first->json('action.review_log_id');

        $results = $this->runConcurrent([
            ['explicit-rate', $this->explicitWorkerPayload('good', $actionId)],
            ['explicit-undo', $this->explicitUndoWorkerPayload($reviewLogId, $this->readingActionId(33))],
        ]);

        $this->assertWorkerOutcome($results[0], [ReadingSessionService::ERROR_EXPLICIT_ACTION_UNDONE]);
        $this->assertWorkerOutcome($results[1]);
        $this->assertSame(1, $this->explicitLogs()->count(), $this->workerDiagnostics($results));
        $this->assertSame(0, $this->activeExplicitLogs()->count(), $this->workerDiagnostics($results));
        $this->assertSame(1, $this->explicitActionCount($actionId), $this->workerDiagnostics($results));
        $this->assertNotNull(ReviewLog::findOrFail($reviewLogId)->undone_at, $this->workerDiagnostics($results));
        $this->assertTrue($snapshotService->matches($this->card->fresh(), $before), $this->workerDiagnostics($results));
    }

    public function test_true_concurrent_start_creates_one_current_active_session(): void
    {
        ReadingSession::query()
            ->where('user_id', $this->user->id)
            ->where('chapter_id', $this->chapter->id)
            ->delete();
        $payload = [
            'user_id' => $this->user->id,
            'language' => 'english',
            'chapter_id' => $this->chapter->id,
        ];
        $results = $this->runConcurrent([
            ['start-session', $payload],
            ['start-session', $payload],
        ]);

        $this->assertAllWorkersSucceeded($results);
        $this->assertSame(1, ReadingSession::where('user_id', $this->user->id)->where('chapter_id', $this->chapter->id)->where('status', ReadingSession::STATUS_ACTIVE)->count());
        $this->assertSame($results[0]['json']['reading_session_id'], $results[1]['json']['reading_session_id']);
    }

    public function test_true_concurrent_finish_commits_one_completion_one_legacy_effect_and_one_passive_good(): void
    {
        $this->bindCurrentOccurrenceTo($this->sense);
        $results = $this->runConcurrent([
            ['finish-commit', $this->finishWorkerPayload()],
            ['finish-commit', $this->finishWorkerPayload()],
        ]);

        $this->assertAllWorkersSucceeded($results);
        $this->assertSame(1, ReadingSessionCompletion::where('reading_session_id', $this->session->id)->count());
        $this->assertSame(1, $this->chapter->fresh()->read_count);
        $this->assertSame(1, ReviewLog::where('review_card_id', $this->card->id)->where('source', ReviewLog::SOURCE_READING_PASSIVE)->count());
    }

    public function test_raw_api_explicit_vs_finish_race_without_preacknowledged_intent_is_first_lock_wins_without_double_formal_rating(): void
    {
        $this->bindCurrentOccurrenceTo($this->sense);
        $this->assertSame(
            0,
            ReadingSessionInteraction::where('reading_session_id', $this->session->id)->count(),
            'Raw API race scope requires no committed opened/helped/explicit intent before the barrier.',
        );

        $results = $this->runConcurrent([
            ['explicit-rate', $this->explicitWorkerPayload('good', $this->readingActionId(34))],
            ['finish-commit', $this->finishWorkerPayload()],
        ]);

        $this->assertWorkerOutcome($results[0], [
            ReadingSessionService::ERROR_SESSION_NOT_ACTIVE,
            ReadingSessionService::ERROR_EXPLICIT_ACTION_ACTIVE,
        ]);
        $this->assertWorkerOutcome($results[1]);
        $logs = ReviewLog::where('review_card_id', $this->card->id)
            ->whereIn('source', [ReviewLog::SOURCE_READING_EXPLICIT, ReviewLog::SOURCE_READING_PASSIVE])
            ->get();
        $explicitCount = $logs->where('source', ReviewLog::SOURCE_READING_EXPLICIT)->count();
        $passiveCount = $logs->where('source', ReviewLog::SOURCE_READING_PASSIVE)->count();
        $this->assertCount(1, $logs, 'Raw API first-lock-wins must still produce exactly one formal outcome. '.$this->workerDiagnostics($results));
        $this->assertFalse(
            $explicitCount > 0 && $passiveCount > 0,
            'Raw API first-lock-wins must never create both reading_explicit and reading_passive. '.$this->workerDiagnostics($results),
        );

        $explicitStatus = $results[0]['json']['http_status'];
        if ($explicitStatus >= 200 && $explicitStatus < 300) {
            $this->assertSame(1, $explicitCount, $this->workerDiagnostics($results));
            $this->assertSame(0, $passiveCount, $this->workerDiagnostics($results));
            $this->assertSame(ReviewLog::SOURCE_READING_EXPLICIT, $logs[0]->source, $this->workerDiagnostics($results));
            $this->assertSame($logs[0]->id, $results[0]['json']['body']['action']['review_log_id']);
        } else {
            $this->assertSame(409, $explicitStatus, $this->workerDiagnostics($results));
            $this->assertContains(
                $results[0]['json']['body']['error_code'] ?? null,
                [ReadingSessionService::ERROR_SESSION_NOT_ACTIVE, ReadingSessionService::ERROR_EXPLICIT_ACTION_ACTIVE],
                $this->workerDiagnostics($results),
            );
            $this->assertSame(0, $explicitCount, $this->workerDiagnostics($results));
            $this->assertSame(1, $passiveCount, $this->workerDiagnostics($results));
            $this->assertSame(ReviewLog::SOURCE_READING_PASSIVE, $logs[0]->source, $this->workerDiagnostics($results));
        }

        $this->assertSame(1, ReadingSessionCompletion::where('reading_session_id', $this->session->id)->count(), $this->workerDiagnostics($results));
    }

    public function test_preacknowledged_opened_intent_keeps_passive_zero_during_explicit_vs_finish_race(): void
    {
        $this->bindCurrentOccurrenceTo($this->sense);
        $acknowledgement = app(ReadingSessionService::class)->recordOccurrenceInteraction(
            $this->user->id,
            'english',
            $this->session->uuid,
            ReadingSessionInteraction::TYPE_OPENED,
            $this->occurrenceId,
        );
        $this->assertTrue($acknowledgement['recorded'] ?? false, 'Opened intent must be positively acknowledged before the race barrier is released.');
        $this->assertSame($this->occurrenceId, $acknowledgement['occurrence_id'] ?? null);
        $this->assertSame(
            1,
            ReadingSessionInteraction::where('reading_session_id', $this->session->id)
                ->where('interaction_type', ReadingSessionInteraction::TYPE_OPENED)
                ->where('occurrence_id', $this->occurrenceId)
                ->count(),
            'Opened intent must be durably committed before concurrent Finish/explicit workers start.',
        );

        $results = $this->runConcurrent([
            ['explicit-rate', $this->explicitWorkerPayload('good', $this->readingActionId(36))],
            ['finish-commit', $this->finishWorkerPayload()],
        ]);

        $this->assertWorkerOutcome($results[0], [ReadingSessionService::ERROR_SESSION_NOT_ACTIVE]);
        $this->assertWorkerOutcome($results[1]);
        $explicitCount = ReviewLog::where('review_card_id', $this->card->id)
            ->where('source', ReviewLog::SOURCE_READING_EXPLICIT)
            ->count();
        $passiveCount = ReviewLog::where('review_card_id', $this->card->id)
            ->where('source', ReviewLog::SOURCE_READING_PASSIVE)
            ->count();
        $this->assertSame(
            0,
            $passiveCount,
            'Once opened is acknowledged, later Finish must never create reading_passive for that occurrence. '.$this->workerDiagnostics($results),
        );
        $this->assertLessThanOrEqual(1, $explicitCount, $this->workerDiagnostics($results));

        $explicitStatus = $results[0]['json']['http_status'];
        if ($explicitStatus >= 200 && $explicitStatus < 300) {
            $this->assertSame(1, $explicitCount, $this->workerDiagnostics($results));
            $this->assertSame(
                ReviewLog::where('review_card_id', $this->card->id)
                    ->where('source', ReviewLog::SOURCE_READING_EXPLICIT)
                    ->sole()->id,
                $results[0]['json']['body']['action']['review_log_id'],
                $this->workerDiagnostics($results),
            );
        } else {
            $this->assertSame(409, $explicitStatus, $this->workerDiagnostics($results));
            $this->assertSame(
                ReadingSessionService::ERROR_SESSION_NOT_ACTIVE,
                $results[0]['json']['body']['error_code'] ?? null,
                $this->workerDiagnostics($results),
            );
            $this->assertSame(0, $explicitCount, $this->workerDiagnostics($results));
        }

        $this->assertSame(1, ReadingSessionCompletion::where('reading_session_id', $this->session->id)->count(), $this->workerDiagnostics($results));
        $this->assertSame(1, $this->chapter->fresh()->read_count, $this->workerDiagnostics($results));
        $this->assertSame(
            0,
            ReadingSessionCardSettlement::where('reading_session_id', $this->session->id)
                ->where('review_card_id', $this->card->id)
                ->count(),
            'Acknowledged opened intent must prevent passive card settlement side effects.',
        );
        $this->assertLessThanOrEqual(1, $explicitCount + $passiveCount, 'The race must not duplicate formal logs. '.$this->workerDiagnostics($results));
    }

    public function test_true_opened_vs_finish_race_has_no_impossible_opened_plus_passive_terminal_state(): void
    {
        $this->bindCurrentOccurrenceTo($this->sense);
        $results = $this->runConcurrent([
            ['interaction', $this->interactionWorkerPayload('opened')],
            ['finish-commit', $this->finishWorkerPayload()],
        ]);

        $this->assertWorkerOutcome($results[0], [ReadingSessionService::ERROR_SESSION_NOT_ACTIVE]);
        $this->assertWorkerOutcome($results[1]);
        $opened = ReadingSessionInteraction::where('reading_session_id', $this->session->id)
            ->where('interaction_type', ReadingSessionInteraction::TYPE_OPENED)->exists();
        $passive = ReviewLog::where('review_card_id', $this->card->id)->where('source', ReviewLog::SOURCE_READING_PASSIVE)->exists();
        $this->assertFalse($opened && $passive, $this->workerDiagnostics($results));
    }

    public function test_true_helped_vs_finish_race_has_no_impossible_helped_plus_passive_terminal_state(): void
    {
        $this->bindCurrentOccurrenceTo($this->sense);
        $results = $this->runConcurrent([
            ['interaction', $this->interactionWorkerPayload('helped')],
            ['finish-commit', $this->finishWorkerPayload()],
        ]);

        $this->assertWorkerOutcome($results[0], [ReadingSessionService::ERROR_SESSION_NOT_ACTIVE]);
        $this->assertWorkerOutcome($results[1]);
        $helped = ReadingSessionInteraction::where('reading_session_id', $this->session->id)
            ->where('interaction_type', ReadingSessionInteraction::TYPE_HELPED)->exists();
        $passive = ReviewLog::where('review_card_id', $this->card->id)->where('source', ReviewLog::SOURCE_READING_PASSIVE)->exists();
        $this->assertFalse($helped && $passive, $this->workerDiagnostics($results));
    }

    public function test_true_evidence_correction_vs_finish_race_serializes_to_one_valid_passive_choice(): void
    {
        $alternate = $this->makeSense('bank', '河岸');
        $alternateCard = app(ReviewCardService::class)->ensureSenseCard($alternate);
        $this->anchorCardForReaderGood($alternateCard, now()->subDays(2)->startOfSecond());
        $catalog = app(ReadingTargetCatalogService::class)->build($this->user->id, 'english', $this->chapter->id);
        $this->occurrenceId = $catalog['targets'][0]['occurrence_id'];
        $this->bindCurrentOccurrenceTo($this->sense);

        $results = $this->runConcurrent([
            ['user-evidence', [
                'user_id' => $this->user->id,
                'language' => 'english',
                'chapter_id' => $this->chapter->id,
                'occurrence_id' => $this->occurrenceId,
                'resolution' => ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
                'word_sense_id' => $alternate->id,
            ]],
            ['finish-commit', $this->finishWorkerPayload()],
        ]);

        $this->assertAllWorkersSucceeded($results);
        $passive = ReviewLog::where('source', ReviewLog::SOURCE_READING_PASSIVE)
            ->whereIn('review_card_id', [$this->card->id, $alternateCard->id])->get();
        $this->assertLessThanOrEqual(1, $passive->count(), $this->workerDiagnostics($results));
        if (ReadingSessionCompletion::where('reading_session_id', $this->session->id)->exists()) {
            $this->assertCount(1, $passive, $this->workerDiagnostics($results));
        } else {
            $this->assertCount(0, $passive, $this->workerDiagnostics($results));
        }
    }

    public function test_true_source_change_vs_finish_race_has_only_a_serialized_success_or_stale_terminal_state(): void
    {
        $this->bindCurrentOccurrenceTo($this->sense);
        $beforeReadCount = $this->chapter->read_count;
        $results = $this->runConcurrent([
            ['chapter-source-change', [
                'user_id' => $this->user->id,
                'language' => 'english',
                'chapter_id' => $this->chapter->id,
            ]],
            ['finish-commit', $this->finishWorkerPayload()],
        ]);

        $this->assertWorkerOutcome($results[0]);
        $this->assertWorkerOutcome($results[1], [ReadingSessionService::ERROR_SESSION_STALE_SOURCE]);
        $completed = ReadingSessionCompletion::where('reading_session_id', $this->session->id)->exists();
        $passiveCount = ReviewLog::where('review_card_id', $this->card->id)->where('source', ReviewLog::SOURCE_READING_PASSIVE)->count();
        $readDelta = $this->chapter->fresh()->read_count - $beforeReadCount;
        if ($completed) {
            $this->assertSame(1, $passiveCount, $this->workerDiagnostics($results));
            $this->assertSame(1, $readDelta, $this->workerDiagnostics($results));
        } else {
            $this->assertSame(0, $passiveCount, $this->workerDiagnostics($results));
            $this->assertSame(0, $readDelta, $this->workerDiagnostics($results));
        }
    }

    private function assertExplicitPairingRejected(
        ReviewCard $card,
        string $occurrenceId,
        int $expectedStatus,
        string $expectedErrorCode,
    ): void {
        $snapshotService = app(ReviewCardFsrsSnapshotService::class);
        $beforeSnapshot = $snapshotService->capture($card->fresh());
        $beforeLogs = ReviewLog::count();
        $beforeLedger = ReadingSessionInteraction::query()
            ->where('reading_session_id', $this->session->id)
            ->where('interaction_type', ReadingSessionInteraction::TYPE_EXPLICIT_RATED)
            ->count();

        $response = $this->actingAs($this->user)->postJson('/reviews/senses/'.$card->id.'/rate', [
            'rating' => 'good',
            'reading_session_id' => $this->session->uuid,
            'occurrence_id' => $occurrenceId,
            'reading_action_id' => $this->readingActionId(80),
            'ignoreDailyLimits' => true,
        ]);

        $response->assertStatus($expectedStatus)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', $expectedErrorCode);
        $this->assertSame($beforeLogs, ReviewLog::count());
        $this->assertTrue($snapshotService->matches($card->fresh(), $beforeSnapshot));
        $this->assertSame(
            $beforeLedger,
            ReadingSessionInteraction::query()
                ->where('reading_session_id', $this->session->id)
                ->where('interaction_type', ReadingSessionInteraction::TYPE_EXPLICIT_RATED)
                ->count(),
        );
    }

    private function activateCard(ReviewCard $card): ReviewCard
    {
        $card->forceFill([
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
            'fsrs_enabled' => true,
            'fsrs_due_at' => now(),
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
        ])->save();

        return $card->fresh();
    }

    private function makeSense(
        string $lemma,
        string $senseZh,
        ?User $owner = null,
        string $language = 'english',
    ): WordSense {
        $owner ??= $this->user;

        return WordSense::forceCreate([
            'user_id' => $owner->id,
            'language' => $language,
            'language_id' => $language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'NOUN',
            'sense_zh' => $senseZh,
            'sense_en' => $senseZh,
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', $language.'|'.$lemma.'|'.$senseZh.'|'.Str::uuid()),
        ]);
    }

    private function deleteTestUserData(int $userId): void
    {
        ReadingSessionCardSettlement::query()->where('user_id', $userId)->delete();
        ReadingSessionCompletion::query()->where('user_id', $userId)->delete();
        ReadingSessionInteraction::query()->where('user_id', $userId)->delete();
        ReadingOccurrenceSenseEvidence::query()->where('user_id', $userId)->delete();
        ReadingUnfamiliarTarget::query()->where('user_id', $userId)->delete();
        ReviewLog::query()->where('user_id', $userId)->delete();
        ReadingSession::query()->where('user_id', $userId)->delete();
        ReviewCard::query()->where('user_id', $userId)->delete();
        GoalAchievement::query()->where('user_id', $userId)->delete();
        Goal::query()->where('user_id', $userId)->delete();
        // learning_started_source_occurrence_id restricts deleting an occurrence while its sense still exists.
        WordSense::query()->where('user_id', $userId)->delete();
        WordSenseOccurrence::query()->where('user_id', $userId)->delete();
        Chapter::query()->where('user_id', $userId)->delete();
        Book::query()->where('user_id', $userId)->delete();
        User::query()->where('id', $userId)->delete();
    }

    private function bindCurrentOccurrenceTo(WordSense $sense): void
    {
        app(ReadingOccurrenceSenseEvidenceService::class)->storeUserDecision(
            $this->user->id,
            'english',
            $this->chapter->id,
            $this->occurrenceId,
            ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
            $sense->id,
        );
    }

    /** @return array{Chapter, ReadingSession, string} */
    private function createSecondReadingSessionForSense(string $label): array
    {
        $processed = [[
            'word_index' => 0,
            'word' => 'bank',
            'lemma' => 'bank',
            'pos' => 'NOUN',
            'sentence_index' => 0,
            'spaceAfter' => false,
        ]];
        $chapter = Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => $this->chapter->book_id,
            'name' => 'PAB R4 '.$label.' '.Str::lower(Str::random(6)),
            'language' => 'english',
            'raw_text' => 'bank',
            'word_count' => 1,
            'read_count' => 0,
            'unique_words' => '["bank"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode($processed), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
        $catalog = app(ReadingTargetCatalogService::class)->build($this->user->id, 'english', $chapter->id);
        $this->assertCount(1, $catalog['targets']);
        $occurrenceId = $catalog['targets'][0]['occurrence_id'];
        $candidateIds = array_map(
            fn (array $candidate): int => (int) ($candidate['word_sense_id'] ?? $candidate['sense_id'] ?? $candidate['id'] ?? 0),
            $catalog['targets'][0]['candidate_word_senses'] ?? [],
        );
        $this->assertContains($this->sense->id, $candidateIds);
        $started = app(ReadingSessionService::class)->startSession($this->user->id, 'english', $chapter->id);
        $session = ReadingSession::where('uuid', $started['reading_session_id'])->firstOrFail();
        app(ReadingOccurrenceSenseEvidenceService::class)->storeUserDecision(
            $this->user->id,
            'english',
            $chapter->id,
            $occurrenceId,
            ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
            $this->sense->id,
        );

        return [$chapter, $session, $occurrenceId];
    }

    private function setMainCardAnchor(Carbon $anchor): void
    {
        $log = ReviewLog::query()
            ->where('review_card_id', $this->card->id)
            ->where('source', ReviewLog::SOURCE_SENSE_REVIEW)
            ->orderByDesc('id')
            ->firstOrFail();
        $log->reviewed_at = $anchor;
        $log->save();
        $this->card->forceFill([
            'fsrs_state' => 'review',
            'fsrs_step_index' => null,
            'fsrs_due_at' => $anchor->copy()->addDays(30),
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 4,
            'fsrs_last_reviewed_at' => $anchor,
            'fsrs_enabled' => true,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ])->save();
        $this->card = $this->card->fresh();
    }

    private function anchorCardForReaderGood(ReviewCard $card, Carbon $anchor): ReviewCard
    {
        $card->forceFill([
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_step_index' => null,
            'fsrs_due_at' => $anchor->copy()->addDays(30),
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 4,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => $anchor,
        ])->save();
        ReviewLog::forceCreate([
            'user_id' => $card->user_id,
            'language_id' => $card->language_id,
            'language' => $card->language_id,
            'review_card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => $anchor,
            'previous_state' => 'review',
            'new_state' => 'review',
            'source' => ReviewLog::SOURCE_SENSE_REVIEW,
        ]);

        return $card->fresh();
    }

    private function readingActionId(int $sequence): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $sequence);
    }

    private function explicitRequestPayload(string $rating, string $actionId): array
    {
        return [
            'rating' => $rating,
            'reading_session_id' => $this->session->uuid,
            'occurrence_id' => $this->occurrenceId,
            'reading_action_id' => $actionId,
            'ignoreDailyLimits' => true,
        ];
    }

    private function readerManualSensePayload(): array
    {
        return [
            'lemma' => 'bank',
            'surface_form' => 'bank',
            'pos' => 'noun',
            'sense_zh' => '河岸',
            'chapter_id' => $this->chapter->id,
            'sentence_id' => '0',
            'reading_session_id' => $this->session->uuid,
            'source_revision' => $this->session->source_revision,
            'occurrence_id' => $this->occurrenceId,
        ];
    }

    private function undoExplicit(int $reviewLogId, string $undoRequestId)
    {
        return $this->actingAs($this->user)->postJson(
            '/reviews/senses/review-actions/'.$reviewLogId.'/undo',
            [
                'review_session_id' => $this->session->uuid,
                'undo_request_id' => $undoRequestId,
                'source' => 'sense_review_snackbar',
            ],
        );
    }

    private function explicitLogs()
    {
        return ReviewLog::query()
            ->where('review_card_id', $this->card->id)
            ->where('source', ReviewLog::SOURCE_READING_EXPLICIT)
            ->orderBy('id')
            ->get();
    }

    private function activeExplicitLogs()
    {
        return ReviewLog::query()
            ->where('review_card_id', $this->card->id)
            ->where('source', ReviewLog::SOURCE_READING_EXPLICIT)
            ->whereNull('undone_at')
            ->orderBy('id')
            ->get();
    }

    private function explicitActionCount(?string $actionId = null): int
    {
        $query = ReadingSessionInteraction::query()
            ->where('reading_session_id', $this->session->id)
            ->where('interaction_type', ReadingSessionInteraction::TYPE_EXPLICIT_RATED);
        if ($actionId !== null) {
            $query->where('reading_action_id', $actionId);
        }

        return $query->count();
    }

    private function explicitWorkerPayload(string $rating, string $actionId): array
    {
        return [
            'user_id' => $this->user->id,
            'review_card_id' => $this->card->id,
            'rating' => $rating,
            'reading_session_id' => $this->session->uuid,
            'occurrence_id' => $this->occurrenceId,
            'reading_action_id' => $actionId,
        ];
    }

    private function explicitWorkerPayloadFor(
        ReadingSession $session,
        string $occurrenceId,
        string $rating,
        string $actionId,
    ): array {
        return [
            'user_id' => $this->user->id,
            'review_card_id' => $this->card->id,
            'rating' => $rating,
            'reading_session_id' => $session->uuid,
            'occurrence_id' => $occurrenceId,
            'reading_action_id' => $actionId,
        ];
    }

    private function explicitUndoWorkerPayload(int $reviewLogId, string $undoRequestId): array
    {
        return [
            'user_id' => $this->user->id,
            'review_log_id' => $reviewLogId,
            'review_session_id' => $this->session->uuid,
            'undo_request_id' => $undoRequestId,
            'source' => 'sense_review_snackbar',
        ];
    }

    private function interactionWorkerPayload(string $type): array
    {
        return [
            'user_id' => $this->user->id,
            'language' => 'english',
            'reading_session_id' => $this->session->uuid,
            'interaction_type' => $type,
            'occurrence_id' => $this->occurrenceId,
        ];
    }

    private function finishWorkerPayload(): array
    {
        return [
            'user_id' => $this->user->id,
            'language' => 'english',
            'chapter_id' => $this->chapter->id,
            'reading_session_id' => $this->session->uuid,
            'auto_move_words_to_known' => false,
            'unique_words' => [],
            'auto_level_up_words' => false,
            'leveled_up_words' => [],
            'leveled_up_phrases' => [],
        ];
    }

    /** @param array<int, array{0:string,1:array}> $operations */
    private function runConcurrent(array $operations): array
    {
        $processes = [];
        $code = <<<'PHP'
$basePath = $argv[1];
$operation = $argv[2];
$payload = json_decode(base64_decode($argv[3]), true, 512, JSON_THROW_ON_ERROR);
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
require $basePath.'/tests/bootstrap.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
fgets(STDIN);
try {
    $result = Tests\Support\PabR3ReadingConcurrencyWorker::run($operation, $payload);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
PHP;
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        foreach ($operations as [$operation, $payload]) {
            $process = proc_open([
                PHP_BINARY,
                '-d',
                'max_execution_time=20',
                '-r',
                $code,
                base_path(),
                $operation,
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            ], $descriptors, $pipes, base_path());
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start PAB R3 concurrency worker.');
            }
            $ready = fgets($pipes[1]);
            if ($ready !== "READY\n") {
                $stderr = stream_get_contents($pipes[2]);
                throw new RuntimeException("Concurrency worker failed before barrier: {$stderr}");
            }
            $processes[] = [$process, $pipes, $operation];
        }

        foreach ($processes as [, $pipes]) {
            fwrite($pipes[0], "go\n");
            fclose($pipes[0]);
        }

        $results = [];
        foreach ($processes as [$process, $pipes, $operation]) {
            $stdout = trim(stream_get_contents($pipes[1]));
            $stderr = trim(stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            $lines = array_values(array_filter(preg_split('/\R/', $stdout) ?: [], fn (string $line) => trim($line) !== ''));
            $jsonLine = $lines !== [] ? end($lines) : null;
            $json = is_string($jsonLine) ? json_decode($jsonLine, true) : null;
            $results[] = compact('operation', 'exitCode', 'stdout', 'stderr', 'json');
        }

        return $results;
    }

    private function assertAllWorkersSucceeded(array $results): void
    {
        foreach ($results as $result) {
            $this->assertWorkerOutcome($result);
        }
    }

    private function assertWorkerOutcome(array $result, array $allowedErrors = []): void
    {
        if ($result['exitCode'] === 0) {
            $this->assertIsArray($result['json'], $this->workerDiagnostics([$result]));
            if (!in_array($result['operation'], ['explicit-rate', 'explicit-undo'], true)) {
                return;
            }

            $this->assertSame($result['operation'], $result['json']['operation'] ?? null, $this->workerDiagnostics([$result]));
            $this->assertIsInt($result['json']['http_status'] ?? null, $this->workerDiagnostics([$result]));
            $this->assertIsArray($result['json']['body'] ?? null, $this->workerDiagnostics([$result]));
            $status = $result['json']['http_status'];
            if ($status >= 200 && $status < 300) {
                $this->assertIsArray($result['json']['body']['action'] ?? null, $this->workerDiagnostics([$result]));
                $this->assertArrayHasKey('review_log_id', $result['json']['body']['action'], $this->workerDiagnostics([$result]));
                return;
            }

            $errorCode = $result['json']['body']['error_code'] ?? $result['json']['body']['blocked_reason'] ?? null;
            if (in_array($errorCode, $allowedErrors, true)) {
                $this->addToAssertionCount(1);
                return;
            }

            $this->fail('Unexpected '.$result['operation'].' HTTP/application outcome: '.$this->workerDiagnostics([$result]));
        }

        foreach ($allowedErrors as $allowedError) {
            if (str_contains($result['stderr'], $allowedError)) {
                $this->addToAssertionCount(1);
                return;
            }
        }

        $this->fail('Unexpected concurrency worker failure: '.$this->workerDiagnostics([$result]));
    }

    private function workerDiagnostics(array $results): string
    {
        return json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
