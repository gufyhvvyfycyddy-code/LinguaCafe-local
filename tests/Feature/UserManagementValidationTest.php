<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_create_rejects_duplicate_email_as_validation_error(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        User::factory()->create(['email' => 'existing@example.test']);

        $this->actingAs($admin)
            ->postJson('/users/create', [
                'name' => 'Duplicate User',
                'email' => 'existing@example.test',
                'password' => 'password',
                'password_confirmation' => 'password',
                'isAdmin' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame(2, User::count());
    }

    public function test_admin_update_rejects_another_users_email_as_validation_error(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $target = User::factory()->create(['email' => 'target@example.test']);
        User::factory()->create(['email' => 'existing@example.test']);

        $this->actingAs($admin)
            ->postJson('/users/update', [
                'userId' => $target->id,
                'name' => $target->name,
                'email' => 'existing@example.test',
                'isAdmin' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame('target@example.test', $target->fresh()->email);
    }

    public function test_admin_update_rejects_unknown_user_as_validation_error(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->postJson('/users/update', [
                'userId' => 999999,
                'name' => 'Missing User',
                'email' => 'missing@example.test',
                'isAdmin' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('userId');
    }

    public function test_last_admin_demotion_returns_conflict(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'email' => 'last-admin@example.test',
        ]);

        $this->actingAs($admin)
            ->postJson('/users/update', [
                'userId' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'isAdmin' => false,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'LAST_ADMIN_REQUIRED');

        $this->assertTrue((bool) $admin->fresh()->is_admin);
    }

    public function test_admin_update_can_keep_the_targets_current_email(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $target = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'same@example.test',
            'is_admin' => false,
        ]);

        $this->actingAs($admin)
            ->postJson('/users/update', [
                'userId' => $target->id,
                'name' => 'Updated Name',
                'email' => 'same@example.test',
                'isAdmin' => false,
            ])
            ->assertOk();

        $this->assertSame('Updated Name', $target->fresh()->name);
        $this->assertSame('same@example.test', $target->email);
    }
}
