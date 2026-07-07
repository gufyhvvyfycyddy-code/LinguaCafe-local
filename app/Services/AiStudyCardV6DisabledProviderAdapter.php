<?php

namespace App\Services;

class AiStudyCardV6DisabledProviderAdapter implements AiStudyCardV6ProviderInterface
{
    public function providerName(): string
    {
        return 'disabled-provider';
    }

    public function isEnabled(): bool
    {
        return false;
    }

    public function recommend(array $requestPackage): array
    {
        throw new AiStudyCardV6ProviderDisabledException(
            'V6 provider is disabled. No external AI request was made.'
        );
    }
}
