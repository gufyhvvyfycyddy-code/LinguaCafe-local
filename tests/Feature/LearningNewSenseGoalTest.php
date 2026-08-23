<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\GoalAchievement;
use App\Models\User;
use App\Models\WordSense;
use App\Services\GoalService;
use App\Services\LearningHistoryQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class LearningNewSenseGoalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private GoalService $goals;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-23 12:00:00', config('app.timezone', 'UTC')));
        $this->user = $this->createUser('owner');
        $this->goals = app(GoalService::class);
        $this->goals->createGoalsForLanguage($this->user->id, 'english');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_today_count_is_scoped_reading_sense_rows_not_distinct_lemmas(): void
    {
        $this->trackedSense('bank', WordSense::LEARNING_ORIGIN_READING, now()->subHour());
        $this->trackedSense('bank', WordSense::LEARNING_ORIGIN_READING, now()->subMinutes(5));
        $this->trackedSense('plain', WordSense::LEARNING_ORIGIN_NON_READING, now());
        $this->trackedSense('old', WordSense::LEARNING_ORIGIN_READING, now()->subDay());
        $this->trackedSense('other-language', WordSense::LEARNING_ORIGIN_READING, now(), $this->user, 'spanish');
        $this->trackedSense('other-user', WordSense::LEARNING_ORIGIN_READING, now(), $this->createUser('other'));
        $target = Goal::where('user_id', $this->user->id)
            ->where('language', 'english')
            ->where('type', 'learn_words')
            ->firstOrFail();
        $this->goals->updateGoal($this->user->id, $target->id, 1);

        $count = app(LearningHistoryQueryService::class)
            ->countReadingSensesStartedToday($this->user->id, 'english');

        $this->assertSame(2, $count);
        $learnGoal = $this->actingAs($this->user)
            ->postJson('/goals/get')
            ->assertOk()
            ->json();
        $learnGoal = collect($learnGoal)->firstWhere('type', 'learn_words');
        $this->assertSame(2, $learnGoal['todaysQuantity']);
        $this->assertSame(1, $learnGoal['quantity']);
    }

    public function test_daily_count_uses_the_study_timezone_half_open_day_boundary(): void
    {
        $timezone = app(\App\Services\ReviewStudyTimezoneService::class)->getStudyTimezone();
        $dayOne = Carbon::parse('2026-08-23 23:59:59', $timezone);
        $dayTwo = Carbon::parse('2026-08-24 00:00:00', $timezone);
        $this->trackedSense('before-midnight', WordSense::LEARNING_ORIGIN_READING, $dayOne);
        $this->trackedSense('at-midnight', WordSense::LEARNING_ORIGIN_READING, $dayTwo);
        $query = app(LearningHistoryQueryService::class);

        $this->assertSame(1, $query->countReadingSensesStartedToday($this->user->id, 'english', $dayOne));
        $this->assertSame(1, $query->countReadingSensesStartedToday($this->user->id, 'english', $dayTwo));
    }

    public function test_legacy_learn_words_writers_are_rejected_and_target_edit_stays_target_only(): void
    {
        $goal = Goal::where('user_id', $this->user->id)
            ->where('language', 'english')
            ->where('type', 'learn_words')
            ->firstOrFail();
        $achievement = GoalAchievement::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'goal_id' => $goal->id,
            'achieved_quantity' => 9,
            'goal_quantity' => $goal->quantity,
            'day' => now()->toDateString(),
        ]);

        try {
            $this->goals->updateGoalAchievement($this->user->id, 'english', 'learn_words', 1);
            $this->fail('Derived learn_words achievement accepted a legacy increment.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('derived', $exception->getMessage());
        }

        $this->goals->updateGoal($this->user->id, $goal->id, 15);
        $this->assertSame(15, $goal->fresh()->quantity);
        $this->assertSame(9, $achievement->fresh()->achieved_quantity);
        $this->assertSame(10, $achievement->fresh()->goal_quantity);

        $this->actingAs($this->user)->postJson('/goals/achievement/update', [
            'achievementGoalId' => $achievement->id,
            'achievementType' => 'learn_words',
            'day' => now()->toDateString(),
            'newValue' => 99,
        ])->assertUnprocessable();
        $this->assertSame(9, $achievement->fresh()->achieved_quantity);
    }

    private function createUser(string $label): User
    {
        return User::forceCreate([
            'name' => "Goal {$label}",
            'email' => "goal-{$label}-".Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function trackedSense(
        string $lemma,
        string $origin,
        Carbon $startedAt,
        ?User $owner = null,
        string $language = 'english',
    ): WordSense {
        $owner ??= $this->user;

        return WordSense::forceCreate([
            'user_id' => $owner->id,
            'language' => $language,
            'language_id' => $language,
            'lemma' => $lemma,
            'sense_key' => $lemma.'-'.Str::uuid(),
            'sense_zh' => '释义',
            'status' => WordSense::STATUS_CONFIRMED,
            'learning_started_at' => $startedAt,
            'learning_started_origin' => $origin,
        ]);
    }
}
