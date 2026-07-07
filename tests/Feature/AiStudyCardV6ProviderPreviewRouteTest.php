<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AiStudyCardV6ProviderPreviewRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_preview_route_requires_authentication(): void
    {
        $this->postJson('/ai-study-card/v6/recommendations/provider-preview', [
            'request_package' => $this->validRequestPackage(),
        ])->assertUnauthorized();
    }

    public function test_provider_preview_route_exists_and_points_to_v6_controller(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));
        $controller = file_get_contents(app_path('Http/Controllers/AiStudyCardV6RecommendationController.php'));

        $this->assertStringContainsString("Route::post('/ai-study-card/v6/recommendations/provider-preview', [App\\Http\\Controllers\\AiStudyCardV6RecommendationController::class, 'providerPreview'])", $routes);
        $this->assertStringContainsString('public function providerPreview(Request $request)', $controller);
    }

    public function test_provider_preview_rejects_malformed_request_package_before_policy_check(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->postJson('/ai-study-card/v6/recommendations/provider-preview', [
            'request_package' => [
                'schema_version' => 'wrong-schema',
                'selected_items' => [],
                'safety_flags' => [],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('package', null);
        $response->assertJsonPath('safety_flags.no_provider_called', true);
        $this->assertStringContainsString('schema_version must be ai-study-card-v6-request-package-v1', $response->getContent());
        $this->assertSafeLearningTablesRemainEmpty();
    }

    public function test_provider_preview_fails_closed_while_security_preconditions_are_not_met(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->postJson('/ai-study-card/v6/recommendations/provider-preview', [
            'request_package' => $this->validRequestPackage(),
        ]);

        $response->assertStatus(503);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('package', null);
        $response->assertJsonPath('safety_flags.no_provider_called', true);
        $response->assertJsonPath('safety_flags.security_policy_blocked', true);
        $response->assertJsonPath('safety_flags.external_requests_enabled', false);
        $this->assertStringContainsString('external_requests_disabled', $response->getContent());
        $this->assertStringContainsString('provider_name_disabled', $response->getContent());
        $this->assertStringContainsString('secret_source_not_configured', $response->getContent());
        $this->assertStringContainsString('timeout_not_configured', $response->getContent());
        $this->assertSafeLearningTablesRemainEmpty();
    }

    public function test_provider_preview_does_not_expose_secret_material_in_disabled_response(): void
    {
        $user = $this->createUser();

        $content = $this->actingAs($user)->postJson('/ai-study-card/v6/recommendations/provider-preview', [
            'request_package' => $this->validRequestPackage(),
        ])->getContent();

        $forbidden = [
            'OPENAI_API_KEY',
            'DEEPSEEK_API_KEY',
            'ANTHROPIC_API_KEY',
            'GEMINI_API_KEY',
            'sk-',
            'Bearer ',
            'api.openai.com',
            'api.deepseek.com',
            'api.anthropic.com',
            'generativelanguage.googleapis.com',
        ];

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString($needle, $content);
        }
    }

    public function test_provider_preview_does_not_change_existing_v6_request_package_route(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString("Route::post('/ai-study-card/v6/recommendations/request-package', [App\\Http\\Controllers\\AiStudyCardV6RecommendationController::class, 'requestPackage'])", $routes);
        $this->assertStringContainsString("Route::post('/ai-study-card/v6/recommendations/provider-preview', [App\\Http\\Controllers\\AiStudyCardV6RecommendationController::class, 'providerPreview'])", $routes);
    }

    private function createUser(): User
    {
        return User::forceCreate([
            'name' => 'ai-v6-provider-preview@example.test',
            'email' => 'ai-v6-provider-preview@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
        ]);
    }

    private function validRequestPackage(): array
    {
        return [
            'schema_version' => 'ai-study-card-v6-request-package-v1',
            'language' => 'english',
            'selected_pending_item_ids' => [1],
            'selected_items' => [
                [
                    'item_id' => 1,
                    'word' => 'agency',
                    'lemma' => 'agency',
                    'surface' => 'agency',
                    'sentence_text' => 'Agency is the capacity to act.',
                    'source' => 'user_selected_pending_item',
                ],
            ],
            'safety_flags' => [
                'user_triggered_request' => true,
                'no_card_creation' => true,
                'no_review_log_created' => true,
                'no_fsrs_changed' => true,
                'no_word_sense_created' => true,
                'no_review_card_created' => true,
                'user_confirmation_required' => true,
            ],
        ];
    }

    private function assertSafeLearningTablesRemainEmpty(): void
    {
        $this->assertSame(0, WordSense::count());
        $this->assertSame(0, ReviewCard::count());
        $this->assertSame(0, ReviewLog::count());
    }
}
