<?php

namespace Tests\Feature;

use App\Services\AiStudyCardV6ProviderSecurityPolicyService;
use Tests\TestCase;

class AiStudyCardV6ProviderSecurityConfigTest extends TestCase
{
    public function test_v6_provider_security_config_exists_and_defaults_to_disabled(): void
    {
        $this->assertFileExists(config_path('ai_study_card_v6.php'));

        $this->assertSame('disabled', config('ai_study_card_v6.provider.name'));
        $this->assertFalse(config('ai_study_card_v6.provider.external_requests_enabled'));
        $this->assertSame('disabled', config('ai_study_card_v6.provider.allowed_adapter'));
        $this->assertSame('env', config('ai_study_card_v6.provider.secret_source'));
        $this->assertSame('AI_STUDY_CARD_V6_API_KEY', config('ai_study_card_v6.provider.secret_reference'));
    }

    public function test_v6_request_policy_fails_closed_by_default(): void
    {
        $this->assertTrue(config('ai_study_card_v6.request_policy.explicit_user_action_required'));
        $this->assertFalse(config('ai_study_card_v6.request_policy.background_requests_allowed'));
        $this->assertFalse(config('ai_study_card_v6.request_policy.page_load_requests_allowed'));
        $this->assertFalse(config('ai_study_card_v6.request_policy.token_click_requests_allowed'));
        $this->assertSame(50, config('ai_study_card_v6.request_policy.max_items_per_request'));
        $this->assertSame(0, config('ai_study_card_v6.request_policy.timeout_seconds'));
        $this->assertSame(0, config('ai_study_card_v6.request_policy.max_retries'));
        $this->assertSame('fail_closed', config('ai_study_card_v6.request_policy.quota_failure_policy'));
        $this->assertSame('fail_closed', config('ai_study_card_v6.request_policy.malformed_output_policy'));
        $this->assertSame('fail_closed', config('ai_study_card_v6.request_policy.network_failure_policy'));
    }

    public function test_v6_logging_policy_disallows_raw_prompt_response_and_secret_logging(): void
    {
        $this->assertFalse(config('ai_study_card_v6.logging_policy.log_raw_prompt'));
        $this->assertFalse(config('ai_study_card_v6.logging_policy.log_raw_response'));
        $this->assertFalse(config('ai_study_card_v6.logging_policy.log_source_text'));
        $this->assertFalse(config('ai_study_card_v6.logging_policy.log_secret_reference'));
        $this->assertFalse(config('ai_study_card_v6.logging_policy.log_provider_headers'));
        $this->assertTrue(config('ai_study_card_v6.logging_policy.redact_provider_metadata'));
    }

    public function test_v6_data_policy_disallows_learning_writes_from_provider(): void
    {
        $this->assertFalse(config('ai_study_card_v6.data_policy.provider_may_create_word_sense'));
        $this->assertFalse(config('ai_study_card_v6.data_policy.provider_may_create_review_card'));
        $this->assertFalse(config('ai_study_card_v6.data_policy.provider_may_create_review_log'));
        $this->assertFalse(config('ai_study_card_v6.data_policy.provider_may_change_fsrs'));
        $this->assertFalse(config('ai_study_card_v6.data_policy.provider_may_create_legacy_word_card'));
        $this->assertTrue(config('ai_study_card_v6.data_policy.user_confirmation_required'));
        $this->assertTrue(config('ai_study_card_v6.data_policy.recommendations_default_unchecked'));
        $this->assertTrue(config('ai_study_card_v6.data_policy.ai_reason_is_not_sense_zh'));
    }

    public function test_v6_network_validation_requires_browser_smoke_before_real_provider(): void
    {
        $this->assertTrue(config('ai_study_card_v6.network_validation.browser_network_smoke_required_before_real_provider'));
        $this->assertTrue(config('ai_study_card_v6.network_validation.forbid_external_ai_requests_until_enabled'));
        $this->assertContains('localhost', config('ai_study_card_v6.network_validation.allowed_local_hosts'));
        $this->assertContains('127.0.0.1', config('ai_study_card_v6.network_validation.allowed_local_hosts'));
        $this->assertContains('api.openai.com', config('ai_study_card_v6.network_validation.forbidden_provider_domains'));
        $this->assertContains('api.deepseek.com', config('ai_study_card_v6.network_validation.forbidden_provider_domains'));
        $this->assertContains('api.anthropic.com', config('ai_study_card_v6.network_validation.forbidden_provider_domains'));
        $this->assertContains('generativelanguage.googleapis.com', config('ai_study_card_v6.network_validation.forbidden_provider_domains'));
    }

    public function test_security_policy_service_reports_real_provider_preconditions_not_met(): void
    {
        $policy = app(AiStudyCardV6ProviderSecurityPolicyService::class);

        $this->assertFalse($policy->externalRequestsEnabled());
        $this->assertSame('disabled', $policy->providerName());
        $this->assertSame(0, $policy->timeoutSeconds());
        $this->assertSame(0, $policy->maxRetries());
        $this->assertSame(50, $policy->maxItemsPerRequest());
        $this->assertTrue($policy->browserNetworkSmokeRequiredBeforeRealProvider());

        $preconditions = $policy->assertRealProviderPreconditions();
        $this->assertFalse($preconditions['ok']);
        $this->assertContains('external_requests_disabled', $preconditions['errors']);
        $this->assertContains('provider_name_disabled', $preconditions['errors']);
        $this->assertContains('adapter_not_openai_compatible', $preconditions['errors']);
        $this->assertContains('api_key_not_configured', $preconditions['errors']);
        $this->assertContains('base_url_not_configured', $preconditions['errors']);
        $this->assertContains('timeout_not_configured', $preconditions['errors']);
    }

    public function test_security_policy_service_exposes_safe_flags_snapshot(): void
    {
        $policy = app(AiStudyCardV6ProviderSecurityPolicyService::class);
        $flags = $policy->safetyFlags();

        $this->assertFalse($flags['external_requests_enabled']);
        $this->assertTrue($flags['explicit_user_action_required']);
        $this->assertFalse($flags['background_requests_allowed']);
        $this->assertFalse($flags['log_raw_prompt']);
        $this->assertFalse($flags['log_raw_response']);
        $this->assertFalse($flags['provider_may_create_word_sense']);
        $this->assertFalse($flags['provider_may_create_review_card']);
        $this->assertFalse($flags['provider_may_create_review_log']);
        $this->assertFalse($flags['provider_may_change_fsrs']);
        $this->assertTrue($flags['user_confirmation_required']);
    }

    public function test_v6_config_and_policy_files_do_not_contain_secret_values_or_env_key_names(): void
    {
        $paths = [
            config_path('ai_study_card_v6.php'),
            app_path('Services/AiStudyCardV6ProviderSecurityPolicyService.php'),
        ];

        $forbidden = [
            'sk-',
            'Bearer ',
            'api.openai.com/v1',
            'api.deepseek.com/v1',
            'api.anthropic.com/v1',
            'generativelanguage.googleapis.com/v1',
        ];

        foreach ($paths as $path) {
            $contents = file_get_contents($path);
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $contents, basename($path) . " must not contain secret material or live provider config: {$needle}");
            }
        }
    }
}
