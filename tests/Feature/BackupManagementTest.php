<?php

namespace Tests\Feature;

use App\Exceptions\BackupException;
use App\Models\User;
use App\Services\DatabaseDumpProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class BackupManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backup');
        config([
            'backup.disk' => 'backup',
            'backup.max_backups' => 14,
            'backup.lock_seconds' => 30,
            'backup.restore_coordination_store' => 'array',
            'cache.default' => 'array',
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'testing_database',
        ]);
    }

    public function test_admin_can_create_and_list_a_published_backup(): void
    {
        $this->bindSuccessfulRunner();
        $admin = $this->user('backup-admin@example.test', true);

        $created = $this->actingAs($admin)
            ->postJson('/backups')
            ->assertCreated()
            ->assertJsonPath('backup.status', 'successful');
        $backupId = $created->json('backup.backup_id');

        $this->actingAs($admin)
            ->getJson('/backups')
            ->assertOk()
            ->assertJsonCount(1, 'backups')
            ->assertJsonPath('backups.0.backup_id', $backupId);
    }

    public function test_backup_routes_require_an_authenticated_administrator(): void
    {
        $this->getJson('/backups')->assertUnauthorized();
        $this->postJson('/backups')->assertUnauthorized();

        $ordinaryUser = $this->user('backup-user@example.test', false);
        $this->actingAs($ordinaryUser)->getJson('/backups')->assertForbidden();
        $this->actingAs($ordinaryUser)->postJson('/backups')->assertForbidden();
    }

    public function test_legacy_get_mutation_is_removed(): void
    {
        $admin = $this->user('backup-legacy@example.test', true);

        $this->actingAs($admin)
            ->getJson('/backups/create')
            ->assertNotFound();
    }

    public function test_backup_failure_returns_stable_sanitized_error(): void
    {
        $runner = Mockery::mock(DatabaseDumpProcess::class);
        $runner->shouldReceive('dump')
            ->once()
            ->andThrow(new BackupException(
                'BACKUP_DATABASE_DUMP_FAILED',
                'The database backup process failed.',
            ));
        $this->app->instance(DatabaseDumpProcess::class, $runner);
        $admin = $this->user('backup-failure@example.test', true);

        $this->actingAs($admin)
            ->postJson('/backups')
            ->assertInternalServerError()
            ->assertExactJson([
                'error' => [
                    'code' => 'BACKUP_DATABASE_DUMP_FAILED',
                    'message' => 'The database backup process failed.',
                ],
            ]);
        $this->assertSame([], Storage::disk('backup')->files());
    }

    private function bindSuccessfulRunner(): void
    {
        $runner = Mockery::mock(DatabaseDumpProcess::class);
        $runner->shouldReceive('dump')
            ->once()
            ->andReturnUsing(function (string $path) {
                file_put_contents($path, 'SELECT 1;');
            });
        $this->app->instance(DatabaseDumpProcess::class, $runner);
    }

    private function user(string $email, bool $isAdmin): User
    {
        return User::forceCreate([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => $isAdmin,
            'uuid' => (string) Str::uuid(),
        ]);
    }
}
