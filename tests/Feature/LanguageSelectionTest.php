<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\User;
use App\Services\GoalService;
use App\Services\LanguageService;
use App\Services\RestoreWriteFence;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LanguageSelectionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'backup.restore_coordination_store' => 'array',
            'cache.default' => 'array',
        ]);
    }

    public function test_language_selection_api_returns_study_languages_for_current_user(): void
    {
        Http::fake([
            '*' => Http::response(['Japanese'], 200),
        ]);

        $user = $this->createUser();

        $response = $this->actingAs($user)->getJson('/languages/get-language-selection-dialog-data');

        $response->assertOk();
        $this->assertContains('English', $response->json('languages'));
        $this->assertContains('Japanese', $response->json('languages'));
    }

    public function test_language_selection_api_falls_back_when_python_service_is_unavailable(): void
    {
        Http::fake(function () {
            throw new RuntimeException('Python service unavailable.');
        });

        $user = $this->createUser();

        $response = $this->actingAs($user)->getJson('/languages/get-language-selection-dialog-data');

        $response->assertOk();
        $this->assertContains('English', $response->json('languages'));
        $this->assertNotContains('Japanese', $response->json('languages'));
    }

    public function test_route_contract_registers_only_put_for_language_selection(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => $route->uri() === 'languages/select/{language}')
            ->values();

        $this->assertCount(1, $routes);
        $this->assertSame(['PUT'], $routes->first()->methods());
    }

    public function test_get_language_selection_is_method_not_allowed_and_has_no_side_effects(): void
    {
        $user = $this->createUser('english');

        $this->actingAs($user)
            ->getJson('/languages/select/french')
            ->assertMethodNotAllowed();

        $this->assertSame('english', $user->refresh()->selected_language);
        $this->assertSame(0, $this->goalCount($user, 'french'));
    }

    public function test_head_language_selection_is_method_not_allowed_and_has_no_side_effects(): void
    {
        $user = $this->createUser('english');

        $this->actingAs($user)
            ->call('HEAD', '/languages/select/french')
            ->assertMethodNotAllowed();

        $this->assertSame('english', $user->refresh()->selected_language);
        $this->assertSame(0, $this->goalCount($user, 'french'));
    }

    public function test_put_switches_language_and_ensures_all_default_goals(): void
    {
        Http::fake([
            '*' => Http::response([], 200),
        ]);
        $user = $this->createUser('english');

        $this->actingAs($user)
            ->putJson('/languages/select/french')
            ->assertOk()
            ->assertJsonPath('language', 'french');

        $this->assertSame('french', $user->refresh()->selected_language);
        $this->assertDefaultGoals($user, 'french');
    }

    public function test_put_normalizes_language_id_and_accepts_installed_required_language(): void
    {
        Http::fake([
            '*' => Http::response(['Japanese'], 200),
        ]);
        $user = $this->createUser('english');

        $this->actingAs($user)
            ->putJson('/languages/select/JAPANESE')
            ->assertOk()
            ->assertJsonPath('language', 'japanese');

        $this->assertSame('japanese', $user->refresh()->selected_language);
        $this->assertDefaultGoals($user, 'japanese');
    }

    public function test_repeated_put_is_idempotent_and_does_not_duplicate_goals(): void
    {
        $user = $this->createUser('english');

        $this->actingAs($user)->putJson('/languages/select/french')->assertOk();
        $this->actingAs($user)->putJson('/languages/select/french')->assertOk();

        $this->assertSame('french', $user->refresh()->selected_language);
        $this->assertDefaultGoals($user, 'french');
        $this->assertSame(3, $this->goalCount($user, 'french'));
    }

    public function test_partial_default_goals_are_completed_without_overwriting_custom_quantity(): void
    {
        $user = $this->createUser('english');
        Goal::forceCreate([
            'user_id' => $user->id,
            'language' => 'french',
            'name' => 'Reviews',
            'type' => 'review',
            'quantity' => 37,
        ]);

        $this->actingAs($user)->putJson('/languages/select/french')->assertOk();

        $this->assertDefaultGoals($user, 'french');
        $this->assertSame(
            37,
            (int) Goal::query()
                ->where('user_id', $user->id)
                ->where('language', 'french')
                ->where('type', 'review')
                ->value('quantity'),
        );
    }

    public function test_switch_does_not_change_other_user_or_other_language_goals(): void
    {
        $user = $this->createUser('english');
        $other = $this->createUser('english');
        Goal::forceCreate([
            'user_id' => $other->id,
            'language' => 'french',
            'name' => 'Reading',
            'type' => 'read_words',
            'quantity' => 444,
        ]);
        Goal::forceCreate([
            'user_id' => $user->id,
            'language' => 'spanish',
            'name' => 'New words',
            'type' => 'learn_words',
            'quantity' => 22,
        ]);

        $this->actingAs($user)->putJson('/languages/select/french')->assertOk();

        $this->assertDatabaseHas('goals', [
            'user_id' => $other->id,
            'language' => 'french',
            'type' => 'read_words',
            'quantity' => 444,
        ]);
        $this->assertDatabaseHas('goals', [
            'user_id' => $user->id,
            'language' => 'spanish',
            'type' => 'learn_words',
            'quantity' => 22,
        ]);
    }

    public function test_unsupported_language_returns_structured_422_and_writes_nothing(): void
    {
        $user = $this->createUser('english');

        $this->actingAs($user)
            ->putJson('/languages/select/klingon')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'UNSUPPORTED_LANGUAGE');

        $this->assertSame('english', $user->refresh()->selected_language);
        $this->assertSame(0, $this->goalCount($user, 'klingon'));
    }

    public function test_required_language_not_installed_returns_structured_conflict_and_writes_nothing(): void
    {
        Http::fake([
            '*' => Http::response([], 200),
        ]);
        $user = $this->createUser('english');

        $this->actingAs($user)
            ->putJson('/languages/select/japanese')
            ->assertConflict()
            ->assertJsonPath('error.code', 'LANGUAGE_NOT_INSTALLED');

        $this->assertSame('english', $user->refresh()->selected_language);
        $this->assertSame(0, $this->goalCount($user, 'japanese'));
    }

    public function test_python_service_failure_does_not_block_language_without_install_requirement(): void
    {
        Http::fake(function () {
            throw new RuntimeException('Python service unavailable.');
        });
        $user = $this->createUser('spanish');

        $this->actingAs($user)
            ->putJson('/languages/select/english')
            ->assertOk()
            ->assertJsonPath('language', 'english');

        $this->assertSame('english', $user->refresh()->selected_language);
        $this->assertDefaultGoals($user, 'english');
        Http::assertNothingSent();
    }

    public function test_python_service_failure_fails_closed_for_required_language(): void
    {
        Http::fake(function () {
            throw new RuntimeException('Python service unavailable.');
        });
        $user = $this->createUser('english');

        $this->actingAs($user)
            ->putJson('/languages/select/japanese')
            ->assertConflict()
            ->assertJsonPath('error.code', 'LANGUAGE_NOT_INSTALLED');

        $this->assertSame('english', $user->refresh()->selected_language);
        $this->assertSame(0, $this->goalCount($user, 'japanese'));
    }

    public function test_goal_failure_rolls_back_language_and_partial_goal_creation(): void
    {
        $user = $this->createUser('english');
        $goalService = Mockery::mock(GoalService::class);
        $goalService->shouldReceive('ensureDefaultGoalsForLockedUser')
            ->once()
            ->andReturnUsing(function (int $userId, string $language): void {
                Goal::forceCreate([
                    'user_id' => $userId,
                    'language' => $language,
                    'name' => 'Reviews',
                    'type' => 'review',
                    'quantity' => 0,
                ]);

                throw new RuntimeException('Simulated goal creation failure.');
            });

        $service = new LanguageService($goalService);

        try {
            $service->selectLanguage($user, 'french');
            $this->fail('Expected language selection to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated goal creation failure.', $exception->getMessage());
        }

        $this->assertSame('english', $user->refresh()->selected_language);
        $this->assertSame(0, $this->goalCount($user, 'french'));
    }

    public function test_restore_write_fence_rejects_put_before_language_or_goals_change(): void
    {
        $user = $this->createUser('english');
        $operationId = (string) Str::uuid();
        app(RestoreWriteFence::class)->activate($operationId);

        try {
            $this->actingAs($user)
                ->putJson('/languages/select/french')
                ->assertServiceUnavailable()
                ->assertJsonPath('error.code', 'RESTORE_WRITE_FENCE_ACTIVE');

            $this->assertSame('english', $user->refresh()->selected_language);
            $this->assertSame(0, $this->goalCount($user, 'french'));
        } finally {
            app(RestoreWriteFence::class)->deactivate($operationId);
        }
    }

    public function test_guest_put_returns_unauthenticated_contract(): void
    {
        $this->putJson('/languages/select/english')->assertUnauthorized();
    }

    private function createUser(string $language = 'english'): User
    {
        return User::forceCreate([
            'name' => 'Language User',
            'email' => 'language-'.Str::uuid().'@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function goalCount(User $user, string $language): int
    {
        return Goal::query()
            ->where('user_id', $user->id)
            ->where('language', $language)
            ->count();
    }

    private function assertDefaultGoals(User $user, string $language): void
    {
        $this->assertSame(3, $this->goalCount($user, $language));
        $this->assertSame(
            ['learn_words', 'read_words', 'review'],
            Goal::query()
                ->where('user_id', $user->id)
                ->where('language', $language)
                ->orderBy('type')
                ->pluck('type')
                ->all(),
        );
    }
}
