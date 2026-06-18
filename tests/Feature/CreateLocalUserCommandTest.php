<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateLocalUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_create_command_creates_loginable_local_user(): void
    {
        $this->artisan('user:create --email=test@example.com --password=12345678')
            ->expectsOutput('Local user created successfully.')
            ->expectsOutput('Email: test@example.com')
            ->expectsOutput('Password: 12345678')
            ->assertSuccessful();

        $user = User::where('email', 'test@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('test@example.com', $user->name);
        $this->assertTrue((bool) $user->is_admin);
        $this->assertTrue((bool) $user->password_changed);
        $this->assertNotNull($user->uuid);
        $this->assertTrue(Hash::check('12345678', $user->password));
        $this->assertTrue(Auth::validate([
            'email' => 'test@example.com',
            'password' => '12345678',
        ]));
    }

    public function test_user_create_command_rejects_duplicate_email(): void
    {
        $this->artisan('user:create --email=test@example.com --password=12345678')
            ->assertSuccessful();

        $this->artisan('user:create --email=test@example.com --password=12345678')
            ->expectsOutput('An other already exists with this email address.')
            ->assertFailed();

        $this->assertSame(1, User::where('email', 'test@example.com')->count());
    }
}
