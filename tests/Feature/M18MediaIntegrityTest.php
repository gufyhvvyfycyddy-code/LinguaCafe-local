<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\MediaReference;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\MobileReviewPackageService;
use App\Services\BackupService;
use App\Services\PortableDataService;
use App\Services\ReviewCardManageItemSerializerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;
use ZipArchive;

class M18MediaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    public function test_upload_manifest_download_mobile_projection_and_removal_are_scoped_and_do_not_rate(): void
    {
        $user = $this->user('m18@example.test');
        $other = $this->user('m18-other@example.test');
        [$sense, $card] = $this->senseAndCard($user);
        $before = [ReviewLog::count(), $card->fsrs_state, $card->fsrs_due_at?->toISOString()];

        $response = $this->actingAs($user)->post('/word-senses/' . $sense->id . '/media', [
            'file' => $this->mp3('focus.mp3', 'word-audio'),
            'role' => MediaReference::ROLE_WORD_PRONUNCIATION,
            'copyright_status' => 'owned',
            'copyright_source' => 'Recorded by test user',
        ])->assertCreated()
            ->assertJsonPath('media.0.role', MediaReference::ROLE_WORD_PRONUNCIATION)
            ->assertJsonPath('media.0.copyright_status', 'owned');

        $assetId = $response->json('media.0.asset_id');
        $referenceId = $response->json('media.0.reference_id');
        $asset = MediaAsset::query()->where('public_id', $assetId)->firstOrFail();
        Storage::disk('media')->assertExists('user-' . $user->id . '/' . $asset->storage_name);

        $this->actingAs($user)->getJson('/reviews/senses')
            ->assertOk()
            ->assertJsonPath('cards.0.media.0.asset_id', $assetId)
            ->assertJsonPath('cards.0.media.0.sha256', $asset->sha256);

        $package = app(MobileReviewPackageService::class)->build($user->id, 'english', 0, 10, null);
        $this->assertSame($assetId, $package['items'][0]['display']['media'][0]['asset_id']);

        $this->actingAs($user)->get('/media/assets/' . $assetId)
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('X-Content-SHA256', $asset->sha256)
            ->assertHeader('Cache-Control', 'immutable, max-age=86400, private');
        $this->flushSession();
        $this->actingAs($other)->get('/media/assets/' . $assetId)->assertNotFound();

        $this->flushSession();
        $this->actingAs($user)->deleteJson('/media/references/' . $referenceId)
            ->assertOk()->assertJsonPath('retention_days', 30);
        $this->assertDatabaseMissing('media_references', ['public_id' => $referenceId]);
        $this->assertSoftDeleted('media_assets', ['id' => $asset->id]);
        Storage::disk('media')->assertExists('user-' . $user->id . '/' . $asset->storage_name);

        $freshCard = $card->fresh();
        $this->assertSame($before, [
            ReviewLog::count(),
            $freshCard->fsrs_state,
            $freshCard->fsrs_due_at?->toISOString(),
        ]);
    }

    public function test_example_slot_replacement_reuses_content_and_check_media_reports_missing_orphan_and_incompatible(): void
    {
        $user = $this->user('m18-check@example.test');
        [$sense] = $this->senseAndCard($user);
        $payload = [
            'file' => $this->mp3('example.mp3', 'same-audio'),
            'role' => MediaReference::ROLE_EXAMPLE_AUDIO,
            'sentence' => 'A focused example.',
            'copyright_status' => 'public_domain',
        ];
        $first = $this->actingAs($user)->post('/word-senses/' . $sense->id . '/media', $payload)
            ->assertCreated()->json();
        $second = $this->actingAs($user)->post('/word-senses/' . $sense->id . '/media', [
            ...$payload,
            'file' => $this->mp3('renamed.mp3', 'same-audio'),
        ])->assertCreated()->json();
        $this->assertSame($first['media'][0]['asset_id'], $second['media'][0]['asset_id']);
        $this->assertSame(1, MediaAsset::count());
        $this->assertSame(1, MediaReference::count());

        $asset = MediaAsset::firstOrFail();
        Storage::disk('media')->delete('user-' . $user->id . '/' . $asset->storage_name);
        $asset->forceFill(['mime_type' => 'audio/ogg'])->save();
        MediaReference::query()->delete();
        Storage::disk('media')->put('user-' . $user->id . '/untracked.mp3', 'ID3untracked');

        $this->actingAs($user)->getJson('/media/check')->assertOk()
            ->assertJsonPath('counts.assets', 1)
            ->assertJsonPath('counts.missing', 1)
            ->assertJsonPath('counts.orphaned', 1)
            ->assertJsonPath('counts.incompatible', 1)
            ->assertJsonPath('counts.untracked_files', 1)
            ->assertJsonPath('untracked_files.0', 'untracked.mp3');
    }

    public function test_rejects_bad_format_missing_example_binding_quota_and_cross_user_upload(): void
    {
        $user = $this->user('m18-reject@example.test');
        $other = $this->user('m18-reject-other@example.test');
        [$sense] = $this->senseAndCard($user);

        $this->actingAs($user)->post('/word-senses/' . $sense->id . '/media', [
            'file' => UploadedFile::fake()->createWithContent('note.txt', 'not audio'),
            'role' => MediaReference::ROLE_WORD_PRONUNCIATION,
            'copyright_status' => 'unknown',
        ])->assertSessionHasErrors('file');

        $this->actingAs($user)->post('/word-senses/' . $sense->id . '/media', [
            'file' => $this->mp3('example.mp3', 'audio'),
            'role' => MediaReference::ROLE_EXAMPLE_AUDIO,
            'copyright_status' => 'unknown',
        ])->assertSessionHasErrors('sentence');

        config()->set('media.user_quota_bytes', 1);
        $this->actingAs($user)->post('/word-senses/' . $sense->id . '/media', [
            'file' => $this->mp3('word.mp3', 'audio'),
            'role' => MediaReference::ROLE_WORD_PRONUNCIATION,
            'copyright_status' => 'owned',
        ])->assertSessionHasErrors('file');

        $this->flushSession();
        $this->actingAs($other)->withHeader('Accept', 'application/json')
            ->post('/word-senses/' . $sense->id . '/media', [
            'file' => $this->mp3('word.mp3', 'audio'),
            'role' => MediaReference::ROLE_WORD_PRONUNCIATION,
            'copyright_status' => 'owned',
            ])->assertNotFound();
        $this->assertSame(0, MediaAsset::withTrashed()->count());
    }

    public function test_full_package_media_is_opt_in_checksum_verified_and_restored_for_the_target_user(): void
    {
        $source = $this->user('m18-portable-source@example.test');
        [$sense, $card] = $this->senseAndCard($source);
        $this->actingAs($source)->post('/word-senses/' . $sense->id . '/media', [
            'file' => $this->mp3('portable.mp3', 'portable-audio'),
            'role' => MediaReference::ROLE_WORD_PRONUNCIATION,
            'copyright_status' => 'licensed',
            'copyright_source' => 'Portable fixture licence',
        ])->assertCreated();
        $items = app(ReviewCardManageItemSerializerService::class)
            ->buildItems(ReviewCard::query()->whereKey($card->id)->get(), $source->id, 'english')->all();
        $service = app(PortableDataService::class);

        $withoutMedia = $service->buildFullPackage($items, $source->id, 'english');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($withoutMedia['path']) === true);
        $this->assertFalse($zip->locateName('media.json'));
        $zip->close();
        $service->cleanupPackage($withoutMedia['path']);

        $package = $service->buildFullPackage($items, $source->id, 'english', true);
        $this->assertSame(1, $package['media_count']);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($package['path']) === true);
        $this->assertNotFalse($zip->locateName('media.json'));
        $media = json_decode($zip->getFromName('media.json'), true, flags: JSON_THROW_ON_ERROR);
        $mediaFile = $media['assets'][0]['file'];
        $this->assertNotFalse($zip->locateName($mediaFile));
        $zip->close();

        $target = $this->user('m18-portable-target@example.test');
        $this->flushSession();
        $preview = $this->actingAs($target)->post('/review-cards/manage/portable/import-preview', [
            'file' => new UploadedFile($package['path'], 'portable.lcpkg', 'application/zip', null, true),
        ])->assertOk()
            ->assertJsonPath('counts.media_assets', 1)
            ->assertJsonPath('counts.media_references', 1);
        $backupId = (string) Str::uuid();
        $this->mock(BackupService::class, function (MockInterface $mock) use ($backupId) {
            $mock->shouldReceive('createBackup')->once()->andReturn(['backup_id' => $backupId]);
        });
        $this->actingAs($target)->postJson('/review-cards/manage/portable/import-apply', [
            'preview_token' => $preview->json('preview_token'),
            'confirm' => true,
        ])->assertOk()->assertJsonPath('media_references', 1);
        $this->assertDatabaseHas('media_assets', [
            'user_id' => $target->id,
            'copyright_status' => 'licensed',
        ]);
        $targetSense = WordSense::query()->where('user_id', $target->id)->where('lemma', 'focus')->firstOrFail();
        $this->assertDatabaseHas('media_references', [
            'user_id' => $target->id,
            'word_sense_id' => $targetSense->id,
            'role' => MediaReference::ROLE_WORD_PRONUNCIATION,
        ]);
        $service->cleanupPackage($package['path']);

        $tampered = $service->buildFullPackage($items, $source->id, 'english', true);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tampered['path']) === true);
        $zip->addFromString($mediaFile, 'ID3tampered');
        $zip->close();
        $this->flushSession();
        $this->actingAs($target)->post('/review-cards/manage/portable/import-preview', [
            'file' => new UploadedFile($tampered['path'], 'tampered.lcpkg', 'application/zip', null, true),
        ])->assertSessionHasErrors('file');
        $service->cleanupPackage($tampered['path']);
    }

    private function mp3(string $name, string $marker): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, 'ID3' . $marker . str_repeat("\0", 1024));
    }

    private function user(string $email): User
    {
        return User::forceCreate([
            'name' => 'M18 User',
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function senseAndCard(User $user): array
    {
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'focus',
            'surface_form' => 'focused',
            'pos' => 'noun',
            'sense_zh' => '焦点',
            'sense_en' => 'the center of attention',
            'example_sentence_en' => 'A focused example.',
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
            'sense_key' => hash('sha256', $user->id . '|m18-focus'),
        ]);
        $card = ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subMinute(),
            'fsrs_enabled' => true,
            'fsrs_stability' => 8,
            'fsrs_difficulty' => 5,
        ]);
        return [$sense, $card];
    }
}
