<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class H06PasswordChangeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_cannot_change_a_password(): void
    {
        $this->postJson('/users/update-password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertUnauthorized();
    }

    public function test_current_password_is_required_before_password_change(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $originalHash = $user->password;

        $this->actingAs($user)
            ->postJson('/users/update-password', [
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);

        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_wrong_current_password_does_not_change_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $originalHash = $user->password;

        $this->actingAs($user)
            ->postJson('/users/update-password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.current_password.0', '当前密码不正确。');

        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_correct_current_password_changes_password_and_keeps_current_session_valid(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
            'password_changed' => false,
        ]);

        $this->actingAs($user)
            ->postJson('/users/update-password', [
                'current_password' => 'old-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->assertTrue((bool) $user->password_changed);

        $this->getJson('/users/is-password-changed')->assertOk();
        $this->assertAuthenticatedAs($user);
    }
}
