<?php

namespace App\Services;

use RuntimeException;

class AiStudyCardV6OpenAiCompatibleProviderAdapter implements AiStudyCardV6ProviderInterface
{
    public function __construct(
        private AiStudyCardV6ProviderSecurityPolicyService $securityPolicy,
        private AiStudyCardV6PromptBuilderService $promptBuilder,
        private AiStudyCardV6ProviderResponseParserService $responseParser,
        private AiStudyCardV6ProviderTransportInterface $transport,
    )
    {
    }

    public function providerName(): string
    {
        return 'openai-compatible-skeleton';
    }

    public function isEnabled(): bool
    {
        return $this->securityPolicy->assertRealProviderPreconditions()['ok'];
    }

    /**
     * Build a provider-neutral prompt payload, send it through the injected
     * transport, and normalize an OpenAI-compatible chat-completions response.
     *
     * The default app binding does not use this adapter. Tests inject fake
     * transports only. This class does not know provider endpoints or secrets.
     */
    public function recommend(array $requestPackage): array
    {
        $preconditions = $this->securityPolicy->assertRealProviderPreconditions();

        if (!$preconditions['ok']) {
            throw new AiStudyCardV6ProviderDisabledException(
                'V6 OpenAI-compatible adapter is disabled by security policy.'
            );
        }

        $prompt = $this->promptBuilder->buildPromptPayload($requestPackage);

        if (!$prompt['success']) {
            throw new RuntimeException('V6 prompt payload failed validation.');
        }

        $response = $this->transport->sendChatCompletions(
            $this->toChatCompletionsPayload($prompt['payload']),
            $this->transportOptions(),
        );

        $content = $this->extractMessageContent($response);
        $parsed = $this->responseParser->parseAndValidate($content);

        if (!$parsed['success']) {
            throw new RuntimeException('V6 provider response failed validation.');
        }

        return $parsed['package'];
    }

    private function toChatCompletionsPayload(array $promptPayload): array
    {
        return [
            'schema_version' => $promptPayload['schema_version'],
            'messages' => $promptPayload['messages'],
            'response_format' => ['type' => 'json_object'],
            'safety_flags' => $promptPayload['safety_flags'],
        ];
    }

    private function transportOptions(): array
    {
        return [
            'provider_name' => $this->securityPolicy->providerName(),
            'timeout_seconds' => $this->securityPolicy->timeoutSeconds(),
            'max_retries' => $this->securityPolicy->maxRetries(),
            'external_requests_enabled' => $this->securityPolicy->externalRequestsEnabled(),
        ];
    }

    private function extractMessageContent(array $response): string
    {
        $content = $response['choices'][0]['message']['content'] ?? null;

        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('V6 provider response missing choices.0.message.content.');
        }

        return $content;
    }
}
