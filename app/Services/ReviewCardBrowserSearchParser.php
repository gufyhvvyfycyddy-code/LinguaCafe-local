<?php

namespace App\Services;

/**
 * ReviewCardBrowserSearchParser
 *
 * ADR-0012: Pure-function parser for the review card browser search
 * grammar. Converts a raw query string into a ReviewCardBrowserSearchCriteria
 * value object.
 *
 * V1 grammar (case-insensitive, normalized to lowercase):
 *   is:leech | is:struggling          (governance — max 1)
 *   is:active | is:buried | is:suspended | is:archived  (lifecycle — max 1)
 *   rated:again | rated:hard | rated:good | rated:easy  (max 4, no duplicates)
 *   prop:lapses{=,>,>=,<,<=}<int>     (V1: only lapses field)
 *   flag:0..7                         (exact ReviewCard marker)
 *
 * All conditions AND-combined. No OR / NOT / parentheses.
 *
 * Pure function contract:
 *  - No DB queries.
 *  - No Request / Auth access.
 *  - No state mutation.
 *  - No FSRS / lifecycle / ReviewLog writes.
 *  - Deterministic: same input always produces same output.
 *
 * Error handling:
 *  - Unknown/unsupported tokens that look like advanced tokens (contain
 *    a colon with prefix is/rated/prop) return a structured error list
 *    via InvalidBrowserSearchException.
 *  - Plain text with a colon whose prefix is NOT is/rated/prop is kept
 *    as plain text (e.g. URLs).
 *  - Same-category conflicts (e.g. is:leech is:struggling) return 422.
 *
 * @see ADR-0012-review-card-browser-search.md
 */
class ReviewCardBrowserSearchParser
{
    private const GOVERNANCE_STATUSES = ['leech', 'struggling'];
    private const LIFECYCLE_STATUSES = ['active', 'buried', 'suspended', 'archived'];
    private const RATINGS = ['again', 'hard', 'good', 'easy'];
    private const PROP_FIELDS = ['lapses'];
    private const PROP_OPERATORS = ['=', '>', '>=', '<', '<='];

    /**
     * Parse a raw query string into a ReviewCardBrowserSearchCriteria.
     *
     * @param  string $rawQuery
     * @return ReviewCardBrowserSearchCriteria
     * @throws InvalidBrowserSearchException When any token is invalid,
     *         unsupported, or conflicting.
     */
    public function parse(string $rawQuery): ReviewCardBrowserSearchCriteria
    {
        $rawQuery = trim($rawQuery);
        // Collapse multiple whitespace into single spaces for consistent
        // tokenization. preserve original in rawQuery for display.
        $normalized = preg_replace('/\s+/', ' ', $rawQuery) ?? '';
        $segments = $normalized === '' ? [] : explode(' ', $normalized);

        $textParts = [];
        $normalizedTokens = [];
        $errors = [];

        $governanceStatus = null;
        $lifecycleStatus = null;
        $ratings = [];
        $propertyConditions = [];
        $marker = null;

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            // Does this segment look like an advanced token?
            // Rule: contains a colon AND the part before the first colon
            // is one of is/rated/prop (case-insensitive).
            $colonPos = strpos($segment, ':');
            if ($colonPos === false || $colonPos === 0) {
                // No colon or colon at start — plain text.
                $textParts[] = $segment;
                continue;
            }

            $prefix = strtolower(substr($segment, 0, $colonPos));
            $valuePart = substr($segment, $colonPos + 1);

            if (!in_array($prefix, ['is', 'rated', 'prop', 'flag'], true)) {
                // Not an advanced token prefix — treat as plain text
                // (e.g. http://example.com).
                $textParts[] = $segment;
                continue;
            }

            // It's an advanced token — parse it.
            $parseResult = $this->parseToken($prefix, $valuePart, $segment);
            if ($parseResult['error'] !== null) {
                $errors[] = $parseResult['error'];
                continue;
            }

            $tokenData = $parseResult['data'];
            $normalizedToken = $tokenData['normalized'];

            // ADR-0013: Deduplicate by first-occurrence order. Same token
            // appearing twice (e.g. `is:leech is:leech`) is normalized to a
            // single entry in normalizedTokens and a single condition. This
            // prevents duplicate chips on the frontend and duplicate SQL WHERE
            // clauses in the applier.
            if (in_array($normalizedToken, $normalizedTokens, true)) {
                continue;
            }
            $normalizedTokens[] = $normalizedToken;

            // Apply to criteria fields (with conflict detection).
            switch ($tokenData['kind']) {
                case 'governance':
                    if ($governanceStatus !== null && $governanceStatus !== $tokenData['value']) {
                        $errors[] = [
                            'token' => $segment,
                            'reason' => '不能同时指定多个治理状态 (governance)。每个查询最多一个 is:leech 或 is:struggling。',
                            'example' => 'is:leech',
                        ];
                    } else {
                        $governanceStatus = $tokenData['value'];
                    }
                    break;
                case 'lifecycle':
                    if ($lifecycleStatus !== null && $lifecycleStatus !== $tokenData['value']) {
                        $errors[] = [
                            'token' => $segment,
                            'reason' => '不能同时指定多个生命周期状态 (lifecycle)。每个查询最多一个 is:active/buried/suspended/archived。',
                            'example' => 'is:suspended',
                        ];
                    } else {
                        $lifecycleStatus = $tokenData['value'];
                    }
                    break;
                case 'rating':
                    if (!in_array($tokenData['value'], $ratings, true)) {
                        $ratings[] = $tokenData['value'];
                    }
                    break;
                case 'property':
                    // ADR-0013: Deduplicate identical property conditions
                    // (same field + operator + value). Different operators on
                    // the same field are kept (e.g. prop:lapses>=2 prop:lapses<5).
                    $dup = false;
                    foreach ($propertyConditions as $existing) {
                        if ($existing['field'] === $tokenData['field']
                            && $existing['operator'] === $tokenData['operator']
                            && $existing['value'] === $tokenData['value']
                        ) {
                            $dup = true;
                            break;
                        }
                    }
                    if (!$dup) {
                        $propertyConditions[] = [
                            'field' => $tokenData['field'],
                            'operator' => $tokenData['operator'],
                            'value' => $tokenData['value'],
                        ];
                    }
                    break;
                case 'marker':
                    if ($marker !== null && $marker !== $tokenData['value']) {
                        $errors[] = [
                            'token' => $segment,
                            'reason' => '不能同时指定多个卡片标记。每个查询最多一个 flag:0..7。',
                            'example' => 'flag:1',
                        ];
                    } else {
                        $marker = $tokenData['value'];
                    }
                    break;
            }
        }

        if (!empty($errors)) {
            throw new InvalidBrowserSearchException(
                '高级搜索语法有误。',
                $errors
            );
        }

        $textQuery = trim(implode(' ', $textParts));

        return new ReviewCardBrowserSearchCriteria(
            rawQuery: $rawQuery,
            textQuery: $textQuery,
            governanceStatus: $governanceStatus,
            lifecycleStatus: $lifecycleStatus,
            marker: $marker,
            ratings: $ratings,
            propertyConditions: $propertyConditions,
            normalizedTokens: $normalizedTokens,
            errors: [],
        );
    }

    /**
     * Parse a single advanced token.
     *
     * @param  string $prefix    'is' | 'rated' | 'prop'
     * @param  string $valuePart The part after the colon.
     * @param  string $original  The original segment (for error messages).
     * @return array{data: array|null, error: array|null}
     */
    private function parseToken(string $prefix, string $valuePart, string $original): array
    {
        $lowerValue = strtolower($valuePart);

        if ($prefix === 'is') {
            return $this->parseIsToken($lowerValue, $original);
        }
        if ($prefix === 'rated') {
            return $this->parseRatedToken($lowerValue, $original);
        }
        if ($prefix === 'prop') {
            return $this->parsePropToken($valuePart, $original);
        }
        if ($prefix === 'flag') {
            return $this->parseFlagToken($lowerValue, $original);
        }

        // Should never reach here due to the prefix check above.
        return [
            'data' => null,
            'error' => [
                'token' => $original,
                'reason' => '不支持的搜索类型',
                'example' => 'is:leech',
            ],
        ];
    }

    private function parseFlagToken(string $lowerValue, string $original): array
    {
        if (preg_match('/^[0-7]$/', $lowerValue) === 1) {
            $marker = (int) $lowerValue;

            return [
                'data' => [
                    'kind' => 'marker',
                    'value' => $marker,
                    'normalized' => 'flag:' . $marker,
                ],
                'error' => null,
            ];
        }

        return [
            'data' => null,
            'error' => [
                'token' => $original,
                'reason' => '不支持的 flag: 值。只支持 0 到 7。',
                'example' => 'flag:1',
            ],
        ];
    }

    /**
     * Parse is:<value> token.
     */
    private function parseIsToken(string $lowerValue, string $original): array
    {
        if (in_array($lowerValue, self::GOVERNANCE_STATUSES, true)) {
            return [
                'data' => [
                    'kind' => 'governance',
                    'value' => $lowerValue,
                    'normalized' => 'is:' . $lowerValue,
                ],
                'error' => null,
            ];
        }
        if (in_array($lowerValue, self::LIFECYCLE_STATUSES, true)) {
            return [
                'data' => [
                    'kind' => 'lifecycle',
                    'value' => $lowerValue,
                    'normalized' => 'is:' . $lowerValue,
                ],
                'error' => null,
            ];
        }

        return [
            'data' => null,
            'error' => [
                'token' => $original,
                'reason' => "不支持的 is: 值 '{$lowerValue}'。支持: leech, struggling, active, buried, suspended, archived",
                'example' => 'is:leech',
            ],
        ];
    }

    /**
     * Parse rated:<value> token.
     */
    private function parseRatedToken(string $lowerValue, string $original): array
    {
        if (in_array($lowerValue, self::RATINGS, true)) {
            return [
                'data' => [
                    'kind' => 'rating',
                    'value' => $lowerValue,
                    'normalized' => 'rated:' . $lowerValue,
                ],
                'error' => null,
            ];
        }

        return [
            'data' => null,
            'error' => [
                'token' => $original,
                'reason' => "不支持的 rated: 值 '{$lowerValue}'。支持: rated:again, rated:hard, rated:good, rated:easy",
                'example' => 'rated:again',
            ],
        ];
    }

    /**
     * Parse prop:<field><operator><value> token.
     *
     * The valuePart is the raw string after "prop:" — e.g. "lapses>=2".
     * We must NOT lowercase the operator or number, but we DO lowercase
     * the field name.
     */
    private function parsePropToken(string $valuePart, string $original): array
    {
        // Match: field name (letters), then operator, then integer.
        // Operators: >=, <=, >, <, = (longest first to avoid partial match)
        if (!preg_match('/^([a-zA-Z]+)(>=|<=|>|<|=)(-?\d+)$/', $valuePart, $matches)) {
            return [
                'data' => null,
                'error' => [
                    'token' => $original,
                    'reason' => '不支持的属性比较格式。V1 只支持 prop:lapses{=,>,>=,<,<=}<非负整数>',
                    'example' => 'prop:lapses>=2',
                ],
            ];
        }

        $field = strtolower($matches[1]);
        $operator = $matches[2];
        $value = (int) $matches[3];

        if (!in_array($field, self::PROP_FIELDS, true)) {
            return [
                'data' => null,
                'error' => [
                    'token' => $original,
                    'reason' => "不支持的属性 '{$field}'。V1 只支持: prop:lapses",
                    'example' => 'prop:lapses>=2',
                ],
            ];
        }

        if (!in_array($operator, self::PROP_OPERATORS, true)) {
            return [
                'data' => null,
                'error' => [
                    'token' => $original,
                    'reason' => "不支持的运算符 '{$operator}'。支持: =, >, >=, <, <=",
                    'example' => 'prop:lapses>=2',
                ],
            ];
        }

        if ($value < 0) {
            return [
                'data' => null,
                'error' => [
                    'token' => $original,
                    'reason' => '属性值不能为负数。lapses 必须 >= 0',
                    'example' => 'prop:lapses=0',
                ],
            ];
        }

        return [
            'data' => [
                'kind' => 'property',
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
                'normalized' => 'prop:' . $field . $operator . $value,
            ],
            'error' => null,
        ];
    }
}
