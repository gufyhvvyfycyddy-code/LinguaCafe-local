<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_can_register_through_the_local_user_endpoint(): void
    {
        config(['linguacafe.allow_web_register' => true]);
        User::factory()->create(['is_admin' => true]);

        $response = $this->post('/users/create', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'isAdmin' => true,
        ]);

        $this->assertGuest();
        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'is_admin' => false,
            'password_changed' => true,
        ]);
    }

    public function test_authenticated_non_admin_cannot_create_another_user_or_grant_admin(): void
    {
        config(['linguacafe.allow_web_register' => true]);
        User::factory()->create(['is_admin' => true]);
        $ordinaryUser = User::factory()->create(['is_admin' => false]);

        $this->actingAs($ordinaryUser)
            ->post('/users/create', [
                'name' => 'Escalated User',
                'email' => 'escalated@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
                'isAdmin' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', [
            'email' => 'escalated@example.com',
        ]);
    }

    public function test_authenticated_non_admin_cannot_create_a_regular_user_even_when_public_registration_is_enabled(): void
    {
        config(['linguacafe.allow_web_register' => true]);
        User::factory()->create(['is_admin' => true]);
        $ordinaryUser = User::factory()->create(['is_admin' => false]);

        $this->actingAs($ordinaryUser)
            ->post('/users/create', [
                'name' => 'Regular Invite',
                'email' => 'regular-invite@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
                'isAdmin' => false,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', [
            'email' => 'regular-invite@example.com',
        ]);
    }

    public function test_authenticated_admin_can_create_an_admin_user(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post('/users/create', [
                'name' => 'Second Admin',
                'email' => 'second-admin@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
                'isAdmin' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'email' => 'second-admin@example.com',
            'is_admin' => true,
        ]);
    }
}
