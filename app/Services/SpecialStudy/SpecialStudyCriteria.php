<?php

namespace App\Services\SpecialStudy;

use App\Exceptions\SpecialStudyException;
use App\Models\ReviewCard;

final class SpecialStudyCriteria
{
    public const MODE_PREVIEW = 'preview';
    public const MODE_FORMAL = 'formal';
    public const MODE_EARLY_REVIEW = 'early_review';

    public const SCENARIO_TODAY_FORGOTTEN = 'today_forgotten';
    public const SCENARIO_BACKLOG = 'backlog';
    public const SCENARIO_REVIEW_AHEAD = 'review_ahead';
    public const SCENARIO_RECENT_NEW = 'recent_new';
    public const SCENARIO_FILTERED = 'filtered';

    public const SORT_MOST_OVERDUE = 'most_overdue';
    public const SORT_MOST_LAPSES = 'most_lapses';
    public const SORT_LOWEST_RETRIEVABILITY = 'lowest_retrievability';
    public const SORT_RANDOM = 'random';
    public const SORT_SOURCE = 'source';

    private const MODES = [
        self::MODE_PREVIEW,
        self::MODE_FORMAL,
        self::MODE_EARLY_REVIEW,
    ];

    private const SCENARIOS = [
        self::SCENARIO_TODAY_FORGOTTEN,
        self::SCENARIO_BACKLOG,
        self::SCENARIO_REVIEW_AHEAD,
        self::SCENARIO_RECENT_NEW,
        self::SCENARIO_FILTERED,
    ];

    private const SORTS = [
        self::SORT_MOST_OVERDUE,
        self::SORT_MOST_LAPSES,
        self::SORT_LOWEST_RETRIEVABILITY,
        self::SORT_RANDOM,
        self::SORT_SOURCE,
    ];

    private const LIFECYCLES = [
        ReviewCard::LIFECYCLE_ACTIVE,
        ReviewCard::LIFECYCLE_BURIED,
        ReviewCard::LIFECYCLE_SUSPENDED,
        ReviewCard::LIFECYCLE_ARCHIVED,
    ];

    private const FSRS_STATES = ['new', 'learning', 'review', 'relearning'];

    private function __construct(private readonly array $values)
    {
    }

    public static function fromArray(array $input): self
    {
        $scenario = self::enum(
            $input,
            'scenario',
            self::SCENARIOS,
            null,
        );
        $executionMode = self::enum(
            $input,
            'execution_mode',
            self::MODES,
            self::MODE_PREVIEW,
        );
        $sort = self::enum(
            $input,
            'sort',
            self::SORTS,
            self::SORT_LOWEST_RETRIEVABILITY,
        );
        $cardLimit = self::boundedInteger($input, 'card_limit', 1, 500, 100);
        $days = self::boundedInteger($input, 'days', 1, 365, 7);

        if ($scenario === self::SCENARIO_RECENT_NEW
            && $executionMode !== self::MODE_PREVIEW) {
            throw self::validation(
                'execution_mode',
                'recent_new_preview_only',
                'Recently created senses can only be studied in preview mode.',
            );
        }

        if ($scenario === self::SCENARIO_REVIEW_AHEAD) {
            if ($executionMode === self::MODE_FORMAL) {
                throw self::validation(
                    'execution_mode',
                    'review_ahead_requires_early_mode',
                    'Writing review-ahead sessions must use early_review mode.',
                );
            }
        } elseif ($executionMode === self::MODE_EARLY_REVIEW) {
            throw self::validation(
                'execution_mode',
                'early_mode_requires_review_ahead',
                'early_review mode is only valid for review_ahead sessions.',
            );
        }

        $rawFilters = $input['filters'] ?? [];
        if (! is_array($rawFilters)) {
            throw self::validation(
                'filters',
                'invalid_type',
                'Special Study filters must be an object.',
            );
        }

        $lifecycles = self::enumList(
            $rawFilters,
            'lifecycle_states',
            self::LIFECYCLES,
            4,
        );
        if ($lifecycles === []) {
            $lifecycles = [ReviewCard::LIFECYCLE_ACTIVE];
        }
        if ($executionMode !== self::MODE_PREVIEW
            && $lifecycles !== [ReviewCard::LIFECYCLE_ACTIVE]) {
            throw self::validation(
                'lifecycle_states',
                'formal_requires_active',
                'Formal Special Study sessions can only include active cards.',
            );
        }

        return new self([
            'version' => 1,
            'scenario' => $scenario,
            'execution_mode' => $executionMode,
            'sort' => $sort,
            'card_limit' => $cardLimit,
            'days' => $days,
            'filters' => [
                'tag_ids' => self::idList($rawFilters, 'tag_ids', 20),
                'markers' => self::integerList(
                    $rawFilters,
                    'markers',
                    ReviewCard::MARKERS,
                    8,
                ),
                'article_ids' => self::idList($rawFilters, 'article_ids', 20),
                'chapter_ids' => self::idList($rawFilters, 'chapter_ids', 20),
                'lifecycle_states' => $lifecycles,
                'fsrs_states' => self::enumList(
                    $rawFilters,
                    'fsrs_states',
                    self::FSRS_STATES,
                    4,
                ),
            ],
        ]);
    }

    public function get(string $key): mixed
    {
        return $this->values[$key];
    }

    public function filters(): array
    {
        return $this->values['filters'];
    }

    public function toArray(): array
    {
        return $this->values;
    }

    private static function enum(
        array $input,
        string $field,
        array $allowed,
        ?string $default,
    ): string {
        if (! array_key_exists($field, $input)) {
            if ($default !== null) {
                return $default;
            }

            throw self::validation(
                $field,
                'missing',
                "Special Study {$field} is required.",
            );
        }

        $value = $input[$field];
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw self::validation(
                $field,
                'unknown_value',
                "Special Study {$field} is invalid.",
            );
        }

        return $value;
    }

    private static function boundedInteger(
        array $input,
        string $field,
        int $minimum,
        int $maximum,
        int $default,
    ): int {
        if (! array_key_exists($field, $input)) {
            return $default;
        }

        $value = $input[$field];
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw self::validation(
                $field,
                'out_of_range',
                "Special Study {$field} must be an integer from {$minimum} to {$maximum}.",
            );
        }

        return $value;
    }

    private static function idList(array $input, string $field, int $maximum): array
    {
        return self::integerList(
            $input,
            $field,
            null,
            $maximum,
            1,
        );
    }

    private static function integerList(
        array $input,
        string $field,
        ?array $allowed,
        int $maximum,
        ?int $minimum = null,
    ): array {
        $values = $input[$field] ?? [];
        if (! is_array($values) || count($values) > $maximum) {
            throw self::validation(
                $field,
                'invalid_list',
                "Special Study {$field} must be a bounded list.",
            );
        }

        foreach ($values as $value) {
            if (! is_int($value)
                || ($minimum !== null && $value < $minimum)
                || ($allowed !== null && ! in_array($value, $allowed, true))) {
                throw self::validation(
                    $field,
                    'invalid_value',
                    "Special Study {$field} contains an invalid value.",
                );
            }
        }

        $values = array_values(array_unique($values));
        sort($values, SORT_NUMERIC);

        return $values;
    }

    private static function enumList(
        array $input,
        string $field,
        array $allowed,
        int $maximum,
    ): array {
        $values = $input[$field] ?? [];
        if (! is_array($values) || count($values) > $maximum) {
            throw self::validation(
                $field,
                'invalid_list',
                "Special Study {$field} must be a bounded list.",
            );
        }

        foreach ($values as $value) {
            if (! is_string($value) || ! in_array($value, $allowed, true)) {
                throw self::validation(
                    $field,
                    'invalid_value',
                    "Special Study {$field} contains an invalid value.",
                );
            }
        }

        $values = array_values(array_unique($values));
        usort(
            $values,
            fn (string $left, string $right) =>
                array_search($left, $allowed, true)
                <=> array_search($right, $allowed, true),
        );

        return $values;
    }

    private static function validation(
        string $field,
        string $reason,
        string $message,
    ): SpecialStudyException {
        return new SpecialStudyException($reason, $message, 422, $field);
    }
}
