<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class AiStudyCardV6OpenAiCompatibleHttpTransport implements AiStudyCardV6ProviderTransportInterface
{
    public function sendChatCompletions(array $payload, array $options = []): array
    {
        $baseUrl = $this->normalizedBaseUrl();
        $apiKey = $this->apiKey();
        $model = $this->model();
        $timeout = max(1, (int) ($options['timeout_seconds'] ?? config('ai_study_card_v6.request_policy.timeout_seconds', 60)));

        $requestPayload = array_merge(
            [
                'model' => $model,
                'temperature' => 0.1,
            ],
            $this->withoutInternalSafetyFlags($payload),
        );

        try {
            $response = Http::timeout($timeout)
                ->withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->post($baseUrl . '/chat/completions', $requestPayload);
        } catch (ConnectionException $exception) {
            throw new AiStudyCardV6ProviderTransportException(
                'provider_network_failure',
                'V6 provider network request failed.',
                previous: $exception,
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new AiStudyCardV6ProviderTransportException('provider_auth_failure', 'V6 provider authentication failed.');
        }

        if ($response->status() === 429) {
            throw new AiStudyCardV6ProviderTransportException('provider_quota_failure', 'V6 provider quota or rate limit failed.');
        }

        if ($response->serverError()) {
            throw new AiStudyCardV6ProviderTransportException('provider_server_failure', 'V6 provider server error.');
        }

        if (!$response->successful()) {
            throw new AiStudyCardV6ProviderTransportException('provider_http_failure', 'V6 provider HTTP request failed.');
        }

        $json = $response->json();

        if (!is_array($json)) {
            throw new AiStudyCardV6ProviderTransportException('provider_malformed_response', 'V6 provider returned malformed JSON.');
        }

        return $json;
    }

    private function normalizedBaseUrl(): string
    {
        $baseUrl = trim((string) config('ai_study_card_v6.provider.base_url', ''));

        if ($baseUrl === '') {
            throw new AiStudyCardV6ProviderTransportException('provider_base_url_missing', 'V6 provider base URL is not configured.');
        }

        return rtrim($baseUrl, '/');
    }

    private function apiKey(): string
    {
        $apiKey = trim((string) config('ai_study_card_v6.provider.api_key', ''));

        if ($apiKey === '') {
            throw new AiStudyCardV6ProviderTransportException('provider_key_missing', 'V6 provider key is not configured.');
        }

        return $apiKey;
    }

    private function model(): string
    {
        $model = trim((string) config('ai_study_card_v6.provider.model', ''));

        if ($model === '') {
            throw new AiStudyCardV6ProviderTransportException('provider_model_missing', 'V6 provider model is not configured.');
        }

        return $model;
    }

    private function withoutInternalSafetyFlags(array $payload): array
    {
        unset($payload['safety_flags']);

        return $payload;
    }
}
