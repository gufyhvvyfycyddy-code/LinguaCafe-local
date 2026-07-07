<?php

namespace App\Services;

class AiStudyCardV6ProviderPreviewService
{
    public const REQUEST_SCHEMA_VERSION = 'ai-study-card-v6-request-package-v1';

    public function __construct(
        private AiStudyCardV6ProviderSecurityPolicyService $securityPolicy,
        private AiStudyCardV6RecommendationService $recommendationService,
    )
    {
    }

    public function preview(array $requestPackage): array
    {
        $validation = $this->validateRequestPackage($requestPackage);

        if (!$validation['ok']) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'V6 provider-preview request package failed validation. No provider request was made.',
                'package' => null,
                'errors' => $validation['errors'],
                'safety_flags' => $this->safeFailureFlags(),
            ];
        }

        $preconditions = $this->securityPolicy->assertRealProviderPreconditions();

        if (!$preconditions['ok']) {
            return [
                'success' => false,
                'status' => 503,
                'message' => 'V6 provider-preview is disabled by security policy. No provider request was made.',
                'package' => null,
                'errors' => $preconditions['errors'],
                'safety_flags' => array_merge(
                    $this->safeFailureFlags(),
                    [
                        'security_policy_blocked' => true,
                        'external_requests_enabled' => $this->securityPolicy->externalRequestsEnabled(),
                    ]
                ),
            ];
        }

        return $this->recommendationService->recommend($requestPackage);
    }

    private function validateRequestPackage(array $package): array
    {
        $errors = [];

        if (($package['schema_version'] ?? null) !== self::REQUEST_SCHEMA_VERSION) {
            $errors[] = 'schema_version must be ' . self::REQUEST_SCHEMA_VERSION;
        }

        if (!isset($package['selected_pending_item_ids']) || !is_array($package['selected_pending_item_ids'])) {
            $errors[] = 'selected_pending_item_ids must be an array';
        }

        if (!isset($package['selected_items']) || !is_array($package['selected_items']) || empty($package['selected_items'])) {
            $errors[] = 'selected_items must be a non-empty array';
        }

        if (!isset($package['safety_flags']) || !is_array($package['safety_flags'])) {
            $errors[] = 'safety_flags must be an array';
        } else {
            $requiredFlags = [
                'user_triggered_request',
                'no_card_creation',
                'no_review_log_created',
                'no_fsrs_changed',
                'no_word_sense_created',
                'no_review_card_created',
                'user_confirmation_required',
            ];

            foreach ($requiredFlags as $flag) {
                if (($package['safety_flags'][$flag] ?? null) !== true) {
                    $errors[] = "safety_flags.{$flag} must be true";
                }
            }
        }

        foreach (($package['selected_items'] ?? []) as $index => $item) {
            if (!is_array($item)) {
                $errors[] = "selected_items.{$index} must be an object";
                continue;
            }

            foreach (['item_id', 'word', 'sentence_text', 'source'] as $field) {
                if (!array_key_exists($field, $item)) {
                    $errors[] = "selected_items.{$index}.{$field} is required";
                }
            }
        }

        return [
            'ok' => empty($errors),
            'errors' => $errors,
        ];
    }

    private function safeFailureFlags(): array
    {
        return [
            'no_provider_called' => true,
            'no_card_creation' => true,
            'no_review_log_created' => true,
            'no_fsrs_changed' => true,
            'no_word_sense_created' => true,
            'no_review_card_created' => true,
            'no_legacy_word_card_created' => true,
            'user_confirmation_required' => true,
        ];
    }
}
