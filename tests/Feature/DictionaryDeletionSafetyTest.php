<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RestoreWriteFence;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DictionaryDeletionSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'backup.restore_coordination_store' => 'array',
            'cache.default' => 'array',
        ]);
    }

    public function test_get_route_cannot_delete_dictionary(): void
    {
        $admin = $this->createUser(true);
        [$dictionaryId, $tableName] = $this->createImportedDictionaryAsset('get_guard');

        try {
            $this->actingAs($admin)
                ->getJson('/dictionaries/delete/'.$dictionaryId)
                ->assertMethodNotAllowed();

            $this->assertTrue(Schema::hasTable($tableName));
            $this->assertDatabaseHas('dictionaries', ['id' => $dictionaryId]);
        } finally {
            $this->deleteDictionaryAsset($dictionaryId, $tableName);
            $admin->delete();
        }
    }

    public function test_admin_can_delete_regular_imported_dictionary_with_delete_method(): void
    {
        $admin = $this->createUser(true);
        [$dictionaryId, $tableName] = $this->createImportedDictionaryAsset('delete_success');

        try {
            $this->actingAs($admin)
                ->deleteJson('/dictionaries/delete/'.$dictionaryId)
                ->assertOk();

            $this->assertFalse(Schema::hasTable($tableName));
            $this->assertDatabaseMissing('dictionaries', ['id' => $dictionaryId]);
        } finally {
            $this->deleteDictionaryAsset($dictionaryId, $tableName);
            $admin->delete();
        }
    }

    public function test_jmdict_cannot_be_deleted_through_the_backend(): void
    {
        $admin = $this->createUser(true);
        $this->assertTrue(Schema::hasTable('dict_jp_jmdict'));

        $dictionaryId = DB::table('dictionaries')->insertGetId([
            'name' => 'JMDict deletion guard '.Str::random(6),
            'type' => 'supported',
            'api_host' => null,
            'database_table_name' => 'dict_jp_jmdict',
            'source_language' => 'japanese',
            'target_language' => 'english',
            'color' => '#74E39A',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->actingAs($admin)
                ->deleteJson('/dictionaries/delete/'.$dictionaryId)
                ->assertConflict();

            $this->assertTrue(Schema::hasTable('dict_jp_jmdict'));
            $this->assertDatabaseHas('dictionaries', ['id' => $dictionaryId]);
        } finally {
            DB::table('dictionaries')->where('id', $dictionaryId)->delete();
            $admin->delete();
        }
    }

    public function test_restore_write_fence_blocks_dictionary_delete(): void
    {
        $admin = $this->createUser(true);
        [$dictionaryId, $tableName] = $this->createImportedDictionaryAsset('restore_fence');
        $operationId = (string) Str::uuid();
        app(RestoreWriteFence::class)->activate($operationId);

        try {
            $this->actingAs($admin)
                ->deleteJson('/dictionaries/delete/'.$dictionaryId)
                ->assertServiceUnavailable()
                ->assertJsonPath('error.code', 'RESTORE_WRITE_FENCE_ACTIVE');

            $this->assertTrue(Schema::hasTable($tableName));
            $this->assertDatabaseHas('dictionaries', ['id' => $dictionaryId]);
        } finally {
            app(RestoreWriteFence::class)->deactivate($operationId);
            $this->deleteDictionaryAsset($dictionaryId, $tableName);
            $admin->delete();
        }
    }

    public function test_non_admin_cannot_delete_dictionary(): void
    {
        $user = $this->createUser(false);
        [$dictionaryId, $tableName] = $this->createImportedDictionaryAsset('admin_guard');

        try {
            $this->actingAs($user)
                ->deleteJson('/dictionaries/delete/'.$dictionaryId)
                ->assertForbidden();

            $this->assertTrue(Schema::hasTable($tableName));
            $this->assertDatabaseHas('dictionaries', ['id' => $dictionaryId]);
        } finally {
            $this->deleteDictionaryAsset($dictionaryId, $tableName);
            $user->delete();
        }
    }

    private function createUser(bool $isAdmin): User
    {
        return User::forceCreate([
            'name' => 'Dictionary deletion test',
            'email' => 'dictionary-deletion-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => $isAdmin,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    /** @return array{0: int, 1: string} */
    private function createImportedDictionaryAsset(string $label): array
    {
        $tableName = 'dict_test_'.substr(hash('sha256', $label.Str::uuid()), 0, 16);

        Schema::create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->string('word', 256);
            $table->string('definitions', 2048);
            $table->timestamps();
        });

        $dictionaryId = DB::table('dictionaries')->insertGetId([
            'name' => 'Deletion test '.$label,
            'type' => 'custom_csv',
            'api_host' => null,
            'database_table_name' => $tableName,
            'source_language' => 'english',
            'target_language' => 'english',
            'color' => '#123456',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$dictionaryId, $tableName];
    }

    private function deleteDictionaryAsset(int $dictionaryId, string $tableName): void
    {
        Schema::dropIfExists($tableName);
        DB::table('dictionaries')->where('id', $dictionaryId)->delete();
    }
}
