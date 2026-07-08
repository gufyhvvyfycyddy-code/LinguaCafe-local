<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\WordSense;
use App\Services\AiStudyCardV6DisabledProviderAdapter;
use App\Services\AiStudyCardV6ProviderDisabledException;
use App\Services\AiStudyCardV6ProviderInterface;
use App\Services\AiStudyCardV6RecommendationSchemaService;
use App\Services\AiStudyCardV6RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiStudyCardV6ProviderAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_container_binding_uses_disabled_provider_adapter(): void
    {
        $provider = app(AiStudyCardV6ProviderInterface::class);

        $this->assertInstanceOf(AiStudyCardV6DisabledProviderAdapter::class, $provider);
        $this->assertFalse($provider->isEnabled());
        $this->assertSame('disabled-provider', $provider->providerName());
    }

    public function test_disabled_provider_fails_before_provider_result_is_trusted(): void
    {
        $service = app(AiStudyCardV6RecommendationService::class);

        $result = $service->recommend($this->validRequestPackage());

        $this->assertFalse($result['success']);
        $this->assertSame(503, $result['status']);
        $this->assertSame('disabled-provider', $result['provider']);
        $this->assertNull($result['package']);
        $this->assertContains('provider_disabled', $result['errors']);
        $this->assertTrue($result['safety_flags']['no_provider_result_trusted']);
        $this->assertSafeLearningTablesRemainEmpty();
    }

    public function test_disabled_adapter_throw_is_converted_to_safe_failure(): void
    {
        $service = new AiStudyCardV6RecommendationService(
            new class implements AiStudyCardV6ProviderInterface {
                public function providerName(): string { return 'throwing-disabled-provider'; }
                public function isEnabled(): bool { return true; }
                public function recommend(array $requestPackage): array
                {
                    throw new AiStudyCardV6ProviderDisabledException('Provider disabled by test.');
                }
            },
            new AiStudyCardV6RecommendationSchemaService(),
        );

        $result = $service->recommend($this->validRequestPackage());

        $this->assertFalse($result['success']);
        $this->assertSame(503, $result['status']);
        $this->assertSame('throwing-disabled-provider', $result['provider']);
        $this->assertContains('provider_disabled', $result['errors']);
        $this->assertNull($result['package']);
        $this->assertSafeLearningTablesRemainEmpty();
    }

    public function test_provider_exception_fails_closed_without_writing_learning_data(): void
    {
        $service = new AiStudyCardV6RecommendationService(
            new class implements AiStudyCardV6ProviderInterface {
                public function providerName(): string { return 'failing-provider'; }
                public function isEnabled(): bool { return true; }
                public function recommend(array $requestPackage): array
                {
                    throw new \RuntimeException('network unavailable');
                }
            },
            new AiStudyCardV6RecommendationSchemaService(),
        );

        $result = $service->recommend($this->validRequestPackage());

        $this->assertFalse($result['success']);
        $this->assertSame(502, $result['status']);
        $this->assertSame('failing-provider', $result['provider']);
        $this->assertContains('provider_failed_closed', $result['errors']);
        $this->assertNull($result['package']);
        $this->assertSafeLearningTablesRemainEmpty();
    }

    public function test_malformed_provider_output_fails_schema_validation(): void
    {
        $service = new AiStudyCardV6RecommendationService(
            $this->providerReturning([
                'schema_version' => 'wrong-schema',
                'recommended_items' => [
                    ['word' => 'agency'],
                ],
            ]),
            new AiStudyCardV6RecommendationSchemaService(),
        );

        $result = $service->recommend($this->validRequestPackage());

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status']);
        $this->assertNull($result['package']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSafeLearningTablesRemainEmpty();
    }

    public function test_valid_fake_provider_output_is_validated_but_still_requires_user_confirmation(): void
    {
        $service = new AiStudyCardV6RecommendationService(
            $this->providerReturning($this->validRecommendationPackage()),
            new AiStudyCardV6RecommendationSchemaService(),
        );

        $result = $service->recommend($this->validRequestPackage());

        $this->assertTrue($result['success']);
        $this->assertSame('fake-provider', $result['provider']);
        $this->assertSame('ai-study-card-v6-recommendation-package-v1', $result['package']['schema_version']);
        $this->assertSame('mediation', $result['package']['recommended_items'][0]['word']);
        $this->assertTrue($result['package']['safety_flags']['user_confirmation_required']);
        $this->assertTrue($result['package']['safety_flags']['default_unchecked']);
        $this->assertTrue($result['safety_flags']['no_card_creation']);
        $this->assertTrue($result['safety_flags']['no_review_log_created']);
        $this->assertTrue($result['safety_flags']['no_fsrs_changed']);
        $this->assertSame(false, $result['package']['provider_metadata_redacted']['api_key_exposed']);
        $this->assertSafeLearningTablesRemainEmpty();
    }

    public function test_provider_recommendations_duplicate_with_user_selected_items_are_dropped_after_validation(): void
    {
        $package = $this->validRecommendationPackage();
        array_unshift($package['recommended_items'], [
            'word' => 'Agency',
            'lemma' => 'agency',
            'surface' => 'agency',
            'sentence_text' => 'Agency is the capacity to act.',
            'reason' => 'Provider repeated the user-selected item.',
            'confidence' => 0.95,
            'source' => 'ai_provider_v6',
        ]);

        $service = new AiStudyCardV6RecommendationService(
            $this->providerReturning($package),
            new AiStudyCardV6RecommendationSchemaService(),
        );

        $result = $service->recommend($this->validRequestPackage());

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['package']['recommended_items']);
        $this->assertSame('mediation', $result['package']['recommended_items'][0]['lemma']);
        $this->assertCount(1, $result['package']['dropped_items']);
        $this->assertSame('duplicate_with_user_selected_item', $result['package']['dropped_items'][0]['reason']);
        $this->assertSame(1, $result['package']['provider_metadata_redacted']['duplicate_with_user_selected_count']);
        $this->assertTrue($result['package']['safety_flags']['default_unchecked']);
        $this->assertSafeLearningTablesRemainEmpty();
    }

    public function test_schema_validator_rejects_missing_safety_flags(): void
    {
        $validator = new AiStudyCardV6RecommendationSchemaService();
        $package = $this->validRecommendationPackage();
        unset($package['safety_flags']['default_unchecked']);

        $result = $validator->validate($package);

        $this->assertFalse($result['ok']);
        $this->assertContains('safety_flags.default_unchecked must be true', $result['errors']);
        $this->assertNull($result['package']);
    }

    public function test_schema_validator_rejects_bad_confidence_and_source(): void
    {
        $validator = new AiStudyCardV6RecommendationSchemaService();
        $package = $this->validRecommendationPackage();
        $package['recommended_items'][0]['confidence'] = 2;
        $package['recommended_items'][0]['source'] = 'unknown_source';

        $result = $validator->validate($package);

        $this->assertFalse($result['ok']);
        $this->assertContains('recommended_items.0.confidence must be between 0 and 1', $result['errors']);
        $this->assertContains('recommended_items.0.source must be ai_provider_v6', $result['errors']);
    }

    public function test_v6_provider_boundary_files_contain_no_real_provider_urls_or_key_material(): void
    {
        $paths = [
            app_path('Services/AiStudyCardV6ProviderInterface.php'),
            app_path('Services/AiStudyCardV6DisabledProviderAdapter.php'),
            app_path('Services/AiStudyCardV6RecommendationService.php'),
            app_path('Services/AiStudyCardV6RecommendationSchemaService.php'),
        ];

        $forbidden = [
            'api.openai.com',
            'api.deepseek.com',
            'api.anthropic.com',
            'generativelanguage.googleapis.com',
            'api.x.ai',
            'OPENAI_API_KEY',
            'DEEPSEEK_API_KEY',
            'ANTHROPIC_API_KEY',
            'GEMINI_API_KEY',
            'config(\'services.openai',
            'config(\'services.deepseek',
            'config(\'services.anthropic',
            'config(\'services.gemini',
        ];

        foreach ($paths as $path) {
            $contents = file_get_contents($path);
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $contents, basename($path) . " must not contain real provider/key material: {$needle}");
            }
        }
    }

    private function providerReturning(array $package): AiStudyCardV6ProviderInterface
    {
        return new class($package) implements AiStudyCardV6ProviderInterface {
            public function __construct(private array $package) {}
            public function providerName(): string { return 'fake-provider'; }
            public function isEnabled(): bool { return true; }
            public function recommend(array $requestPackage): array { return $this->package; }
        };
    }

    private function validRequestPackage(): array
    {
        return [
            'schema_version' => 'ai-study-card-v6-request-package-v1',
            'language' => 'english',
            'provider_request_state' => 'provider_disabled',
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
                'provider_disabled' => true,
                'no_provider_called' => true,
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
                    'word' => 'mediation',
                    'lemma' => 'mediation',
                    'surface' => 'mediation',
                    'sentence_text' => 'Mediation can describe an intermediate relation between concepts.',
                    'reason' => 'Related concept that is not already selected by the user.',
                    'confidence' => 0.91,
                    'source' => 'ai_provider_v6',
                ],
            ],
            'dropped_items' => [],
            'provider_metadata_redacted' => [
                'provider' => 'fake-provider',
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
