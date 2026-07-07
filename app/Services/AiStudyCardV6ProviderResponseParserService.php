<?php

namespace App\Services;

class AiStudyCardV6ProviderResponseParserService
{
    public function __construct(
        private AiStudyCardV6RecommendationSchemaService $schemaService,
    )
    {
    }

    /**
     * Parse provider text into a validated recommendation package.
     *
     * This does not trust provider text directly and does not write learning
     * data. Invalid JSON or invalid schema fails closed.
     */
    public function parseAndValidate(string $rawResponse): array
    {
        $trimmed = trim($rawResponse);

        if ($trimmed === '') {
            return $this->failure(['provider response is empty']);
        }

        $decoded = json_decode($trimmed, true);

        if (!is_array($decoded)) {
            return $this->failure(['provider response must be a JSON object']);
        }

        if (array_is_list($decoded)) {
            return $this->failure(['provider response must be a JSON object, not an array']);
        }

        $validation = $this->schemaService->validate($decoded);

        if (!$validation['ok']) {
            return $this->failure($validation['errors']);
        }

        return [
            'success' => true,
            'package' => $validation['package'],
            'errors' => [],
            'safety_flags' => [
                'provider_response_parsed' => true,
                'schema_validated' => true,
                'no_card_creation' => true,
                'no_review_log_created' => true,
                'no_fsrs_changed' => true,
                'user_confirmation_required' => true,
            ],
        ];
    }

    private function failure(array $errors): array
    {
        return [
            'success' => false,
            'package' => null,
            'errors' => $errors,
            'safety_flags' => [
                'provider_response_trusted' => false,
                'schema_validated' => false,
                'no_card_creation' => true,
                'no_review_log_created' => true,
                'no_fsrs_changed' => true,
                'user_confirmation_required' => true,
            ],
        ];
    }
}
