<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\EncounteredWord;
use App\Models\Goal;
use App\Models\LegacyWordCardMigrationItem;
use App\Models\LegacyWordCardMigrationRun;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\BackupService;
use App\Services\ChapterService;
use App\Services\LegacyWordCardMigrationProtectionService;
use App\Services\ReviewCardService;
use App\Services\UserService;
use App\Services\VocabularyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LegacyWordCardMigrationCutoverTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createUser('d04-cutover@example.com', 'english');
    }

    public function test_controlled_command_refuses_non_testing_mutation_and_invalid_modes_without_writes(): void
    {
        $originalEnvironment = $this->app['env'];

        try {
            $this->app['env'] = 'production';

            $this->artisan('reviews:migrate-legacy-word-cards', [
                '--user_id' => $this->user->id,
                '--language' => 'english',
                '--apply' => true,
            ])->assertExitCode(1);

            $this->artisan('reviews:migrate-legacy-word-cards', [
                '--rollback' => '1',
            ])->assertExitCode(1);
        } finally {
            $this->app['env'] = $originalEnvironment;
        }

        $this->artisan('reviews:migrate-legacy-word-cards', [
            '--user_id' => $this->user->id,
            '--language' => 'English',
        ])->assertExitCode(1);
        $this->artisan('reviews:migrate-legacy-word-cards', [
            '--apply' => true,
            '--rollback' => '1',
            '--user_id' => $this->user->id,
            '--language' => 'english',
        ])->assertExitCode(1);
        $this->artisan('reviews:migrate-legacy-word-cards', [
            '--rollback' => '1',
            '--user_id' => $this->user->id,
        ])->assertExitCode(1);

        $this->assertDatabaseCount('legacy_word_card_migration_runs', 0);
        $this->assertDatabaseCount('legacy_word_card_migration_items', 0);
    }

    public function test_testing_plan_apply_and_rollback_reuse_recovery_and_preserve_legacy_history(): void
    {
        [$word, $legacyCard, $sense] = $this->createUniqueMapping('command-unique');
        $ambiguousWord = $this->createWord('command-ambiguous');
        $ambiguousCard = $this->createLegacyWordCard($ambiguousWord);
        $this->createSense($ambiguousWord, 'command-ambiguous-one');
        $this->createSense($ambiguousWord, 'command-ambiguous-two');
        $log = ReviewLog::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'review_card_id' => $legacyCard->id,
            'rating' => 'good',
            'reviewed_at' => now()->subHour(),
            'previous_state' => 'new',
            'new_state' => 'learning',
            'source' => ReviewLog::SOURCE_SENSE_REVIEW,
        ]);
        DB::table('review_card_state_events')->insert([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'review_card_id' => $legacyCard->id,
            'action' => 'suspend',
            'request_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);
        $historyBefore = (array) DB::table('review_logs')->where('id', $log->id)->first();
        $dependencyBefore = (array) DB::table('review_card_state_events')
            ->where('review_card_id', $legacyCard->id)
            ->first();
        $legacyBefore = $this->cardSnapshot($legacyCard);
        $ambiguousBefore = $this->cardSnapshot($ambiguousCard);
        $this->bindSuccessfulBackup();

        $this->artisan('reviews:migrate-legacy-word-cards', [
            '--user_id' => $this->user->id,
            '--language' => 'english',
        ])->assertSuccessful();
        $this->assertDatabaseCount('legacy_word_card_migration_runs', 0);

        $this->artisan('reviews:migrate-legacy-word-cards', [
            '--user_id' => $this->user->id,
            '--language' => 'english',
            '--apply' => true,
        ])->assertSuccessful();

        $run = LegacyWordCardMigrationRun::query()->firstOrFail();
        $this->assertSame(LegacyWordCardMigrationRun::STATE_APPLIED, $run->state);
        $this->assertSame(ReviewCard::LIFECYCLE_ARCHIVED, $legacyCard->fresh()->lifecycle_state);
        $this->assertDatabaseHas('review_cards', [
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
        ]);
        $this->assertSame($ambiguousBefore, $this->cardSnapshot($ambiguousCard->fresh()));
        $this->assertEquals($historyBefore, (array) DB::table('review_logs')->where('id', $log->id)->first());
        $this->assertEquals($dependencyBefore, (array) DB::table('review_card_state_events')
            ->where('review_card_id', $legacyCard->id)
            ->first());

        $this->artisan('reviews:migrate-legacy-word-cards', [
            '--rollback' => (string) $run->id,
        ])->assertSuccessful();

        $this->assertSame(LegacyWordCardMigrationRun::STATE_ROLLED_BACK, $run->fresh()->state);
        $this->assertSame($legacyBefore, $this->cardSnapshot($legacyCard->fresh()));
        $this->assertSame($ambiguousBefore, $this->cardSnapshot($ambiguousCard->fresh()));
        $this->assertEquals($historyBefore, (array) DB::table('review_logs')->where('id', $log->id)->first());
        $this->assertEquals($dependencyBefore, (array) DB::table('review_card_state_events')
            ->where('review_card_id', $legacyCard->id)
            ->first());
        $this->assertFalse(app(LegacyWordCardMigrationProtectionService::class)
            ->isEncounteredWordProtected($word->fresh()));
    }

    public function test_word_creation_initialization_doctor_csv_and_formal_rating_are_cut_over(): void
    {
        $word = $this->createWord('no-create');
        $service = app(ReviewCardService::class);

        $this->assertNull($service->ensureWordCard($word));
        $this->assertSame(0, $service->initializeExistingWords($this->user->id, 'english'));
        $this->assertFalse($this->legacyCardExists($word));

        $this->artisan('reviews:initialize-cards', [
            '--user_id' => $this->user->id,
            '--language' => 'english',
        ])->assertExitCode(1);
        $this->artisan('fsrs:doctor', [
            '--fix' => true,
            '--user_id' => $this->user->id,
            '--language' => 'english',
        ])->assertSuccessful();
        $this->assertFalse($this->legacyCardExists($word));

        $stageWord = $this->createWord('explicit-negative', 2, ['translation' => '']);
        app(VocabularyService::class)->updateWord(
            $this->user->id,
            'english',
            $stageWord->id,
            [],
            -3,
        );
        $this->assertSame(-3, $stageWord->fresh()->stage);
        $this->assertFalse($this->legacyCardExists($stageWord));

        $csvName = $this->writeCsv("csv-cutover,,,,,1\n");
        try {
            app(VocabularyService::class)->importFromCsv(
                $this->user->id,
                'english',
                $csvName,
                ',',
                false,
                false,
            );
        } finally {
            File::delete(storage_path('app/temp/' . $csvName));
        }
        $csvWord = EncounteredWord::query()
            ->where('user_id', $this->user->id)
            ->where('language', 'english')
            ->where('word', 'csv-cutover')
            ->firstOrFail();
        $this->assertSame(-1, $csvWord->stage);
        $this->assertFalse($this->legacyCardExists($csvWord));

        $legacyCard = $this->createLegacyWordCard($word, [
            'fsrs_state' => 'review',
            'fsrs_reps' => 4,
            'fsrs_stability' => 5.5,
            'fsrs_difficulty' => 4.5,
        ]);
        $before = $this->cardSnapshot($legacyCard);
        $this->actingAs($this->user)->post('/reviews/rate', [
            'reviewCardId' => $legacyCard->id,
            'rating' => 'good',
        ])->assertStatus(422);
        $this->assertSame($before, $this->cardSnapshot($legacyCard->fresh()));
        $this->assertSame(0, ReviewLog::where('review_card_id', $legacyCard->id)->count());
    }

    public function test_applied_protection_blocks_direct_and_csv_stage_mutation_until_rolled_back(): void
    {
        [$word, $legacyCard, $sense] = $this->createUniqueMapping('protected-stage');
        $senseCard = $this->createSenseCard($sense);
        $run = $this->createAppliedProtection($word, $legacyCard, $sense, $senseCard);
        $service = app(VocabularyService::class);
        $stageBefore = $word->stage;
        $cardBefore = $this->cardSnapshot($legacyCard);

        try {
            $service->updateWord($this->user->id, 'english', $word->id, [], 0);
            $this->fail('Applied migration protection must refuse a direct stage mutation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('protected', $exception->getMessage());
        }
        $this->assertSame($stageBefore, $word->fresh()->stage);
        $this->assertSame($cardBefore, $this->cardSnapshot($legacyCard->fresh()));

        $csvName = $this->writeCsv("protected-stage,,,,,learned\n");
        try {
            try {
                $service->importFromCsv($this->user->id, 'english', $csvName, ',', false, false);
                $this->fail('Applied migration protection must refuse a CSV stage mutation.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('protected', $exception->getMessage());
            }
        } finally {
            File::delete(storage_path('app/temp/' . $csvName));
        }
        $this->assertSame($stageBefore, $word->fresh()->stage);
        $this->assertSame($cardBefore, $this->cardSnapshot($legacyCard->fresh()));

        $run->update([
            'state' => LegacyWordCardMigrationRun::STATE_ROLLED_BACK,
            'rolled_back_at' => now(),
        ]);
        $this->assertFalse(app(LegacyWordCardMigrationProtectionService::class)
            ->isEncounteredWordProtected($word->fresh()));

        $service->updateWord($this->user->id, 'english', $word->id, [], 0);
        $this->assertSame(0, $word->fresh()->stage);
    }

    public function test_applied_protection_blocks_chapter_finish_hard_delete_and_language_delete_before_side_effects(): void
    {
        [$word, $legacyCard, $sense] = $this->createUniqueMapping('protected-destructive');
        $senseCard = $this->createSenseCard($sense);
        $this->createAppliedProtection($word, $legacyCard, $sense, $senseCard);
        $log = ReviewLog::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'review_card_id' => $legacyCard->id,
            'rating' => 'hard',
            'reviewed_at' => now()->subHour(),
            'previous_state' => 'review',
            'new_state' => 'review',
            'source' => ReviewLog::SOURCE_SENSE_REVIEW,
        ]);
        $chapter = $this->createChapter();
        $chapterBefore = $chapter->only(['read_count', 'word_count']);
        $wordBefore = $word->only(['stage', 'read_count']);

        try {
            app(ChapterService::class)->finishChapter(
                $this->user->id,
                $chapter->id,
                false,
                [],
                true,
                [$word->id],
                [],
                'english',
            );
            $this->fail('Protected chapter stage mutation must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('protected', $exception->getMessage());
        }
        $this->assertEquals($chapterBefore, $chapter->fresh()->only(array_keys($chapterBefore)));
        $this->assertEquals($wordBefore, $word->fresh()->only(array_keys($wordBefore)));

        try {
            app(VocabularyService::class)->hardDeleteWordsByIds(
                $this->user->id,
                'english',
                [$word->id],
            );
            $this->fail('Protected hard delete must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('protected', $exception->getMessage());
        }
        $this->assertDatabaseHas('encountered_words', ['id' => $word->id]);
        $this->assertDatabaseHas('review_cards', ['id' => $legacyCard->id]);
        $this->assertDatabaseHas('review_logs', ['id' => $log->id]);
        $this->assertDatabaseHas('word_senses', ['id' => $sense->id]);

        try {
            app(UserService::class)->deleteUserLanguageData($this->user->id, 'english');
            $this->fail('Protected language deletion must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('protected', $exception->getMessage());
        }
        $this->assertDatabaseHas('encountered_words', ['id' => $word->id]);
        $this->assertDatabaseHas('chapters', ['id' => $chapter->id]);
        $this->assertDatabaseHas('review_cards', ['id' => $legacyCard->id]);
        $this->assertDatabaseHas('review_logs', ['id' => $log->id]);
    }

    public function test_goal_due_quantity_and_formal_queue_use_confirmed_eligible_due_sense_authority_only(): void
    {
        $legacyWord = $this->createWord('goal-legacy');
        $this->createLegacyWordCard($legacyWord, ['fsrs_due_at' => now()->subMinute()]);

        $dueWord = $this->createWord('goal-sense');
        $dueSense = $this->createSense($dueWord, 'goal-sense');
        $dueCard = $this->createSenseCard($dueSense, ['fsrs_due_at' => now()->subMinute()]);

        $futureWord = $this->createWord('goal-future');
        $futureSense = $this->createSense($futureWord, 'goal-future');
        $this->createSenseCard($futureSense, ['fsrs_due_at' => now()->addDay()]);

        $rejectedWord = $this->createWord('goal-rejected');
        $rejectedSense = $this->createSense($rejectedWord, 'goal-rejected');
        $rejectedSense->update(['status' => WordSense::STATUS_REJECTED]);
        $this->createSenseCard($rejectedSense, ['fsrs_due_at' => now()->subMinute()]);

        $otherUser = $this->createUser('d04-other@example.com', 'english');
        $otherWord = $this->createWordFor($otherUser, 'goal-other');
        $otherSense = $this->createSense($otherWord, 'goal-other');
        $this->createSenseCard($otherSense, ['fsrs_due_at' => now()->subMinute()]);

        $this->actingAs($this->user);
        $this->assertSame(1, (new Goal())->getTodaysReviewGoalQuantity());

        $response = $this->post('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
        ]);
        $response->assertOk();
        $reviews = $response->json('reviews');
        $this->assertNotEmpty($reviews);
        $this->assertSame([$dueCard->id], array_values(array_unique(array_column($reviews, 'review_card_id'))));
        $this->assertSame(['sense'], array_values(array_unique(array_column($reviews, 'type'))));
    }

    private function bindSuccessfulBackup(): void
    {
        $backupId = (string) Str::uuid();
        $manifest = ['backup_id' => $backupId, 'sha256' => str_repeat('b', 64)];
        $backup = Mockery::mock(BackupService::class);
        $backup->shouldReceive('withExclusiveOperation')->once()->andReturnUsing(
            fn (callable $operation) => $operation(fn (array $protected = []) => $manifest),
        );
        $backup->shouldReceive('inspectBackup')->once()->andReturn([
            'manifest' => $manifest,
            'manifest_sha256' => str_repeat('a', 64),
            'payload_path' => 'unused',
        ]);
        $this->app->instance(BackupService::class, $backup);
    }

    private function createAppliedProtection(
        EncounteredWord $word,
        ReviewCard $legacyCard,
        WordSense $sense,
        ReviewCard $senseCard,
    ): LegacyWordCardMigrationRun {
        $fingerprint = hash('sha256', (string) Str::uuid());
        $run = LegacyWordCardMigrationRun::create([
            'run_uuid' => (string) Str::uuid(),
            'schema_version' => 'legacy_word_card_migration_plan_v1',
            'classifier_schema_version' => 'legacy_word_card_classification_v1',
            'report_fingerprint' => hash('sha256', 'report-' . $fingerprint),
            'plan_fingerprint' => hash('sha256', 'plan-' . $fingerprint),
            'backup_id' => (string) Str::uuid(),
            'backup_manifest_sha256' => str_repeat('a', 64),
            'backup_payload_sha256' => str_repeat('b', 64),
            'filters' => ['user_id' => $word->user_id, 'language' => $word->language],
            'counts' => ['unique_mapping' => 1],
            'state' => LegacyWordCardMigrationRun::STATE_APPLIED,
            'applied_at' => now(),
        ]);

        LegacyWordCardMigrationItem::create([
            'run_id' => $run->id,
            'legacy_review_card_id' => $legacyCard->id,
            'encountered_word_id' => $word->id,
            'word_sense_id' => $sense->id,
            'sense_review_card_id' => $senseCard->id,
            'user_id' => $word->user_id,
            'language_id' => $word->language,
            'created_sense_card' => false,
            'classification' => 'unique_mapping',
            'primary_reason_code' => 'unique_confirmed_direct_candidate',
            'reason_codes' => ['unique_confirmed_direct_candidate'],
            'before_classification_evidence' => [],
            'before_classification_fingerprint' => str_repeat('c', 64),
            'after_classification_evidence' => [],
            'after_classification_fingerprint' => str_repeat('d', 64),
            'before_legacy_snapshot' => [],
            'before_legacy_fingerprint' => str_repeat('e', 64),
            'after_legacy_snapshot' => [],
            'after_legacy_fingerprint' => str_repeat('f', 64),
            'before_sense_snapshot' => [],
            'before_sense_fingerprint' => str_repeat('1', 64),
            'after_sense_snapshot' => [],
            'after_sense_fingerprint' => str_repeat('2', 64),
        ]);

        return $run;
    }

    private function createUniqueMapping(string $name): array
    {
        $word = $this->createWord($name);
        $legacyCard = $this->createLegacyWordCard($word, [
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subHour(),
            'fsrs_stability' => 4.5,
            'fsrs_difficulty' => 6.5,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 1,
        ]);
        $sense = $this->createSense($word, $name);

        return [$word, $legacyCard, $sense];
    }

    private function createUser(string $email, string $language): User
    {
        $user = User::forceCreate([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        foreach ([
            ['review', 'Reviews', 0],
            ['read_words', 'Reading', 1000],
            ['learn_words', 'New words', 10],
        ] as [$type, $name, $quantity]) {
            Goal::forceCreate([
                'user_id' => $user->id,
                'language' => $language,
                'type' => $type,
                'name' => $name,
                'quantity' => $quantity,
            ]);
        }

        return $user;
    }

    private function createWord(string $word, int $stage = -1, array $overrides = []): EncounteredWord
    {
        return $this->createWordFor($this->user, $word, $stage, $overrides);
    }

    private function createWordFor(User $user, string $word, int $stage = -1, array $overrides = []): EncounteredWord
    {
        return EncounteredWord::forceCreate(array_merge([
            'user_id' => $user->id,
            'language' => $user->selected_language,
            'stage' => $stage,
            'word' => $word,
            'lemma' => $word,
            'study_base' => $word,
            'kanji' => '',
            'reading' => '',
            'translation' => '',
            'base_word' => '',
            'base_word_reading' => '',
            'lookup_count' => 0,
            'read_count' => 0,
            'relearning' => false,
        ], $overrides));
    }

    private function createLegacyWordCard(EncounteredWord $word, array $overrides = []): ReviewCard
    {
        return ReviewCard::forceCreate(array_merge([
            'user_id' => $word->user_id,
            'language_id' => $word->language,
            'language' => $word->language,
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => $word->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ], $overrides));
    }

    private function createSense(EncounteredWord $word, string $name): WordSense
    {
        return WordSense::forceCreate([
            'user_id' => $word->user_id,
            'language' => $word->language,
            'language_id' => $word->language,
            'encountered_word_id' => $word->id,
            'lemma' => $name,
            'surface_form' => $name,
            'pos' => 'noun',
            'sense_key' => hash('sha256', $name . '|' . Str::uuid()),
            'sense_zh' => $name . ' zh',
            'sense_en' => $name . ' en',
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
    }

    private function createSenseCard(WordSense $sense, array $overrides = []): ReviewCard
    {
        return ReviewCard::forceCreate(array_merge([
            'user_id' => $sense->user_id,
            'language_id' => $sense->language_id,
            'language' => $sense->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ], $overrides));
    }

    private function createChapter(): Chapter
    {
        return Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => 1,
            'name' => 'D-04 protected chapter',
            'read_count' => 0,
            'word_count' => 10,
            'language' => 'english',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'raw_text' => '',
            'type' => 'text',
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
            'processed_text' => gzcompress('[]', 1),
        ]);
    }

    private function writeCsv(string $contents): string
    {
        File::ensureDirectoryExists(storage_path('app/temp'));
        $name = 'd04-cutover-' . Str::uuid() . '.csv';
        File::put(storage_path('app/temp/' . $name), $contents);

        return $name;
    }

    private function legacyCardExists(EncounteredWord $word): bool
    {
        return ReviewCard::query()
            ->where('user_id', $word->user_id)
            ->where('language_id', $word->language)
            ->where('target_type', ReviewCard::TARGET_WORD)
            ->where('target_id', $word->id)
            ->exists();
    }

    private function cardSnapshot(ReviewCard $card): array
    {
        $persisted = $card->fresh() ?? $card;
        $fields = [
            'user_id',
            'language_id',
            'language',
            'target_type',
            'target_id',
            'fsrs_state',
            'fsrs_step_index',
            'fsrs_due_at',
            'fsrs_stability',
            'fsrs_difficulty',
            'fsrs_reps',
            'fsrs_lapses',
            'fsrs_enabled',
            'lifecycle_state',
            'buried_until',
            'lifecycle_version',
            'marker',
        ];

        return collect($fields)
            ->mapWithKeys(fn (string $field): array => [$field => $persisted->getRawOriginal($field)])
            ->all();
    }
}
