<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewDurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sense_rating_persists_optional_duration_and_rejects_invalid_values(): void
    {
        [$user, $card] = $this->fixture();
        $this->actingAs($user)->postJson('/reviews/senses/' . $card->id . '/rate', [
            'rating' => 'good', 'review_duration_ms' => 4321,
        ])->assertOk();
        $this->assertSame(4321, ReviewLog::latest('id')->value('review_duration_ms'));

        foreach ([-1, 600001, 1.5, 'abc'] as $invalid) {
            $this->actingAs($user)->postJson('/reviews/senses/' . $card->id . '/rate', [
                'rating' => 'good', 'review_duration_ms' => $invalid,
            ])->assertUnprocessable()->assertJsonValidationErrors(['review_duration_ms']);
        }
    }

    public function test_missing_duration_remains_null_and_legacy_endpoint_records_duration(): void
    {
        [$user, $card] = $this->fixture();
        $this->actingAs($user)->postJson('/reviews/rate', [
            'reviewCardId' => $card->id, 'rating' => 'good', 'review_duration_ms' => 987,
        ])->assertOk();
        $this->assertSame(987, ReviewLog::latest('id')->value('review_duration_ms'));

        [, $second] = $this->fixture($user, 'second-duration-card');
        $this->actingAs($user)->postJson('/reviews/senses/' . $second->id . '/rate', ['rating' => 'good'])->assertOk();
        $this->assertNull(ReviewLog::latest('id')->value('review_duration_ms'));
    }

    private function fixture(?User $user = null, string $lemma = 'duration-card'): array
    {
        $user ??= User::forceCreate([
            'name' => 'Duration User', 'email' => 'duration@example.test', 'password' => Hash::make('password'),
            'selected_language' => 'english', 'password_changed' => true, 'is_admin' => true, 'uuid' => (string) Str::uuid(),
        ]);
        $sense = WordSense::forceCreate([
            'user_id' => $user->id, 'language' => 'english', 'language_id' => 'english', 'lemma' => $lemma, 'surface_form' => $lemma,
            'pos' => 'noun', 'sense_zh' => $lemma, 'sense_en' => $lemma, 'aliases_zh' => [], 'collocations' => [],
            'example_sentence_en' => 'Example.', 'example_sentence_zh' => 'Example.', 'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true, 'sense_key' => hash('sha256', $lemma),
        ]);
        $card = ReviewCard::forceCreate([
            'user_id' => $user->id, 'language' => 'english', 'language_id' => 'english', 'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id, 'fsrs_state' => 'review', 'fsrs_due_at' => Carbon::now()->subMinute(),
            'fsrs_enabled' => true, 'fsrs_stability' => 5, 'fsrs_difficulty' => 5, 'fsrs_last_reviewed_at' => Carbon::now()->subDays(5),
        ]);
        return [$user, $card];
    }
}
