<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\GoalAchievement;
use App\Models\User;
use App\Services\GoalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalIdentityServiceRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_default_goal_creation_preserves_custom_quantity_and_achievement_link(): void
    {
        $user = User::factory()->create([
            'selected_language' => 'english',
        ]);
        $reviewGoal = Goal::forceCreate([
            'name' => 'Custom Reviews',
            'user_id' => $user->id,
            'language' => 'english',
            'type' => 'review',
            'target_id' => null,
            'current_chapter' => null,
            'quantity' => 37,
        ]);
        $service = app(GoalService::class);

        $service->createGoalsForLanguage($user->id, ' English ');
        $service->createGoalsForLanguage($user->id, 'ENGLISH');
        $service->updateGoalAchievement($user->id, ' English ', ' REVIEW ', 4);

        $this->assertSame(
            ['learn_words', 'read_words', 'review'],
            Goal::query()
                ->where('user_id', $user->id)
                ->where('language', 'english')
                ->orderBy('type')
                ->pluck('type')
                ->all(),
        );
        $this->assertSame(3, Goal::query()->where('user_id', $user->id)->count());
        $this->assertSame(37, (int) $reviewGoal->fresh()->quantity);

        $achievement = GoalAchievement::query()
            ->where('user_id', $user->id)
            ->where('language', 'english')
            ->where('day', now()->toDateString())
            ->firstOrFail();
        $this->assertSame($reviewGoal->id, (int) $achievement->goal_id);
        $this->assertSame(4, (int) $achievement->achieved_quantity);
        $this->assertSame(37, (int) $achievement->goal_quantity);
    }
}
