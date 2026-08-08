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
use App\Models\ReadingUnfamiliarTarget;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\ChapterService;
use App\Services\ReadingChapterTextService;
use App\Services\ReadingFinishSettlementService;
use App\Services\ReadingOccurrenceSenseEvidenceService;
use App\Services\ReadingSessionService;
use App\Services\ReviewCardFsrsSnapshotService;
use App\Services\ReviewCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Support\PabR3AiReadingAssistV2Harness as V2Harness;
use Tests\TestCase;

/**
 * Executable Phase B DB contract. Integration owns its execution under the
 * exclusive testing-DB lease; this parallel lane only lints/discovers it.
 */
class ReadingReviewSettlementContractTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Chapter $chapter;
    private ReadingSession $session;
    private array $catalog = [];
    private array $evidenceMap = [];
    private array $interactionSummary = [];
    private int $formalWriteCount = 0;
    private int $legacyFinishCount = 0;
    private ReadingFinishSettlementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::forceCreate([
            'name' => 'PAB R3 Settlement',
            'email' => 'pab-r3-settlement-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $book = Book::forceCreate(['user_id' => $this->user->id, 'name' => 'PAB R3 Book', 'language' => 'english']);
        $this->chapter = Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'name' => 'PAB R3 Chapter',
            'language' => 'english',
            'raw_text' => 'Harness sentence.',
            'word_count' => 1,
            'read_count' => 0,
            'unique_words' => '["harness"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
        $this->session = ReadingSession::forceCreate([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'chapter_id' => $this->chapter->id,
            'source_revision' => V2Harness::SOURCE_REVISION,
            'status' => ReadingSession::STATUS_ACTIVE,
            'started_at' => now()->subMinute(),
        ]);
        $this->catalog = $this->catalogWithTargets([]);
        $this->interactionSummary = $this->emptyInteractionSummary();
        $this->service = $this->makeSettlementService();
    }

    private function makeSettlementService(
        ?ReviewCardService $cardService = null,
        ?ChapterService $chapterService = null,
    ): ReadingFinishSettlementService {
        $sessionService = Mockery::mock(ReadingSessionService::class);
        $sessionService->shouldReceive('resolveOwnedSession')->andReturnUsing(function (int $userId, string $language, string $uuid) {
            $session = ReadingSession::query()
                ->where('uuid', $uuid)->where('user_id', $userId)->where('language_id', $language)->first();
            if (!$session) throw new \InvalidArgumentException('Reading session does not exist in the current user and language scope.');
            return $session;
        });
        $sessionService->shouldReceive('lockActiveSessionContext')->andReturnUsing(function (int $userId, string $language, string $uuid, ?int $chapterId = null) {
            $session = ReadingSession::query()
                ->where('uuid', $uuid)->where('user_id', $userId)->where('language_id', $language)->first();
            if (!$session || ($chapterId !== null && (int) $session->chapter_id !== $chapterId)) {
                throw new \InvalidArgumentException('Reading session does not exist in the current scope.');
            }
            return ['session' => $session, 'catalog' => $this->catalog];
        });
        $sessionService->shouldReceive('interactionSummary')->andReturnUsing(fn () => $this->interactionSummary);

        $evidenceService = Mockery::mock(ReadingOccurrenceSenseEvidenceService::class);
        $evidenceService->shouldReceive('currentEvidenceMap')->andReturnUsing(fn () => $this->evidenceMap);
        $evidenceService->shouldReceive('isCurrentConfirmedBinding')->andReturn(true);

        if ($cardService === null) {
            $cardService = Mockery::mock(ReviewCardService::class);
            $cardService->shouldReceive('recordReviewWithLog')->andReturnUsing(function (
                int $userId,
                string $language,
                int $reviewCardId,
                string $rating,
                string $source,
                ?string $reviewSessionId = null,
                ...$rest
            ) {
                $this->formalWriteCount++;
                $log = ReviewLog::forceCreate([
                    'user_id' => $userId,
                    'language_id' => $language,
                    'language' => $language,
                    'review_card_id' => $reviewCardId,
                    'rating' => $rating,
                    'reviewed_at' => now(),
                    'source' => $source,
                    'review_session_id' => $reviewSessionId,
                ]);
                return ['review_log' => $log, 'card' => ReviewCard::findOrFail($reviewCardId)];
            });
        }

        if ($chapterService === null) {
            $chapterService = Mockery::mock(ChapterService::class);
            $chapterService->shouldReceive('finishChapter')->andReturnUsing(function (...$args) {
                $this->legacyFinishCount++;
                $this->chapter->increment('read_count');
                return true;
            });
        }

        return new ReadingFinishSettlementService($sessionService, $evidenceService, $cardService, $chapterService);
    }

    private function emptyInteractionSummary(): array
    {
        return [
            'opened_occurrence_ids' => [],
            'helped_occurrence_ids' => [],
            'explicit_review_card_ids' => [],
            'explicit_word_sense_ids' => [],
        ];
    }

    private function catalogWithTargets(array $targets): array
    {
        $byId = [];
        foreach ($targets as $target) $byId[$target['occurrence_id']] = $target;
        return [
            'chapter' => $this->chapter ?? (object) ['id' => 0],
            'source_revision' => V2Harness::SOURCE_REVISION,
            'sentences' => [0 => 'Harness sentence.'],
            'targets' => $targets,
            'targets_by_id' => $byId,
        ];
    }

    private function addEligibleTarget(string $occurrenceId = 'occ2_settlement_1', string $purpose = 'passive_disambiguation', ?WordSense $sense = null): array
    {
        $sense ??= $this->makeSense($occurrenceId);
        $card = ReviewCard::query()
            ->where('user_id', $this->user->id)
            ->where('language_id', 'english')
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->where('target_id', $sense->id)
            ->first() ?: ReviewCard::forceCreate([
                'user_id' => $this->user->id,
                'language_id' => 'english',
                'language' => 'english',
                'target_type' => ReviewCard::TARGET_SENSE,
                'target_id' => $sense->id,
                'fsrs_state' => 'review',
                'fsrs_due_at' => now(),
                'fsrs_enabled' => true,
                'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
                'lifecycle_version' => 0,
            ]);
        $target = [
            'occurrence_id' => $occurrenceId,
            'kind' => 'word',
            'purpose' => $purpose,
            'start_word_index' => count($this->catalog['targets'] ?? []),
            'end_word_index' => count($this->catalog['targets'] ?? []),
            'sentence_index' => 0,
            'surface' => $sense->surface_form,
            'lemma' => $sense->lemma,
            'pos' => $sense->pos,
            'source_sentence' => 'Harness sentence.',
            'candidate_word_senses' => [['word_sense_id' => $sense->id]],
        ];
        $targets = $this->catalog['targets'] ?? [];
        $targets[] = $target;
        $this->catalog = $this->catalogWithTargets($targets);

        $evidence = new ReadingOccurrenceSenseEvidence([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'chapter_id' => $this->chapter->id,
            'source_revision' => V2Harness::SOURCE_REVISION,
            'occurrence_id' => $occurrenceId,
            'target_origin' => $purpose,
            'resolution' => ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
            'word_sense_id' => $sense->id,
            'resolution_source' => ReadingOccurrenceSenseEvidence::SOURCE_USER,
        ]);
        $evidence->updated_at = now()->subMinutes(2);
        $this->evidenceMap[$occurrenceId] = $evidence;
        return [$sense, $card, $target, $evidence];
    }

    private function makeSense(string $suffix): WordSense
    {
        $lemma = 'settlement-'.preg_replace('/[^a-z0-9]+/i', '-', $suffix).'-'.Str::lower(Str::random(4));
        return WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'NOUN',
            'sense_zh' => '测试',
            'sense_en' => 'test',
            'aliases_zh' => [], 'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', $lemma.'|'.Str::uuid()),
        ]);
    }

    private function finish(string $mode): array
    {
        return $this->service->finishChapterWithSession(
            $this->user->id, 'english', $this->chapter->id, $this->session->uuid,
            false, [], false, [], [], $mode,
        );
    }

    public function test_start_resume_returns_one_current_active_session_and_recovers_completed_result(): void
    {
        $this->session->delete();
        $chapterText = Mockery::mock(ReadingChapterTextService::class);
        $chapterText->shouldReceive('chapterForUser')->andReturn($this->chapter);
        $chapterText->shouldReceive('lockChapterForUser')->andReturn($this->chapter);
        $chapterText->shouldReceive('sourceRevision')->andReturn(V2Harness::SOURCE_REVISION);
        $catalog = Mockery::mock(ReadingTargetCatalogService::class);
        $catalog->shouldReceive('build')->andReturn($this->catalog);
        $sessions = new ReadingSessionService($chapterText, $catalog);

        $first = $sessions->startSession($this->user->id, 'english', $this->chapter->id, null);
        $second = $sessions->startSession($this->user->id, 'english', $this->chapter->id, null);
        $resumed = $sessions->startSession($this->user->id, 'english', $this->chapter->id, $first['reading_session_id']);
        $this->assertSame($first['reading_session_id'], $second['reading_session_id']);
        $this->assertSame($first['reading_session_id'], $resumed['reading_session_id']);
        $this->assertSame(1, ReadingSession::where('status', ReadingSession::STATUS_ACTIVE)->count());

        $row = ReadingSession::where('uuid', $first['reading_session_id'])->firstOrFail();
        $row->update(['status' => ReadingSession::STATUS_COMPLETED, 'completed_at' => now()]);
        $stored = ['success' => true, 'completed' => true, 'reading_session_id' => $row->uuid];
        ReadingSessionCompletion::forceCreate([
            'reading_session_id' => $row->id, 'user_id' => $this->user->id, 'language_id' => 'english',
            'chapter_id' => $this->chapter->id, 'source_revision' => V2Harness::SOURCE_REVISION, 'result' => $stored,
        ]);
        $recovered = $sessions->startSession($this->user->id, 'english', $this->chapter->id, $row->uuid);
        $this->assertSame($stored, $recovered, 'Completed-session resume must return the stored completion result verbatim.');
    }

    public function test_preflight_with_zero_unresolved_is_always_read_only(): void
    {
        $before = ['logs' => ReviewLog::count(), 'settlements' => ReadingSessionCardSettlement::count(), 'completions' => ReadingSessionCompletion::count(), 'read_count' => $this->chapter->read_count];
        $result = $this->finish('preflight');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['completed']);
        $this->assertArrayHasKey('can_commit', $result);
        $this->assertTrue($result['can_commit']);
        $this->assertSame($before, ['logs' => ReviewLog::count(), 'settlements' => ReadingSessionCardSettlement::count(), 'completions' => ReadingSessionCompletion::count(), 'read_count' => $this->chapter->fresh()->read_count]);
        $this->assertSame(0, $this->formalWriteCount);
        $this->assertSame(0, $this->legacyFinishCount);
    }

    public function test_old_trust_bypass_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->finish('trust');
    }

    public function test_commit_with_unresolved_item_returns_projection_with_zero_writes(): void
    {
        $target = V2Harness::catalog(1)['targets'][0];
        $this->catalog = $this->catalogWithTargets([$target]);
        $beforeReadCount = $this->chapter->read_count;
        $result = $this->finish('commit');

        $this->assertFalse($result['completed']);
        $this->assertGreaterThan(0, $result['unresolved_count']);
        $this->assertSame(0, ReviewLog::count());
        $this->assertSame(0, ReadingSessionCompletion::count());
        $this->assertSame($beforeReadCount, $this->chapter->fresh()->read_count);
    }

    public function test_reliable_binding_settles_good_once_and_finish_retry_is_idempotent(): void
    {
        [, $card] = $this->addEligibleTarget();
        $first = $this->finish('commit');
        $second = $this->finish('commit');

        $this->assertTrue($first['completed']);
        $this->assertSame($first, $second, 'Finish retry must return the exact stored completion result.');
        $this->assertSame(1, ReviewLog::where('review_card_id', $card->id)->where('source', ReviewLog::SOURCE_READING_PASSIVE)->count());
        $this->assertSame(1, ReadingSessionCardSettlement::where('review_card_id', $card->id)->count());
        $this->assertSame(1, ReadingSessionCompletion::count());
        $this->assertSame(1, $this->formalWriteCount);
        $this->assertSame(1, $this->legacyFinishCount);
    }

    public function test_finish_outer_transaction_rolls_back_formal_rating_settlement_and_completion_when_late_finish_step_fails(): void
    {
        $sense = $this->makeSense('finish-rollback');
        $card = app(ReviewCardService::class)->ensureSenseCard($sense);
        $card->forceFill([
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
            'fsrs_enabled' => true,
            'fsrs_due_at' => now(),
        ])->save();
        $this->addEligibleTarget('occ2_finish_rollback', 'passive_disambiguation', $sense);

        $snapshotService = app(ReviewCardFsrsSnapshotService::class);
        $beforeSnapshot = $snapshotService->capture($card->fresh());
        $before = [
            'logs' => ReviewLog::count(),
            'settlements' => ReadingSessionCardSettlement::count(),
            'completions' => ReadingSessionCompletion::count(),
            'read_count' => $this->chapter->read_count,
            'goals' => Goal::where('user_id', $this->user->id)->count(),
            'goal_achievements' => GoalAchievement::where('user_id', $this->user->id)->count(),
            'session_status' => $this->session->status,
            'session_completed_at' => $this->session->completed_at,
        ];

        $throwingChapterService = Mockery::mock(ChapterService::class);
        $throwingChapterService->shouldReceive('finishChapter')->once()->andThrow(
            new RuntimeException('PAB_R3_FINISH_OUTER_ROLLBACK'),
        );
        $this->service = $this->makeSettlementService(app(ReviewCardService::class), $throwingChapterService);

        try {
            $this->finish('commit');
            $this->fail('Injected late Finish failure must roll back the whole outer transaction.');
        } catch (RuntimeException $e) {
            $this->assertSame('PAB_R3_FINISH_OUTER_ROLLBACK', $e->getMessage());
        }

        $this->assertTrue($snapshotService->matches($card->fresh(), $beforeSnapshot));
        $this->assertSame($before['logs'], ReviewLog::count());
        $this->assertSame($before['settlements'], ReadingSessionCardSettlement::count());
        $this->assertSame($before['completions'], ReadingSessionCompletion::count());
        $this->assertSame($before['read_count'], $this->chapter->fresh()->read_count);
        $this->assertSame($before['goals'], Goal::where('user_id', $this->user->id)->count());
        $this->assertSame($before['goal_achievements'], GoalAchievement::where('user_id', $this->user->id)->count());
        $this->session->refresh();
        $this->assertSame($before['session_status'], $this->session->status);
        $this->assertEquals($before['session_completed_at'], $this->session->completed_at);
    }

    public function test_opened_or_helped_occurrence_creates_zero_passive_rating(): void
    {
        [, $card, $target] = $this->addEligibleTarget();
        $this->interactionSummary['opened_occurrence_ids'][$target['occurrence_id']] = true;
        $this->interactionSummary['helped_occurrence_ids'][$target['occurrence_id']] = true;
        $this->finish('commit');
        $this->assertSame(0, ReviewLog::where('review_card_id', $card->id)->count());
    }

    public function test_opened_occurrence_alone_creates_zero_passive_rating(): void
    {
        [, $card, $target] = $this->addEligibleTarget();
        $this->interactionSummary['opened_occurrence_ids'][$target['occurrence_id']] = true;

        $this->finish('commit');

        $this->assertSame(0, ReviewLog::where('review_card_id', $card->id)->count());
    }

    public function test_explicit_rating_wins_over_passive_for_same_sense_session(): void
    {
        [$sense, $card] = $this->addEligibleTarget();
        $this->interactionSummary['explicit_review_card_ids'][$card->id] = true;
        $this->interactionSummary['explicit_word_sense_ids'][$sense->id] = true;
        $this->finish('commit');
        $this->assertSame(0, ReviewLog::where('review_card_id', $card->id)->where('source', ReviewLog::SOURCE_READING_PASSIVE)->count());
    }

    public function test_multiple_occurrences_of_same_sense_create_one_passive_log_per_session(): void
    {
        $sense = $this->makeSense('same-sense');
        [, $card] = $this->addEligibleTarget('occ2_same_1', 'passive_disambiguation', $sense);
        $this->addEligibleTarget('occ2_same_2', 'passive_disambiguation', $sense);
        $this->finish('commit');
        $this->assertSame(1, ReviewLog::where('review_card_id', $card->id)->where('source', ReviewLog::SOURCE_READING_PASSIVE)->count());
    }

    public function test_same_reading_new_marked_unknown_learning_state_has_zero_passive_good(): void
    {
        [, $card, $target, $evidence] = $this->addEligibleTarget('occ2_marked_new', 'marked_unknown');
        $evidence->updated_at = $this->session->started_at->copy()->subMinute();
        $this->evidenceMap[$target['occurrence_id']] = $evidence;
        ReadingUnfamiliarTarget::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'chapter_id' => $this->chapter->id,
            'source_revision' => V2Harness::SOURCE_REVISION,
            'occurrence_id' => $target['occurrence_id'],
            'kind' => 'word',
            'start_word_index' => $target['start_word_index'],
            'end_word_index' => $target['end_word_index'],
            'sentence_index' => $target['sentence_index'],
            'surface' => $target['surface'],
            'lemma' => $target['lemma'],
            'pos' => $target['pos'],
            'source_sentence' => $target['source_sentence'],
        ]);

        $this->finish('commit');

        $this->assertSame(0, ReviewLog::where('review_card_id', $card->id)->count());
    }

    public function test_same_reading_marked_unknown_user_resolution_has_zero_passive_good(): void
    {
        [, $card, $target, $evidence] = $this->addEligibleTarget('occ2_marked_resolved', 'marked_unknown');
        $evidence->updated_at = $this->session->started_at->copy()->addSecond();
        $this->evidenceMap[$target['occurrence_id']] = $evidence;
        $this->finish('commit');
        $this->assertSame(0, ReviewLog::where('review_card_id', $card->id)->count());
    }

    public function test_recent_trust_ai_high_matched_existing_passive_disambiguation_remains_eligible(): void
    {
        [, $card, $target, $evidence] = $this->addEligibleTarget('occ2_trust_recent', 'passive_disambiguation');
        $evidence->resolution_source = ReadingOccurrenceSenseEvidence::SOURCE_TRUST_AI;
        $evidence->updated_at = $this->session->started_at->copy()->addSecond();
        $this->evidenceMap[$target['occurrence_id']] = $evidence;

        $result = $this->finish('commit');

        $this->assertTrue($result['completed']);
        $this->assertSame(1, ReviewLog::where('review_card_id', $card->id)->where('source', ReviewLog::SOURCE_READING_PASSIVE)->count());
    }

    public function test_excluded_new_sense_and_non_current_binding_create_zero_passive_rating(): void
    {
        [, $card, $target, $evidence] = $this->addEligibleTarget();
        foreach ([ReadingOccurrenceSenseEvidence::RESOLUTION_EXCLUDED, ReadingOccurrenceSenseEvidence::RESOLUTION_NEW_SENSE] as $resolution) {
            $evidence->resolution = $resolution;
            $evidence->word_sense_id = null;
            $this->evidenceMap[$target['occurrence_id']] = $evidence;
            $result = $this->finish('preflight');
            $this->assertSame(0, $result['passive_good_count']);
        }
        $this->assertSame(0, ReviewLog::where('review_card_id', $card->id)->count());
    }

    public function test_marked_unknown_binding_resolved_in_one_reading_becomes_eligible_in_a_later_session(): void
    {
        [, $card, $target, $evidence] = $this->addEligibleTarget('occ2_marked_later', 'marked_unknown');
        $evidence->updated_at = $this->session->started_at->copy()->addSecond();
        $this->evidenceMap[$target['occurrence_id']] = $evidence;

        $first = $this->finish('commit');
        $this->assertTrue($first['completed']);
        $this->assertSame(0, ReviewLog::where('review_card_id', $card->id)->where('source', ReviewLog::SOURCE_READING_PASSIVE)->count());

        $this->session = ReadingSession::forceCreate([
            'uuid' => (string) Str::uuid(), 'user_id' => $this->user->id, 'language_id' => 'english',
            'chapter_id' => $this->chapter->id, 'source_revision' => V2Harness::SOURCE_REVISION,
            'status' => ReadingSession::STATUS_ACTIVE, 'started_at' => now(),
        ]);
        $second = $this->finish('commit');

        $this->assertTrue($second['completed']);
        $this->assertSame(1, ReviewLog::where('review_card_id', $card->id)->where('source', ReviewLog::SOURCE_READING_PASSIVE)->count());
    }

    public function test_later_new_reading_session_can_rate_same_sense_again(): void
    {
        [, $card] = $this->addEligibleTarget();
        $this->finish('commit');
        $this->session = ReadingSession::forceCreate([
            'uuid' => (string) Str::uuid(), 'user_id' => $this->user->id, 'language_id' => 'english',
            'chapter_id' => $this->chapter->id, 'source_revision' => V2Harness::SOURCE_REVISION,
            'status' => ReadingSession::STATUS_ACTIVE, 'started_at' => now(),
        ]);
        $this->finish('commit');
        $this->assertSame(2, ReviewLog::where('review_card_id', $card->id)->where('source', ReviewLog::SOURCE_READING_PASSIVE)->count());
    }

    public function test_cross_user_language_or_session_replay_is_rejected_without_rating(): void
    {
        $this->addEligibleTarget();
        $before = ReviewLog::count();
        try {
            $this->service->finishChapterWithSession(
                $this->user->id + 999, 'english', $this->chapter->id, $this->session->uuid,
                false, [], false, [], [], 'commit',
            );
            $this->fail('Cross-user replay must be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($before, ReviewLog::count());
        }
    }
}
