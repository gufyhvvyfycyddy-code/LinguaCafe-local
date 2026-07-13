<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature tests for the Queue Order settings endpoint.
 *
 * Covers ADR-0015 V1 surface:
 *   GET  /settings/fsrs/queue-order
 *   POST /settings/fsrs/queue-order
 *
 * Verifies:
 *   - defaults when no settings saved
 *   - save and read back full config
 *   - each valid enum value
 *   - invalid enum rejected with structured 422
 *   - partial input (only some keys) saves only provided keys
 *   - unknown keys ignored (behavior locked by test)
 *   - invalid value does NOT partially save
 *   - auth required
 *   - admin middleware enforced
 *   - no migration / no impact on daily-limits
 */
class FsrsQueueOrderSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $normal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::forceCreate([
            'name' => 'Queue Order Admin',
            'email' => '__VG_EMAIL_queue_order_admin__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->normal = User::forceCreate([
            'name' => 'Queue Order Normal',
            'email' => '__VG_EMAIL_queue_order_normal__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    public function test_default_queue_order_when_no_settings_saved(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/settings/fsrs/queue-order');

        $response->assertOk();
        $response->assertJson([
            'interday_learning_review_order' => 'mix',
            'new_review_order' => 'mix',
            'review_sort_order' => 'due_random',
            'new_sort_order' => 'created_asc',
            'scope' => 'global',
            'preset_supported' => false,
        ]);
    }

    public function test_save_and_read_back_full_config(): void
    {
        $save = $this->actingAs($this->admin)->postJson('/settings/fsrs/queue-order', [
            'interday_learning_review_order' => 'before',
            'new_review_order' => 'after',
            'review_sort_order' => 'due_stable',
            'new_sort_order' => 'created_desc',
        ]);

        $save->assertOk();
        $save->assertJson([
            'interday_learning_review_order' => 'before',
            'new_review_order' => 'after',
            'review_sort_order' => 'due_stable',
            'new_sort_order' => 'created_desc',
            'scope' => 'global',
            'preset_supported' => false,
        ]);

        $read = $this->actingAs($this->admin)->getJson('/settings/fsrs/queue-order');
        $read->assertOk();
        $read->assertJson([
            'interday_learning_review_order' => 'before',
            'new_review_order' => 'after',
            'review_sort_order' => 'due_stable',
            'new_sort_order' => 'created_desc',
        ]);
    }

    public function test_each_valid_interday_enum(): void
    {
        foreach (['mix', 'before', 'after'] as $val) {
            $this->actingAs($this->admin)->postJson('/settings/fsrs/queue-order', [
                'interday_learning_review_order' => $val,
            ])->assertOk();

            $this->actingAs($this->admin)->getJson('/settings/fsrs/queue-order')
                ->assertJsonPath('interday_learning_review_order', $val);
        }
    }

    public function test_each_valid_new_review_enum(): void
    {
        foreach (['mix', 'before', 'after'] as $val) {
            $this->actingAs($this->admin)->postJson('/settings/fsrs/queue-order', [
                'new_review_order' => $val,
            ])->assertOk();

            $this->actingAs($this->admin)->getJson('/settings/fsrs/queue-order')
                ->assertJsonPath('new_review_order', $val);
        }
    }

    public function test_each_valid_review_sort_enum(): void
    {
        foreach (['due_random', 'due_stable', 'ascending_retrievability', 'random'] as $val) {
            $this->actingAs($this->admin)->postJson('/settings/fsrs/queue-order', [
                'review_sort_order' => $val,
            ])->assertOk();

            $this->actingAs($this->admin)->getJson('/settings/fsrs/queue-order')
                ->assertJsonPath('review_sort_order', $val);
        }
    }

    public function test_each_valid_new_sort_enum(): void
    {
        foreach (['created_asc', 'created_desc', 'random'] as $val) {
            $this->actingAs($this->admin)->postJson('/settings/fsrs/queue-order', [
                'new_sort_order' => $val,
            ])->assertOk();

            $this->actingAs($this->admin)->getJson('/settings/fsrs/queue-order')
                ->assertJsonPath('new_sort_order', $val);
        }
    }

    public function test_invalid_interday_enum_rejected_with_422(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/settings/fsrs/queue-order', [
            'interday_learning_review_order' => 'sandwich',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);
        $response->assertJsonStructure(['message', 'errors' => ['interday_learning_review_order']]);
    }

    public function test_invalid_new_review_enum_rejected_with_422(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/settings/fsrs/queue-order', [
            'new_review_order' => 'everywhere',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
        $response->assertJsonStructure(['errors' => ['new_review_order']]);
    }

    public function test_invalid_review_sort_enum_rejected_with_422(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/settings/fsrs/queue-order', [
            'review_sort_order' => 'least_stable_first',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
        $response->assertJsonStructure(['errors' => ['review_sort_order']]);
    }

    public function test_invalid_new_sort_enum_rejected_with_422(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/settings/fsrs/queue-order', [
            'new_sort_order' => 'hardest_first',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
        $response->assertJsonStructure(['errors' => ['new_sort_order']]);
    }

    public function test_unknown_keys_are_ignored(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/settings/fsrs/queue-order', [
            'interday_learning_review_order' => 'before',
            'unknown_key' => 'whatever',
            'deck_priority' => 'yes',
        ]);

        $response->assertOk();
        $response->assertJsonPath('interday_learning_review_order', 'before');
        // Unknown keys must not appear in the response
        $this->assertArrayNotHasKey('unknown_key', $response->json());
        $this->assertArrayNotHasKey('deck_priority', $response->json());
    }

    public function test_invalid_value_does_not_partially_save(): void
    {
        // Save a valid baseline
        $this->actingAs($this->admin)->postJson('/settings/fsrs/queue-order', [
            'interday_learning_review_order' => 'before',
            'new_review_order' => 'after',
            'review_sort_order' => 'due_stable',
            'new_sort_order' => 'created_desc',
        ])->assertOk();

        // Send a mixed request with one invalid value
        $this->actingAs($this->admin)->postJson('/settings/fsrs/queue-order', [
            'interday_learning_review_order' => 'mix',
            'review_sort_order' => 'not_a_real_sort',
        ])->assertStatus(422);

        // Read back — must be unchanged from the valid baseline
        $final = $this->actingAs($this->admin)->getJson('/settings/fsrs/queue-order');
        $final->assertJson([
            'interday_learning_review_order' => 'before',
            'new_review_order' => 'after',
            'review_sort_order' => 'due_stable',
            'new_sort_order' => 'created_desc',
        ]);
    }

    public function test_queue_order_requires_auth(): void
    {
        $this->getJson('/settings/fsrs/queue-order')->assertStatus(401);
        $this->postJson('/settings/fsrs/queue-order', [])->assertStatus(401);
    }

    public function test_queue_order_get_requires_admin(): void
    {
        $response = $this->actingAs($this->normal)->getJson('/settings/fsrs/queue-order');
        $response->assertStatus(403);
    }

    public function test_queue_order_post_requires_admin(): void
    {
        $response = $this->actingAs($this->normal)->postJson('/settings/fsrs/queue-order', [
            'interday_learning_review_order' => 'before',
        ]);
        $response->assertStatus(403);
    }

    public function test_queue_order_does_not_impact_daily_limits(): void
    {
        // Save daily limits baseline
        $this->actingAs($this->admin)->postJson('/settings/fsrs/daily-limits', [
            'daily_new_limit' => 17,
            'daily_review_limit' => 234,
        ])->assertOk();

        // Save queue order
        $this->actingAs($this->admin)->postJson('/settings/fsrs/queue-order', [
            'interday_learning_review_order' => 'after',
        ])->assertOk();

        // Daily limits must be unchanged
        $limits = $this->actingAs($this->admin)->getJson('/settings/fsrs/daily-limits');
        $limits->assertJsonPath('daily_new_limit', 17);
        $limits->assertJsonPath('daily_review_limit', 234);
    }

    public function test_queue_order_uses_global_scope_settings(): void
    {
        // Save queue order
        $this->actingAs($this->admin)->postJson('/settings/fsrs/queue-order', [
            'interday_learning_review_order' => 'before',
        ])->assertOk();

        // Verify stored in settings table with user_id = -1
        $this->assertDatabaseHas('settings', [
            'name' => 'fsrs_queue_interday_learning_review_order',
            'user_id' => -1,
        ]);
        $row = Setting::where('name', 'fsrs_queue_interday_learning_review_order')
            ->where('user_id', -1)
            ->first();
        $this->assertSame('before', json_decode($row->value));
    }
}
