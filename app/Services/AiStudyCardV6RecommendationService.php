<?php

namespace App\Services;

class AiStudyCardV6RecommendationService
{
    public function __construct(
        private AiStudyCardV6ProviderInterface $provider,
        private AiStudyCardV6RecommendationSchemaService $schemaService,
    )
    {
    }

    /**
     * Ask the configured provider adapter for V6 recommendations.
     *
     * V6-2 is disabled by default: the production adapter reports disabled and
     * this method fails closed before any external request can be made.
     */
    public function recommend(array $requestPackage): array
    {
        if (!$this->provider->isEnabled()) {
            return [
                'success' => false,
                'status' => 503,
                'message' => 'V6 provider adapter is disabled. No external AI request was made.',
                'provider' => $this->provider->providerName(),
                'package' => null,
                'errors' => ['provider_disabled'],
                'safety_flags' => $this->safeFailureFlags(),
            ];
        }

        try {
            $rawPackage = $this->provider->recommend($requestPackage);
        } catch (AiStudyCardV6ProviderDisabledException $exception) {
            return [
                'success' => false,
                'status' => 503,
                'message' => $exception->getMessage(),
                'provider' => $this->provider->providerName(),
                'package' => null,
                'errors' => ['provider_disabled'],
                'safety_flags' => $this->safeFailureFlags(),
            ];
        } catch (\Throwable) {
            return [
                'success' => false,
                'status' => 502,
                'message' => 'V6 provider adapter failed closed. No learning data was written.',
                'provider' => $this->provider->providerName(),
                'package' => null,
                'errors' => ['provider_failed_closed'],
                'safety_flags' => $this->safeFailureFlags(),
            ];
        }

        $validation = $this->schemaService->validate($rawPackage);

        if (!$validation['ok']) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'V6 provider output failed schema validation. No learning data was written.',
                'provider' => $this->provider->providerName(),
                'package' => null,
                'errors' => $validation['errors'],
                'safety_flags' => $this->safeFailureFlags(),
            ];
        }

        return [
            'success' => true,
            'message' => 'V6 provider recommendation package validated. User confirmation is still required.',
            'provider' => $this->provider->providerName(),
            'package' => $validation['package'],
            'errors' => [],
            'safety_flags' => [
                'ai_generated_suggestions_only' => true,
                'user_confirmation_required' => true,
                'default_unchecked' => true,
                'no_card_creation' => true,
                'no_review_log_created' => true,
                'no_fsrs_changed' => true,
                'no_word_sense_created' => true,
                'no_review_card_created' => true,
                'no_legacy_word_card_created' => true,
            ],
        ];
    }

    private function safeFailureFlags(): array
    {
        return [
            'no_provider_result_trusted' => true,
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
