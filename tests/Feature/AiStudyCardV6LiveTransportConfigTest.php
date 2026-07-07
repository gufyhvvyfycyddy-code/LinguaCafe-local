<?php

namespace Tests\Feature;

use App\Services\AiStudyCardV6DisabledProviderAdapter;
use App\Services\AiStudyCardV6OpenAiCompatibleHttpTransport;
use App\Services\AiStudyCardV6OpenAiCompatibleProviderAdapter;
use App\Services\AiStudyCardV6ProviderInterface;
use App\Services\AiStudyCardV6ProviderTransportInterface;
use App\Models\User;
use App\Services\AiStudyCardV6RecommendationService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiStudyCardV6LiveTransportConfigTest extends TestCase
{
    public function test_default_binding_remains_disabled_without_environment_configuration(): void
    {
        $provider = app(AiStudyCardV6ProviderInterface::class);

        $this->assertInstanceOf(AiStudyCardV6DisabledProviderAdapter::class, $provider);
        $this->assertFalse($provider->isEnabled());
    }

    public function test_configured_openai_compatible_binding_uses_live_adapter_and_http_transport(): void
    {
        $this->enableConfiguredProvider();

        $provider = app(AiStudyCardV6ProviderInterface::class);
        $transport = app(AiStudyCardV6ProviderTransportInterface::class);

        $this->assertInstanceOf(AiStudyCardV6OpenAiCompatibleProviderAdapter::class, $provider);
        $this->assertInstanceOf(AiStudyCardV6OpenAiCompatibleHttpTransport::class, $transport);
        $this->assertTrue($provider->isEnabled());
    }

    public function test_configured_provider_preview_uses_local_backend_route_and_fake_http_response(): void
    {
        $this->enableConfiguredProvider();
        Http::fake([
            'https://api.deepseek.com/chat/completions' => Http::response($this->validProviderHttpResponse(), 200),
        ]);

        $service = app(AiStudyCardV6RecommendationService::class);
        $result = $service->recommend($this->validRequestPackage());

        $this->assertTrue($result['success']);
        $this->assertSame('openai-compatible-skeleton', $result['provider']);
        $this->assertSame('ai-study-card-v6-recommendation-package-v1', $result['package']['schema_version']);
        $this->assertTrue($result['package']['safety_flags']['user_confirmation_required']);
        $this->assertTrue($result['package']['safety_flags']['default_unchecked']);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $this->assertSame('https://api.deepseek.com/chat/completions', (string) $request->url());
            $this->assertSame('deepseek-chat', $request['model']);
            $this->assertSame('json_object', $request['response_format']['type']);
            $this->assertArrayNotHasKey('safety_flags', $request->data());
            return true;
        });
    }

    public function test_provider_preview_route_can_use_configured_backend_transport_with_fake_http(): void
    {
        $this->enableConfiguredProvider();
        Http::fake([
            'https://api.deepseek.com/chat/completions' => Http::response($this->validProviderHttpResponse(), 200),
        ]);

        $user = User::forceCreate([
            'name' => 'ai-v6-live-route@example.test',
            'email' => 'ai-v6-live-route@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
        ]);

        $response = $this->actingAs($user)->postJson('/ai-study-card/v6/recommendations/provider-preview', [
            'request_package' => $this->validRequestPackage(),
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('package.schema_version', 'ai-study-card-v6-recommendation-package-v1');
        $response->assertJsonPath('safety_flags.no_card_creation', true);
        $response->assertJsonPath('safety_flags.no_review_log_created', true);
        $response->assertJsonPath('safety_flags.no_fsrs_changed', true);
        Http::assertSentCount(1);
    }

    public function test_quota_response_returns_detailed_failure_without_package(): void
    {
        $this->enableConfiguredProvider();
        Http::fake([
            'https://api.deepseek.com/chat/completions' => Http::response(['error' => ['message' => 'rate limited']], 429),
        ]);

        $result = app(AiStudyCardV6RecommendationService::class)->recommend($this->validRequestPackage());

        $this->assertFalse($result['success']);
        $this->assertSame(429, $result['status']);
        $this->assertContains('provider_quota_failure', $result['errors']);
        $this->assertNull($result['package']);
        $this->assertTrue($result['safety_flags']['no_card_creation']);
        $this->assertTrue($result['safety_flags']['no_review_log_created']);
        $this->assertTrue($result['safety_flags']['no_fsrs_changed']);
    }

    public function test_malformed_json_response_returns_detailed_failure_without_package(): void
    {
        $this->enableConfiguredProvider();
        Http::fake([
            'https://api.deepseek.com/chat/completions' => Http::response('not-json', 200),
        ]);

        $result = app(AiStudyCardV6RecommendationService::class)->recommend($this->validRequestPackage());

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status']);
        $this->assertContains('provider_malformed_response', $result['errors']);
        $this->assertNull($result['package']);
    }

    public function test_missing_key_blocks_preconditions_before_http_request(): void
    {
        $this->enableConfiguredProvider();
        config(['ai_study_card_v6.provider.api_key' => null]);
        Http::fake();

        $result = app(AiStudyCardV6RecommendationService::class)->recommend($this->validRequestPackage());

        $this->assertFalse($result['success']);
        $this->assertSame(503, $result['status']);
        $this->assertContains('provider_disabled', $result['errors']);
        Http::assertNothingSent();
    }

    private function enableConfiguredProvider(): void
    {
        config([
            'ai_study_card_v6.provider.name' => 'deepseek',
            'ai_study_card_v6.provider.external_requests_enabled' => true,
            'ai_study_card_v6.provider.allowed_adapter' => 'openai_compatible',
            'ai_study_card_v6.provider.secret_source' => 'env',
            'ai_study_card_v6.provider.secret_reference' => 'AI_STUDY_CARD_V6_API_KEY',
            'ai_study_card_v6.provider.base_url' => 'https://api.deepseek.com',
            'ai_study_card_v6.provider.model' => 'deepseek-chat',
            'ai_study_card_v6.provider.api_key' => 'test-key-not-real',
            'ai_study_card_v6.request_policy.timeout_seconds' => 60,
        ]);

        $this->app->forgetInstance(AiStudyCardV6ProviderInterface::class);
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
                    'sentence_text' => 'Agency is the capacity to act in a situation.',
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

    private function validProviderHttpResponse(): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'schema_version' => 'ai-study-card-v6-recommendation-package-v1',
                            'recommended_items' => [
                                [
                                    'word' => 'agency',
                                    'lemma' => 'agency',
                                    'surface' => 'agency',
                                    'sentence_text' => 'Agency is the capacity to act in a situation.',
                                    'reason' => 'Central concept in the sentence.',
                                    'confidence' => 0.9,
                                    'source' => 'ai_provider_v6',
                                ],
                            ],
                            'dropped_items' => [],
                            'provider_metadata_redacted' => ['provider' => 'deepseek'],
                            'safety_flags' => [
                                'ai_generated_suggestions_only' => true,
                                'user_confirmation_required' => true,
                                'default_unchecked' => true,
                                'no_card_creation' => true,
                                'no_review_log_created' => true,
                                'no_fsrs_changed' => true,
                            ],
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ],
            ],
        ];
    }
}
