<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Setting;
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
        $this->assertSame('english', $user->selected_language);
        $this->assertTrue(Hash::check('12345678', $user->password));
        $this->assertTrue(Auth::validate([
            'email' => 'test@example.com',
            'password' => '12345678',
        ]));
        $this->assertSame('zh-CN', json_decode(Setting::where('user_id', $user->id)->where('name', 'uiLanguage')->value('value')));
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

    public function test_first_user_can_be_created_from_web_setup_endpoint(): void
    {
        $this->get('/setup')
            ->assertOk()
            ->assertSee(':_setup-mode="true"', false);

        $this->post('/users/create', [
            'name' => 'first@example.com',
            'email' => 'first@example.com',
            'password' => '12345678',
            'password_confirmation' => '12345678',
            'isAdmin' => false,
        ])->assertOk();

        $user = User::where('email', 'first@example.com')->first();

        $this->assertNotNull($user);
        $this->assertTrue((bool) $user->is_admin);
        $this->assertTrue((bool) $user->password_changed);
        $this->assertSame('english', $user->selected_language);
        $this->assertSame('zh-CN', json_decode(Setting::where('user_id', $user->id)->where('name', 'uiLanguage')->value('value')));
        $this->assertTrue(Auth::validate([
            'email' => 'first@example.com',
            'password' => '12345678',
        ]));
    }

    public function test_public_user_create_is_closed_after_system_is_initialized(): void
    {
        config(['linguacafe.allow_web_register' => false]);

        $this->artisan('user:create --email=admin@example.com --password=12345678')
            ->assertSuccessful();

        $this->get('/setup')
            ->assertOk()
            ->assertSee(':_setup-mode="true"', false);

        $this->post('/users/create', [
            'name' => 'second@example.com',
            'email' => 'second@example.com',
            'password' => '12345678',
            'password_confirmation' => '12345678',
            'isAdmin' => true,
        ])->assertStatus(401);

        $this->assertSame(0, User::where('email', 'second@example.com')->count());
    }

    public function test_web_registration_can_create_regular_local_user_when_enabled(): void
    {
        config(['linguacafe.allow_web_register' => true]);

        $this->artisan('user:create --email=admin@example.com --password=12345678')
            ->assertSuccessful();

        $this->get('/register')
            ->assertOk()
            ->assertSee(':_register-mode="true"', false)
            ->assertSee(':_allow-web-register="true"', false);

        $this->post('/users/create', [
            'name' => 'local@example.com',
            'email' => 'local@example.com',
            'password' => '12345678',
            'password_confirmation' => '12345678',
            'isAdmin' => true,
        ])->assertOk();

        $user = User::where('email', 'local@example.com')->first();

        $this->assertNotNull($user);
        $this->assertFalse((bool) $user->is_admin);
        $this->assertTrue((bool) $user->password_changed);
        $this->assertSame('english', $user->selected_language);
        $this->assertSame('zh-CN', json_decode(Setting::where('user_id', $user->id)->where('name', 'uiLanguage')->value('value')));
        $this->assertTrue(Auth::validate([
            'email' => 'local@example.com',
            'password' => '12345678',
        ]));
    }
}
