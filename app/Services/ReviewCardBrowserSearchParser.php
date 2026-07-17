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
 *   rated:again | rated:hard | rated:good | rated:easy  (lifetime existence)
 *   rated:<1..365> | rated:<1..365>:<1..4>  (recent natural-day window)
 *   prop:lapses{=,>,>=,<,<=}<int>     (V1: only lapses field)
 *   flag:0..7                         (exact ReviewCard marker)
 *   state:new | state:learning | state:review | state:relearning  (max 1 distinct)
 *   source:chapter:<positive-id> | source:book:<positive-id>
 *   missing:definition | missing:example | missing:source
 *   "<phrase>" | -<plain-term> | -"<phrase>"
 *
 * All conditions AND-combined. No OR / parentheses / advanced-token negation.
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
    private const PROP_FIELDS = ['lapses', 'reps', 'stability', 'difficulty'];
    private const INTEGER_PROP_FIELDS = ['lapses', 'reps'];
    private const PROP_OPERATORS = ['=', '>', '>=', '<', '<='];
    private const FSRS_STATES = ['new', 'learning', 'review', 'relearning'];
    private const RELATIVE_DUE_DATES = ['yesterday', 'today', 'tomorrow'];
    private const MISSING_FIELDS = ['definition', 'example', 'source'];

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
        $lexed = $this->lexQuery($rawQuery);

        $textParts = [];
        $positivePhrases = $lexed['positive_phrases'];
        $negativeTexts = $lexed['negative_texts'];
        $normalizedTokens = [];
        $errors = $lexed['errors'];

        $governanceStatus = null;
        $lifecycleStatus = null;
        $ratings = [];
        $recentReviewConditions = [];
        $propertyConditions = [];
        $marker = null;
        $fsrsStates = [];
        $dueDate = null;
        $sourceTargets = [];
        $missingFields = [];

        foreach ($lexed['segments'] as $segment) {
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

            if (!in_array($prefix, ['is', 'rated', 'prop', 'flag', 'state', 'due', 'source', 'missing'], true)) {
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
                case 'recent_rating':
                    $recentReviewConditions[] = [
                        'days' => $tokenData['days'],
                        'rating' => $tokenData['rating'],
                    ];
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
                case 'fsrs_state':
                    if (!in_array($tokenData['value'], $fsrsStates, true)) {
                        if (!empty($fsrsStates)) {
                            $errors[] = [
                                'token' => $segment,
                                'reason' => '不能同时指定多个不同的 FSRS 状态。每个查询最多一个 state:new/learning/review/relearning。',
                                'example' => 'state:new',
                            ];
                        } else {
                            $fsrsStates[] = $tokenData['value'];
                        }
                    }
                    break;
                case 'due_date':
                    if ($dueDate !== null && $dueDate !== $tokenData['value']) {
                        $errors[] = [
                            'token' => $segment,
                            'reason' => '不能同时指定多个到期日期。每个查询最多一个 due: 日期条件。',
                            'example' => 'due:today',
                        ];
                    } else {
                        $dueDate = $tokenData['value'];
                    }
                    break;
                case 'source':
                    $sourceTargets[] = [
                        'kind' => $tokenData['source_kind'],
                        'id' => $tokenData['source_id'],
                    ];
                    break;
                case 'missing_field':
                    $missingFields[] = $tokenData['value'];
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
            positivePhrases: $positivePhrases,
            negativeTexts: $negativeTexts,
            governanceStatus: $governanceStatus,
            lifecycleStatus: $lifecycleStatus,
            marker: $marker,
            ratings: $ratings,
            recentReviewConditions: $recentReviewConditions,
            propertyConditions: $propertyConditions,
            fsrsStates: $fsrsStates,
            dueDate: $dueDate,
            sourceTargets: $sourceTargets,
            missingFields: $missingFields,
            normalizedTokens: $normalizedTokens,
            errors: [],
        );
    }

    /**
     * Split the linear Browser grammar without introducing a general AST.
     *
     * @return array{
     *   segments: list<string>,
     *   positive_phrases: list<string>,
     *   negative_texts: list<string>,
     *   errors: list<array{token: string, reason: string, example: string}>
     * }
     */
    private function lexQuery(string $query): array
    {
        $segments = [];
        $positivePhrases = [];
        $negativeTexts = [];
        $errors = [];
        $length = strlen($query);
        $offset = 0;

        while ($offset < $length) {
            while ($offset < $length && ctype_space($query[$offset])) {
                $offset++;
            }
            if ($offset >= $length) {
                break;
            }

            $start = $offset;
            $isNegativePhrase = $query[$offset] === '-'
                && ($offset + 1) < $length
                && $query[$offset + 1] === '"';

            if ($query[$offset] === '"' || $isNegativePhrase) {
                $quoteOffset = $isNegativePhrase ? $offset + 1 : $offset;
                $offset = $quoteOffset + 1;
                $decoded = '';
                $closed = false;

                while ($offset < $length) {
                    $character = $query[$offset];
                    if ($character === '\\' && ($offset + 1) < $length) {
                        $next = $query[$offset + 1];
                        if ($next === '"' || $next === '\\') {
                            $decoded .= $next;
                            $offset += 2;
                            continue;
                        }

                        $decoded .= '\\';
                        $offset++;
                        continue;
                    }

                    if ($character === '"') {
                        $offset++;
                        $closed = true;
                        break;
                    }

                    $decoded .= $character;
                    $offset++;
                }

                if (!$closed) {
                    $errors[] = [
                        'token' => substr($query, $start),
                        'reason' => '引号短语没有闭合。',
                        'example' => '"take charge"',
                    ];
                    break;
                }

                if ($offset < $length && !ctype_space($query[$offset])) {
                    while ($offset < $length && !ctype_space($query[$offset])) {
                        $offset++;
                    }
                    $errors[] = [
                        'token' => substr($query, $start, $offset - $start),
                        'reason' => '引号短语必须作为独立搜索项，并用空白与其他条件分隔。',
                        'example' => '"take charge"',
                    ];
                    continue;
                }

                $token = substr($query, $start, $offset - $start);
                if (trim($decoded) === '') {
                    $errors[] = [
                        'token' => $token,
                        'reason' => '引号短语不能为空。',
                        'example' => '"take charge"',
                    ];
                    continue;
                }

                if ($isNegativePhrase) {
                    if (!in_array($decoded, $negativeTexts, true)) {
                        $negativeTexts[] = $decoded;
                    }
                } elseif (!in_array($decoded, $positivePhrases, true)) {
                    $positivePhrases[] = $decoded;
                }
                continue;
            }

            while ($offset < $length && !ctype_space($query[$offset])) {
                $offset++;
            }
            $token = substr($query, $start, $offset - $start);

            if ($token[0] !== '-') {
                if (str_contains($token, '"')) {
                    $errors[] = [
                        'token' => $token,
                        'reason' => '双引号只能出现在搜索项边界。',
                        'example' => '"take charge"',
                    ];
                } else {
                    $segments[] = $token;
                }
                continue;
            }

            if ($token === '-') {
                $errors[] = [
                    'token' => $token,
                    'reason' => '减号后必须提供要排除的文本。',
                    'example' => '-charge',
                ];
                continue;
            }

            if (str_starts_with($token, '--')) {
                $errors[] = [
                    'token' => $token,
                    'reason' => '不支持重复的前导减号。',
                    'example' => '-charge',
                ];
                continue;
            }

            $negativeText = substr($token, 1);
            if (str_contains($negativeText, '"')) {
                $errors[] = [
                    'token' => $token,
                    'reason' => '负向引号短语必须使用 -"..." 完整包围。',
                    'example' => '-"take charge"',
                ];
                continue;
            }

            if ($this->looksLikeRecognizedAdvancedToken($negativeText)) {
                $errors[] = [
                    'token' => $token,
                    'reason' => '当前阶段不支持高级 token 取反；只能排除普通文本或引号短语。',
                    'example' => '-charge',
                ];
                continue;
            }

            if (!in_array($negativeText, $negativeTexts, true)) {
                $negativeTexts[] = $negativeText;
            }
        }

        return [
            'segments' => $segments,
            'positive_phrases' => $positivePhrases,
            'negative_texts' => $negativeTexts,
            'errors' => $errors,
        ];
    }

    private function looksLikeRecognizedAdvancedToken(string $candidate): bool
    {
        $colonPos = strpos($candidate, ':');
        if ($colonPos === false || $colonPos === 0) {
            return false;
        }

        return in_array(
            strtolower(substr($candidate, 0, $colonPos)),
            ['is', 'rated', 'prop', 'flag', 'state', 'due', 'source', 'missing'],
            true,
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
        if ($prefix === 'state') {
            return $this->parseStateToken($lowerValue, $original);
        }
        if ($prefix === 'due') {
            return $this->parseDueToken($lowerValue, $original);
        }
        if ($prefix === 'source') {
            return $this->parseSourceToken($lowerValue, $original);
        }
        if ($prefix === 'missing') {
            return $this->parseMissingToken($lowerValue, $original);
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

    private function parseMissingToken(string $lowerValue, string $original): array
    {
        if (in_array($lowerValue, self::MISSING_FIELDS, true)) {
            return [
                'data' => [
                    'kind' => 'missing_field',
                    'value' => $lowerValue,
                    'normalized' => 'missing:' . $lowerValue,
                ],
                'error' => null,
            ];
        }

        return [
            'data' => null,
            'error' => [
                'token' => $original,
                'reason' => '不支持的 missing: 值。只支持 definition、example 或 source。',
                'example' => 'missing:definition',
            ],
        ];
    }

    private function parseSourceToken(string $lowerValue, string $original): array
    {
        if (preg_match('/^(chapter|book):(\d+)$/', $lowerValue, $matches) === 1) {
            $sourceId = (int) $matches[2];
            if ($sourceId > 0) {
                return [
                    'data' => [
                        'kind' => 'source',
                        'source_kind' => $matches[1],
                        'source_id' => $sourceId,
                        'normalized' => implode(':', ['source', $matches[1], (string) $sourceId]),
                    ],
                    'error' => null,
                ];
            }
        }

        return [
            'data' => null,
            'error' => [
                'token' => $original,
                'reason' => '不支持的 source: 格式。只支持 chapter 或 book 加正整数 ID。',
                'example' => implode(':', ['source', 'chapter', '46']),
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

    private function parseDueToken(string $lowerValue, string $original): array
    {
        $isRelative = in_array($lowerValue, self::RELATIVE_DUE_DATES, true);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $lowerValue);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        $isAbsolute = $date !== false
            && $date->format('Y-m-d') === $lowerValue
            && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0));

        if ($isRelative || $isAbsolute) {
            return [
                'data' => [
                    'kind' => 'due_date',
                    'value' => $lowerValue,
                    'normalized' => 'due:' . $lowerValue,
                ],
                'error' => null,
            ];
        }

        return [
            'data' => null,
            'error' => [
                'token' => $original,
                'reason' => '不支持的 due: 值。支持 yesterday、today、tomorrow 或 YYYY-MM-DD。',
                'example' => 'due:today',
            ],
        ];
    }

    /**
     * Parse state:<value> token.
     */
    private function parseStateToken(string $lowerValue, string $original): array
    {
        if (in_array($lowerValue, self::FSRS_STATES, true)) {
            return [
                'data' => [
                    'kind' => 'fsrs_state',
                    'value' => $lowerValue,
                    'normalized' => 'state:' . $lowerValue,
                ],
                'error' => null,
            ];
        }

        return [
            'data' => null,
            'error' => [
                'token' => $original,
                'reason' => "不支持的 state: 值 '{$lowerValue}'。支持: state:new, state:learning, state:review, state:relearning",
                'example' => 'state:new',
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

        if (preg_match('/^(\d+)(?::(\d+))?$/', $lowerValue, $matches) === 1) {
            $days = (int) $matches[1];
            $ratingCode = isset($matches[2]) ? (int) $matches[2] : null;
            $ratingMap = [1 => 'again', 2 => 'hard', 3 => 'good', 4 => 'easy'];

            if ($days >= 1 && $days <= 365 && ($ratingCode === null || isset($ratingMap[$ratingCode]))) {
                $normalized = 'rated:' . $days;
                if ($ratingCode !== null) {
                    $normalized .= ':' . $ratingCode;
                }

                return [
                    'data' => [
                        'kind' => 'recent_rating',
                        'days' => $days,
                        'rating' => $ratingCode === null ? null : $ratingMap[$ratingCode],
                        'normalized' => $normalized,
                    ],
                    'error' => null,
                ];
            }
        }

        return [
            'data' => null,
            'error' => [
                'token' => $original,
                'reason' => '不支持的 rated: 值。支持不限时间的评分名称，或 1 到 365 天及可选评分代码 1 到 4。',
                'example' => 'rated:7:1',
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
        if (!preg_match('/^([a-zA-Z]+)(>=|<=|>|<|=)(-?(?:\d+(?:\.\d+)?))$/', $valuePart, $matches)) {
            return [
                'data' => null,
                'error' => [
                    'token' => $original,
                    'reason' => '不支持的属性比较格式。支持 lapses、reps、stability、difficulty 与标准比较运算符。',
                    'example' => 'prop:stability>=3.5',
                ],
            ];
        }

        $field = strtolower($matches[1]);
        $operator = $matches[2];
        $rawValue = $matches[3];

        if (!in_array($field, self::PROP_FIELDS, true)) {
            return [
                'data' => null,
                'error' => [
                    'token' => $original,
                    'reason' => "不支持的属性 '{$field}'。支持: lapses, reps, stability, difficulty",
                    'example' => 'prop:stability>=3.5',
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

        if ((float) $rawValue < 0) {
            return [
                'data' => null,
                'error' => [
                    'token' => $original,
                    'reason' => '属性值不能为负数。',
                    'example' => 'prop:lapses=0',
                ],
            ];
        }

        if (in_array($field, self::INTEGER_PROP_FIELDS, true) && !ctype_digit($rawValue)) {
            return [
                'data' => null,
                'error' => [
                    'token' => $original,
                    'reason' => "{$field} 只支持非负整数。",
                    'example' => 'prop:reps>=4',
                ],
            ];
        }

        $value = in_array($field, self::INTEGER_PROP_FIELDS, true)
            ? (int) $rawValue
            : (float) $rawValue;
        $normalizedValue = is_int($value)
            ? (string) $value
            : rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

        return [
            'data' => [
                'kind' => 'property',
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
                'normalized' => 'prop:' . $field . $operator . $normalizedValue,
            ],
            'error' => null,
        ];
    }
}
