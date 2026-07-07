<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Study Card V6 Provider Security Configuration
    |--------------------------------------------------------------------------
    |
    | This config intentionally ships with external provider calls disabled.
    | It contains no secret value and no concrete provider endpoint. A future
    | real-provider task must first update the security plan and tests.
    |
    */

    'provider' => [
        'name' => 'disabled',
        'external_requests_enabled' => false,
        'allowed_adapter' => 'disabled',
        'secret_source' => 'not_configured',
        'secret_reference' => null,
    ],

    'request_policy' => [
        'explicit_user_action_required' => true,
        'background_requests_allowed' => false,
        'page_load_requests_allowed' => false,
        'token_click_requests_allowed' => false,
        'max_items_per_request' => 50,
        'timeout_seconds' => 0,
        'max_retries' => 0,
        'quota_failure_policy' => 'fail_closed',
        'malformed_output_policy' => 'fail_closed',
        'network_failure_policy' => 'fail_closed',
    ],

    'logging_policy' => [
        'log_raw_prompt' => false,
        'log_raw_response' => false,
        'log_source_text' => false,
        'log_secret_reference' => false,
        'log_provider_headers' => false,
        'redact_provider_metadata' => true,
    ],

    'data_policy' => [
        'provider_may_create_word_sense' => false,
        'provider_may_create_review_card' => false,
        'provider_may_create_review_log' => false,
        'provider_may_change_fsrs' => false,
        'provider_may_create_legacy_word_card' => false,
        'user_confirmation_required' => true,
        'recommendations_default_unchecked' => true,
        'ai_reason_is_not_sense_zh' => true,
    ],

    'network_validation' => [
        'browser_network_smoke_required_before_real_provider' => true,
        'forbid_external_ai_requests_until_enabled' => true,
        'allowed_local_hosts' => [
            'localhost',
            '127.0.0.1',
        ],
        'forbidden_provider_domains' => [
            'api.openai.com',
            'api.deepseek.com',
            'api.anthropic.com',
            'generativelanguage.googleapis.com',
            'api.x.ai',
        ],
    ],
];
