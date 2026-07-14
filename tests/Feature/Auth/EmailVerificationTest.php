<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_routes_are_not_exposed(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $this->assertFalse(Route::has('verification.verify'));
        $this->assertFalse(Route::has('verification.send'));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        Event::assertNotDispatched(Verified::class);
    }

    public function test_direct_email_verification_request_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get('/verify-email/'.$user->id.'/'.sha1($user->email))
            ->assertNotFound();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }
}
