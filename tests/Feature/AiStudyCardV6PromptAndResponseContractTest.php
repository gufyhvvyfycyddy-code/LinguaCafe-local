<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\WordSense;
use App\Services\AiStudyCardV6PromptBuilderService;
use App\Services\AiStudyCardV6ProviderResponseParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiStudyCardV6PromptAndResponseContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_builder_creates_provider_neutral_payload_without_calling_provider(): void
    {
        $service = app(AiStudyCardV6PromptBuilderService::class);

        $result = $service->buildPromptPayload($this->validRequestPackage());

        $this->assertTrue($result['success']);
        $payload = $result['payload'];
        $this->assertSame('ai-study-card-v6-provider-prompt-payload-v1', $payload['schema_version']);
        $this->assertSame('ai-study-card-v6-request-package-v1', $payload['request_schema_version']);
        $this->assertSame('ai-study-card-v6-recommendation-package-v1', $payload['response_schema_version']);
        $this->assertCount(2, $payload['messages']);
        $this->assertSame('system', $payload['messages'][0]['role']);
        $this->assertSame('user', $payload['messages'][1]['role']);
        $this->assertTrue($payload['safety_flags']['provider_neutral_payload']);
        $this->assertTrue($payload['safety_flags']['no_provider_called']);
        $this->assertTrue($payload['safety_flags']['no_secret_in_payload']);
        $this->assertTrue($payload['safety_flags']['no_full_chapter_text']);
        $this->assertTrue($payload['safety_flags']['user_confirmation_required']);
        $this->assertSafeLearningTablesRemainEmpty();
    }

    public function test_prompt_payload_contains_required_safety_instructions(): void
    {
        $payload = app(AiStudyCardV6PromptBuilderService::class)
            ->buildPromptPayload($this->validRequestPackage())['payload'];

        $joined = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $required = [
            'Return JSON only',
            'ai-study-card-v6-recommendation-package-v1',
            'recommended_items',
            'dropped_items',
            'provider_metadata_redacted',
            'Recommendations are suggestions only',
            'user_confirmation_required',
            'default_unchecked',
            'reason_is_reference_text_not_final_meaning',
            'exclude_user_selected_items_from_recommended_items',
            'drop_duplicates_with_reason_duplicate_with_user_selected_item',
            'duplicate_with_user_selected_item',
            'do_not_create_cards',
            'do_not_rate_reviews',
            'do_not_change_fsrs',
        ];

        foreach ($required as $needle) {
            $this->assertStringContainsString($needle, $joined);
        }
    }

    public function test_prompt_payload_does_not_include_raw_source_payload_or_full_chapter_text(): void
    {
        $requestPackage = $this->validRequestPackage();
        $requestPackage['source_payload'] = ['raw' => 'raw payload must not appear'];
        $requestPackage['full_chapter_text'] = 'FULL CHAPTER TEXT MUST NOT APPEAR';
        $requestPackage['selected_items'][0]['source_payload'] = ['raw' => 'nested raw payload must not appear'];

        $payload = app(AiStudyCardV6PromptBuilderService::class)
            ->buildPromptPayload($requestPackage)['payload'];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString('raw payload must not appear', $json);
        $this->assertStringNotContainsString('nested raw payload must not appear', $json);
        $this->assertStringNotContainsString('FULL CHAPTER TEXT MUST NOT APPEAR', $json);
    }

    public function test_prompt_payload_truncates_long_sentence_text(): void
    {
        $requestPackage = $this->validRequestPackage();
        $requestPackage['selected_items'][0]['sentence_text'] = str_repeat('a', 700);

        $payload = app(AiStudyCardV6PromptBuilderService::class)
            ->buildPromptPayload($requestPackage)['payload'];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString(str_repeat('a', 500) . '…', $json);
        $this->assertStringNotContainsString(str_repeat('a', 650), $json);
    }

    public function test_prompt_builder_rejects_malformed_request_package(): void
    {
        $result = app(AiStudyCardV6PromptBuilderService::class)->buildPromptPayload([
            'schema_version' => 'wrong',
            'selected_items' => [],
        ]);

        $this->assertFalse($result['success']);
        $this->assertNull($result['payload']);
        $this->assertContains('schema_version must be ai-study-card-v6-request-package-v1', $result['errors']);
        $this->assertContains('selected_items must be a non-empty array', $result['errors']);
    }

    public function test_response_parser_rejects_empty_invalid_json_and_array_json(): void
    {
        $parser = app(AiStudyCardV6ProviderResponseParserService::class);

        $empty = $parser->parseAndValidate('');
        $this->assertFalse($empty['success']);
        $this->assertContains('provider response is empty', $empty['errors']);

        $invalid = $parser->parseAndValidate('{not json');
        $this->assertFalse($invalid['success']);
        $this->assertContains('provider response must be a JSON object', $invalid['errors']);

        $array = $parser->parseAndValidate('[{"word":"agency"}]');
        $this->assertFalse($array['success']);
        $this->assertContains('provider response must be a JSON object, not an array', $array['errors']);

        $this->assertSafeLearningTablesRemainEmpty();
    }

    public function test_response_parser_rejects_schema_invalid_json_object(): void
    {
        $result = app(AiStudyCardV6ProviderResponseParserService::class)->parseAndValidate(json_encode([
            'schema_version' => 'wrong-schema',
            'recommended_items' => [],
            'dropped_items' => [],
            'provider_metadata_redacted' => [],
            'safety_flags' => [],
        ]));

        $this->assertFalse($result['success']);
        $this->assertNull($result['package']);
        $this->assertNotEmpty($result['errors']);
        $this->assertTrue($result['safety_flags']['no_card_creation']);
        $this->assertTrue($result['safety_flags']['no_review_log_created']);
        $this->assertTrue($result['safety_flags']['no_fsrs_changed']);
        $this->assertSafeLearningTablesRemainEmpty();
    }

    public function test_response_parser_accepts_valid_package_but_still_requires_user_confirmation(): void
    {
        $result = app(AiStudyCardV6ProviderResponseParserService::class)->parseAndValidate(json_encode($this->validRecommendationPackage(), JSON_UNESCAPED_UNICODE));

        $this->assertTrue($result['success']);
        $this->assertSame('ai-study-card-v6-recommendation-package-v1', $result['package']['schema_version']);
        $this->assertTrue($result['package']['safety_flags']['user_confirmation_required']);
        $this->assertTrue($result['package']['safety_flags']['default_unchecked']);
        $this->assertTrue($result['safety_flags']['schema_validated']);
        $this->assertTrue($result['safety_flags']['no_card_creation']);
        $this->assertSafeLearningTablesRemainEmpty();
    }

    public function test_prompt_and_response_contract_files_contain_no_provider_key_or_endpoint_material(): void
    {
        $paths = [
            app_path('Services/AiStudyCardV6PromptBuilderService.php'),
            app_path('Services/AiStudyCardV6ProviderResponseParserService.php'),
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
