<?php

namespace App\Services;

class AiStudyCardV6RecommendationSchemaService
{
    public const SCHEMA_VERSION = 'ai-study-card-v6-recommendation-package-v1';

    /**
     * Validate provider output without mutating learning data.
     *
     * Returns a fail-closed result so malformed provider output never reaches
     * the user-confirmation/card-generation path as trusted data.
     */
    public function validate(array $package): array
    {
        $errors = [];

        if (($package['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $errors[] = 'schema_version must be ' . self::SCHEMA_VERSION;
        }

        if (!isset($package['recommended_items']) || !is_array($package['recommended_items'])) {
            $errors[] = 'recommended_items must be an array';
        }

        if (!isset($package['dropped_items']) || !is_array($package['dropped_items'])) {
            $errors[] = 'dropped_items must be an array';
        }

        if (!isset($package['provider_metadata_redacted']) || !is_array($package['provider_metadata_redacted'])) {
            $errors[] = 'provider_metadata_redacted must be an array';
        }

        if (!isset($package['safety_flags']) || !is_array($package['safety_flags'])) {
            $errors[] = 'safety_flags must be an array';
        } else {
            $requiredSafetyFlags = [
                'ai_generated_suggestions_only',
                'user_confirmation_required',
                'default_unchecked',
                'no_card_creation',
                'no_review_log_created',
                'no_fsrs_changed',
            ];

            foreach ($requiredSafetyFlags as $flag) {
                if (($package['safety_flags'][$flag] ?? null) !== true) {
                    $errors[] = "safety_flags.{$flag} must be true";
                }
            }
        }

        foreach (($package['recommended_items'] ?? []) as $index => $item) {
            if (!is_array($item)) {
                $errors[] = "recommended_items.{$index} must be an object";
                continue;
            }

            foreach (['word', 'lemma', 'surface', 'sentence_text', 'reason', 'confidence', 'source'] as $field) {
                if (!array_key_exists($field, $item)) {
                    $errors[] = "recommended_items.{$index}.{$field} is required";
                }
            }

            if (($item['source'] ?? null) !== 'ai_provider_v6') {
                $errors[] = "recommended_items.{$index}.source must be ai_provider_v6";
            }

            if (isset($item['confidence']) && (!is_numeric($item['confidence']) || $item['confidence'] < 0 || $item['confidence'] > 1)) {
                $errors[] = "recommended_items.{$index}.confidence must be between 0 and 1";
            }
        }

        if (!empty($errors)) {
            return [
                'ok' => false,
                'errors' => $errors,
                'package' => null,
            ];
        }

        return [
            'ok' => true,
            'errors' => [],
            'package' => $this->redactPackage($package),
        ];
    }

    private function redactPackage(array $package): array
    {
        $package['provider_metadata_redacted'] = array_merge(
            $package['provider_metadata_redacted'] ?? [],
            [
                'raw_prompt_logged' => false,
                'raw_response_logged' => false,
                'api_key_exposed' => false,
            ]
        );

        return $package;
    }
}
