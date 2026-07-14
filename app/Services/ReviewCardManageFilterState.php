<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class ReviewCardManageFilterState
{
    public const FILTERS = [
        'all', 'enabled', 'disabled', 'active', 'buried', 'suspended',
        'archived', 'learning', 'due', 'future', 'leech', 'struggling',
        'missing_definition', 'missing_example', 'missing_source',
    ];

    public const SORT_FIELDS = [
        'id', 'fsrs_state', 'fsrs_due_at', 'fsrs_stability',
        'fsrs_difficulty', 'fsrs_reps', 'fsrs_lapses', 'fsrs_last_reviewed_at',
    ];

    public const FSRS_STATES = ['new', 'learning', 'review', 'relearning'];
    public const DUE_RANGES = ['all', 'overdue', 'today', 'next7', 'future', 'none'];

    private function __construct(private array $values)
    {
    }

    public static function fromRequest(Request $request): self
    {
        return self::fromArray($request->only([
            'q', 'filter', 'sort_by', 'sort_dir', 'fsrs_states',
            'due_range', 'reps_min', 'lapses_min',
        ]));
    }

    public static function fromArray(array $input): self
    {
        if (isset($input['fsrs_states']) && is_array($input['fsrs_states'])) {
            $input['fsrs_states'] = array_values(array_filter(
                $input['fsrs_states'],
                fn ($value) => $value !== '' && $value !== null,
            ));
        }

        $validator = Validator::make($input, [
            'q' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'filter' => ['sometimes', 'string', 'in:' . implode(',', self::FILTERS)],
            'sort_by' => ['sometimes', 'string', 'in:' . implode(',', self::SORT_FIELDS)],
            'sort_dir' => ['sometimes', 'string', function ($attribute, $value, $fail) {
                if (!is_string($value) || !in_array(strtolower($value), ['asc', 'desc'], true)) {
                    $fail('The sort direction must be asc or desc.');
                }
            }],
            'fsrs_states' => ['sometimes', 'array'],
            'fsrs_states.*' => ['string', 'in:' . implode(',', self::FSRS_STATES)],
            'due_range' => ['sometimes', 'string', 'in:' . implode(',', self::DUE_RANGES)],
            'reps_min' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'lapses_min' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);
        $validator->validate();

        $states = $input['fsrs_states'] ?? [];
        $states = array_values(array_unique($states));
        $order = array_flip(self::FSRS_STATES);
        usort($states, fn ($left, $right) => $order[$left] <=> $order[$right]);

        return new self([
            'q' => trim((string) ($input['q'] ?? '')),
            'filter' => $input['filter'] ?? 'enabled',
            'sort_by' => $input['sort_by'] ?? 'id',
            'sort_dir' => strtolower($input['sort_dir'] ?? 'desc'),
            'fsrs_states' => $states,
            'due_range' => $input['due_range'] ?? 'all',
            'reps_min' => self::nullableInt($input['reps_min'] ?? null),
            'lapses_min' => self::nullableInt($input['lapses_min'] ?? null),
        ]);
    }

    public function get(string $key)
    {
        return $this->values[$key];
    }

    public function toArray(): array
    {
        return $this->values;
    }

    private static function nullableInt($value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
