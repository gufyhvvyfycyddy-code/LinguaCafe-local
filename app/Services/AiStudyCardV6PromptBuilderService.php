<?php

namespace App\Services;

class AiStudyCardV6PromptBuilderService
{
    public const PROMPT_PAYLOAD_SCHEMA_VERSION = 'ai-study-card-v6-provider-prompt-payload-v1';
    public const REQUEST_SCHEMA_VERSION = 'ai-study-card-v6-request-package-v1';
    public const RESPONSE_SCHEMA_VERSION = 'ai-study-card-v6-recommendation-package-v1';
    private const MAX_SENTENCE_LENGTH = 500;

    /**
     * Build a provider-neutral prompt payload from a V6 request package.
     *
     * This does not call any provider and does not write learning data. The
     * payload is a future adapter input, not a browser response contract.
     */
    public function buildPromptPayload(array $requestPackage): array
    {
        $validation = $this->validateRequestPackage($requestPackage);

        if (!$validation['ok']) {
            return [
                'success' => false,
                'payload' => null,
                'errors' => $validation['errors'],
            ];
        }

        $selectedItems = collect($requestPackage['selected_items'])
            ->map(fn (array $item) => $this->toPromptItem($item))
            ->values()
            ->all();

        return [
            'success' => true,
            'payload' => [
                'schema_version' => self::PROMPT_PAYLOAD_SCHEMA_VERSION,
                'request_schema_version' => self::REQUEST_SCHEMA_VERSION,
                'response_schema_version' => self::RESPONSE_SCHEMA_VERSION,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $this->systemMessage(),
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode([
                            'task' => 'recommend_candidate_study_items_only',
                            'language' => $requestPackage['language'] ?? null,
                            'selected_items' => $selectedItems,
                            'required_output_schema_version' => self::RESPONSE_SCHEMA_VERSION,
                            'required_output_contract' => $this->outputContract(),
                            'rules' => $this->rules(),
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ],
                ],
                'safety_flags' => [
                    'provider_neutral_payload' => true,
                    'no_provider_called' => true,
                    'no_secret_in_payload' => true,
                    'no_raw_source_payload' => true,
                    'no_full_chapter_text' => true,
                    'no_card_creation' => true,
                    'no_review_log_created' => true,
                    'no_fsrs_changed' => true,
                    'user_confirmation_required' => true,
                ],
            ],
            'errors' => [],
        ];
    }

    private function validateRequestPackage(array $package): array
    {
        $errors = [];

        if (($package['schema_version'] ?? null) !== self::REQUEST_SCHEMA_VERSION) {
            $errors[] = 'schema_version must be ' . self::REQUEST_SCHEMA_VERSION;
        }

        if (!isset($package['selected_items']) || !is_array($package['selected_items']) || empty($package['selected_items'])) {
            $errors[] = 'selected_items must be a non-empty array';
        }

        if (count($package['selected_items'] ?? []) > 50) {
            $errors[] = 'selected_items must contain at most 50 items';
        }

        foreach (($package['selected_items'] ?? []) as $index => $item) {
            if (!is_array($item)) {
                $errors[] = "selected_items.{$index} must be an object";
                continue;
            }

            foreach (['item_id', 'word', 'sentence_text'] as $field) {
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

    private function toPromptItem(array $item): array
    {
        return [
            'item_id' => $item['item_id'] ?? null,
            'word' => $this->stringValue($item['word'] ?? ''),
            'lemma' => $this->stringValue($item['lemma'] ?? ($item['word'] ?? '')),
            'surface' => $this->stringValue($item['surface'] ?? ($item['word'] ?? '')),
            'sentence_text' => $this->truncate($this->stringValue($item['sentence_text'] ?? ''), self::MAX_SENTENCE_LENGTH),
        ];
    }

    private function stringValue(mixed $value): string
    {
        return trim((string) $value);
    }

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit) . '…';
    }

    private function systemMessage(): string
    {
        return implode("\n", [
            'You are helping LinguaCafe recommend candidate study items.',
            'Return JSON only.',
            'Return schema_version=' . self::RESPONSE_SCHEMA_VERSION . '.',
            'Return exactly these top-level keys: schema_version, recommended_items, dropped_items, provider_metadata_redacted, safety_flags.',
            'Use recommended_items as the recommendation array key. Do not use recommendations as a top-level key.',
            'Recommendations are suggestions only and must require user confirmation.',
            'Do not create study cards, write review logs, change FSRS, or write final meanings.',
            'Do not include secrets, provider settings, or unrelated commentary.',
        ]);
    }

    private function outputContract(): array
    {
        return [
            'required_top_level_keys' => [
                'schema_version',
                'recommended_items',
                'dropped_items',
                'provider_metadata_redacted',
                'safety_flags',
            ],
            'forbidden_top_level_keys' => [
                'recommendations',
            ],
            'json_template' => [
                'schema_version' => self::RESPONSE_SCHEMA_VERSION,
                'recommended_items' => [
                    [
                        'word' => 'agency',
                        'lemma' => 'agency',
                        'surface' => 'agency',
                        'sentence_text' => 'Agency is the capacity to act in a situation.',
                        'reason' => 'Reference-only reason for recommending this candidate.',
                        'confidence' => 0.9,
                        'source' => 'ai_provider_v6',
                    ],
                ],
                'dropped_items' => [],
                'provider_metadata_redacted' => [
                    'provider' => 'redacted',
                    'secrets_exposed' => false,
                ],
                'safety_flags' => [
                    'ai_generated_suggestions_only' => true,
                    'user_confirmation_required' => true,
                    'default_unchecked' => true,
                    'no_card_creation' => true,
                    'no_review_log_created' => true,
                    'no_fsrs_changed' => true,
                ],
            ],
        ];
    }

    private function rules(): array
    {
        return [
            'recommendations_are_suggestions_only' => true,
            'user_confirmation_required' => true,
            'default_unchecked' => true,
            'reason_is_reference_text_not_final_meaning' => true,
            'do_not_create_cards' => true,
            'do_not_rate_reviews' => true,
            'do_not_change_fsrs' => true,
            'return_json_only' => true,
        ];
    }
}
