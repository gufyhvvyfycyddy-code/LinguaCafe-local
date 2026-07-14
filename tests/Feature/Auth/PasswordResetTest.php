<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_routes_are_not_exposed(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->assertFalse(Route::has('password.email'));
        $this->assertFalse(Route::has('password.store'));

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertNotFound();

        Notification::assertNothingSent();
    }

    public function test_direct_password_reset_request_is_rejected(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $originalPassword = $user->password;

        $this->post('/reset-password', [
            'token' => 'disabled-route-token',
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertNotFound();

        $this->assertSame($originalPassword, $user->fresh()->password);
        Notification::assertNothingSent();
    }
}
