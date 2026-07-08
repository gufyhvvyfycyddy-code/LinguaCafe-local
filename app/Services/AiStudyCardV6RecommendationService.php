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
        } catch (AiStudyCardV6ProviderTransportException $exception) {
            return [
                'success' => false,
                'status' => $this->statusForTransportFailure($exception->failureCode()),
                'message' => $this->messageForTransportFailure($exception->failureCode()),
                'provider' => $this->provider->providerName(),
                'package' => null,
                'errors' => [$exception->failureCode()],
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

        $package = $this->removeDuplicateRecommendationsAgainstRequest($validation['package'], $requestPackage);

        return [
            'success' => true,
            'message' => 'V6 provider recommendation package validated. User confirmation is still required.',
            'provider' => $this->provider->providerName(),
            'package' => $package,
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

    private function removeDuplicateRecommendationsAgainstRequest(array $package, array $requestPackage): array
    {
        $selectedKeys = [];
        foreach (($requestPackage['selected_items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (['lemma', 'word', 'surface'] as $field) {
                $key = $this->recommendationKey($item[$field] ?? '');
                if ($key !== '') {
                    $selectedKeys[$key] = true;
                }
            }
        }

        if (empty($selectedKeys)) {
            return $package;
        }

        $kept = [];
        $originalDroppedCount = count($package['dropped_items'] ?? []);
        $dropped = $package['dropped_items'] ?? [];
        foreach (($package['recommended_items'] ?? []) as $item) {
            $candidateKeys = array_filter([
                $this->recommendationKey($item['lemma'] ?? ''),
                $this->recommendationKey($item['word'] ?? ''),
                $this->recommendationKey($item['surface'] ?? ''),
            ], fn (string $key) => $key !== '');

            $duplicatesSelectedItem = false;
            foreach ($candidateKeys as $key) {
                if (isset($selectedKeys[$key])) {
                    $duplicatesSelectedItem = true;
                    break;
                }
            }

            if ($duplicatesSelectedItem) {
                $dropped[] = [
                    'word' => (string) ($item['word'] ?? ''),
                    'lemma' => (string) ($item['lemma'] ?? ($item['word'] ?? '')),
                    'reason' => 'duplicate_with_user_selected_item',
                    'source' => 'ai_provider_v6',
                ];
                continue;
            }

            $kept[] = $item;
        }

        $package['recommended_items'] = array_values($kept);
        $package['dropped_items'] = array_values($dropped);
        $package['provider_metadata_redacted']['duplicate_with_user_selected_count'] = count($dropped) - $originalDroppedCount;

        return $package;
    }

    private function recommendationKey(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function statusForTransportFailure(string $failureCode): int
    {
        return match ($failureCode) {
            'provider_auth_failure' => 401,
            'provider_quota_failure' => 429,
            'provider_malformed_response' => 422,
            'provider_network_failure' => 504,
            default => 502,
        };
    }

    private function messageForTransportFailure(string $failureCode): string
    {
        return match ($failureCode) {
            'provider_auth_failure' => 'AI 推荐失败：Provider 鉴权失败。',
            'provider_quota_failure' => 'AI 推荐失败：Provider 配额或频率限制。',
            'provider_malformed_response' => 'AI 推荐失败：Provider 返回格式错误。',
            'provider_network_failure' => 'AI 推荐失败：网络连接失败或超时。',
            'provider_base_url_missing' => 'AI 推荐失败：Provider base URL 未配置。',
            'provider_key_missing' => 'AI 推荐失败：Provider key 未配置。',
            'provider_model_missing' => 'AI 推荐失败：Provider model 未配置。',
            default => 'AI 推荐失败：Provider 请求失败。',
        };
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
