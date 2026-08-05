<?php

namespace Tests\Feature;

use App\Exceptions\BackupException;
use App\Models\User;
use App\Services\BackupRestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class BackupRestoreManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_routes_require_an_authenticated_user(): void
    {
        $backupId = (string) Str::uuid();
        $operationId = (string) Str::uuid();

        $this->postJson("/backups/{$backupId}/restore")->assertUnauthorized();
        $this->getJson("/backup-restores/{$operationId}")->assertUnauthorized();
        $this->getJson('/backups')->assertUnauthorized();
        $this->postJson('/backups')->assertUnauthorized();

        $user = $this->user('restore-user@example.test', false);
        $restore = Mockery::mock(BackupRestoreService::class);
        $restore->shouldReceive('confirm')
            ->once()
            ->with($backupId, $user->id, 'RESTORE')
            ->andReturn([
                'operation_id' => $operationId,
                'backup_id' => $backupId,
                'status' => 'queued',
            ]);
        $restore->shouldReceive('status')
            ->once()
            ->with($operationId, $user->id)
            ->andReturn([
                'operation_id' => $operationId,
                'backup_id' => $backupId,
                'status' => 'queued',
            ]);
        $this->app->instance(BackupRestoreService::class, $restore);

        $this->actingAs($user)
            ->postJson("/backups/{$backupId}/restore", [
                'confirmation' => 'RESTORE',
            ])
            ->assertStatus(202)
            ->assertJsonPath('restore_operation.operation_id', $operationId)
            ->assertJsonPath('restore_operation.status', 'queued');
        $this->actingAs($user)
            ->getJson("/backup-restores/{$operationId}")
            ->assertOk()
            ->assertJsonPath('restore_operation.operation_id', $operationId);
    }

    public function test_non_admin_user_without_admin_flag_can_submit_restore(): void
    {
        $user = $this->user('restore-non-admin@example.test', false);
        $backupId = (string) Str::uuid();
        $operationId = (string) Str::uuid();
        $restore = Mockery::mock(BackupRestoreService::class);
        $restore->shouldReceive('confirm')
            ->once()
            ->with($backupId, $user->id, 'RESTORE')
            ->andReturn([
                'operation_id' => $operationId,
                'backup_id' => $backupId,
                'status' => 'queued',
            ]);
        $this->app->instance(BackupRestoreService::class, $restore);

        $this->actingAs($user)
            ->postJson("/backups/{$backupId}/restore", [
                'confirmation' => 'RESTORE',
            ])
            ->assertStatus(202)
            ->assertJsonPath('restore_operation.operation_id', $operationId);
    }

    public function test_restore_preview_route_no_longer_exists(): void
    {
        $user = $this->user('restore-no-preview@example.test', false);
        $backupId = (string) Str::uuid();

        $this->actingAs($user)
            ->postJson("/backups/{$backupId}/restore-preview")
            ->assertNotFound();
    }

    public function test_missing_confirmation_uses_stable_error_shape(): void
    {
        $user = $this->user('restore-missing-confirmation@example.test', false);
        $backupId = (string) Str::uuid();

        $this->actingAs($user)
            ->postJson("/backups/{$backupId}/restore")
            ->assertUnprocessable()
            ->assertExactJson([
                'error' => [
                    'code' => 'BACKUP_RESTORE_REQUEST_INVALID',
                    'message' => 'The restore request is invalid.',
                ],
            ]);
    }

    public function test_incorrect_confirmation_uses_stable_error_shape(): void
    {
        $user = $this->user('restore-wrong-confirmation@example.test', false);
        $backupId = (string) Str::uuid();
        $restore = Mockery::mock(BackupRestoreService::class);
        $restore->shouldReceive('confirm')
            ->once()
            ->with($backupId, $user->id, 'restore')
            ->andThrow(new BackupException(
                'BACKUP_RESTORE_CONFIRMATION_INVALID',
                'Type RESTORE to confirm this operation.',
                422,
            ));
        $this->app->instance(BackupRestoreService::class, $restore);

        $this->actingAs($user)
            ->postJson("/backups/{$backupId}/restore", [
                'confirmation' => 'restore',
            ])
            ->assertUnprocessable()
            ->assertExactJson([
                'error' => [
                    'code' => 'BACKUP_RESTORE_CONFIRMATION_INVALID',
                    'message' => 'Type RESTORE to confirm this operation.',
                ],
            ]);
    }

    public function test_repeated_confirmation_returns_the_same_operation(): void
    {
        $user = $this->user('restore-idempotent@example.test', false);
        $backupId = (string) Str::uuid();
        $operationId = (string) Str::uuid();
        $restore = Mockery::mock(BackupRestoreService::class);
        $restore->shouldReceive('confirm')
            ->twice()
            ->with($backupId, $user->id, 'RESTORE')
            ->andReturn([
                'operation_id' => $operationId,
                'backup_id' => $backupId,
                'status' => 'queued',
            ]);
        $this->app->instance(BackupRestoreService::class, $restore);

        $this->actingAs($user)
            ->postJson("/backups/{$backupId}/restore", ['confirmation' => 'RESTORE'])
            ->assertStatus(202)
            ->assertJsonPath('restore_operation.operation_id', $operationId);
        $this->actingAs($user)
            ->postJson("/backups/{$backupId}/restore", ['confirmation' => 'RESTORE'])
            ->assertStatus(202)
            ->assertJsonPath('restore_operation.operation_id', $operationId);
    }

    public function test_unknown_operation_status_is_not_found(): void
    {
        $user = $this->user('restore-unknown@example.test', false);
        $operationId = (string) Str::uuid();
        $restore = Mockery::mock(BackupRestoreService::class);
        $restore->shouldReceive('status')
            ->once()
            ->with($operationId, $user->id)
            ->andThrow(new BackupException(
                'BACKUP_RESTORE_OPERATION_NOT_FOUND',
                'The requested restore operation was not found.',
                404,
            ));
        $this->app->instance(BackupRestoreService::class, $restore);

        $this->actingAs($user)
            ->getJson("/backup-restores/{$operationId}")
            ->assertNotFound()
            ->assertExactJson([
                'error' => [
                    'code' => 'BACKUP_RESTORE_OPERATION_NOT_FOUND',
                    'message' => 'The requested restore operation was not found.',
                ],
            ]);
    }

    public function test_operation_status_is_scoped_to_the_owning_user(): void
    {
        $user = $this->user('restore-owner@example.test', false);
        $other = $this->user('restore-other@example.test', false);
        $operationId = (string) Str::uuid();
        $restore = Mockery::mock(BackupRestoreService::class);
        $restore->shouldReceive('status')
            ->once()
            ->with($operationId, $user->id)
            ->andReturn([
                'operation_id' => $operationId,
                'backup_id' => (string) Str::uuid(),
                'status' => 'running',
            ]);
        $restore->shouldReceive('status')
            ->once()
            ->with($operationId, $other->id)
            ->andThrow(new BackupException(
                'BACKUP_RESTORE_OPERATION_NOT_FOUND',
                'The requested restore operation was not found.',
                404,
            ));
        $this->app->instance(BackupRestoreService::class, $restore);

        $this->actingAs($user)
            ->getJson("/backup-restores/{$operationId}")
            ->assertOk()
            ->assertJsonPath('restore_operation.status', 'running');
        $this->flushSession();
        $this->actingAs($other)
            ->getJson("/backup-restores/{$operationId}")
            ->assertNotFound();
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
