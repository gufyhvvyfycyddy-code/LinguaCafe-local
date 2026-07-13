<?php

namespace Tests\Unit;

use App\Services\CustomStudy\CustomStudyCriteria;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CustomStudyCriteria value object.
 *
 * Pure unit tests — no Laravel container, no DB, no Auth, no Request.
 *
 * Verifies the four frozen criteria modes, parameter contracts,
 * immutability, unknown-key isolation, and toArray() round-trip.
 *
 * Task CS-1 of Custom Study 1A Phase 1 (Task 2000-16).
 */
class CustomStudyCriteriaTest extends TestCase
{
    // ---------- 8.3 / 8.4: valid modes ----------

    public function test_from_array_accepts_today_forgotten_with_empty_parameters(): void
    {
        $criteria = CustomStudyCriteria::fromArray([
            'mode' => 'today_forgotten',
            'parameters' => [],
        ]);

        $this->assertSame('today_forgotten', $criteria->mode());
        $this->assertSame([], $criteria->parameters());
    }

    public function test_from_array_accepts_today_forgotten_without_parameters_key(): void
    {
        $criteria = CustomStudyCriteria::fromArray([
            'mode' => 'today_forgotten',
        ]);

        $this->assertSame('today_forgotten', $criteria->mode());
        $this->assertSame([], $criteria->parameters());
    }

    public function test_from_array_accepts_overdue_with_empty_parameters(): void
    {
        $criteria = CustomStudyCriteria::fromArray([
            'mode' => 'overdue',
            'parameters' => [],
        ]);

        $this->assertSame('overdue', $criteria->mode());
        $this->assertSame([], $criteria->parameters());
    }

    public function test_from_array_accepts_overdue_without_parameters_key(): void
    {
        $criteria = CustomStudyCriteria::fromArray([
            'mode' => 'overdue',
        ]);

        $this->assertSame('overdue', $criteria->mode());
        $this->assertSame([], $criteria->parameters());
    }

    public function test_from_array_accepts_source_chapter_with_valid_chapter_id(): void
    {
        $criteria = CustomStudyCriteria::fromArray([
            'mode' => 'source_chapter',
            'parameters' => ['chapter_id' => 42],
        ]);

        $this->assertSame('source_chapter', $criteria->mode());
        $this->assertSame(['chapter_id' => 42], $criteria->parameters());
    }

    public function test_from_array_accepts_leech_attention_with_leech_only(): void
    {
        $criteria = CustomStudyCriteria::fromArray([
            'mode' => 'leech_attention',
            'parameters' => ['sub_mode' => 'leech_only'],
        ]);

        $this->assertSame('leech_attention', $criteria->mode());
        $this->assertSame(['sub_mode' => 'leech_only'], $criteria->parameters());
    }

    public function test_from_array_accepts_leech_attention_with_leech_plus_struggling(): void
    {
        $criteria = CustomStudyCriteria::fromArray([
            'mode' => 'leech_attention',
            'parameters' => ['sub_mode' => 'leech_plus_struggling'],
        ]);

        $this->assertSame('leech_attention', $criteria->mode());
        $this->assertSame(['sub_mode' => 'leech_plus_struggling'], $criteria->parameters());
    }

    // ---------- 8.4: unknown mode ----------

    public function test_from_array_rejects_unknown_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CustomStudyCriteria::fromArray([
            'mode' => 'marked',
            'parameters' => [],
        ]);
    }

    public function test_from_array_rejects_another_unknown_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CustomStudyCriteria::fromArray([
            'mode' => 'filtered_deck',
        ]);
    }

    // ---------- 8.3: missing / invalid parameters ----------

    public function test_from_array_rejects_source_chapter_missing_chapter_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CustomStudyCriteria::fromArray([
            'mode' => 'source_chapter',
            'parameters' => [],
        ]);
    }

    public function test_from_array_rejects_source_chapter_with_chapter_id_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CustomStudyCriteria::fromArray([
            'mode' => 'source_chapter',
            'parameters' => ['chapter_id' => 0],
        ]);
    }

    public function test_from_array_rejects_source_chapter_with_negative_chapter_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CustomStudyCriteria::fromArray([
            'mode' => 'source_chapter',
            'parameters' => ['chapter_id' => -5],
        ]);
    }

    public function test_from_array_rejects_source_chapter_with_string_chapter_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CustomStudyCriteria::fromArray([
            'mode' => 'source_chapter',
            'parameters' => ['chapter_id' => '42'],
        ]);
    }

    public function test_from_array_rejects_source_chapter_with_null_chapter_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CustomStudyCriteria::fromArray([
            'mode' => 'source_chapter',
            'parameters' => ['chapter_id' => null],
        ]);
    }

    public function test_from_array_rejects_leech_attention_missing_sub_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CustomStudyCriteria::fromArray([
            'mode' => 'leech_attention',
            'parameters' => [],
        ]);
    }

    public function test_from_array_rejects_leech_attention_with_invalid_sub_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CustomStudyCriteria::fromArray([
            'mode' => 'leech_attention',
            'parameters' => ['sub_mode' => 'all'],
        ]);
    }

    // ---------- 8.4: toArray round-trip ----------

    public function test_to_array_round_trips_for_today_forgotten(): void
    {
        $input = ['mode' => 'today_forgotten', 'parameters' => []];
        $criteria = CustomStudyCriteria::fromArray($input);

        $this->assertSame($input, $criteria->toArray());
    }

    public function test_to_array_round_trips_for_source_chapter(): void
    {
        $input = ['mode' => 'source_chapter', 'parameters' => ['chapter_id' => 7]];
        $criteria = CustomStudyCriteria::fromArray($input);

        $this->assertSame($input, $criteria->toArray());
    }

    public function test_to_array_round_trips_for_leech_attention(): void
    {
        $input = ['mode' => 'leech_attention', 'parameters' => ['sub_mode' => 'leech_only']];
        $criteria = CustomStudyCriteria::fromArray($input);

        $this->assertSame($input, $criteria->toArray());
    }

    // ---------- 8.4: unknown keys ignored ----------

    public function test_unknown_top_level_keys_are_ignored(): void
    {
        $criteria = CustomStudyCriteria::fromArray([
            'mode' => 'today_forgotten',
            'parameters' => [],
            'user_id' => 999,
            'language' => 'other-language',
            'token' => 'malicious-token',
        ]);

        $this->assertSame('today_forgotten', $criteria->mode());
        $this->assertSame([], $criteria->parameters());
        $this->assertSame(
            ['mode' => 'today_forgotten', 'parameters' => []],
            $criteria->toArray()
        );
    }

    public function test_unknown_parameter_keys_are_ignored(): void
    {
        $criteria = CustomStudyCriteria::fromArray([
            'mode' => 'source_chapter',
            'parameters' => ['chapter_id' => 7, 'extra' => 'ignored'],
        ]);

        $this->assertSame(['chapter_id' => 7], $criteria->parameters());
        $this->assertSame(
            ['mode' => 'source_chapter', 'parameters' => ['chapter_id' => 7]],
            $criteria->toArray()
        );
    }

    // ---------- 8.4: immutability ----------

    public function test_external_modification_of_input_array_does_not_change_value_object(): void
    {
        $input = ['mode' => 'source_chapter', 'parameters' => ['chapter_id' => 7]];
        $criteria = CustomStudyCriteria::fromArray($input);

        $input['mode'] = 'today_forgotten';
        $input['parameters']['chapter_id'] = 999;

        $this->assertSame('source_chapter', $criteria->mode());
        $this->assertSame(['chapter_id' => 7], $criteria->parameters());
    }

    public function test_external_modification_of_returned_parameters_does_not_change_internal_state(): void
    {
        $criteria = CustomStudyCriteria::fromArray([
            'mode' => 'source_chapter',
            'parameters' => ['chapter_id' => 7],
        ]);

        $params = $criteria->parameters();
        $params['chapter_id'] = 999;

        $this->assertSame(['chapter_id' => 7], $criteria->parameters());
    }

    public function test_external_modification_of_to_array_output_does_not_change_internal_state(): void
    {
        $criteria = CustomStudyCriteria::fromArray([
            'mode' => 'source_chapter',
            'parameters' => ['chapter_id' => 7],
        ]);

        $arr = $criteria->toArray();
        $arr['mode'] = 'overdue';
        $arr['parameters']['chapter_id'] = 999;

        $this->assertSame('source_chapter', $criteria->mode());
        $this->assertSame(['chapter_id' => 7], $criteria->parameters());
    }

    // ---------- 8.5: malicious user_id / language do not become part of criteria ----------

    public function test_malicious_user_id_in_input_does_not_become_part_of_criteria(): void
    {
        $criteria = CustomStudyCriteria::fromArray([
            'mode' => 'today_forgotten',
            'parameters' => [],
            'user_id' => 999,
        ]);

        $this->assertSame(
            ['mode' => 'today_forgotten', 'parameters' => []],
            $criteria->toArray()
        );
    }

    public function test_malicious_language_in_input_does_not_become_part_of_criteria(): void
    {
        $criteria = CustomStudyCriteria::fromArray([
            'mode' => 'overdue',
            'parameters' => [],
            'language' => 'other-language',
        ]);

        $this->assertSame(
            ['mode' => 'overdue', 'parameters' => []],
            $criteria->toArray()
        );
    }

    public function test_malicious_user_id_and_language_in_parameters_do_not_become_part_of_criteria(): void
    {
        $criteria = CustomStudyCriteria::fromArray([
            'mode' => 'source_chapter',
            'parameters' => [
                'chapter_id' => 7,
                'user_id' => 999,
                'language' => 'other-language',
            ],
        ]);

        $this->assertSame(['chapter_id' => 7], $criteria->parameters());
        $this->assertSame(
            ['mode' => 'source_chapter', 'parameters' => ['chapter_id' => 7]],
            $criteria->toArray()
        );
    }
}
