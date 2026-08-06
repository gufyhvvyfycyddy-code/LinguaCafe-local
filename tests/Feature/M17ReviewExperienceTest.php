<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\Settings\Presets\ReviewSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class M17ReviewExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sense_queue_additively_returns_scoped_experience_without_learning_writes(): void
    {
        $user = $this->user('m17@example.test', 'english');
        $other = $this->user('m17-other@example.test', 'french');
        app(ReviewSettingsResolver::class)->mutate($user->id, 'english', [
            'experience' => [
                'show_timer' => true,
                'question_timer_seconds' => 12,
                'answer_timer_seconds' => 7,
                'auto_advance_enabled' => true,
            ],
        ]);
        app(ReviewSettingsResolver::class)->mutate($other->id, 'french', [
            'experience' => [
                'show_timer' => false,
                'question_timer_seconds' => 30,
                'answer_timer_seconds' => 0,
                'auto_advance_enabled' => true,
            ],
        ]);
        $card = $this->card($user, marker: 4);
        $before = [ReviewCard::count(), ReviewLog::count()];

        $this->actingAs($user)->getJson('/reviews/senses')
            ->assertOk()
            ->assertJsonPath('experience.show_timer', true)
            ->assertJsonPath('experience.question_timer_seconds', 12)
            ->assertJsonPath('experience.answer_timer_seconds', 7)
            ->assertJsonPath('experience.auto_advance_enabled', true)
            ->assertJsonPath('cards.0.review_card_id', $card->id)
            ->assertJsonPath('cards.0.marker', 4)
            ->assertJsonMissingPath('experience.audio_autoplay');

        $this->assertSame($before, [ReviewCard::count(), ReviewLog::count()]);
        $this->assertSame('review', $card->fresh()->fsrs_state);
    }

    public function test_sense_queue_experience_requires_authentication(): void
    {
        $this->getJson('/reviews/senses')->assertUnauthorized();
    }

    private function user(string $email, string $language): User
    {
        return User::forceCreate([
            'name' => 'M17 User',
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function card(User $user, int $marker): ReviewCard
    {
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => $user->selected_language,
            'language_id' => $user->selected_language,
            'lemma' => 'focus',
            'surface_form' => 'focus',
            'pos' => 'noun',
            'sense_zh' => '焦点',
            'sense_en' => 'the center of attention',
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
            'sense_key' => hash('sha256', $user->id . '|m17-focus'),
        ]);

        return ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language' => $user->selected_language,
            'language_id' => $user->selected_language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subMinute(),
            'fsrs_enabled' => true,
            'fsrs_stability' => 8,
            'fsrs_difficulty' => 5,
            'marker' => $marker,
        ]);
    }
}
