<?php

namespace App\Services;

interface AiStudyCardV6ProviderTransportInterface
{
    /**
     * Send a chat-completions style payload and return a provider-shaped array.
     *
     * Implementations for automated tests must be fake transports. A real
     * transport requires a separate approval task and browser Network evidence.
     */
    public function sendChatCompletions(array $payload, array $options = []): array;
}
