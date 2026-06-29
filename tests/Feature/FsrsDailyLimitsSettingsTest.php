<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FsrsDailyLimitsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Daily Limits Test',
            'email' => '__VG_EMAIL_daily_limits_test__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    public function test_default_limits_when_no_settings_saved(): void
    {
        $response = $this->actingAs($this->user)->getJson('/settings/fsrs/daily-limits');

        $response->assertOk();
        $response->assertJson([
            'daily_new_limit_enabled' => true,
            'daily_new_limit' => 20,
            'daily_review_limit_enabled' => false,
            'daily_review_limit' => 200,
            'new_cards_ignore_review_limit' => false,
            'is_queue_enforced' => false,
        ]);
    }

    public function test_save_and_read_back_full_config(): void
    {
        // Save
        $saveResponse = $this->actingAs($this->user)->postJson('/settings/fsrs/daily-limits', [
            'daily_new_limit_enabled' => false,
            'daily_new_limit' => 15,
            'daily_review_limit_enabled' => true,
            'daily_review_limit' => 150,
            'new_cards_ignore_review_limit' => true,
        ]);

        $saveResponse->assertOk();
        $saveResponse->assertJson([
            'daily_new_limit_enabled' => false,
            'daily_new_limit' => 15,
            'daily_review_limit_enabled' => true,
            'daily_review_limit' => 150,
            'new_cards_ignore_review_limit' => true,
            'is_queue_enforced' => false,
        ]);

        // Read back
        $readResponse = $this->actingAs($this->user)->getJson('/settings/fsrs/daily-limits');

        $readResponse->assertOk();
        $readResponse->assertJson([
            'daily_new_limit_enabled' => false,
            'daily_new_limit' => 15,
            'daily_review_limit_enabled' => true,
            'daily_review_limit' => 150,
            'new_cards_ignore_review_limit' => true,
        ]);
    }

    public function test_boolean_fields_handle_true_false(): void
    {
        // Save with true
        $this->actingAs($this->user)->postJson('/settings/fsrs/daily-limits', [
            'daily_new_limit_enabled' => true,
            'daily_review_limit_enabled' => true,
            'new_cards_ignore_review_limit' => true,
        ]);

        $response = $this->actingAs($this->user)->getJson('/settings/fsrs/daily-limits');
        $response->assertJson([
            'daily_new_limit_enabled' => true,
            'daily_review_limit_enabled' => true,
            'new_cards_ignore_review_limit' => true,
        ]);

        // Save with false
        $this->actingAs($this->user)->postJson('/settings/fsrs/daily-limits', [
            'daily_new_limit_enabled' => false,
            'daily_review_limit_enabled' => false,
            'new_cards_ignore_review_limit' => false,
        ]);

        $response2 = $this->actingAs($this->user)->getJson('/settings/fsrs/daily-limits');
        $response2->assertJson([
            'daily_new_limit_enabled' => false,
            'daily_review_limit_enabled' => false,
            'new_cards_ignore_review_limit' => false,
        ]);
    }

    public function test_daily_new_limit_range_validation(): void
    {
        // Below 0 should be rejected (stays at default)
        $this->actingAs($this->user)->postJson('/settings/fsrs/daily-limits', [
            'daily_new_limit' => -1,
        ]);
        $response = $this->actingAs($this->user)->getJson('/settings/fsrs/daily-limits');
        $this->assertEquals(20, $response->json('daily_new_limit')); // unchanged

        // Above 999 should be rejected
        $this->actingAs($this->user)->postJson('/settings/fsrs/daily-limits', [
            'daily_new_limit' => 1000,
        ]);
        $response2 = $this->actingAs($this->user)->getJson('/settings/fsrs/daily-limits');
        $this->assertEquals(20, $response2->json('daily_new_limit')); // unchanged

        // Valid value should be saved
        $this->actingAs($this->user)->postJson('/settings/fsrs/daily-limits', [
            'daily_new_limit' => 50,
        ]);
        $response3 = $this->actingAs($this->user)->getJson('/settings/fsrs/daily-limits');
        $this->assertEquals(50, $response3->json('daily_new_limit'));
    }

    public function test_daily_review_limit_range_validation(): void
    {
        // Below 0 should be rejected
        $this->actingAs($this->user)->postJson('/settings/fsrs/daily-limits', [
            'daily_review_limit' => -1,
        ]);
        $response = $this->actingAs($this->user)->getJson('/settings/fsrs/daily-limits');
        $this->assertEquals(200, $response->json('daily_review_limit'));

        // Above 9999 should be rejected
        $this->actingAs($this->user)->postJson('/settings/fsrs/daily-limits', [
            'daily_review_limit' => 10000,
        ]);
        $response2 = $this->actingAs($this->user)->getJson('/settings/fsrs/daily-limits');
        $this->assertEquals(200, $response2->json('daily_review_limit'));

        // Valid value should be saved
        $this->actingAs($this->user)->postJson('/settings/fsrs/daily-limits', [
            'daily_review_limit' => 300,
        ]);
        $response3 = $this->actingAs($this->user)->getJson('/settings/fsrs/daily-limits');
        $this->assertEquals(300, $response3->json('daily_review_limit'));
    }

    public function test_daily_limits_are_saved_but_not_enforced_yet(): void
    {
        $response = $this->actingAs($this->user)->getJson('/settings/fsrs/daily-limits');
        $response->assertOk();
        $response->assertJsonPath('is_queue_enforced', false);
        $this->assertStringContainsString('暂不限制', $response->json('message'));
    }

    public function test_daily_limits_requires_auth(): void
    {
        $response = $this->getJson('/settings/fsrs/daily-limits');
        $response->assertStatus(401);
    }
}
