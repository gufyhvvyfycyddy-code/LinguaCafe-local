<?php

namespace App\Services;

class AiStudyCardV6ProviderSecurityPolicyService
{
    public function snapshot(): array
    {
        return [
            'provider' => config('ai_study_card_v6.provider', []),
            'request_policy' => config('ai_study_card_v6.request_policy', []),
            'logging_policy' => config('ai_study_card_v6.logging_policy', []),
            'data_policy' => config('ai_study_card_v6.data_policy', []),
            'network_validation' => config('ai_study_card_v6.network_validation', []),
        ];
    }

    public function externalRequestsEnabled(): bool
    {
        return (bool) config('ai_study_card_v6.provider.external_requests_enabled', false);
    }

    public function providerName(): string
    {
        return (string) config('ai_study_card_v6.provider.name', 'disabled');
    }

    public function timeoutSeconds(): int
    {
        return (int) config('ai_study_card_v6.request_policy.timeout_seconds', 0);
    }

    public function maxRetries(): int
    {
        return (int) config('ai_study_card_v6.request_policy.max_retries', 0);
    }

    public function maxItemsPerRequest(): int
    {
        return (int) config('ai_study_card_v6.request_policy.max_items_per_request', 50);
    }

    public function browserNetworkSmokeRequiredBeforeRealProvider(): bool
    {
        return (bool) config('ai_study_card_v6.network_validation.browser_network_smoke_required_before_real_provider', true);
    }

    public function safetyFlags(): array
    {
        return [
            'external_requests_enabled' => $this->externalRequestsEnabled(),
            'explicit_user_action_required' => (bool) config('ai_study_card_v6.request_policy.explicit_user_action_required', true),
            'background_requests_allowed' => (bool) config('ai_study_card_v6.request_policy.background_requests_allowed', false),
            'log_raw_prompt' => (bool) config('ai_study_card_v6.logging_policy.log_raw_prompt', false),
            'log_raw_response' => (bool) config('ai_study_card_v6.logging_policy.log_raw_response', false),
            'provider_may_create_word_sense' => (bool) config('ai_study_card_v6.data_policy.provider_may_create_word_sense', false),
            'provider_may_create_review_card' => (bool) config('ai_study_card_v6.data_policy.provider_may_create_review_card', false),
            'provider_may_create_review_log' => (bool) config('ai_study_card_v6.data_policy.provider_may_create_review_log', false),
            'provider_may_change_fsrs' => (bool) config('ai_study_card_v6.data_policy.provider_may_change_fsrs', false),
            'user_confirmation_required' => (bool) config('ai_study_card_v6.data_policy.user_confirmation_required', true),
        ];
    }

    public function assertRealProviderPreconditions(): array
    {
        $errors = [];

        if (!$this->externalRequestsEnabled()) {
            $errors[] = 'external_requests_disabled';
        }

        if ($this->providerName() === 'disabled') {
            $errors[] = 'provider_name_disabled';
        }

        if (config('ai_study_card_v6.provider.secret_source') === 'not_configured') {
            $errors[] = 'secret_source_not_configured';
        }

        if ($this->timeoutSeconds() <= 0) {
            $errors[] = 'timeout_not_configured';
        }

        if (!$this->browserNetworkSmokeRequiredBeforeRealProvider()) {
            $errors[] = 'browser_network_smoke_not_required';
        }

        return [
            'ok' => empty($errors),
            'errors' => $errors,
        ];
    }
}
