<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class LanguageSelectionTest extends TestCase
{
    use RefreshDatabase;

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
            throw new \RuntimeException('Python service unavailable.');
        });

        $user = $this->createUser();

        $response = $this->actingAs($user)->getJson('/languages/get-language-selection-dialog-data');

        $response->assertOk();
        $this->assertContains('English', $response->json('languages'));
        $this->assertNotContains('Japanese', $response->json('languages'));
    }

    public function test_select_language_updates_study_language_without_ui_language(): void
    {
        Http::fake([
            '*' => Http::response([], 200),
        ]);

        $user = $this->createUser();

        $this->actingAs($user)->getJson('/languages/select/english')->assertOk();

        $this->assertSame('english', $user->refresh()->selected_language);
    }

    private function createUser(): User
    {
        return User::forceCreate([
            'name' => 'Language User',
            'email' => 'language@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }
}
