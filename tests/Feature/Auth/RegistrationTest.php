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
}
