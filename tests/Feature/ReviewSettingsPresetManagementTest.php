<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\ReviewSettingPreset;
use App\Models\ReviewSettingPresetBinding;
use App\Models\User;
use App\Models\WordSense;
use App\Services\Settings\Presets\ReviewSettingsPresetConfig;
use App\Services\Settings\Presets\ReviewSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewSettingsPresetManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('preset-v1b-admin@example.test', true);
        $this->other = $this->user('preset-v1b-other@example.test', true);
    }

    public function test_list_initializes_default_and_returns_canonical_state(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/settings/review-presets');

        $response->assertOk()
            ->assertJsonPath('current_language', 'english')
            ->assertJsonPath('presets.0.name', 'Default')
            ->assertJsonPath('presets.0.is_default', true)
            ->assertJsonPath('presets.0.is_current', true)
            ->assertJsonPath('presets.0.bound_languages.0', 'english');

        $this->assertDatabaseCount('review_setting_presets', 1);
        $this->assertDatabaseCount('review_setting_preset_bindings', 1);
    }

    public function test_create_uses_system_defaults_and_does_not_change_current_binding(): void
    {
        $resolver = app(ReviewSettingsResolver::class);
        $resolver->mutate($this->admin->id, 'english', ['fsrs' => ['desired_retention' => 0.95]]);
        $currentId = ReviewSettingPresetBinding::where('user_id', $this->admin->id)->value('preset_id');

        $response = $this->actingAs($this->admin)->postJson('/settings/review-presets', [
            'name' => 'Focused Study',
        ]);

        $response->assertOk();
        $created = ReviewSettingPreset::where('user_id', $this->admin->id)
            ->where('name', 'Focused Study')->firstOrFail();

        $this->assertNull($created->is_default);
        $this->assertSame(0.90, (float) $created->config['fsrs']['desired_retention']);
        $this->assertSame((int) $currentId, (int) ReviewSettingPresetBinding::where('user_id', $this->admin->id)->value('preset_id'));
    }

    public function test_clone_copies_selected_config_and_requires_a_unique_non_default_name(): void
    {
        $resolver = app(ReviewSettingsResolver::class);
        $resolver->mutate($this->admin->id, 'english', ['fsrs' => ['desired_retention' => 0.93]]);
        $default = ReviewSettingPreset::where('user_id', $this->admin->id)->firstOrFail();

        $response = $this->actingAs($this->admin)->postJson(
            "/settings/review-presets/{$default->id}/clone",
            ['name' => 'Default Copy']
        );

        $response->assertOk();
        $copy = ReviewSettingPreset::where('user_id', $this->admin->id)
            ->where('name', 'Default Copy')->firstOrFail();
        $this->assertSame(0.93, (float) $copy->config['fsrs']['desired_retention']);
        $this->assertNull($copy->is_default);

        $this->actingAs($this->admin)
            ->postJson("/settings/review-presets/{$default->id}/clone", ['name' => 'Default Copy'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', '该 Preset 名称已存在。');
    }

    public function test_rename_updates_an_ordinary_preset_but_protects_default_and_reserved_name(): void
    {
        $this->actingAs($this->admin)->postJson('/settings/review-presets', ['name' => 'Old Name'])->assertOk();
        $ordinary = ReviewSettingPreset::where('user_id', $this->admin->id)->where('name', 'Old Name')->firstOrFail();
        $default = ReviewSettingPreset::where('user_id', $this->admin->id)->where('is_default', true)->firstOrFail();

        $this->actingAs($this->admin)
            ->patchJson("/settings/review-presets/{$ordinary->id}", ['name' => 'New Name'])
            ->assertOk();
        $this->assertDatabaseHas('review_setting_presets', ['id' => $ordinary->id, 'name' => 'New Name']);

        $this->actingAs($this->admin)
            ->patchJson("/settings/review-presets/{$default->id}", ['name' => 'Renamed Default'])
            ->assertStatus(422);
        $this->actingAs($this->admin)
            ->patchJson("/settings/review-presets/{$ordinary->id}", ['name' => 'default'])
            ->assertStatus(422);
    }

    public function test_switch_changes_only_the_current_language_binding(): void
    {
        $resolver = app(ReviewSettingsResolver::class);
        $resolver->resolve($this->admin->id, 'english');
        $resolver->resolve($this->admin->id, 'french');
        $defaultId = ReviewSettingPreset::where('user_id', $this->admin->id)->where('is_default', true)->value('id');
        $this->actingAs($this->admin)->postJson('/settings/review-presets', ['name' => 'French Focus'])->assertOk();
        $focusId = ReviewSettingPreset::where('user_id', $this->admin->id)->where('name', 'French Focus')->value('id');

        $this->admin->selected_language = 'french';
        $this->admin->save();

        $this->actingAs($this->admin->fresh())
            ->putJson('/settings/review-presets/current-language', ['preset_id' => $focusId])
            ->assertOk()
            ->assertJsonPath('current_preset_id', $focusId);

        $this->assertDatabaseHas('review_setting_preset_bindings', [
            'user_id' => $this->admin->id, 'language_id' => 'english', 'preset_id' => $defaultId,
        ]);
        $this->assertDatabaseHas('review_setting_preset_bindings', [
            'user_id' => $this->admin->id, 'language_id' => 'french', 'preset_id' => $focusId,
        ]);
    }

    public function test_delete_rebinds_all_languages_to_default_inside_the_user_boundary(): void
    {
        $resolver = app(ReviewSettingsResolver::class);
        $resolver->resolve($this->admin->id, 'english');
        $resolver->resolve($this->admin->id, 'french');
        $defaultId = ReviewSettingPreset::where('user_id', $this->admin->id)->where('is_default', true)->value('id');
        $this->actingAs($this->admin)->postJson('/settings/review-presets', ['name' => 'Shared'])->assertOk();
        $sharedId = ReviewSettingPreset::where('user_id', $this->admin->id)->where('name', 'Shared')->value('id');
        ReviewSettingPresetBinding::where('user_id', $this->admin->id)->update(['preset_id' => $sharedId]);

        $response = $this->actingAs($this->admin)->deleteJson("/settings/review-presets/{$sharedId}");

        $response->assertOk();
        $this->assertDatabaseMissing('review_setting_presets', ['id' => $sharedId]);
        $this->assertSame(2, ReviewSettingPresetBinding::where('user_id', $this->admin->id)
            ->where('preset_id', $defaultId)->count());

        $this->actingAs($this->admin)
            ->deleteJson("/settings/review-presets/{$defaultId}")
            ->assertStatus(422);
    }

    public function test_cross_owner_ids_are_hidden_for_clone_rename_delete_and_switch(): void
    {
        app(ReviewSettingsResolver::class)->resolve($this->other->id, 'english');
        $foreignId = ReviewSettingPreset::where('user_id', $this->other->id)->value('id');

        $this->actingAs($this->admin)
            ->postJson("/settings/review-presets/{$foreignId}/clone", ['name' => 'Nope'])
            ->assertNotFound();
        $this->actingAs($this->admin)
            ->patchJson("/settings/review-presets/{$foreignId}", ['name' => 'Nope'])
            ->assertNotFound();
        $this->actingAs($this->admin)
            ->deleteJson("/settings/review-presets/{$foreignId}")
            ->assertNotFound();
        $this->actingAs($this->admin)
            ->putJson('/settings/review-presets/current-language', ['preset_id' => $foreignId])
            ->assertNotFound();
    }

    public function test_management_actions_do_not_touch_learning_records_or_scheduling(): void
    {
        $before = $this->learningSnapshot();

        $this->actingAs($this->admin)->getJson('/settings/review-presets')->assertOk();
        $this->actingAs($this->admin)->postJson('/settings/review-presets', ['name' => 'Safe'])->assertOk();
        $safe = ReviewSettingPreset::where('user_id', $this->admin->id)->where('name', 'Safe')->firstOrFail();
        $this->actingAs($this->admin)
            ->putJson('/settings/review-presets/current-language', ['preset_id' => $safe->id])->assertOk();
        $this->actingAs($this->admin)->deleteJson("/settings/review-presets/{$safe->id}")->assertOk();

        $this->assertSame($before, $this->learningSnapshot());
    }

    public function test_routes_require_authentication_and_admin_access(): void
    {
        $this->getJson('/settings/review-presets')->assertUnauthorized();

        $normal = $this->user('preset-v1b-normal@example.test', false);
        $this->actingAs($normal)->getJson('/settings/review-presets')->assertForbidden();
    }

    private function user(string $email, bool $admin): User
    {
        return User::forceCreate([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => $admin,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function learningSnapshot(): array
    {
        return [
            'word_senses' => WordSense::count(),
            'review_cards' => ReviewCard::count(),
            'review_logs' => ReviewLog::count(),
            'due_values' => ReviewCard::orderBy('id')->pluck('fsrs_due_at', 'id')->all(),
            'lifecycle_values' => ReviewCard::orderBy('id')->pluck('lifecycle_state', 'id')->all(),
        ];
    }
}
