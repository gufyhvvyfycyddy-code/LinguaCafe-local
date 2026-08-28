<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response
            ->assertOk()
            ->assertContent('"User has been logged in successfully."');
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS')
            ->assertJsonPath('error.message', 'The email or password is incorrect.');
    }

    public function test_unknown_email_and_wrong_password_share_the_same_public_failure(): void
    {
        $existing = User::factory()->create();
        $missingEmail = 'missing-'.Str::uuid().'@example.test';

        $wrongPassword = $this->postJson('/login', [
            'email' => $existing->email,
            'password' => 'wrong-password',
        ]);
        $unknownEmail = $this->postJson('/login', [
            'email' => $missingEmail,
            'password' => 'wrong-password',
        ]);

        $wrongPassword->assertUnauthorized();
        $unknownEmail->assertUnauthorized();
        $this->assertSame($wrongPassword->json('error'), $unknownEmail->json('error'));
    }

    public function test_login_is_rate_limited_on_the_sixth_failed_account_attempt(): void
    {
        $user = User::factory()->create();
        $accountKey = 'login:account:'.Str::transliterate(Str::lower($user->email));
        $ipKey = 'login:ip:127.0.0.1';

        RateLimiter::clear($accountKey);
        RateLimiter::clear($ipKey);
        try {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $this->postJson('/login', [
                    'email' => $user->email,
                    'password' => 'wrong-password',
                ])->assertUnauthorized();
            }
            $this->assertSame(5, RateLimiter::attempts($accountKey));

            $this->postJson('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])
                ->assertTooManyRequests()
                ->assertJsonPath('error.code', 'LOGIN_RATE_LIMITED')
                ->assertJsonPath('error.message', 'Too many login attempts. Please try again later.');
        } finally {
            RateLimiter::clear($accountKey);
            RateLimiter::clear($ipKey);
        }
    }

    public function test_same_ip_rotating_emails_is_blocked_after_twenty_five_attempts(): void
    {
        $ipKey = 'login:ip:127.0.0.1';
        $accountKeys = [];

        RateLimiter::clear($ipKey);
        try {
            for ($i = 0; $i < 25; $i++) {
                $email = 'rotate-'.$i.'-'.Str::uuid().'@example.test';
                $accountKeys[] = 'login:account:'.Str::transliterate(Str::lower($email));

                $this->postJson('/login', [
                    'email' => $email,
                    'password' => 'wrong-password',
                ])->assertUnauthorized();
            }
            $this->assertSame(25, RateLimiter::attempts($ipKey));

            $finalEmail = 'rotate-final-'.Str::uuid().'@example.test';
            $accountKeys[] = 'login:account:'.Str::transliterate(Str::lower($finalEmail));
            $this->postJson('/login', [
                'email' => $finalEmail,
                'password' => 'wrong-password',
            ])
                ->assertTooManyRequests()
                ->assertJsonPath('error.code', 'LOGIN_RATE_LIMITED')
                ->assertJsonPath('error.message', 'Too many login attempts. Please try again later.');
        } finally {
            RateLimiter::clear($ipKey);
            foreach ($accountKeys as $accountKey) {
                RateLimiter::clear($accountKey);
            }
        }
    }

    public function test_successful_login_clears_only_the_account_attempt_counter(): void
    {
        $user = User::factory()->create();
        $accountKey = 'login:account:'.Str::transliterate(Str::lower($user->email));
        $ipKey = 'login:ip:127.0.0.1';

        RateLimiter::clear($accountKey);
        RateLimiter::clear($ipKey);
        try {
            $this->postJson('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertUnauthorized();
            $this->assertSame(1, RateLimiter::attempts($accountKey));
            $this->assertSame(1, RateLimiter::attempts($ipKey));

            $this->postJson('/login', [
                'email' => $user->email,
                'password' => 'password',
            ])->assertOk();

            $this->assertSame(0, RateLimiter::attempts($accountKey));
            $this->assertSame(1, RateLimiter::attempts($ipKey));
        } finally {
            RateLimiter::clear($accountKey);
            RateLimiter::clear($ipKey);
        }
    }

    public function test_logged_in_user_cannot_switch_identity_by_logging_in_as_another_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $response = $this->actingAs($userA)->post('/login', [
            'email' => $userB->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($userA);
        $this->assertNotSame($userB->id, auth()->id());
        $response->assertRedirect();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertNoContent();
    }
}
