<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewTodayLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Shanghai']);
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'Asia/Shanghai'));
        $this->user = $this->makeUser('today-limits@example.test', 'english');
        Setting::forceCreate(['name' => 'daily_new_limit', 'user_id' => -1, 'value' => json_encode(20)]);
        Setting::forceCreate(['name' => 'daily_review_limit', 'user_id' => -1, 'value' => json_encode(200)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_today_override_is_private_scoped_and_does_not_change_settings(): void
    {
        $response = $this->actingAs($this->user)->putJson('/reviews/senses/today-limits', [
            'new_limit_delta' => 5,
            'review_limit_delta' => 25,
            'pause_new_cards' => false,
            'study_date' => '1999-01-01',
            'user_id' => 999,
            'language_id' => 'german',
        ]);

        $response->assertOk()
            ->assertJsonPath('study_date', '2026-07-14')
            ->assertJsonPath('permanent_new_limit', 20)
            ->assertJsonPath('effective_new_limit', 25)
            ->assertJsonPath('effective_review_limit', 225)
            ->assertJsonPath('override.new_limit_delta', 5);

        $this->assertDatabaseHas('review_daily_limit_overrides', [
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'study_date' => '2026-07-14',
        ]);
        $this->assertDatabaseMissing('review_daily_limit_overrides', ['user_id' => 999]);
        $this->assertSame(20, json_decode(Setting::where('name', 'daily_new_limit')->value('value'), true));
    }

    public function test_pause_and_resume_preserve_delta_and_reset_deletes_today_override(): void
    {
        $this->actingAs($this->user)->putJson('/reviews/senses/today-limits', [
            'new_limit_delta' => 7,
            'review_limit_delta' => 10,
            'pause_new_cards' => true,
        ])->assertJsonPath('effective_new_limit', 0)
            ->assertJsonPath('effective_review_limit', 210)
            ->assertJsonPath('override.review_limit_delta', 10);

        $this->actingAs($this->user)->putJson('/reviews/senses/today-limits', [
            'new_limit_delta' => 7,
            'review_limit_delta' => 10,
            'pause_new_cards' => false,
        ])->assertJsonPath('effective_new_limit', 27)
            ->assertJsonPath('effective_review_limit', 210)
            ->assertJsonPath('override.new_limit_delta', 7);

        $this->actingAs($this->user)->deleteJson('/reviews/senses/today-limits')
            ->assertOk()
            ->assertJsonPath('override', null)
            ->assertJsonPath('effective_new_limit', 20);
    }

    public function test_override_expires_on_next_study_day_without_cleanup(): void
    {
        $this->actingAs($this->user)->putJson('/reviews/senses/today-limits', [
            'new_limit_delta' => 9,
            'review_limit_delta' => 0,
            'pause_new_cards' => false,
        ])->assertJsonPath('effective_new_limit', 29);

        Carbon::setTestNow(Carbon::create(2026, 7, 15, 0, 1, 0, 'Asia/Shanghai'));
        $this->actingAs($this->user)->getJson('/reviews/senses/today-limits')
            ->assertJsonPath('study_date', '2026-07-15')
            ->assertJsonPath('override', null)
            ->assertJsonPath('effective_new_limit', 20);
    }

    public function test_validation_and_authentication(): void
    {
        $this->putJson('/reviews/senses/today-limits', [])->assertUnauthorized();
        $this->actingAs($this->user)->putJson('/reviews/senses/today-limits', [
            'new_limit_delta' => 1000,
            'review_limit_delta' => 10000,
            'pause_new_cards' => 'no',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['new_limit_delta', 'review_limit_delta', 'pause_new_cards']);
    }

    public function test_validation_rejects_negatives_decimals_strings_and_null(): void
    {
        $this->actingAs($this->user)->putJson('/reviews/senses/today-limits', [
            'new_limit_delta' => -1,
            'review_limit_delta' => -1,
            'pause_new_cards' => false,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['new_limit_delta', 'review_limit_delta']);

        $this->actingAs($this->user)->putJson('/reviews/senses/today-limits', [
            'new_limit_delta' => 1.5,
            'review_limit_delta' => 2.5,
            'pause_new_cards' => false,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['new_limit_delta', 'review_limit_delta']);

        $this->actingAs($this->user)->putJson('/reviews/senses/today-limits', [
            'new_limit_delta' => 'abc',
            'review_limit_delta' => 'xyz',
            'pause_new_cards' => false,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['new_limit_delta', 'review_limit_delta']);

        $this->actingAs($this->user)->putJson('/reviews/senses/today-limits', [
            'new_limit_delta' => null,
            'review_limit_delta' => null,
            'pause_new_cards' => false,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['new_limit_delta', 'review_limit_delta']);
    }

    public function test_validation_accepts_boundary_values_zero_and_max(): void
    {
        $this->actingAs($this->user)->putJson('/reviews/senses/today-limits', [
            'new_limit_delta' => 0,
            'review_limit_delta' => 0,
            'pause_new_cards' => false,
        ])->assertOk()
            ->assertJsonPath('override.new_limit_delta', 0)
            ->assertJsonPath('override.review_limit_delta', 0);

        $this->actingAs($this->user)->putJson('/reviews/senses/today-limits', [
            'new_limit_delta' => 999,
            'review_limit_delta' => 9999,
            'pause_new_cards' => false,
        ])->assertOk()
            ->assertJsonPath('override.new_limit_delta', 999)
            ->assertJsonPath('override.review_limit_delta', 9999)
            ->assertJsonPath('effective_new_limit', 1019)
            ->assertJsonPath('effective_review_limit', 10199);
    }

    public function test_legacy_negative_override_is_normalized_on_read(): void
    {
        \App\Models\ReviewDailyLimitOverride::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'study_date' => '2026-07-14',
            'new_limit_delta' => -50,
            'review_limit_delta' => -150,
            'pause_new_cards' => false,
        ]);

        $this->actingAs($this->user)->getJson('/reviews/senses/today-limits')
            ->assertOk()
            ->assertJsonPath('override.new_limit_delta', 0)
            ->assertJsonPath('override.review_limit_delta', 0)
            ->assertJsonPath('effective_new_limit', 20)
            ->assertJsonPath('effective_review_limit', 200);

        $row = \App\Models\ReviewDailyLimitOverride::where('user_id', $this->user->id)
            ->where('language_id', 'english')
            ->where('study_date', '2026-07-14')
            ->first();
        $this->assertSame(-50, (int) $row->getRawOriginal('new_limit_delta'), 'legacy negative must not be rewritten by read');
        $this->assertSame(-150, (int) $row->getRawOriginal('review_limit_delta'));
    }

    public function test_current_override_isolated_by_user_and_language(): void
    {
        $other = $this->makeUser('other-limits@example.test', 'english');
        \App\Models\ReviewDailyLimitOverride::forceCreate([
            'user_id' => $other->id, 'language_id' => 'english', 'study_date' => '2026-07-14',
            'new_limit_delta' => 50, 'review_limit_delta' => 50, 'pause_new_cards' => false,
        ]);
        \App\Models\ReviewDailyLimitOverride::forceCreate([
            'user_id' => $this->user->id, 'language_id' => 'german', 'study_date' => '2026-07-14',
            'new_limit_delta' => 40, 'review_limit_delta' => 40, 'pause_new_cards' => false,
        ]);

        $this->actingAs($this->user)->getJson('/reviews/senses/today-limits')
            ->assertJsonPath('override', null)
            ->assertJsonPath('effective_new_limit', 20);
    }

    public function test_introduced_today_counts_each_truly_new_sense_card_once(): void
    {
        $introduced = $this->makeCard('introduced', 'new');
        $this->makeLog($introduced, ['previous_state' => 'new', 'reviewed_at' => now()->subHours(2)]);
        $this->makeLog($introduced, ['previous_state' => 'learning', 'reviewed_at' => now()->subHour()]);

        $historical = $this->makeCard('historical-reset', 'new');
        $this->makeLog($historical, ['previous_state' => 'review', 'reviewed_at' => now()->subDays(3)]);
        $this->makeLog($historical, ['previous_state' => 'new', 'reviewed_at' => now()->subMinutes(30)]);

        $undone = $this->makeCard('undone', 'new');
        $this->makeLog($undone, ['previous_state' => 'new', 'undone_at' => now()]);

        $custom = $this->makeCard('custom', 'new');
        $this->makeLog($custom, ['previous_state' => 'new', 'source' => 'custom_study']);

        $this->actingAs($this->user)->getJson('/reviews/senses/today-limits')
            ->assertOk()
            ->assertJsonPath('introduced_today_count', 1)
            ->assertJsonPath('remaining_new', 19);
    }

    public function test_today_override_changes_both_normal_and_legacy_queue_results(): void
    {
        $this->makeCard('review-one', 'review');
        $this->makeCard('review-two', 'review');
        $this->actingAs($this->user)->putJson('/reviews/senses/today-limits', [
            'new_limit_delta' => 5,
            'review_limit_delta' => 5,
            'pause_new_cards' => false,
        ])->assertOk();

        $this->actingAs($this->user)->getJson('/reviews/senses')
            ->assertJsonCount(2, 'cards')
            ->assertJsonPath('summary.effective_review_limit', 205);
        $this->actingAs($this->user)->postJson('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
        ])->assertJsonCount(2, 'reviews')
            ->assertJsonPath('summary.effective_review_limit', 205);
    }

    public function test_pause_new_cards_makes_effective_new_zero_but_keeps_delta(): void
    {
        $this->actingAs($this->user)->putJson('/reviews/senses/today-limits', [
            'new_limit_delta' => 15,
            'review_limit_delta' => 30,
            'pause_new_cards' => true,
        ])->assertOk()
            ->assertJsonPath('effective_new_limit', 0)
            ->assertJsonPath('effective_review_limit', 230)
            ->assertJsonPath('override.new_limit_delta', 15)
            ->assertJsonPath('pause_new_cards', true);

        // Resume: delta preserved, new cards flow again.
        $this->actingAs($this->user)->putJson('/reviews/senses/today-limits', [
            'new_limit_delta' => 15,
            'review_limit_delta' => 30,
            'pause_new_cards' => false,
        ])->assertOk()
            ->assertJsonPath('effective_new_limit', 35)
            ->assertJsonPath('override.new_limit_delta', 15);
    }

    private function makeUser(string $email, string $language): User
    {
        return User::forceCreate([
            'name' => 'Today Limits User',
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function makeCard(string $lemma, string $state): ReviewCard
    {
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id, 'language' => 'english', 'language_id' => 'english',
            'lemma' => $lemma, 'surface_form' => $lemma, 'pos' => 'noun',
            'sense_zh' => $lemma, 'sense_en' => $lemma, 'aliases_zh' => [], 'collocations' => [],
            'example_sentence_en' => 'Example.', 'example_sentence_zh' => 'Example.',
            'status' => WordSense::STATUS_CONFIRMED, 'is_context_specific' => true,
            'sense_key' => hash('sha256', $lemma . Str::uuid()),
        ]);

        return ReviewCard::forceCreate([
            'user_id' => $this->user->id, 'language' => 'english', 'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE, 'target_id' => $sense->id,
            'fsrs_state' => $state, 'fsrs_due_at' => now()->subMinute(), 'fsrs_enabled' => true,
            'fsrs_stability' => $state === 'new' ? null : 5.0, 'fsrs_difficulty' => $state === 'new' ? null : 5.0,
        ]);
    }

    private function makeLog(ReviewCard $card, array $overrides): ReviewLog
    {
        return ReviewLog::forceCreate(array_merge([
            'user_id' => $this->user->id, 'language' => 'english', 'language_id' => 'english',
            'review_card_id' => $card->id, 'rating' => 'good', 'reviewed_at' => now()->subHour(),
            'previous_state' => 'review', 'new_state' => 'review', 'source' => 'sense_review',
        ], $overrides));
    }
}
