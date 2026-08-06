<?php

namespace Tests\Unit;

use App\Services\ReviewCardManageFilterState;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReviewCardManageFilterStateTest extends TestCase
{
    public function test_round_trip_is_normalized_and_stable(): void
    {
        $state = ReviewCardManageFilterState::fromArray([
            'q' => '  is:review   difficult  ',
            'filter' => 'all',
            'sort_by' => 'fsrs_due_at',
            'sort_dir' => 'ASC',
            'fsrs_states' => ['review', 'new', 'review'],
            'due_range' => 'today',
            'reps_min' => '3',
            'lapses_min' => null,
            'tag_ids' => [],
        ]);

        $expected = [
            'q' => 'is:review   difficult',
            'filter' => 'all',
            'sort_by' => 'fsrs_due_at',
            'sort_dir' => 'asc',
            'fsrs_states' => ['new', 'review'],
            'due_range' => 'today',
            'reps_min' => 3,
            'lapses_min' => null,
            'tag_ids' => [],
        ];

        $this->assertSame($expected, $state->toArray());
        $this->assertSame($expected, ReviewCardManageFilterState::fromArray($state->toArray())->toArray());
        $this->assertSame(json_encode($expected), json_encode($state->toArray()));
    }

    public function test_defaults_are_explicit(): void
    {
        $this->assertSame([
            'q' => '',
            'filter' => 'enabled',
            'sort_by' => 'id',
            'sort_dir' => 'desc',
            'fsrs_states' => [],
            'due_range' => 'all',
            'reps_min' => null,
            'lapses_min' => null,
            'tag_ids' => [],
        ], ReviewCardManageFilterState::fromArray([])->toArray());
    }

    #[DataProvider('invalidStates')]
    public function test_invalid_state_throws_structured_validation(array $input, string $field): void
    {
        try {
            ReviewCardManageFilterState::fromArray($input);
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public static function invalidStates(): array
    {
        return [
            'filter' => [['filter' => 'unknown'], 'filter'],
            'sort' => [['sort_by' => 'word_senses.sense_en'], 'sort_by'],
            'direction' => [['sort_dir' => 'sideways'], 'sort_dir'],
            'states shape' => [['fsrs_states' => 'review'], 'fsrs_states'],
            'state value' => [['fsrs_states' => ['review', 'bad']], 'fsrs_states.1'],
            'due range' => [['due_range' => 'month'], 'due_range'],
            'negative reps' => [['reps_min' => -1], 'reps_min'],
            'float lapses' => [['lapses_min' => 1.5], 'lapses_min'],
            'tags shape' => [['tag_ids' => '1'], 'tag_ids'],
            'invalid tag id' => [['tag_ids' => [0]], 'tag_ids.0'],
            'duplicate tag id' => [['tag_ids' => [1, 1]], 'tag_ids.1'],
        ];
    }
}
