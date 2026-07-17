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
 *  - textQuery:          remaining unquoted positive text after extraction (may be empty)
 *  - positivePhrases:    ordered, decoded quoted phrases (deduplicated)
 *  - negativeTexts:      ordered negative plain terms/phrases (deduplicated)
 *  - governanceStatus:   'leech' | 'struggling' | null  (ADR-0011 classification)
 *  - lifecycleStatus:    'active' | 'buried' | 'suspended' | 'archived' | null  (ADR-0010)
 *  - ratings:            list<'again'|'hard'|'good'|'easy'>  (max 4, no duplicates)
 *  - recentReviewConditions: list<array{days: int, rating: string|null}>
 *  - propertyConditions: list<array{field: string, operator: string, value: int|float}>
 *                        (lapses, reps, stability, difficulty)
 *  - fsrsStates:         list<'new'|'learning'|'review'|'relearning'>
 *                        (max 1 distinct value, deduplicated)
 *  - dueDate:            yesterday | today | tomorrow | YYYY-MM-DD | null
 *  - sourceTargets:      list<array{kind: 'chapter'|'book', id: int}>
 *  - missingFields:      list<'definition'|'example'|'source'>
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
     * @param list<string> $positivePhrases
     * @param list<string> $negativeTexts
     * @param string|null $governanceStatus
     * @param string|null $lifecycleStatus
     * @param int|null $marker
     * @param list<'again'|'hard'|'good'|'easy'> $ratings
     * @param list<array{days: int, rating: 'again'|'hard'|'good'|'easy'|null}> $recentReviewConditions
     * @param list<array{field: string, operator: string, value: int|float}> $propertyConditions
     * @param list<'new'|'learning'|'review'|'relearning'> $fsrsStates
     * @param string|null $dueDate
     * @param list<array{kind: 'chapter'|'book', id: int}> $sourceTargets
     * @param list<'definition'|'example'|'source'> $missingFields
     * @param list<string> $normalizedTokens
     * @param list<array{token: string, reason: string, example: string}> $errors
     */
    public function __construct(
        public readonly string $rawQuery,
        public readonly string $textQuery,
        public readonly array $positivePhrases,
        public readonly array $negativeTexts,
        public readonly ?string $governanceStatus,
        public readonly ?string $lifecycleStatus,
        public readonly ?int $marker,
        public readonly array $ratings,
        public readonly array $recentReviewConditions,
        public readonly array $propertyConditions,
        public readonly array $fsrsStates,
        public readonly ?string $dueDate,
        public readonly array $sourceTargets,
        public readonly array $missingFields,
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

    public function hasPositivePhrases(): bool
    {
        return !empty($this->positivePhrases);
    }

    public function hasNegativeTexts(): bool
    {
        return !empty($this->negativeTexts);
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

    public function hasMarker(): bool
    {
        return $this->marker !== null;
    }

    /**
     * Whether any rated condition is present.
     */
    public function hasRatings(): bool
    {
        return !empty($this->ratings);
    }

    public function hasRecentReviewConditions(): bool
    {
        return !empty($this->recentReviewConditions);
    }

    /**
     * Whether any property condition is present.
     */
    public function hasPropertyConditions(): bool
    {
        return !empty($this->propertyConditions);
    }

    /**
     * Whether any fsrs state condition is present.
     */
    public function hasFsrsStates(): bool
    {
        return !empty($this->fsrsStates);
    }

    public function hasDueDate(): bool
    {
        return $this->dueDate !== null;
    }

    public function hasSourceTargets(): bool
    {
        return !empty($this->sourceTargets);
    }

    public function hasMissingFields(): bool
    {
        return !empty($this->missingFields);
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
