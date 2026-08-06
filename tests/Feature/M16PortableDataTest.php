<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\AnkiWordSensePackageService;
use App\Services\BackupService;
use App\Services\PortableDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PDO;
use Tests\TestCase;
use ZipArchive;

class M16PortableDataTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::forceCreate([
            'name' => 'M16 User',
            'email' => 'm16@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_fixed_apkg_round_trips_and_defaults_to_new_queue(): void
    {
        [$sense] = $this->senseCard('portable');
        $service = app(AnkiWordSensePackageService::class);
        $package = $service->build(collect([$this->exportItem($sense)]), false);

        try {
            $this->assertFileExists($package['path']);
            $this->assertSame(1, $package['count']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $package['sha256']);
            $this->assertSame('portable', $service->parse($package['path'])[0]['lemma']);
            $this->assertSame([0, 0, 0], $this->cardSchedule($package['path']));
        } finally {
            $service->cleanupPackage($package['path']);
        }
    }

    public function test_explicit_schedule_export_maps_review_card_without_revlog(): void
    {
        [$sense] = $this->senseCard('scheduled');
        $service = app(AnkiWordSensePackageService::class);
        $package = $service->build(collect([$this->exportItem($sense)]), true);
        try {
            [$type, $queue, $reps] = $this->cardSchedule($package['path']);
            $this->assertSame(2, $type);
            $this->assertSame(2, $queue);
            $this->assertSame(3, $reps);
            $this->assertSame(0, $this->revlogCount($package['path']));
        } finally {
            $service->cleanupPackage($package['path']);
        }
    }

    public function test_content_envelope_omits_scheduling_unless_explicit(): void
    {
        [$sense] = $this->senseCard('content-schedule');
        $service = app(PortableDataService::class);
        $default = $service->contentEnvelope([$this->exportItem($sense)], $this->user->id, 'english');
        $explicit = $service->contentEnvelope([$this->exportItem($sense)], $this->user->id, 'english', true);

        $this->assertFalse($default['include_scheduling']);
        $this->assertSame('', $default['items'][0]['fsrs_state']);
        $this->assertTrue($explicit['include_scheduling']);
        $this->assertSame('review', $explicit['items'][0]['fsrs_state']);
    }

    public function test_json_preview_classifies_create_and_apply_is_backup_gated(): void
    {
        $item = $this->contentItem('lc-sense:aaaaaaaaaaaaaaaa:999999', 'created');
        $file = $this->jsonUpload([$item]);
        $preview = $this->actingAs($this->user)
            ->post('/review-cards/manage/portable/import-preview', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('counts.create', 1)
            ->assertJsonPath('can_apply', true);

        $backupId = (string) Str::uuid();
        $this->mock(BackupService::class, function (MockInterface $mock) use ($backupId) {
            $mock->shouldReceive('createBackup')->once()->andReturn(['backup_id' => $backupId]);
        });
        $this->actingAs($this->user)->postJson('/review-cards/manage/portable/import-apply', [
            'preview_token' => $preview->json('preview_token'),
            'confirm' => true,
        ])->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('backup_id', $backupId);

        $this->assertDatabaseHas('word_senses', [
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'lemma' => 'created',
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseCount('review_logs', 0);
    }

    public function test_csv_uses_frozen_header_and_reaches_controlled_preview(): void
    {
        $item = $this->contentItem('lc-sense:aaaaaaaaaaaaaaaa:999998', 'csv-created');
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, PortableDataService::CONTENT_FIELDS);
        fputcsv($stream, array_map(
            fn ($field) => is_array($item[$field]) ? implode(', ', $item[$field]) : $item[$field],
            PortableDataService::CONTENT_FIELDS,
        ));
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $this->actingAs($this->user)->post('/review-cards/manage/portable/import-preview', [
            'file' => UploadedFile::fake()->createWithContent('portable.csv', $csv),
        ])->assertOk()
            ->assertJsonPath('source_kind', 'csv')
            ->assertJsonPath('counts.create', 1);
    }

    public function test_origin_namespace_prevents_cross_user_numeric_id_collision(): void
    {
        $other = User::forceCreate([
            'name' => 'Other', 'email' => 'm16-other@example.test',
            'password' => Hash::make('password'), 'selected_language' => 'english',
            'password_changed' => true, 'uuid' => (string) Str::uuid(),
        ]);
        $sense = WordSense::forceCreate([
            'user_id' => $other->id, 'language' => 'english', 'language_id' => 'english',
            'lemma' => 'owned', 'surface_form' => 'owned', 'pos' => 'noun',
            'sense_zh' => '他人', 'sense_en' => 'owned', 'aliases_zh' => [],
            'collocations' => [], 'status' => WordSense::STATUS_CONFIRMED,
            'sense_key' => hash('sha256', Str::uuid()),
        ]);
        $preview = $this->actingAs($this->user)->post('/review-cards/manage/portable/import-preview', [
            'file' => $this->jsonUpload([$this->contentItem($this->externalId($sense->id, $other->id), 'owned')]),
        ])->assertOk();
        $this->assertSame(1, $preview->json('counts.create'));
        $this->assertSame(0, $preview->json('counts.conflict'));
        $this->assertTrue($preview->json('can_apply'));
        $this->assertSame('owned', $sense->fresh()->lemma);
    }

    public function test_forged_current_origin_cannot_target_another_users_numeric_id(): void
    {
        $other = User::forceCreate([
            'name' => 'Forged Target', 'email' => 'm16-forged@example.test',
            'password' => Hash::make('password'), 'selected_language' => 'english',
            'password_changed' => true, 'uuid' => (string) Str::uuid(),
        ]);
        $sense = WordSense::forceCreate([
            'user_id' => $other->id, 'language' => 'english', 'language_id' => 'english',
            'lemma' => 'protected', 'surface_form' => 'protected', 'pos' => 'noun',
            'sense_zh' => '受保护', 'sense_en' => 'protected', 'aliases_zh' => [],
            'collocations' => [], 'status' => WordSense::STATUS_CONFIRMED,
            'sense_key' => hash('sha256', Str::uuid()),
        ]);
        $preview = $this->actingAs($this->user)->post('/review-cards/manage/portable/import-preview', [
            'file' => $this->jsonUpload([$this->contentItem(
                $this->externalId($sense->id, $this->user->id),
                'forged',
            )]),
        ])->assertOk();
        $this->assertSame(1, $preview->json('counts.conflict'));
        $this->assertFalse($preview->json('can_apply'));
        $this->assertSame('protected', $sense->fresh()->lemma);
    }

    public function test_apply_rejects_database_drift_before_backup_or_write(): void
    {
        [$sense] = $this->senseCard('drift');
        $item = $this->contentItem($this->externalId($sense->id, $this->user->id), 'drift');
        $item['sense_en'] = 'incoming';
        $preview = $this->actingAs($this->user)->post('/review-cards/manage/portable/import-preview', [
            'file' => $this->jsonUpload([$item]),
        ])->assertOk();
        $sense->sense_en = 'changed after preview';
        $sense->save();

        $this->mock(BackupService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('createBackup');
        });
        $this->actingAs($this->user)->postJson('/review-cards/manage/portable/import-apply', [
            'preview_token' => $preview->json('preview_token'), 'confirm' => true,
        ])->assertUnprocessable();
        $this->assertSame('changed after preview', $sense->fresh()->sense_en);
    }

    public function test_preview_candidate_loading_uses_bounded_queries_for_many_items(): void
    {
        $items = [];
        foreach (range(1, 25) as $index) {
            [$sense] = $this->senseCard("bounded-{$index}");
            $items[] = $this->contentItem(
                $this->externalId($sense->id, $this->user->id),
                $sense->lemma,
            );
        }
        $upload = $this->jsonUpload($items);
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(PortableDataService::class)->preview(
            $upload,
            $this->user->id,
            'english',
        );
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(12, $queryCount);
    }

    public function test_full_package_has_manifest_checksums_and_can_be_previewed(): void
    {
        [$sense] = $this->senseCard('full-package');
        $service = app(PortableDataService::class);
        $package = $service->buildFullPackage([$this->exportItem($sense)], $this->user->id, 'english');
        try {
            $upload = new UploadedFile($package['path'], 'portable.lcpkg', 'application/zip', null, true);
            $this->actingAs($this->user)->post('/review-cards/manage/portable/import-preview', ['file' => $upload])
                ->assertOk()
                ->assertJsonPath('source_kind', 'lcpkg')
                ->assertJsonPath('counts.skip', 1);
        } finally {
            $service->cleanupPackage($package['path']);
        }
    }

    public function test_full_package_manifest_counts_only_exported_sense_history(): void
    {
        [$sense] = $this->senseCard('sense-history-scope');
        $wordCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => 999999,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->addDay(),
            'fsrs_stability' => 2,
            'fsrs_difficulty' => 5,
            'fsrs_reps' => 1,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
            'lifecycle_version' => 1,
        ]);
        ReviewLog::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'review_card_id' => $wordCard->id,
            'rating' => 'good',
            'reviewed_at' => now()->subHour(),
            'previous_state' => 'learning',
            'new_state' => 'review',
            'source' => 'legacy_word',
        ]);
        $service = app(PortableDataService::class);
        $package = $service->buildFullPackage(
            [$this->exportItem($sense)],
            $this->user->id,
            'english',
        );

        try {
            $this->actingAs($this->user)->post(
                '/review-cards/manage/portable/import-preview',
                ['file' => new UploadedFile(
                    $package['path'],
                    'portable.lcpkg',
                    'application/zip',
                    null,
                    true,
                )],
            )->assertOk()
                ->assertJsonPath('counts.history', 0);
        } finally {
            $service->cleanupPackage($package['path']);
        }
    }

    public function test_full_package_restores_validated_schedule_without_review_log(): void
    {
        [$sense, $card] = $this->senseCard('schedule-restore');
        $service = app(PortableDataService::class);
        $package = $service->buildFullPackage([$this->exportItem($sense)], $this->user->id, 'english');
        try {
            $card->forceFill(['fsrs_state' => 'new', 'fsrs_reps' => 0, 'fsrs_lapses' => 0])->save();
            $upload = new UploadedFile($package['path'], 'portable.lcpkg', 'application/zip', null, true);
            $preview = $this->actingAs($this->user)->post('/review-cards/manage/portable/import-preview', [
                'file' => $upload,
            ])->assertOk()->assertJsonPath('counts.update', 1);
            $this->mock(BackupService::class, function (MockInterface $mock) {
                $mock->shouldReceive('createBackup')->once()->andReturn(['backup_id' => (string) Str::uuid()]);
            });
            $this->actingAs($this->user)->postJson('/review-cards/manage/portable/import-apply', [
                'preview_token' => $preview->json('preview_token'), 'confirm' => true,
            ])->assertOk()->assertJsonPath('updated', 1);
            $this->assertSame('review', $card->fresh()->fsrs_state);
            $this->assertSame(3, $card->fresh()->fsrs_reps);
            $this->assertDatabaseCount('review_logs', 0);
        } finally {
            $service->cleanupPackage($package['path']);
        }
    }

    public function test_full_package_recreates_article_structure_and_safe_settings(): void
    {
        [$sense, $card] = $this->senseCard('logical-package');
        $log = ReviewLog::forceCreate([
            'user_id' => $this->user->id, 'language' => 'english', 'language_id' => 'english',
            'review_card_id' => $card->id, 'rating' => 'good', 'reviewed_at' => now()->subHour(),
            'review_duration_ms' => 1200, 'previous_state' => 'learning', 'new_state' => 'review',
            'previous_due_at' => now()->subHour(), 'new_due_at' => now()->addDays(5),
            'previous_stability' => 1.2, 'new_stability' => 5.0,
            'previous_difficulty' => 5.5, 'new_difficulty' => 5.0,
            'source' => ReviewLog::SOURCE_SENSE_REVIEW,
        ]);
        $book = Book::forceCreate([
            'user_id' => $this->user->id, 'name' => 'Portable Book',
            'cover_image' => null, 'language' => 'english',
        ]);
        $chapter = Chapter::forceCreate([
            'user_id' => $this->user->id, 'book_id' => $book->id,
            'read_count' => 2, 'word_count' => 3, 'name' => 'Chapter One',
            'language' => 'english', 'raw_text' => 'Portable article text.',
            'unique_words' => '', 'subtitle_timestamps' => '', 'type' => 'text',
            'processing_status' => 'unprocessed',
        ]);
        $chapterTwo = Chapter::forceCreate([
            'user_id' => $this->user->id, 'book_id' => $book->id,
            'read_count' => 0, 'word_count' => 2, 'name' => 'Chapter Two',
            'language' => 'english', 'raw_text' => 'Second portable chapter.',
            'unique_words' => '', 'subtitle_timestamps' => '', 'type' => 'text',
            'processing_status' => 'unprocessed',
        ]);
        $setting = Setting::forceCreate([
            'user_id' => $this->user->id, 'name' => 'uiLanguage', 'value' => '"zh"',
        ]);
        $service = app(PortableDataService::class);
        $package = $service->buildFullPackage([$this->exportItem($sense)], $this->user->id, 'english');
        try {
            $chapter->delete();
            $chapterTwo->delete();
            $book->delete();
            $setting->delete();
            $log->delete();
            $preview = $this->actingAs($this->user)->post('/review-cards/manage/portable/import-preview', [
                'file' => new UploadedFile($package['path'], 'portable.lcpkg', 'application/zip', null, true),
            ])->assertOk()
                ->assertJsonPath('counts.articles', 2)
                ->assertJsonPath('counts.settings', 1)
                ->assertJsonPath('counts.history', 1);
            $this->mock(BackupService::class, function (MockInterface $mock) {
                $mock->shouldReceive('createBackup')->once()->andReturn(['backup_id' => (string) Str::uuid()]);
            });
            $this->actingAs($this->user)->postJson('/review-cards/manage/portable/import-apply', [
                'preview_token' => $preview->json('preview_token'), 'confirm' => true,
            ])->assertOk()
                ->assertJsonPath('articles', 2)
                ->assertJsonPath('settings', 1)
                ->assertJsonPath('history', 1);
            $this->assertDatabaseHas('books', [
                'user_id' => $this->user->id, 'language' => 'english', 'name' => 'Portable Book',
            ]);
            $this->assertDatabaseHas('chapters', [
                'user_id' => $this->user->id, 'language' => 'english',
                'name' => 'Chapter One', 'raw_text' => 'Portable article text.',
            ]);
            $this->assertDatabaseHas('chapters', [
                'user_id' => $this->user->id, 'language' => 'english',
                'name' => 'Chapter Two', 'raw_text' => 'Second portable chapter.',
            ]);
            $this->assertDatabaseHas('settings', [
                'user_id' => $this->user->id, 'name' => 'uiLanguage', 'value' => '"zh"',
            ]);
            $this->assertDatabaseHas('review_logs', [
                'user_id' => $this->user->id, 'review_card_id' => $card->id,
                'rating' => 'good', 'source' => 'restore:sense_review',
            ]);
        } finally {
            $service->cleanupPackage($package['path']);
        }
    }

    public function test_full_package_rejects_setting_drift_before_backup_or_write(): void
    {
        [$sense] = $this->senseCard('setting-drift');
        $setting = Setting::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'uiLanguage',
            'value' => '"zh"',
        ]);
        $service = app(PortableDataService::class);
        $package = $service->buildFullPackage(
            [$this->exportItem($sense)],
            $this->user->id,
            'english',
        );

        try {
            $preview = $this->actingAs($this->user)->post(
                '/review-cards/manage/portable/import-preview',
                ['file' => new UploadedFile(
                    $package['path'],
                    'portable.lcpkg',
                    'application/zip',
                    null,
                    true,
                )],
            )->assertOk();
            $setting->value = '"en"';
            $setting->save();
            $this->mock(BackupService::class, function (MockInterface $mock) {
                $mock->shouldNotReceive('createBackup');
            });

            $this->actingAs($this->user)->postJson(
                '/review-cards/manage/portable/import-apply',
                ['preview_token' => $preview->json('preview_token'), 'confirm' => true],
            )->assertUnprocessable();
            $this->assertSame('"en"', $setting->fresh()->value);
        } finally {
            $service->cleanupPackage($package['path']);
        }
    }

    public function test_full_package_rechecks_setting_drift_after_backup_inside_transaction(): void
    {
        [$sense] = $this->senseCard('setting-backup-race');
        $setting = Setting::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'uiLanguage',
            'value' => '"zh"',
        ]);
        $service = app(PortableDataService::class);
        $package = $service->buildFullPackage(
            [$this->exportItem($sense)],
            $this->user->id,
            'english',
        );

        try {
            $preview = $this->actingAs($this->user)->post(
                '/review-cards/manage/portable/import-preview',
                ['file' => new UploadedFile(
                    $package['path'],
                    'portable.lcpkg',
                    'application/zip',
                    null,
                    true,
                )],
            )->assertOk();
            $this->mock(BackupService::class, function (MockInterface $mock) use ($setting) {
                $mock->shouldReceive('createBackup')->once()->andReturnUsing(
                    function () use ($setting) {
                        $setting->value = '"en"';
                        $setting->save();
                        return ['backup_id' => (string) Str::uuid()];
                    },
                );
            });

            $this->actingAs($this->user)->postJson(
                '/review-cards/manage/portable/import-apply',
                ['preview_token' => $preview->json('preview_token'), 'confirm' => true],
            )->assertUnprocessable()
                ->assertJsonValidationErrors('preview_token');
            $this->assertSame('"en"', $setting->fresh()->value);
        } finally {
            $service->cleanupPackage($package['path']);
        }
    }

    public function test_full_package_maps_history_to_existing_cross_origin_equivalent_sense(): void
    {
        $sourceUser = User::forceCreate([
            'name' => 'M16 Source',
            'email' => 'm16-source@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        [$sourceSense, $sourceCard] = $this->senseCardFor($sourceUser, 'equivalent');
        ReviewLog::forceCreate([
            'user_id' => $sourceUser->id,
            'language' => 'english',
            'language_id' => 'english',
            'review_card_id' => $sourceCard->id,
            'rating' => 'good',
            'reviewed_at' => now()->subHour(),
            'previous_state' => 'learning',
            'new_state' => 'review',
            'source' => ReviewLog::SOURCE_SENSE_REVIEW,
        ]);
        [$targetSense, $targetCard] = $this->senseCardFor($this->user, 'equivalent');
        $targetCard->forceFill([
            'fsrs_state' => $sourceCard->fsrs_state,
            'fsrs_due_at' => $sourceCard->fsrs_due_at,
            'fsrs_last_reviewed_at' => $sourceCard->fsrs_last_reviewed_at,
            'fsrs_stability' => $sourceCard->fsrs_stability,
            'fsrs_difficulty' => $sourceCard->fsrs_difficulty,
            'fsrs_reps' => $sourceCard->fsrs_reps,
            'fsrs_lapses' => $sourceCard->fsrs_lapses,
        ])->save();
        $service = app(PortableDataService::class);
        $package = $service->buildFullPackage(
            [$this->exportItem($sourceSense)],
            $sourceUser->id,
            'english',
        );

        try {
            $preview = $this->actingAs($this->user)->post(
                '/review-cards/manage/portable/import-preview',
                ['file' => new UploadedFile(
                    $package['path'],
                    'portable.lcpkg',
                    'application/zip',
                    null,
                    true,
                )],
            )->assertOk()
                ->assertJsonPath('counts.skip', 1)
                ->assertJsonPath('counts.history', 1);
            $this->mock(BackupService::class, function (MockInterface $mock) {
                $mock->shouldReceive('createBackup')
                    ->once()
                    ->andReturn(['backup_id' => (string) Str::uuid()]);
            });
            $this->actingAs($this->user)->postJson(
                '/review-cards/manage/portable/import-apply',
                ['preview_token' => $preview->json('preview_token'), 'confirm' => true],
            )->assertOk()->assertJsonPath('history', 1);
            $this->assertDatabaseHas('review_logs', [
                'user_id' => $this->user->id,
                'review_card_id' => $targetCard->id,
                'source' => 'restore:sense_review',
            ]);
            $this->assertSame($targetSense->id, $targetCard->target_id);
        } finally {
            $service->cleanupPackage($package['path']);
        }
    }

    public function test_anki_parser_rejects_modified_fixed_template(): void
    {
        [$sense] = $this->senseCard('template-tamper');
        $service = app(AnkiWordSensePackageService::class);
        $package = $service->build(collect([$this->exportItem($sense)]));

        try {
            $zip = new ZipArchive();
            $zip->open($package['path']);
            $databasePath = tempnam(sys_get_temp_dir(), 'm16-template-');
            $this->temporaryFiles[] = $databasePath;
            file_put_contents($databasePath, $zip->getFromName('collection.anki2'));
            $zip->close();
            $pdo = new PDO('sqlite:' . $databasePath);
            $models = json_decode(
                (string) $pdo->query('SELECT models FROM col LIMIT 1')->fetchColumn(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $models[(string) AnkiWordSensePackageService::MODEL_ID]['tmpls'][0]['qfmt']
                = '<div>tampered</div>';
            $statement = $pdo->prepare('UPDATE col SET models = :models');
            $statement->execute(['models' => json_encode($models)]);
            $pdo = null;
            $zip = new ZipArchive();
            $zip->open($package['path']);
            $zip->deleteName('collection.anki2');
            $zip->addFile($databasePath, 'collection.anki2');
            $zip->close();

            try {
                $service->parse($package['path']);
                $this->fail('Expected the modified template to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('fixed LinguaCafe', $exception->getMessage());
            }
        } finally {
            $service->cleanupPackage($package['path']);
        }
    }

    public function test_anki_parser_rejects_unsafe_archive_entry(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'm16-unsafe-');
        $this->temporaryFiles[] = $path;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('../collection.anki2', 'not sqlite');
        $zip->addFromString('media', '{}');
        $zip->close();

        $this->expectException(\InvalidArgumentException::class);
        app(AnkiWordSensePackageService::class)->parse($path);
    }

    public function test_import_validation_rejects_non_linguacafe_json(): void
    {
        $file = UploadedFile::fake()->createWithContent('invalid.json', json_encode(['items' => []]));
        $this->actingAs($this->user)->withHeader('Accept', 'application/json')
            ->post('/review-cards/manage/portable/import-preview', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_import_validation_rejects_string_fields_that_exceed_database_limits(): void
    {
        $item = $this->contentItem('lc-sense:aaaaaaaaaaaaaaaa:999997', 'valid');
        $item['lemma'] = str_repeat('x', 256);

        $this->actingAs($this->user)
            ->withHeader('Accept', 'application/json')
            ->post('/review-cards/manage/portable/import-preview', [
                'file' => $this->jsonUpload([$item]),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    private function senseCard(string $lemma): array
    {
        return $this->senseCardFor($this->user, $lemma);
    }

    private function senseCardFor(User $user, string $lemma): array
    {
        $sense = WordSense::forceCreate([
            'user_id' => $user->id, 'language' => 'english', 'language_id' => 'english',
            'lemma' => $lemma, 'surface_form' => $lemma, 'pos' => 'noun',
            'sense_zh' => '含义', 'sense_en' => 'meaning', 'aliases_zh' => [],
            'collocations' => [], 'example_sentence_en' => 'A real example.',
            'example_sentence_zh' => '真实例句。', 'status' => WordSense::STATUS_CONFIRMED,
            'sense_key' => hash('sha256', $lemma . Str::uuid()),
        ]);
        $card = ReviewCard::forceCreate([
            'user_id' => $user->id, 'language' => 'english', 'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE, 'target_id' => $sense->id,
            'fsrs_state' => 'review', 'fsrs_due_at' => now()->addDays(5),
            'fsrs_last_reviewed_at' => now()->subDay(), 'fsrs_stability' => 5,
            'fsrs_difficulty' => 5, 'fsrs_reps' => 3, 'fsrs_lapses' => 1,
            'fsrs_enabled' => true, 'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ]);
        return [$sense, $card];
    }

    private function exportItem(WordSense $sense): array
    {
        $card = $sense->reviewCard;
        return [
            'word_sense_id' => $sense->id, 'surface_form' => $sense->surface_form,
            'lemma' => $sense->lemma, 'pos' => $sense->pos,
            'sense_zh' => $sense->sense_zh, 'sense_en' => $sense->sense_en,
            'example_sentence_en' => $sense->example_sentence_en,
            'example_sentence_zh' => $sense->example_sentence_zh,
            'source_chapter_title' => 'Source', 'tags' => [],
            'fsrs_state' => $card->fsrs_state,
            'fsrs_due_at' => $card->fsrs_due_at->toISOString(),
            'fsrs_stability' => $card->fsrs_stability,
            'fsrs_difficulty' => $card->fsrs_difficulty,
            'fsrs_reps' => $card->fsrs_reps, 'fsrs_lapses' => $card->fsrs_lapses,
            'fsrs_last_reviewed_at' => $card->fsrs_last_reviewed_at->toISOString(),
        ];
    }

    private function contentItem(string $externalId, string $lemma): array
    {
        return [
            'external_id' => $externalId, 'surface_form' => $lemma, 'lemma' => $lemma,
            'pos' => 'noun', 'sense_zh' => '新释义', 'sense_en' => 'new meaning',
            'example_sentence_en' => 'A real example.', 'example_sentence_zh' => '真实例句。',
            'source' => 'Source', 'tags' => ['portable'], 'fsrs_state' => '',
            'fsrs_due_at' => '', 'fsrs_stability' => '', 'fsrs_difficulty' => '',
            'fsrs_reps' => '0', 'fsrs_lapses' => '0', 'fsrs_last_reviewed_at' => '',
        ];
    }

    private function jsonUpload(array $items): UploadedFile
    {
        $payload = [
            'format' => PortableDataService::CONTENT_FORMAT,
            'format_version' => PortableDataService::FORMAT_VERSION,
            'items' => $items,
        ];
        return UploadedFile::fake()->createWithContent('portable.json', json_encode($payload));
    }

    private function externalId(int $senseId, int $userId): string
    {
        $origin = substr(hash_hmac(
            'sha256',
            'portable-data-user:' . $userId,
            (string) config('app.key'),
        ), 0, 16);
        return "lc-sense:{$origin}:{$senseId}";
    }

    private function cardSchedule(string $packagePath): array
    {
        $pdo = $this->packageDatabase($packagePath);
        $row = $pdo->query('SELECT type, queue, reps FROM cards LIMIT 1')->fetch(PDO::FETCH_NUM);
        return array_map('intval', $row);
    }

    private function revlogCount(string $packagePath): int
    {
        return (int) $this->packageDatabase($packagePath)->query('SELECT COUNT(*) FROM revlog')->fetchColumn();
    }

    private function packageDatabase(string $packagePath): PDO
    {
        $zip = new ZipArchive();
        $zip->open($packagePath);
        $bytes = $zip->getFromName('collection.anki2');
        $zip->close();
        $path = tempnam(sys_get_temp_dir(), 'm16-anki-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $bytes);
        return new PDO('sqlite:' . $path);
    }
}
