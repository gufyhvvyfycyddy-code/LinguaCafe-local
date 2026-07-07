<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\WordSense;
use App\Services\AiStudyCardV6OpenAiCompatibleProviderAdapter;
use App\Services\AiStudyCardV6ProviderDisabledException;
use App\Services\AiStudyCardV6ProviderSecurityPolicyService;
use App\Services\AiStudyCardV6ProviderTransportException;
use App\Services\AiStudyCardV6ProviderTransportInterface;
use App\Services\AiStudyCardV6PromptBuilderService;
use App\Services\AiStudyCardV6ProviderResponseParserService;
use App\Services\AiStudyCardV6ProviderInterface;
use App\Services\AiStudyCardV6RecommendationSchemaService;
use App\Services\AiStudyCardV6RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiStudyCardV6OpenAiCompatibleAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_app_binding_still_uses_disabled_provider_not_openai_compatible_adapter(): void
    {
        $provider = app(AiStudyCardV6ProviderInterface::class);

        $this->assertNotInstanceOf(AiStudyCardV6OpenAiCompatibleProviderAdapter::class, $provider);
        $this->assertFalse($provider->isEnabled());
    }

    public function test_adapter_is_disabled_when_security_preconditions_are_not_met(): void
    {
        $adapter = $this->adapterWithTransport($this->validProviderResponseTransport());

        $this->assertFalse($adapter->isEnabled());
        $this->expectException(AiStudyCardV6ProviderDisabledException::class);

        $adapter->recommend($this->validRequestPackage());
    }

    public function test_fake_transport_valid_response_is_normalized_through_recommendation_service(): void
    {
        $this->enableFakeProviderPolicy();
        $adapter = $this->adapterWithTransport($this->validProviderResponseTransport());
        $service = new AiStudyCardV6RecommendationService(
            $adapter,
            app(AiStudyCardV6RecommendationSchemaService::class),
        );

        $result = $service->recommend($this->validRequestPackage());

        $this->assertTrue($result['success']);
        $this->assertSame('openai-compatible-skeleton', $result['provider']);
        $this->assertSame('ai-study-card-v6-recommendation-package-v1', $result['package']['schema_version']);
        $this->assertSame('agency', $result['package']['recommended_items'][0]['word']);
        $this->assertTrue($result['package']['safety_flags']['user_confirmation_required']);
        $this->assertTrue($result['package']['safety_flags']['default_unchecked']);
        $this->assertTrue($result['safety_flags']['no_card_creation']);
        $this->assertSafeLearningTablesRemainEmpty();
    }

    public function test_fake_transport_receives_provider_neutral_payload_without_secret_options(): void
    {
        $this->enableFakeProviderPolicy();
        $captured = [];

        $transport = new class($this->validRecommendationPackage(), $captured) implements AiStudyCardV6ProviderTransportInterface {
            public function __construct(private array $package, private array &$captured) {}
            public function sendChatCompletions(array $payload, array $options = []): array
            {
                $this->captured = ['payload' => $payload, 'options' => $options];

                return [
                    'choices' => [
                        ['message' => ['content' => json_encode($this->package, JSON_UNESCAPED_UNICODE)]],
                    ],
                ];
            }
        };

        $adapter = $this->adapterWithTransport($transport);
        $adapter->recommend($this->validRequestPackage());

        $json = json_encode($captured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('ai-study-card-v6-provider-prompt-payload-v1', $json);
        $this->assertStringContainsString('response_format', $json);
        $this->assertStringContainsString('json_object', $json);
        $this->assertStringContainsString('timeout_seconds', $json);
        $this->assertStringNotContainsString('secret_source', strtolower($json));
        $this->assertStringNotContainsString('secret_reference', strtolower($json));
        $this->assertStringNotContainsString('api_key', strtolower($json));
        $this->assertStringNotContainsString('Bearer ', $json);
        $this->assertStringNotContainsString('sk-', $json);
    }

    public function test_missing_choices_content_fails_closed_without_writes(): void
    {
        $this->enableFakeProviderPolicy();
        $adapter = $this->adapterWithTransport(new class implements AiStudyCardV6ProviderTransportInterface {
            public function sendChatCompletions(array $payload, array $options = []): array
            {
                return ['choices' => []];
            }
        });
        $service = new AiStudyCardV6RecommendationService($adapter, app(AiStudyCardV6RecommendationSchemaService::class));

        $result = $service->recommend($this->validRequestPackage());

        $this->assertFalse($result['success']);
        $this->assertSame(502, $result['status']);
        $this->assertContains('provider_failed_closed', $result['errors']);
        $this->assertSafeLearningTablesRemainEmpty();
    }

    public function test_invalid_json_content_fails_closed_without_writes(): void
    {
        $this->enableFakeProviderPolicy();
        $adapter = $this->adapterWithTransport(new class implements AiStudyCardV6ProviderTransportInterface {
            public function sendChatCompletions(array $payload, array $options = []): array
            {
                return ['choices' => [['message' => ['content' => '{not json']]]];
            }
        });
        $service = new AiStudyCardV6RecommendationService($adapter, app(AiStudyCardV6RecommendationSchemaService::class));

        $result = $service->recommend($this->validRequestPackage());

        $this->assertFalse($result['success']);
        $this->assertSame(502, $result['status']);
        $this->assertContains('provider_failed_closed', $result['errors']);
        $this->assertSafeLearningTablesRemainEmpty();
    }

    public function test_schema_invalid_content_fails_closed_without_writes(): void
    {
        $this->enableFakeProviderPolicy();
        $adapter = $this->adapterWithTransport(new class implements AiStudyCardV6ProviderTransportInterface {
            public function sendChatCompletions(array $payload, array $options = []): array
            {
                return [
                    'choices' => [
                        ['message' => ['content' => json_encode(['schema_version' => 'wrong'])]],
                    ],
                ];
            }
        });
        $service = new AiStudyCardV6RecommendationService($adapter, app(AiStudyCardV6RecommendationSchemaService::class));

        $result = $service->recommend($this->validRequestPackage());

        $this->assertFalse($result['success']);
        $this->assertSame(502, $result['status']);
        $this->assertContains('provider_failed_closed', $result['errors']);
        $this->assertSafeLearningTablesRemainEmpty();
    }

    public function test_transport_exception_fails_closed_without_writes(): void
    {
        $this->enableFakeProviderPolicy();
        $adapter = $this->adapterWithTransport(new class implements AiStudyCardV6ProviderTransportInterface {
            public function sendChatCompletions(array $payload, array $options = []): array
            {
                throw new AiStudyCardV6ProviderTransportException('provider_network_failure', 'fake transport failed');
            }
        });
        $service = new AiStudyCardV6RecommendationService($adapter, app(AiStudyCardV6RecommendationSchemaService::class));

        $result = $service->recommend($this->validRequestPackage());

        $this->assertFalse($result['success']);
        $this->assertSame(504, $result['status']);
        $this->assertContains('provider_network_failure', $result['errors']);
        $this->assertSafeLearningTablesRemainEmpty();
    }

    public function test_openai_compatible_adapter_files_have_no_endpoint_key_or_http_client_material(): void
    {
        $paths = [
            app_path('Services/AiStudyCardV6OpenAiCompatibleProviderAdapter.php'),
            app_path('Services/AiStudyCardV6ProviderTransportInterface.php'),
            app_path('Services/AiStudyCardV6ProviderTransportException.php'),
        ];

        $forbidden = [
            'OPENAI_API_KEY',
            'DEEPSEEK_API_KEY',
            'ANTHROPIC_API_KEY',
            'GEMINI_API_KEY',
            'sk-',
            'Bearer ',
            'env(',
            'Http::',
            'curl_',
            'Guzzle',
            'api.openai.com',
            'api.deepseek.com',
            'api.anthropic.com',
            'generativelanguage.googleapis.com',
        ];

        foreach ($paths as $path) {
            $contents = file_get_contents($path);
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $contents, basename($path) . " must not contain live provider/key material: {$needle}");
            }
        }
    }

    private function adapterWithTransport(AiStudyCardV6ProviderTransportInterface $transport): AiStudyCardV6OpenAiCompatibleProviderAdapter
    {
        return new AiStudyCardV6OpenAiCompatibleProviderAdapter(
            app(AiStudyCardV6ProviderSecurityPolicyService::class),
            app(AiStudyCardV6PromptBuilderService::class),
            app(AiStudyCardV6ProviderResponseParserService::class),
            $transport,
        );
    }

    private function validProviderResponseTransport(): AiStudyCardV6ProviderTransportInterface
    {
        return new class($this->validRecommendationPackage()) implements AiStudyCardV6ProviderTransportInterface {
            public function __construct(private array $package) {}
            public function sendChatCompletions(array $payload, array $options = []): array
            {
                return [
                    'id' => 'fake-chat-completion',
                    'object' => 'chat.completion',
                    'choices' => [
                        ['message' => ['role' => 'assistant', 'content' => json_encode($this->package, JSON_UNESCAPED_UNICODE)]],
                    ],
                ];
            }
        };
    }

    private function enableFakeProviderPolicy(): void
    {
        config([
            'ai_study_card_v6.provider.name' => 'fake-openai-compatible',
            'ai_study_card_v6.provider.external_requests_enabled' => true,
            'ai_study_card_v6.provider.allowed_adapter' => 'openai_compatible',
            'ai_study_card_v6.provider.secret_source' => 'test_fake_secret_source',
            'ai_study_card_v6.provider.base_url' => 'https://fake-provider.test',
            'ai_study_card_v6.provider.model' => 'fake-model',
            'ai_study_card_v6.provider.api_key' => 'test-key-not-real',
            'ai_study_card_v6.request_policy.timeout_seconds' => 15,
            'ai_study_card_v6.network_validation.browser_network_smoke_required_before_real_provider' => true,
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

    private function validRecommendationPackage(): array
    {
        return [
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
            'provider_metadata_redacted' => [
                'provider' => 'fake-openai-compatible',
            ],
            'safety_flags' => [
                'ai_generated_suggestions_only' => true,
                'user_confirmation_required' => true,
                'default_unchecked' => true,
                'no_card_creation' => true,
                'no_review_log_created' => true,
                'no_fsrs_changed' => true,
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
