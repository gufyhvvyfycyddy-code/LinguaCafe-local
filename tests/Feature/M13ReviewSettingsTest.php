<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewSettingPreset;
use App\Models\User;
use App\Services\Settings\Presets\ReviewSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class M13ReviewSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::forceCreate([
            'name' => 'M13 User',
            'email' => 'm13-settings@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    public function test_additive_step_index_column_exists(): void
    {
        $this->assertTrue(Schema::hasColumn('review_cards', 'fsrs_step_index'));
    }

    public function test_advanced_settings_require_authentication(): void
    {
        $this->getJson('/settings/fsrs/advanced-settings')->assertUnauthorized();
        $this->putJson('/settings/fsrs/advanced-settings', [])->assertUnauthorized();
    }

    public function test_defaults_are_returned_without_learning_writes(): void
    {
        $beforeCards = ReviewCard::count();

        $this->actingAs($this->user)
            ->getJson('/settings/fsrs/advanced-settings')
            ->assertOk()
            ->assertJsonPath('schema_version', 3)
            ->assertJsonPath('scheduling.learning_steps_minutes.0', 10)
            ->assertJsonPath('scheduling.learning_steps_minutes.1', 30)
            ->assertJsonPath('experience.auto_advance_enabled', false)
            ->assertJsonPath('preset.language', 'english');

        $this->assertSame($beforeCards, ReviewCard::count());
    }

    public function test_settings_update_is_preset_scoped_and_future_only(): void
    {
        $resolver = app(ReviewSettingsResolver::class);
        $resolver->resolve($this->user->id, 'english');
        $beforePreset = ReviewSettingPreset::where('user_id', $this->user->id)->firstOrFail();

        $response = $this->actingAs($this->user)
            ->putJson('/settings/fsrs/advanced-settings', [
                'scheduling' => [
                    'learning_steps_minutes' => [5, 20],
                    'relearning_steps_minutes' => [],
                    'maximum_interval_days' => 365,
                    'minimum_relearning_interval_days' => 2,
                    'easy_days' => ['minimum', 'normal', 'normal', 'normal', 'normal', 'reduced', 'minimum'],
                ],
                'experience' => [
                    'show_timer' => true,
                    'question_timer_seconds' => 15,
                    'answer_timer_seconds' => 10,
                    'auto_advance_enabled' => true,
                    'audio_autoplay' => true,
                    'audio_replay_answer' => false,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('scheduling.maximum_interval_days', 365)
            ->assertJsonPath('experience.auto_advance_enabled', true);

        $this->assertSame($beforePreset->id, ReviewSettingPreset::where('user_id', $this->user->id)->value('id'));
        $this->assertSame([5, 20], $resolver->resolve($this->user->id, 'english')->scheduling()['learning_steps_minutes']);
        $this->assertStringContainsString('之后', $response->json('message'));
    }

    public function test_invalid_auto_advance_and_steps_return_422(): void
    {
        $this->actingAs($this->user)
            ->putJson('/settings/fsrs/advanced-settings', [
                'scheduling' => [
                    'learning_steps_minutes' => [30, 10],
                ],
                'experience' => [
                    'question_timer_seconds' => 0,
                    'answer_timer_seconds' => 0,
                    'auto_advance_enabled' => true,
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
