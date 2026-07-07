<?php

namespace App\Services;

interface AiStudyCardV6ProviderInterface
{
    /**
     * Human-readable provider name used only for redacted diagnostics.
     */
    public function providerName(): string;

    /**
     * Whether this adapter is allowed to issue provider calls.
     *
     * V6-2 ships with the production adapter disabled by default.
     */
    public function isEnabled(): bool;

    /**
     * Return a raw provider recommendation package.
     *
     * Implementations must not create WordSense, ReviewCard, ReviewLog, FSRS
     * changes, or legacy word cards. They only return data for schema
     * validation and later user confirmation.
     */
    public function recommend(array $requestPackage): array;
}
