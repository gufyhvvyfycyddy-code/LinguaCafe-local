<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SenseReviewSmokeDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_prepares_marker_review_card_and_occurrence_paths(): void
    {
        $user = User::forceCreate([
            'name' => 'Smoke Test User',
            'email' => 'sense-smoke@example.test',
            'password' => Hash::make(Str::random(40)),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->artisan('smoke:sense-review-data', [
            '--email' => $user->email,
            '--language' => 'english',
            '--marker' => 'codex_smoke_case',
            '--json' => true,
        ])->assertSuccessful();

        $reviewSense = WordSense::where('user_id', $user->id)
            ->where('language_id', 'english')
            ->where('lemma', 'codex_smoke_case_review')
            ->first();

        $this->assertNotNull($reviewSense);

        $reviewCard = ReviewCard::where('user_id', $user->id)
            ->where('language_id', 'english')
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->where('target_id', $reviewSense->id)
            ->first();

        $this->assertNotNull($reviewCard);
        $this->assertTrue($reviewCard->fsrs_enabled);
        $this->assertTrue($reviewCard->fsrs_due_at->lte(now()));
        $this->assertSame('review', $reviewCard->fsrs_state);
        $this->assertSame(2, $reviewCard->fsrs_reps);

        $pending = WordSenseOccurrence::where('user_id', $user->id)
            ->where('language_id', 'english')
            ->where('status', WordSenseOccurrence::STATUS_PENDING)
            ->where('sentence_id', 'like', 'codex_smoke_case_%')
            ->pluck('sentence_id')
            ->all();

        $this->assertContains('codex_smoke_case_confirm_sentence', $pending);
        $this->assertContains('codex_smoke_case_ignore_sentence', $pending);
        $this->assertContains('codex_smoke_case_reject_sentence', $pending);
        $this->assertContains('codex_smoke_case_bind_sentence', $pending);
        $this->assertContains('codex_smoke_case_create_sentence', $pending);

        $this->assertDatabaseHas('word_sense_occurrences', [
            'user_id' => $user->id,
            'language_id' => 'english',
            'sentence_id' => 'codex_smoke_case_review_source_sentence',
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'review_card_id' => $reviewCard->id,
        ]);
    }

    public function test_command_requires_an_existing_user(): void
    {
        $this->artisan('smoke:sense-review-data', [
            '--email' => 'missing@example.test',
            '--marker' => 'codex_missing_user',
        ])->assertFailed();

        $this->assertSame(0, WordSense::count());
        $this->assertSame(0, WordSenseOccurrence::count());
        $this->assertSame(0, ReviewCard::count());
    }
}
