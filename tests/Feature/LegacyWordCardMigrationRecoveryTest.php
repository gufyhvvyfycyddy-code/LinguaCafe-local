<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\LegacyWordCardMigrationRun;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\BackupService;
use App\Services\LegacyWordCardMigrationClassifier;
use App\Services\LegacyWordCardMigrationRecoveryService;
use App\Services\ReviewCardOperationSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LegacyWordCardMigrationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_creates_or_reuses_sense_cards_without_rewriting_history_and_rolls_back_exactly(): void
    {
        $user = $this->createUser('legacy-recovery@example.com');
        [$createdLegacy, $createdSense] = $this->createUniqueMapping($user, 'create', 4);
        [$reusedLegacy, $reusedSense] = $this->createUniqueMapping($user, 'reuse', 6);
        $reusedCard = $this->createSenseCard($user, $reusedSense, 2);
        $log = ReviewLog::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'review_card_id' => $createdLegacy->id,
            'rating' => 'good',
            'reviewed_at' => '2026-08-12 01:00:00',
            'previous_state' => 'new',
            'new_state' => 'learning',
            'source' => ReviewLog::SOURCE_SENSE_REVIEW,
        ]);
        $this->createDependencies($user, $createdLegacy, $createdSense, $log);
        $historyBefore = DB::table('review_logs')->where('id', $log->id)->first();
        $dependenciesBefore = $this->dependencyFingerprint();
        $reusedBefore = $this->cardState($reusedCard);
        $createdBefore = $this->cardState($createdLegacy);
        $reusedLegacyBefore = $this->cardState($reusedLegacy);
        $createdRawBefore = (array) DB::table('review_cards')->where('id', $createdLegacy->id)->first();
        $reusedRawBefore = (array) DB::table('review_cards')->where('id', $reusedLegacy->id)->first();
        $backup = $this->successfulBackup();
        $service = $this->service($backup);

        $plan = $service->plan($user->id, 'english');
        $run = $service->apply($plan);
        $sameRun = $service->apply($plan);

        $this->assertSame($run->id, $sameRun->id);
        $this->assertSame(2, $run->items->count());
        $createdSenseCard = ReviewCard::query()
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->where('target_id', $createdSense->id)
            ->firstOrFail();
        $this->assertSame(
            $this->operationalState($createdBefore),
            $this->operationalState($this->cardState($createdSenseCard)),
        );
        $this->assertSame($reusedBefore, $this->cardState($reusedCard->fresh()));
        $this->assertSame(ReviewCard::LIFECYCLE_ARCHIVED, $createdLegacy->fresh()->lifecycle_state);
        $this->assertSame(ReviewCard::LIFECYCLE_ARCHIVED, $reusedLegacy->fresh()->lifecycle_state);
        $this->assertSame($createdLegacy->target_id, $createdLegacy->fresh()->target_id);
        $this->assertEquals($historyBefore, DB::table('review_logs')->where('id', $log->id)->first());
        $this->assertSame($dependenciesBefore, $this->dependencyFingerprint());
        $this->assertSame($run->run_uuid, $run->fresh()->run_uuid);
        $this->assertSame('legacy_word_card_classification_v1', $run->classifier_schema_version);
        $this->assertSame(64, strlen($run->items->first()->after_legacy_fingerprint));
        $this->assertSame(
            $run->items->first()->legacy_review_card_id,
            $run->items->first()->before_classification_evidence['review_card']['id'],
        );
        $this->assertSame(64, strlen($run->items->first()->after_classification_fingerprint));

        $rollbackBackup = Mockery::mock(BackupService::class);
        $rollbackBackup->shouldNotReceive('inspectBackup');
        $rollbackService = $this->service($rollbackBackup);
        $rolledBack = $rollbackService->rollback($run->id);
        $sameRollback = $rollbackService->rollback($run->id);

        $this->assertSame(LegacyWordCardMigrationRun::STATE_ROLLED_BACK, $rolledBack->state);
        $this->assertSame($rolledBack->id, $sameRollback->id);
        $this->assertNull(ReviewCard::query()->find($createdSenseCard->id));
        $this->assertSame($createdBefore, $this->cardState($createdLegacy->fresh()));
        $this->assertSame($reusedLegacyBefore, $this->cardState($reusedLegacy->fresh()));
        $this->assertEquals($createdRawBefore, (array) DB::table('review_cards')->where('id', $createdLegacy->id)->first());
        $this->assertEquals($reusedRawBefore, (array) DB::table('review_cards')->where('id', $reusedLegacy->id)->first());
        $this->assertSame($reusedBefore, $this->cardState($reusedCard->fresh()));
        $this->assertEquals($historyBefore, DB::table('review_logs')->where('id', $log->id)->first());
        $this->assertSame($dependenciesBefore, $this->dependencyFingerprint());
    }

    public function test_plan_and_apply_leave_ambiguous_unmapped_and_other_scope_cards_untouched(): void
    {
        $user = $this->createUser('legacy-recovery-scope@example.com');
        $other = $this->createUser('legacy-recovery-other@example.com');
        [$eligible, $eligibleSense] = $this->createUniqueMapping($user, 'eligible');
        [$otherCard] = $this->createUniqueMapping($other, 'other');
        $foreignScopeCard = $this->createSenseCard($other, $eligibleSense, 3);
        $ambiguousWord = $this->createWord($user, 'ambiguous');
        $ambiguous = $this->createWordCard($user, $ambiguousWord);
        $this->createSense($user, $ambiguousWord, 'ambiguous-one');
        WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'encountered_word_id' => $ambiguousWord->id,
            'lemma' => 'ambiguous-two',
            'surface_form' => 'ambiguous-two',
            'pos' => 'noun',
            'sense_key' => hash('sha256', (string) Str::uuid()),
            'sense_zh' => 'two',
            'sense_en' => 'two',
            'status' => WordSense::STATUS_AI_SUGGESTED,
        ]);
        $missing = ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => 999999,
        ]);
        $before = $this->allCards();
        $service = $this->service($this->successfulBackup());

        $plan = $service->plan($user->id, 'english');
        $this->assertSame([$eligible->id], array_column($plan['items'], 'legacy_review_card_id'));
        $service->apply($plan);

        $this->assertSame($before[$ambiguous->id], $this->cardState($ambiguous->fresh()));
        $this->assertSame($before[$missing->id], $this->cardState($missing->fresh()));
        $this->assertSame($before[$otherCard->id], $this->cardState($otherCard->fresh()));
        $this->assertSame($before[$foreignScopeCard->id], $this->cardState($foreignScopeCard->fresh()));
        $this->assertDatabaseHas('review_cards', [
            'user_id' => $user->id,
            'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $eligibleSense->id,
        ]);
    }

    public function test_stale_empty_and_backup_failure_plans_make_no_database_changes(): void
    {
        $user = $this->createUser('legacy-recovery-failure@example.com');
        [$legacy, $sense] = $this->createUniqueMapping($user, 'failure');
        $before = $this->allCards();
        $neverBackup = Mockery::mock(BackupService::class);
        $neverBackup->shouldNotReceive('withExclusiveOperation');
        $service = $this->service($neverBackup);
        $stalePlan = $service->plan($user->id, 'english');
        $sense->status = WordSense::STATUS_REJECTED;
        $sense->save();

        try {
            $service->apply($stalePlan);
            $this->fail('A stale plan must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('stale', $exception->getMessage());
        }
        $this->assertSame($before, $this->allCards());
        $this->assertDatabaseCount('legacy_word_card_migration_runs', 0);

        $sense->status = WordSense::STATUS_CONFIRMED;
        $sense->save();
        $failingBackup = Mockery::mock(BackupService::class);
        $failingBackup->shouldReceive('withExclusiveOperation')->once()->andReturnUsing(
            fn (callable $operation) => $operation(fn () => throw new RuntimeException('backup failed')),
        );
        $service = $this->service($failingBackup);
        try {
            $service->apply($service->plan($user->id, 'english'));
            $this->fail('A backup failure must abort apply.');
        } catch (RuntimeException $exception) {
            $this->assertSame('backup failed', $exception->getMessage());
        }
        $this->assertSame($before, $this->allCards());
        $this->assertSame(ReviewCard::LIFECYCLE_ACTIVE, $legacy->fresh()->lifecycle_state);
        $this->assertDatabaseCount('legacy_word_card_migration_runs', 0);

        $empty = $service->plan(999999, 'english');
        $this->expectException(RuntimeException::class);
        $service->apply($empty);
    }

    public function test_transaction_failure_and_rollback_drift_are_all_or_nothing(): void
    {
        $user = $this->createUser('legacy-recovery-atomic@example.com');
        [$legacy, $sense] = $this->createUniqueMapping($user, 'atomic', 5);
        $before = $this->cardState($legacy);
        $actualSnapshots = app(ReviewCardOperationSnapshotService::class);
        $failingSnapshots = Mockery::mock($actualSnapshots)->makePartial();
        $failingSnapshots->shouldReceive('capture')->times(3)->andReturnUsing(
            function (ReviewCard $card) use ($actualSnapshots) {
                static $calls = 0;
                $calls++;
                if ($calls === 3) {
                    throw new RuntimeException('transaction probe');
                }

                return $actualSnapshots->capture($card);
            },
        );
        $service = $this->service($this->successfulBackup(), $failingSnapshots);
        $plan = $service->plan($user->id, 'english');
        try {
            $service->apply($plan);
            $this->fail('The transaction probe must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('transaction probe', $exception->getMessage());
        }
        $this->assertSame($before, $this->cardState($legacy->fresh()));
        $this->assertDatabaseMissing('review_cards', [
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
        ]);
        $this->assertDatabaseCount('legacy_word_card_migration_runs', 0);

        $service = $this->service($this->successfulBackup());
        $run = $service->apply($service->plan($user->id, 'english'));
        $item = $run->items->first();
        ReviewCard::query()->whereKey($item->sense_review_card_id)->update(['marker' => 7]);
        try {
            $service->rollback($run->id);
            $this->fail('Rollback must refuse drift.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('drifted', $exception->getMessage());
        }
        $this->assertSame(ReviewCard::LIFECYCLE_ARCHIVED, $legacy->fresh()->lifecycle_state);
        $this->assertSame(LegacyWordCardMigrationRun::STATE_APPLIED, $run->fresh()->state);

        ReviewCard::query()->whereKey($item->sense_review_card_id)->update(['marker' => 5]);
        ReviewLog::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'review_card_id' => $legacy->id,
            'rating' => 'hard',
            'reviewed_at' => '2026-08-12 02:00:00',
            'previous_state' => 'review',
            'new_state' => 'review',
            'source' => ReviewLog::SOURCE_SENSE_REVIEW,
        ]);
        try {
            $service->rollback($run->id);
            $this->fail('Rollback must refuse historical dependency drift.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('drifted', $exception->getMessage());
        }
        $this->assertSame(LegacyWordCardMigrationRun::STATE_APPLIED, $run->fresh()->state);
    }

    public function test_backup_retention_ids_are_loaded_inside_each_exclusive_apply(): void
    {
        $firstUser = $this->createUser('legacy-recovery-retention-one@example.com');
        $secondUser = $this->createUser('legacy-recovery-retention-two@example.com');
        $this->createUniqueMapping($firstUser, 'retention-one');
        $this->createUniqueMapping($secondUser, 'retention-two');
        $backupIds = [
            'c79ad5ce-4e47-48ef-bdc5-4fa77a21fb71',
            'f0d3f47b-54dc-4611-9592-1f57d8e610a7',
        ];
        $protectedCalls = [];
        $backup = Mockery::mock(BackupService::class);
        $backup->shouldReceive('withExclusiveOperation')->twice()->andReturnUsing(
            function (callable $operation) use (&$protectedCalls, &$backupIds) {
                return $operation(function (array $protected = []) use (&$protectedCalls, &$backupIds) {
                    $protectedCalls[] = $protected;

                    return ['backup_id' => array_shift($backupIds), 'sha256' => str_repeat('b', 64)];
                });
            },
        );
        $backup->shouldReceive('inspectBackup')->twice()->andReturnUsing(
            fn (string $backupId) => [
                'manifest' => ['backup_id' => $backupId, 'sha256' => str_repeat('b', 64)],
                'manifest_sha256' => str_repeat('a', 64),
                'payload_path' => 'unused',
            ],
        );
        $service = $this->service($backup);

        $firstRun = $service->apply($service->plan($firstUser->id, 'english'));
        $service->apply($service->plan($secondUser->id, 'english'));

        $this->assertSame([], $protectedCalls[0]);
        $this->assertSame([$firstRun->backup_id], $protectedCalls[1]);
    }

    public function test_rollback_refuses_to_delete_a_created_sense_card_with_new_dependencies(): void
    {
        $user = $this->createUser('legacy-recovery-created-dependency@example.com');
        [$legacy] = $this->createUniqueMapping($user, 'created-dependency', 5);
        $service = $this->service($this->successfulBackup());
        $run = $service->apply($service->plan($user->id, 'english'));
        $item = $run->items->first();
        $this->assertTrue($item->created_sense_card);
        $log = ReviewLog::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'review_card_id' => $item->sense_review_card_id,
            'rating' => 'good',
            'reviewed_at' => '2026-08-12 03:00:00',
            'previous_state' => 'review',
            'new_state' => 'review',
            'source' => ReviewLog::SOURCE_SENSE_REVIEW,
        ]);

        try {
            $service->rollback($run->id);
            $this->fail('Rollback must preserve a created Sense card that acquired dependencies.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('dependencies', $exception->getMessage());
        }

        $this->assertSame(LegacyWordCardMigrationRun::STATE_APPLIED, $run->fresh()->state);
        $this->assertSame(ReviewCard::LIFECYCLE_ARCHIVED, $legacy->fresh()->lifecycle_state);
        $this->assertDatabaseHas('review_cards', ['id' => $item->sense_review_card_id]);
        $this->assertDatabaseHas('review_logs', ['id' => $log->id]);
    }

    private function service(
        BackupService $backup,
        ?ReviewCardOperationSnapshotService $snapshots = null,
    ): LegacyWordCardMigrationRecoveryService {
        return new LegacyWordCardMigrationRecoveryService(
            app(LegacyWordCardMigrationClassifier::class),
            $backup,
            $snapshots ?? app(ReviewCardOperationSnapshotService::class),
        );
    }

    private function successfulBackup(): BackupService
    {
        $backupId = '6ebd9fa5-74b6-4f10-8d43-e62e98071ef2';
        $manifest = ['backup_id' => $backupId, 'sha256' => str_repeat('b', 64)];
        $backup = Mockery::mock(BackupService::class);
        $backup->shouldReceive('withExclusiveOperation')->andReturnUsing(
            fn (callable $operation) => $operation(fn (array $protected = []) => $manifest),
        );
        $backup->shouldReceive('inspectBackup')->andReturn([
            'manifest' => $manifest,
            'manifest_sha256' => str_repeat('a', 64),
            'payload_path' => 'unused',
        ]);

        return $backup;
    }

    private function createUniqueMapping(User $user, string $name, int $marker = 0): array
    {
        $word = $this->createWord($user, $name);
        $card = $this->createWordCard($user, $word, $marker);

        return [$card, $this->createSense($user, $word, $name)];
    }

    private function createUser(string $email): User
    {
        return User::forceCreate([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function createWord(User $user, string $word): EncounteredWord
    {
        return EncounteredWord::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'stage' => -1,
            'word' => $word,
            'lemma' => $word,
            'study_base' => $word,
            'kanji' => '',
            'reading' => '',
            'translation' => "{$word} translation",
            'base_word' => '',
            'base_word_reading' => '',
            'lookup_count' => 0,
            'read_count' => 0,
            'relearning' => false,
        ]);
    }

    private function createWordCard(User $user, EncounteredWord $word, int $marker = 0): ReviewCard
    {
        return ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => $word->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => '2026-08-12 01:00:00',
            'fsrs_stability' => 4.5,
            'fsrs_difficulty' => 6.5,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 1,
            'fsrs_enabled' => true,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
            'marker' => $marker,
        ]);
    }

    private function createSense(User $user, EncounteredWord $word, string $name): WordSense
    {
        return WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'encountered_word_id' => $word->id,
            'lemma' => $name,
            'surface_form' => $name,
            'pos' => 'noun',
            'sense_key' => hash('sha256', (string) Str::uuid()),
            'sense_zh' => "{$name} zh",
            'sense_en' => "{$name} en",
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
    }

    private function createSenseCard(User $user, WordSense $sense, int $marker): ReviewCard
    {
        return ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'learning',
            'fsrs_due_at' => '2026-08-13 01:00:00',
            'fsrs_enabled' => true,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
            'marker' => $marker,
        ]);
    }

    private function createDependencies(
        User $user,
        ReviewCard $card,
        WordSense $sense,
        ReviewLog $log,
    ): void {
        $now = '2026-08-12 01:02:00';
        WordSenseOccurrence::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'review_card_id' => $card->id,
            'sentence_id' => 'legacy-recovery-dependency',
            'sentence_en' => 'A stable sentence.',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'create',
            'lemma' => 'create',
            'decision' => 'matched_existing',
            'confidence' => 0.9,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_SENSE_MAPPING_IMPORT,
        ]);
        DB::table('review_card_state_events')->insert([
            'user_id' => $user->id,
            'language_id' => 'english',
            'review_card_id' => $card->id,
            'action' => 'suspend',
            'request_id' => (string) Str::uuid(),
            'created_at' => $now,
        ]);
        $snapshotId = DB::table('reschedule_snapshots')->insertGetId([
            'user_id' => $user->id,
            'language_id' => 'english',
            'batch_id' => (string) Str::uuid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('reschedule_snapshot_items')->insert([
            'reschedule_snapshot_id' => $snapshotId,
            'review_card_id' => $card->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('operations')->insert([
            'operation_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'language_id' => 'english',
            'operation_type' => 'review',
            'scope_type' => 'review_card',
            'scope_id' => (string) $card->id,
            'status' => 'active',
            'review_log_id' => $log->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('reading_session_interactions')->insert([
            'reading_session_id' => 91001,
            'user_id' => $user->id,
            'language_id' => 'english',
            'interaction_key' => 'legacy-recovery-interaction',
            'interaction_type' => 'explicit_rated',
            'word_sense_id' => $sense->id,
            'review_card_id' => $card->id,
            'review_log_id' => $log->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('reading_session_card_settlements')->insert([
            'reading_session_id' => 91001,
            'user_id' => $user->id,
            'language_id' => 'english',
            'review_card_id' => $card->id,
            'word_sense_id' => $sense->id,
            'review_log_id' => $log->id,
            'rating' => 'good',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function cardState(ReviewCard $card): array
    {
        return [
            'target_type' => $card->target_type,
            'target_id' => (int) $card->target_id,
            'fsrs_state' => $card->fsrs_state,
            'fsrs_step_index' => $card->fsrs_step_index,
            'fsrs_due_at' => $card->fsrs_due_at?->toIso8601String(),
            'fsrs_stability' => $card->fsrs_stability,
            'fsrs_difficulty' => $card->fsrs_difficulty,
            'fsrs_reps' => (int) $card->fsrs_reps,
            'fsrs_lapses' => (int) $card->fsrs_lapses,
            'fsrs_enabled' => (bool) $card->fsrs_enabled,
            'lifecycle_state' => $card->lifecycle_state,
            'buried_until' => $card->buried_until?->toIso8601String(),
            'lifecycle_version' => (int) $card->lifecycle_version,
            'marker' => (int) $card->marker,
            'created_at' => $card->getRawOriginal('created_at'),
            'updated_at' => $card->getRawOriginal('updated_at'),
        ];
    }

    private function allCards(): array
    {
        return ReviewCard::query()->orderBy('id')->get()
            ->mapWithKeys(fn (ReviewCard $card) => [$card->id => $this->cardState($card)])
            ->all();
    }

    private function operationalState(array $state): array
    {
        unset($state['target_type'], $state['target_id'], $state['created_at'], $state['updated_at']);

        return $state;
    }

    private function dependencyFingerprint(): string
    {
        $rows = [];
        foreach ([
            'review_logs',
            'review_card_state_events',
            'reschedule_snapshot_items',
            'operations',
            'reading_session_interactions',
            'reading_session_card_settlements',
            'word_sense_occurrences',
        ] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
