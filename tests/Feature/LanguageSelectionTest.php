<?php

namespace Tests\Feature;

use App\Http\Controllers\HomeController;
use App\Models\Book;
use App\Models\Goal;
use App\Models\User;
use App\Services\LanguageService;
use App\Services\RestoreWriteFence;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
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

    public function test_ordinary_language_dialog_exposes_english_only_without_python_lookup(): void
    {
        Http::fake();
        $user = $this->createUser();

        $this->actingAs($user)
            ->getJson('/languages/get-language-selection-dialog-data')
            ->assertOk()
            ->assertExactJson([
                'languages' => ['English'],
                'notInstalledLanguages' => 0,
            ]);

        Http::assertNothingSent();
    }

    public function test_route_contract_registers_only_put_for_language_selection(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => $route->uri() === 'languages/select/{language}')
            ->values();

        $this->assertCount(1, $routes);
        $this->assertSame(['PUT'], $routes->first()->methods());
    }

    public function test_ordinary_english_selection_converges_legacy_context_and_preserves_legacy_goals(): void
    {
        $user = $this->createUser('french');
        Goal::forceCreate([
            'user_id' => $user->id,
            'language' => 'french',
            'name' => 'Reading',
            'type' => 'read_words',
            'quantity' => 41,
        ]);

        $this->actingAs($user)
            ->putJson('/languages/select/ENGLISH')
            ->assertOk()
            ->assertJsonPath('language', 'english');

        $this->assertSame('english', $user->refresh()->selected_language);
        $this->assertDefaultGoals($user, 'english');
        $this->assertDatabaseHas('goals', [
            'user_id' => $user->id,
            'language' => 'french',
            'type' => 'read_words',
            'quantity' => 41,
        ]);
    }

    public function test_repeated_ordinary_english_selection_is_idempotent(): void
    {
        $user = $this->createUser('english');

        $this->actingAs($user)->putJson('/languages/select/english')->assertOk();
        $this->actingAs($user)->putJson('/languages/select/english')->assertOk();

        $this->assertSame('english', $user->refresh()->selected_language);
        $this->assertDefaultGoals($user, 'english');
        $this->assertSame(3, $this->goalCount($user, 'english'));
    }

    public function test_ordinary_non_english_selection_fails_closed_without_goal_or_pointer_change(): void
    {
        $user = $this->createUser('english');

        $this->actingAs($user)
            ->putJson('/languages/select/japanese')
            ->assertConflict()
            ->assertJsonPath('error.code', 'ENGLISH_ONLY_LANGUAGE_SELECTION');

        $this->assertSame('english', $user->refresh()->selected_language);
        $this->assertSame(0, $this->goalCount($user, 'japanese'));
    }

    public function test_lower_language_service_keeps_legacy_non_english_selection_mechanics(): void
    {
        $user = $this->createUser('english');

        app(LanguageService::class)->selectLanguage($user, 'french');

        $this->assertSame('french', $user->refresh()->selected_language);
        $this->assertDefaultGoals($user, 'french');
    }

    public function test_authenticated_web_entry_converges_to_english_without_deleting_legacy_rows(): void
    {
        $user = $this->createUser('japanese');
        $legacyBook = Book::forceCreate([
            'user_id' => $user->id,
            'name' => 'Legacy Japanese Book',
            'language' => 'japanese',
        ]);

        $this->actingAs($user);
        app(HomeController::class)->index();

        $this->assertSame('english', $user->refresh()->selected_language);
        $this->assertDefaultGoals($user, 'english');
        $this->assertDatabaseHas('books', [
            'id' => $legacyBook->id,
            'user_id' => $user->id,
            'language' => 'japanese',
        ]);
    }

    public function test_ordinary_non_english_delete_is_rejected_and_legacy_data_survives(): void
    {
        $user = $this->createUser('english');
        $legacyBook = Book::forceCreate([
            'user_id' => $user->id,
            'name' => 'Protected Japanese Book',
            'language' => 'japanese',
        ]);

        $this->actingAs($user)
            ->deleteJson('/users/delete-language-data/japanese')
            ->assertConflict()
            ->assertJsonPath('error.code', 'ENGLISH_ONLY_LANGUAGE_DATA');

        $this->assertDatabaseHas('books', [
            'id' => $legacyBook->id,
            'language' => 'japanese',
        ]);
    }

    public function test_ordinary_english_delete_removes_english_data_only(): void
    {
        $user = $this->createUser('english');
        $englishBook = Book::forceCreate([
            'user_id' => $user->id,
            'name' => 'English Book',
            'language' => 'english',
        ]);
        $legacyBook = Book::forceCreate([
            'user_id' => $user->id,
            'name' => 'Legacy French Book',
            'language' => 'french',
        ]);

        $this->actingAs($user)
            ->deleteJson('/users/delete-language-data/english')
            ->assertOk();

        $this->assertDatabaseMissing('books', ['id' => $englishBook->id]);
        $this->assertDatabaseHas('books', [
            'id' => $legacyBook->id,
            'language' => 'french',
        ]);
    }

    public function test_kanji_spa_get_surface_is_retired_while_lower_compat_routes_remain(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $this->assertFalse($routes->contains(fn ($route) => $route->uri() === 'kanji/search' && in_array('GET', $route->methods(), true)));
        $this->assertFalse($routes->contains(fn ($route) => $route->uri() === 'kanji/{character}' && in_array('GET', $route->methods(), true)));
        $this->assertTrue($routes->contains(fn ($route) => $route->uri() === 'kanji/search' && in_array('POST', $route->methods(), true)));
        $this->assertTrue($routes->contains(fn ($route) => $route->uri() === 'kanji/details' && in_array('POST', $route->methods(), true)));
        $this->assertTrue($routes->contains(fn ($route) => $route->uri() === 'images/kanji/{fileName}' && in_array('GET', $route->methods(), true)));
        $this->assertTrue($routes->contains(fn ($route) => $route->uri() === 'jmdict/xml-to-text'));
    }

    public function test_restore_write_fence_rejects_english_selection_before_language_or_goals_change(): void
    {
        $user = $this->createUser('french');
        $operationId = (string) Str::uuid();
        app(RestoreWriteFence::class)->activate($operationId);

        try {
            $this->actingAs($user)
                ->putJson('/languages/select/english')
                ->assertServiceUnavailable()
                ->assertJsonPath('error.code', 'RESTORE_WRITE_FENCE_ACTIVE');

            $this->assertSame('french', $user->refresh()->selected_language);
            $this->assertSame(0, $this->goalCount($user, 'english'));
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
