<?php

namespace App\Services;

/**
 * ReviewCardBrowserSearchCriteria
 *
 * ADR-0012: Read-only value object produced by
 * ReviewCardBrowserSearchParser::parse(). It holds the normalized
 * search criteria extracted from a raw query string.
 *
 * This object does NOT query the database, does NOT read Request/Auth,
 * and is NOT mutable after construction. The QueryApplier consumes it.
 *
 * Fields:
 *  - rawQuery:           the original input string (trimmed)
 *  - textQuery:          remaining plain text after token extraction (may be empty)
 *  - governanceStatus:   'leech' | 'struggling' | null  (ADR-0011 classification)
 *  - lifecycleStatus:    'active' | 'buried' | 'suspended' | 'archived' | null  (ADR-0010)
 *  - ratings:            list<'again'|'hard'>  (max 2, no duplicates)
 *  - propertyConditions: list<array{field: string, operator: string, value: int}>
 *                        (V1: field is always 'lapses')
 *  - normalizedTokens:   list<string>  recognized tokens in normalized lowercase form
 *  - errors:             list<array{token: string, reason: string, example: string}>
 *
 * When errors is non-empty, the parser threw InvalidBrowserSearchException
 * and this object is NOT used for query application.
 */
class ReviewCardBrowserSearchCriteria
{
    /**
     * @param string $rawQuery
     * @param string $textQuery
     * @param string|null $governanceStatus
     * @param string|null $lifecycleStatus
     * @param list<'again'|'hard'> $ratings
     * @param list<array{field: string, operator: string, value: int}> $propertyConditions
     * @param list<string> $normalizedTokens
     * @param list<array{token: string, reason: string, example: string}> $errors
     */
    public function __construct(
        public readonly string $rawQuery,
        public readonly string $textQuery,
        public readonly ?string $governanceStatus,
        public readonly ?string $lifecycleStatus,
        public readonly array $ratings,
        public readonly array $propertyConditions,
        public readonly array $normalizedTokens,
        public readonly array $errors,
    ) {
    }

    /**
     * Whether the query contains ANY recognized advanced token.
     */
    public function hasAdvancedTokens(): bool
    {
        return !empty($this->normalizedTokens);
    }

    /**
     * Whether the query has a non-empty plain text component.
     */
    public function hasTextQuery(): bool
    {
        return $this->textQuery !== '';
    }

    /**
     * Whether any governance (leech/struggling) condition is present.
     */
    public function hasGovernanceStatus(): bool
    {
        return $this->governanceStatus !== null;
    }

    /**
     * Whether any lifecycle condition is present.
     */
    public function hasLifecycleStatus(): bool
    {
        return $this->lifecycleStatus !== null;
    }

    /**
     * Whether any rated condition is present.
     */
    public function hasRatings(): bool
    {
        return !empty($this->ratings);
    }

    /**
     * Whether any property condition is present.
     */
    public function hasPropertyConditions(): bool
    {
        return !empty($this->propertyConditions);
    }

    /**
     * Build the search_meta payload for the frontend.
     *
     * @return array{raw_query: string, text_query: string, tokens: list<string>, advanced: bool}
     */
    public function toSearchMeta(): array
    {
        return [
            'raw_query' => $this->rawQuery,
            'text_query' => $this->textQuery,
            'tokens' => $this->normalizedTokens,
            'advanced' => $this->hasAdvancedTokens(),
        ];
    }
}
