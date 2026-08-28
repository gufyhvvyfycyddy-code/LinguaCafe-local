<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\MediaAsset;
use App\Models\MediaReference;
use App\Models\MobileDevice;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\ReviewSettingPreset;
use App\Models\ReviewSettingPresetBinding;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\RestoreWriteFence;
use App\Services\UserService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class H05AccountDeletionPrivacyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        config(['media.disk' => 'media']);
    }

    public function test_every_user_scoped_table_is_cascade_owned_or_explicitly_cleaned(): void
    {
        $database = DB::connection()->getDatabaseName();
        $rows = DB::select(
            <<<'SQL'
SELECT c.TABLE_NAME,
       rc.DELETE_RULE
FROM information_schema.COLUMNS c
LEFT JOIN information_schema.KEY_COLUMN_USAGE k
  ON k.TABLE_SCHEMA = c.TABLE_SCHEMA
 AND k.TABLE_NAME = c.TABLE_NAME
 AND k.COLUMN_NAME = c.COLUMN_NAME
 AND k.REFERENCED_TABLE_SCHEMA = c.TABLE_SCHEMA
 AND k.REFERENCED_TABLE_NAME = 'users'
 AND k.REFERENCED_COLUMN_NAME = 'id'
LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
  ON rc.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
 AND rc.CONSTRAINT_NAME = k.CONSTRAINT_NAME
 AND rc.TABLE_NAME = k.TABLE_NAME
WHERE c.TABLE_SCHEMA = ?
  AND c.COLUMN_NAME = 'user_id'
ORDER BY c.TABLE_NAME
SQL,
            [$database],
        );

        $legacyTables = [];
        foreach ($rows as $row) {
            if ($row->DELETE_RULE === null) {
                $legacyTables[] = (string) $row->TABLE_NAME;
                continue;
            }

            $this->assertSame(
                'CASCADE',
                (string) $row->DELETE_RULE,
                "{$row->TABLE_NAME}.user_id must cascade from users or move into explicit account cleanup.",
            );
        }

        $expected = UserService::ACCOUNT_LEGACY_USER_TABLES;
        sort($legacyTables, SORT_STRING);
        sort($expected, SORT_STRING);
        $this->assertSame($legacyTables, $expected);
    }

    public function test_every_user_language_scoped_table_is_owned_by_language_cleanup(): void
    {
        $database = DB::connection()->getDatabaseName();
        $rows = DB::select(
            <<<'SQL'
SELECT user_columns.TABLE_NAME,
       GROUP_CONCAT(DISTINCT language_columns.COLUMN_NAME ORDER BY language_columns.COLUMN_NAME SEPARATOR ',') AS LANGUAGE_COLUMNS
FROM information_schema.COLUMNS user_columns
JOIN information_schema.COLUMNS language_columns
  ON language_columns.TABLE_SCHEMA = user_columns.TABLE_SCHEMA
 AND language_columns.TABLE_NAME = user_columns.TABLE_NAME
 AND language_columns.COLUMN_NAME IN ('language', 'language_id')
WHERE user_columns.TABLE_SCHEMA = ?
  AND user_columns.COLUMN_NAME = 'user_id'
GROUP BY user_columns.TABLE_NAME
ORDER BY user_columns.TABLE_NAME
SQL,
            [$database],
        );

        $actual = [];
        foreach ($rows as $row) {
            $columns = array_values(array_filter(explode(',', (string) $row->LANGUAGE_COLUMNS)));
            sort($columns, SORT_STRING);
            $actual[(string) $row->TABLE_NAME] = $columns;
        }

        $expected = UserService::LANGUAGE_SCOPED_USER_TABLES;
        foreach ($expected as &$columns) {
            sort($columns, SORT_STRING);
        }
        unset($columns);
        ksort($actual, SORT_STRING);
        ksort($expected, SORT_STRING);

        $this->assertSame(
            $actual,
            $expected,
            'Every user/language table must be explicitly owned by the destructive language cleanup.',
        );
    }

    public function test_english_language_deletion_removes_current_scope_and_preserves_account_other_language_and_shared_media(): void
    {
        $user = $this->createUser(false, 'language-delete@example.test');
        $other = $this->createUser(false, 'language-delete-other@example.test');

        $issued = $user->createToken('h05-preserved-device', ['mobile']);
        $token = $user->tokens()->latest('id')->firstOrFail();
        $device = MobileDevice::forceCreate([
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'platform' => 'android',
            'device_name' => 'H05 preserved device',
            'app_version' => '1.0.0',
            'personal_access_token_id' => $token->id,
        ]);

        $englishBook = Book::forceCreate([
            'user_id' => $user->id,
            'name' => 'English private book',
            'language' => 'english',
        ]);
        $frenchBook = Book::forceCreate([
            'user_id' => $user->id,
            'name' => 'French preserved book',
            'language' => 'french',
        ]);
        $otherEnglishBook = Book::forceCreate([
            'user_id' => $other->id,
            'name' => 'Other user English book',
            'language' => 'english',
        ]);

        [$englishSense, $englishOccurrence] = $this->createSenseWithLearningOccurrence($user, 'english', 'scope-english');
        [$frenchSense, $frenchOccurrence] = $this->createSenseWithLearningOccurrence($user, 'french', 'scope-french');
        $englishCard = $this->createSenseCard($user, $englishSense, 'english');
        $frenchCard = $this->createSenseCard($user, $frenchSense, 'french');
        $englishLog = $this->createReviewLog($user, $englishCard, 'english');
        $frenchLog = $this->createReviewLog($user, $frenchCard, 'french');

        $preset = ReviewSettingPreset::forceCreate([
            'user_id' => $user->id,
            'name' => 'H05 Shared Preset',
            'config' => [],
            'is_default' => true,
        ]);
        $englishBinding = ReviewSettingPresetBinding::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'english',
            'preset_id' => $preset->id,
        ]);
        $frenchBinding = ReviewSettingPresetBinding::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'french',
            'preset_id' => $preset->id,
        ]);

        Storage::disk('media')->put('user-'.$user->id.'/shared.mp3', 'ID3-shared');
        Storage::disk('media')->put('user-'.$user->id.'/english-only.mp3', 'ID3-english-only');
        $englishShared = $this->createMediaAsset($user, 'english', 'shared.mp3', 'shared-en');
        $frenchShared = $this->createMediaAsset($user, 'french', 'shared.mp3', 'shared-fr');
        $englishOnly = $this->createMediaAsset($user, 'english', 'english-only.mp3', 'only-en');
        $this->createMediaReference($user, $englishSense, $englishShared, 'english', 'word_pronunciation', 'h05-en-shared');
        $this->createMediaReference($user, $englishSense, $englishOnly, 'english', 'example_audio', 'h05-en-only');
        $frenchReference = $this->createMediaReference($user, $frenchSense, $frenchShared, 'french', 'word_pronunciation', 'h05-fr-shared');

        $this->actingAs($user)
            ->deleteJson('/users/delete-language-data/english')
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);
        $this->assertDatabaseHas('mobile_devices', ['id' => $device->id, 'revoked_at' => null]);
        $this->assertDatabaseMissing('books', ['id' => $englishBook->id]);
        $this->assertDatabaseMissing('word_senses', ['id' => $englishSense->id]);
        $this->assertDatabaseMissing('word_sense_occurrences', ['id' => $englishOccurrence->id]);
        $this->assertDatabaseMissing('review_cards', ['id' => $englishCard->id]);
        $this->assertDatabaseMissing('review_logs', ['id' => $englishLog->id]);
        $this->assertDatabaseMissing('review_setting_preset_bindings', ['id' => $englishBinding->id]);
        $this->assertDatabaseMissing('media_assets', ['id' => $englishShared->id]);
        $this->assertDatabaseMissing('media_assets', ['id' => $englishOnly->id]);

        $this->assertDatabaseHas('books', ['id' => $frenchBook->id]);
        $this->assertDatabaseHas('word_senses', ['id' => $frenchSense->id]);
        $this->assertDatabaseHas('word_sense_occurrences', ['id' => $frenchOccurrence->id]);
        $this->assertDatabaseHas('review_cards', ['id' => $frenchCard->id]);
        $this->assertDatabaseHas('review_logs', ['id' => $frenchLog->id]);
        $this->assertDatabaseHas('review_setting_presets', ['id' => $preset->id]);
        $this->assertDatabaseHas('review_setting_preset_bindings', ['id' => $frenchBinding->id]);
        $this->assertDatabaseHas('media_assets', ['id' => $frenchShared->id]);
        $this->assertDatabaseHas('media_references', ['id' => $frenchReference->id]);
        Storage::disk('media')->assertExists('user-'.$user->id.'/shared.mp3');
        Storage::disk('media')->assertMissing('user-'.$user->id.'/english-only.mp3');
        $this->assertSame([], Storage::disk('media')->allFiles('.deletion-quarantine'));

        $this->assertDatabaseHas('users', ['id' => $other->id]);
        $this->assertDatabaseHas('books', ['id' => $otherEnglishBook->id]);
        $this->assertNotEmpty($issued->plainTextToken);
    }

    public function test_second_language_media_quarantine_failure_restores_first_file_before_database_cleanup(): void
    {
        $user = $this->createUser(false, 'language-media-failure@example.test');
        $book = Book::forceCreate([
            'user_id' => $user->id,
            'name' => 'Language media rollback book',
            'language' => 'english',
        ]);
        $first = $this->createMediaAsset($user, 'english', 'first.mp3', 'first');
        $second = $this->createMediaAsset($user, 'english', 'second.mp3', 'second');
        $quarantine = Mockery::pattern('/^\.deletion-quarantine\/[0-9a-f-]{36}$/');
        $firstQuarantine = Mockery::pattern('/^\.deletion-quarantine\/[0-9a-f-]{36}\/user-'.$user->id.'\/first\.mp3$/');
        $secondQuarantine = Mockery::pattern('/^\.deletion-quarantine\/[0-9a-f-]{36}\/user-'.$user->id.'\/second\.mp3$/');

        $disk = Mockery::mock();
        $disk->shouldReceive('exists')->once()->with('user-'.$user->id.'/first.mp3')->andReturn(true);
        $disk->shouldReceive('move')->once()->with('user-'.$user->id.'/first.mp3', $firstQuarantine)->andReturn(true);
        $disk->shouldReceive('exists')->once()->with('user-'.$user->id.'/second.mp3')->andReturn(true);
        $disk->shouldReceive('move')->once()->with('user-'.$user->id.'/second.mp3', $secondQuarantine)->andReturn(false);
        $disk->shouldReceive('exists')->once()->with($firstQuarantine)->andReturn(true);
        $disk->shouldReceive('move')->once()->with($firstQuarantine, 'user-'.$user->id.'/first.mp3')->andReturn(true);
        $disk->shouldReceive('exists')->once()->with($quarantine)->andReturn(true);
        $disk->shouldReceive('deleteDirectory')->once()->with($quarantine)->andReturn(true);
        Storage::shouldReceive('disk')->once()->with('media')->andReturn($disk);

        $this->actingAs($user)
            ->deleteJson('/users/delete-language-data/english')
            ->assertServerError();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('books', ['id' => $book->id]);
        $this->assertDatabaseHas('media_assets', ['id' => $first->id]);
        $this->assertDatabaseHas('media_assets', ['id' => $second->id]);
    }

    public function test_guest_cannot_delete_an_account(): void
    {
        $this->deleteJson('/users/account', [
            'confirmation' => 'delete my account',
            'password' => 'password',
        ])->assertUnauthorized();
    }

    public function test_account_deletion_requires_exact_confirmation_and_current_password(): void
    {
        $user = $this->createUser(false);

        $this->actingAs($user)
            ->deleteJson('/users/account', [
                'confirmation' => 'delete account',
                'password' => 'password',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'ACCOUNT_DELETE_CONFIRMATION_REQUIRED');

        $this->actingAs($user)
            ->deleteJson('/users/account', [
                'confirmation' => 'delete my account',
                'password' => 'wrong-password',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'INVALID_PASSWORD');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_last_administrator_cannot_delete_their_account(): void
    {
        User::query()->where('is_admin', true)->update(['is_admin' => false]);
        $admin = $this->createUser(true);
        Storage::disk('media')->put('user-'.$admin->id.'/last-admin.mp3', 'ID3-last-admin');

        $this->actingAs($admin)
            ->deleteJson('/users/account', [
                'confirmation' => 'delete my account',
                'password' => 'password',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'LAST_ADMIN_REQUIRED');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        Storage::disk('media')->assertExists('user-'.$admin->id.'/last-admin.mp3');
        $this->assertSame([], Storage::disk('media')->allFiles('.deletion-quarantine'));
    }

    public function test_account_media_quarantine_failure_happens_before_database_deletion(): void
    {
        $this->createUser(true, 'admin-media-failure@example.test');
        $user = $this->createUser(false, 'media-failure@example.test');
        $book = Book::forceCreate([
            'user_id' => $user->id,
            'name' => 'Media failure protected book',
            'language' => 'english',
        ]);
        $source = 'user-'.$user->id.'/account.mp3';
        $destination = Mockery::pattern('/^\.deletion-quarantine\/[0-9a-f-]{36}\/user-'.$user->id.'\/account\.mp3$/');

        $disk = Mockery::mock();
        $disk->shouldReceive('allFiles')->once()->with('user-'.$user->id)->andReturn([$source]);
        $disk->shouldReceive('exists')->once()->with($source)->andReturn(true);
        $disk->shouldReceive('move')->once()->with($source, $destination)->andReturn(false);
        Storage::shouldReceive('disk')->once()->with('media')->andReturn($disk);

        $this->actingAs($user)
            ->deleteJson('/users/account', [
                'confirmation' => 'delete my account',
                'password' => 'password',
            ])
            ->assertServerError();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('books', ['id' => $book->id]);
    }

    public function test_restore_write_fence_blocks_account_deletion_before_any_data_changes(): void
    {
        $user = $this->createUser(false);
        $book = Book::forceCreate([
            'user_id' => $user->id,
            'name' => 'Fence protected book',
            'language' => 'english',
        ]);
        $operationId = (string) Str::uuid();
        app(RestoreWriteFence::class)->activate($operationId);

        try {
            $this->actingAs($user)
                ->deleteJson('/users/account', [
                    'confirmation' => 'delete my account',
                    'password' => 'password',
                ])
                ->assertServiceUnavailable()
                ->assertJsonPath('error.code', 'RESTORE_WRITE_FENCE_ACTIVE');
        } finally {
            app(RestoreWriteFence::class)->deactivate($operationId);
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('books', ['id' => $book->id]);
    }

    public function test_account_deletion_removes_active_user_data_tokens_devices_and_media_without_cross_user_damage(): void
    {
        $admin = $this->createUser(true, 'admin@example.test');
        $user = $this->createUser(false, 'delete-me@example.test');
        $other = $this->createUser(false, 'keep-me@example.test');

        $book = Book::forceCreate([
            'user_id' => $user->id,
            'name' => 'Private book',
            'language' => 'english',
        ]);
        $otherBook = Book::forceCreate([
            'user_id' => $other->id,
            'name' => 'Other private book',
            'language' => 'english',
        ]);
        [$sense, $occurrence] = $this->createSenseWithLearningOccurrence($user, 'english', 'privacy');
        $card = $this->createSenseCard($user, $sense, 'english');

        $issued = $user->createToken('h05-device', ['mobile']);
        $token = $user->tokens()->latest('id')->firstOrFail();
        $device = MobileDevice::forceCreate([
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'platform' => 'android',
            'device_name' => 'H05 device',
            'app_version' => '1.0.0',
            'personal_access_token_id' => $token->id,
        ]);

        DB::table('password_resets')->insert([
            'email' => $user->email,
            'token' => hash('sha256', 'h05-reset'),
            'created_at' => now(),
        ]);
        DB::table('legacy_word_card_migration_runs')->insert([
            'run_uuid' => (string) Str::uuid(),
            'schema_version' => 'h05-test',
            'classifier_schema_version' => 'h05-test',
            'report_fingerprint' => str_repeat('a', 64),
            'plan_fingerprint' => hash('sha256', 'h05-plan-'.$user->id),
            'backup_id' => (string) Str::uuid(),
            'backup_manifest_sha256' => str_repeat('b', 64),
            'backup_payload_sha256' => str_repeat('c', 64),
            'filters' => json_encode(['user_id' => $user->id, 'language' => 'english']),
            'counts' => json_encode(['cards' => 0]),
            'state' => 'applied',
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Storage::disk('media')->put('user-'.$user->id.'/h05.mp3', 'ID3-private-media');
        MediaAsset::forceCreate([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'language_id' => 'english',
            'sha256' => hash('sha256', 'ID3-private-media'),
            'storage_name' => 'h05.mp3',
            'original_name' => 'private.mp3',
            'mime_type' => 'audio/mpeg',
            'extension' => 'mp3',
            'size_bytes' => strlen('ID3-private-media'),
            'source_kind' => 'user_upload',
            'copyright_status' => 'user_owned',
        ]);

        $response = $this->actingAs($user)
            ->deleteJson('/users/account', [
                'confirmation' => 'delete my account',
                'password' => 'password',
            ]);

        $response->assertOk()->assertExactJson(['deleted' => true]);
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('books', ['id' => $book->id]);
        $this->assertDatabaseMissing('word_senses', ['id' => $sense->id]);
        $this->assertDatabaseMissing('word_sense_occurrences', ['id' => $occurrence->id]);
        $this->assertDatabaseMissing('review_cards', ['id' => $card->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
        $this->assertDatabaseMissing('mobile_devices', ['id' => $device->id]);
        $this->assertDatabaseMissing('password_resets', ['email' => 'delete-me@example.test']);
        $this->assertSame(0, DB::table('legacy_word_card_migration_runs')->where('filters->user_id', $user->id)->count());
        Storage::disk('media')->assertMissing('user-'.$user->id.'/h05.mp3');
        $this->assertSame([], Storage::disk('media')->allFiles('.deletion-quarantine'));

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseHas('users', ['id' => $other->id]);
        $this->assertDatabaseHas('books', ['id' => $otherBook->id]);

        $this->withToken($issued->plainTextToken)
            ->getJson('/api/v1/mobile/bootstrap')
            ->assertUnauthorized();
    }

    private function createUser(bool $admin, ?string $email = null): User
    {
        return User::forceCreate([
            'name' => $admin ? 'H05 Admin' : 'H05 User',
            'email' => $email ?? 'h05-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => $admin,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    /** @return array{WordSense,WordSenseOccurrence} */
    private function createSenseWithLearningOccurrence(User $user, string $language, string $lemma): array
    {
        $occurrence = WordSenseOccurrence::forceCreate([
            'user_id' => $user->id,
            'language' => $language,
            'language_id' => $language,
            'sentence_id' => (string) Str::uuid(),
            'sentence_en' => "Example for {$lemma}.",
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => $lemma,
            'lemma' => $lemma,
            'decision' => 'matched_existing',
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_READING_OCCURRENCE,
        ]);
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => $language,
            'language_id' => $language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_key' => hash('sha256', "h05|{$user->id}|{$language}|{$lemma}"),
            'sense_zh' => '测试',
            'sense_en' => 'test',
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
        $sense->forceFill([
            'learning_started_at' => now(),
            'learning_started_origin' => WordSense::LEARNING_ORIGIN_READING,
            'learning_started_source_occurrence_id' => $occurrence->id,
        ])->save();
        $occurrence->forceFill(['word_sense_id' => $sense->id])->save();

        return [$sense, $occurrence];
    }

    private function createSenseCard(User $user, WordSense $sense, string $language): ReviewCard
    {
        return ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language' => $language,
            'language_id' => $language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
        ]);
    }

    private function createReviewLog(User $user, ReviewCard $card, string $language): ReviewLog
    {
        return ReviewLog::forceCreate([
            'user_id' => $user->id,
            'language' => $language,
            'language_id' => $language,
            'review_card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => now(),
            'new_state' => 'review',
            'source' => ReviewLog::SOURCE_SENSE_REVIEW,
        ]);
    }

    private function createMediaAsset(User $user, string $language, string $storageName, string $marker): MediaAsset
    {
        return MediaAsset::forceCreate([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'language_id' => $language,
            'sha256' => hash('sha256', $marker),
            'storage_name' => $storageName,
            'original_name' => $storageName,
            'mime_type' => 'audio/mpeg',
            'extension' => 'mp3',
            'size_bytes' => strlen($marker),
            'source_kind' => 'user_upload',
            'copyright_status' => 'user_owned',
        ]);
    }

    private function createMediaReference(
        User $user,
        WordSense $sense,
        MediaAsset $asset,
        string $language,
        string $role,
        string $slotKey,
    ): MediaReference {
        return MediaReference::forceCreate([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'language_id' => $language,
            'media_asset_id' => $asset->id,
            'word_sense_id' => $sense->id,
            'role' => $role,
            'slot_key' => $slotKey,
        ]);
    }
}
