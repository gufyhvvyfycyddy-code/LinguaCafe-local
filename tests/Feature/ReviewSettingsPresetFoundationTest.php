<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\ReviewSettingPreset;
use App\Models\ReviewSettingPresetBinding;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\Settings\Presets\ReviewSettingsPresetBindingService;
use App\Services\Settings\Presets\ReviewSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewSettingsPresetFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Preset User',
            'email' => 'preset-v1a@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    public function test_first_resolution_snapshots_legacy_settings_and_is_idempotent(): void
    {
        $this->legacy('fsrsDesiredRetention', 0.93);
        $this->legacy('daily_new_limit', 17);
        $this->legacy('fsrs_queue_review_sort_order', 'ascending_retrievability');

        $resolver = app(ReviewSettingsResolver::class);
        $first = $resolver->resolve($this->user->id, 'english');
        $second = $resolver->resolve($this->user->id, 'english');

        $this->assertSame(0.93, $first->fsrsDesiredRetention());
        $this->assertSame(17, $first->dailyLimitsForApi()['daily_new_limit']);
        $this->assertSame('ascending_retrievability', $first->queueOrderForApi()['review_sort_order']);
        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertSame(1, ReviewSettingPreset::where('user_id', $this->user->id)->where('is_default', true)->count());
        $this->assertSame(1, ReviewSettingPresetBinding::where('user_id', $this->user->id)->where('language_id', 'english')->count());
    }

    public function test_new_language_reuses_users_default_with_one_binding_per_language(): void
    {
        $resolver = app(ReviewSettingsResolver::class);
        $resolver->resolve($this->user->id, 'english');
        $resolver->resolve($this->user->id, 'japanese');
        $resolver->resolve($this->user->id, 'japanese');

        $this->assertSame(1, ReviewSettingPreset::where('user_id', $this->user->id)->count());
        $this->assertSame(2, ReviewSettingPresetBinding::where('user_id', $this->user->id)->count());
        $this->assertSame(1, ReviewSettingPresetBinding::where('user_id', $this->user->id)->where('language_id', 'japanese')->count());
        $this->assertSame(1, ReviewSettingPresetBinding::where('user_id', $this->user->id)->pluck('preset_id')->unique()->count());
    }

    public function test_binding_rejects_a_preset_owned_by_another_user(): void
    {
        $other = User::forceCreate([
            'name' => 'Other Preset User',
            'email' => 'preset-v1a-other@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        app(ReviewSettingsResolver::class)->resolve($other->id, 'english');
        $foreignPreset = ReviewSettingPreset::where('user_id', $other->id)->firstOrFail();

        $this->expectException(\DomainException::class);
        app(ReviewSettingsPresetBindingService::class)->bind($this->user->id, 'english', $foreignPreset);
    }

    public function test_database_rejects_a_second_default_for_one_user(): void
    {
        app(ReviewSettingsResolver::class)->resolve($this->user->id, 'english');

        $this->expectException(QueryException::class);
        ReviewSettingPreset::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'Another Default',
            'config' => app(ReviewSettingsResolver::class)->resolve($this->user->id, 'english')->toArray(),
            'is_default' => true,
        ]);
    }

    public function test_database_rejects_a_binding_to_another_users_preset(): void
    {
        $other = User::forceCreate([
            'name' => 'Foreign Owner',
            'email' => 'preset-v1a-foreign-owner@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        app(ReviewSettingsResolver::class)->resolve($other->id, 'english');
        $foreignPresetId = ReviewSettingPreset::where('user_id', $other->id)->value('id');

        $this->expectException(QueryException::class);
        DB::table('review_setting_preset_bindings')->insert([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'preset_id' => $foreignPresetId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_existing_endpoint_payload_is_preserved_but_values_are_user_scoped(): void
    {
        $other = User::forceCreate([
            'name' => 'Other Endpoint User',
            'email' => 'preset-v1a-endpoint-other@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->actingAs($this->user)->postJson('/settings/global/update', [
            'settings' => ['fsrsDesiredRetention' => 0.95],
        ])->assertOk();

        Auth::logout();
        $this->flushSession();
        $this->actingAs($other)->postJson('/settings/global/update', [
            'settings' => ['fsrsDesiredRetention' => 0.85],
        ])->assertOk();

        Auth::logout();
        $this->flushSession();
        $this->actingAs($this->user)->postJson('/settings/global/get', [
            'settingNames' => ['fsrsDesiredRetention'],
        ])->assertOk()->assertExactJson(['fsrsDesiredRetention' => 0.95]);

        Auth::logout();
        $this->flushSession();
        $this->actingAs($other)->postJson('/settings/global/get', [
            'settingNames' => ['fsrsDesiredRetention'],
        ])->assertOk()->assertExactJson(['fsrsDesiredRetention' => 0.85]);
    }

    public function test_metadata_uses_existing_endpoint_and_initialization_has_no_learning_side_effects(): void
    {
        $before = [ReviewLog::count(), ReviewCard::count(), WordSense::count()];

        $this->actingAs($this->user)->postJson('/settings/global/get', [
            'settingNames' => ['reviewSettingsPresetMetadata'],
        ])->assertOk()->assertExactJson([
            'reviewSettingsPresetMetadata' => [
                'name' => 'Default',
                'is_default' => true,
                'language' => 'english',
                'schema_version' => 1,
            ],
        ]);

        $this->assertSame($before, [ReviewLog::count(), ReviewCard::count(), WordSense::count()]);
    }

    public function test_metadata_is_read_only_on_the_existing_update_endpoint(): void
    {
        $this->actingAs($this->user)->postJson('/settings/global/update', [
            'settings' => ['reviewSettingsPresetMetadata' => ['name' => 'Other']],
        ])->assertStatus(500);

        $this->assertDatabaseCount('review_setting_presets', 0);
    }

    public function test_mixed_global_request_preserves_keys_and_routes_only_owned_values_to_preset(): void
    {
        $this->legacy('reviewIntervals', [1 => [1], 2 => [2]]);

        $this->actingAs($this->user)->postJson('/settings/global/update', [
            'settings' => [
                'fsrsDesiredRetention' => 0.95,
                'reviewIntervals' => [1 => [3], 2 => [4]],
            ],
        ])->assertOk()->assertContent('"Settings have been updated successfully."');

        $this->actingAs($this->user)->postJson('/settings/global/get', [
            'settingNames' => ['fsrsDesiredRetention', 'reviewIntervals'],
        ])->assertOk()->assertExactJson([
            'fsrsDesiredRetention' => 0.95,
            'reviewIntervals' => [1 => [3], 2 => [4]],
        ]);

        $this->assertSame(0.95, app(ReviewSettingsResolver::class)
            ->resolve($this->user->id, 'english')->fsrsDesiredRetention());
        $this->assertDatabaseMissing('settings', [
            'user_id' => -1,
            'name' => 'fsrsDesiredRetention',
        ]);
    }

    public function test_disjoint_panel_updates_merge_without_losing_other_sections(): void
    {
        $this->actingAs($this->user)->postJson('/settings/fsrs/daily-limits', [
            'daily_new_limit' => 31,
        ])->assertOk();
        $this->actingAs($this->user)->postJson('/settings/fsrs/queue-order', [
            'new_sort_order' => 'created_desc',
        ])->assertOk();
        $this->actingAs($this->user)->postJson('/settings/global/update', [
            'settings' => ['fsrsDesiredRetention' => 0.93],
        ])->assertOk();

        $config = app(ReviewSettingsResolver::class)->resolve($this->user->id, 'english');
        $this->assertSame(31, $config->dailyLimitsForApi()['daily_new_limit']);
        $this->assertSame('created_desc', $config->queueOrderForApi()['new_sort_order']);
        $this->assertSame(0.93, $config->fsrsDesiredRetention());
    }

    public function test_new_language_binding_does_not_resnapshot_changed_legacy_rows(): void
    {
        $this->legacy('daily_new_limit', 17);
        $resolver = app(ReviewSettingsResolver::class);
        $resolver->resolve($this->user->id, 'english');

        Setting::where('user_id', -1)->where('name', 'daily_new_limit')
            ->update(['value' => json_encode(99)]);

        $japanese = $resolver->resolve($this->user->id, 'japanese');
        $this->assertSame(17, $japanese->dailyLimitsForApi()['daily_new_limit']);
    }

    public function test_existing_invalid_preset_config_fails_closed(): void
    {
        app(ReviewSettingsResolver::class)->resolve($this->user->id, 'english');
        $preset = ReviewSettingPreset::where('user_id', $this->user->id)->firstOrFail();
        $preset->config = ['schema_version' => 999];
        $preset->save();

        $this->expectException(\InvalidArgumentException::class);
        app(ReviewSettingsResolver::class)->resolve($this->user->id, 'english');
    }

    private function legacy(string $name, mixed $value): void
    {
        Setting::forceCreate(['user_id' => -1, 'name' => $name, 'value' => json_encode($value)]);
    }
}
