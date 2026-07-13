<?php

namespace Tests\Unit;

use App\Exceptions\CustomStudyValidationException;
use App\Services\CustomStudy\CustomStudyCriteria;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CustomStudyCriteria value object.
 *
 * Pure unit tests — no Laravel container, no DB, no Auth, no Request.
 *
 * Verifies the four frozen criteria modes, parameter contracts,
 * immutability, unknown-key isolation, and toArray() round-trip.
 *
 * Task 2000-17 hardens the error contract: CustomStudyCriteria::fromArray()
 * now throws structured CustomStudyValidationException directly, with stable
 * field/reason set at each throw site. Message text is human-only and must
 * NOT be parsed by callers to derive field/reason.
 *
 * Task CS-1 of Custom Study 1A Phase 1 (Task 2000-16).
 * Error contract fix: Task 2000-17.
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

    // ---------- 8.4: missing mode key ----------

    public function test_from_array_rejects_missing_mode_key_with_stable_field_and_reason(): void
    {
        try {
            CustomStudyCriteria::fromArray([]);
            $this->fail('Expected CustomStudyValidationException was not thrown');
        } catch (CustomStudyValidationException $e) {
            $this->assertSame('mode', $e->getField());
            $this->assertSame('missing_mode', $e->getReason());
        }
    }

    // ---------- 8.4: unknown mode ----------

    public function test_from_array_rejects_unknown_mode(): void
    {
        $this->expectException(CustomStudyValidationException::class);
        CustomStudyCriteria::fromArray([
            'mode' => 'marked',
            'parameters' => [],
        ]);
    }

    public function test_from_array_rejects_unknown_mode_with_stable_field_and_reason(): void
    {
        try {
            CustomStudyCriteria::fromArray([
                'mode' => 'marked',
                'parameters' => [],
            ]);
            $this->fail('Expected CustomStudyValidationException was not thrown');
        } catch (CustomStudyValidationException $e) {
            $this->assertSame('mode', $e->getField());
            $this->assertSame('unknown_mode', $e->getReason());
        }
    }

    public function test_from_array_rejects_another_unknown_mode(): void
    {
        $this->expectException(CustomStudyValidationException::class);
        CustomStudyCriteria::fromArray([
            'mode' => 'filtered_deck',
        ]);
    }

    public function test_from_array_rejects_non_string_mode_with_stable_field_and_reason(): void
    {
        try {
            CustomStudyCriteria::fromArray([
                'mode' => 123,
                'parameters' => [],
            ]);
            $this->fail('Expected CustomStudyValidationException was not thrown');
        } catch (CustomStudyValidationException $e) {
            $this->assertSame('mode', $e->getField());
            $this->assertSame('unknown_mode', $e->getReason());
        }
    }

    // ---------- 8.3: invalid parameters shape ----------

    public function test_from_array_rejects_non_array_parameters_with_stable_field_and_reason(): void
    {
        try {
            CustomStudyCriteria::fromArray([
                'mode' => 'today_forgotten',
                'parameters' => 'not-an-array',
            ]);
            $this->fail('Expected CustomStudyValidationException was not thrown');
        } catch (CustomStudyValidationException $e) {
            $this->assertSame('criteria', $e->getField());
            $this->assertSame('invalid_parameters', $e->getReason());
        }
    }

    // ---------- 8.3: missing / invalid parameters ----------

    public function test_from_array_rejects_source_chapter_missing_chapter_id(): void
    {
        $this->expectException(CustomStudyValidationException::class);
        CustomStudyCriteria::fromArray([
            'mode' => 'source_chapter',
            'parameters' => [],
        ]);
    }

    public function test_from_array_rejects_source_chapter_missing_chapter_id_with_stable_field_and_reason(): void
    {
        try {
            CustomStudyCriteria::fromArray([
                'mode' => 'source_chapter',
                'parameters' => [],
            ]);
            $this->fail('Expected CustomStudyValidationException was not thrown');
        } catch (CustomStudyValidationException $e) {
            $this->assertSame('chapter_id', $e->getField());
            $this->assertSame('missing_chapter_id', $e->getReason());
        }
    }

    public function test_from_array_rejects_source_chapter_with_chapter_id_zero(): void
    {
        $this->expectException(CustomStudyValidationException::class);
        CustomStudyCriteria::fromArray([
            'mode' => 'source_chapter',
            'parameters' => ['chapter_id' => 0],
        ]);
    }

    public function test_from_array_rejects_source_chapter_with_chapter_id_zero_with_stable_field_and_reason(): void
    {
        try {
            CustomStudyCriteria::fromArray([
                'mode' => 'source_chapter',
                'parameters' => ['chapter_id' => 0],
            ]);
            $this->fail('Expected CustomStudyValidationException was not thrown');
        } catch (CustomStudyValidationException $e) {
            $this->assertSame('chapter_id', $e->getField());
            $this->assertSame('invalid_chapter_id', $e->getReason());
        }
    }

    public function test_from_array_rejects_source_chapter_with_negative_chapter_id(): void
    {
        $this->expectException(CustomStudyValidationException::class);
        CustomStudyCriteria::fromArray([
            'mode' => 'source_chapter',
            'parameters' => ['chapter_id' => -5],
        ]);
    }

    public function test_from_array_rejects_source_chapter_with_negative_chapter_id_with_stable_field_and_reason(): void
    {
        try {
            CustomStudyCriteria::fromArray([
                'mode' => 'source_chapter',
                'parameters' => ['chapter_id' => -5],
            ]);
            $this->fail('Expected CustomStudyValidationException was not thrown');
        } catch (CustomStudyValidationException $e) {
            $this->assertSame('chapter_id', $e->getField());
            $this->assertSame('invalid_chapter_id', $e->getReason());
        }
    }

    public function test_from_array_rejects_source_chapter_with_string_chapter_id(): void
    {
        $this->expectException(CustomStudyValidationException::class);
        CustomStudyCriteria::fromArray([
            'mode' => 'source_chapter',
            'parameters' => ['chapter_id' => '42'],
        ]);
    }

    public function test_from_array_rejects_source_chapter_with_string_chapter_id_with_stable_field_and_reason(): void
    {
        try {
            CustomStudyCriteria::fromArray([
                'mode' => 'source_chapter',
                'parameters' => ['chapter_id' => '42'],
            ]);
            $this->fail('Expected CustomStudyValidationException was not thrown');
        } catch (CustomStudyValidationException $e) {
            $this->assertSame('chapter_id', $e->getField());
            $this->assertSame('invalid_chapter_id', $e->getReason());
        }
    }

    public function test_from_array_rejects_source_chapter_with_null_chapter_id(): void
    {
        $this->expectException(CustomStudyValidationException::class);
        CustomStudyCriteria::fromArray([
            'mode' => 'source_chapter',
            'parameters' => ['chapter_id' => null],
        ]);
    }

    public function test_from_array_rejects_source_chapter_with_null_chapter_id_with_stable_field_and_reason(): void
    {
        try {
            CustomStudyCriteria::fromArray([
                'mode' => 'source_chapter',
                'parameters' => ['chapter_id' => null],
            ]);
            $this->fail('Expected CustomStudyValidationException was not thrown');
        } catch (CustomStudyValidationException $e) {
            $this->assertSame('chapter_id', $e->getField());
            $this->assertSame('invalid_chapter_id', $e->getReason());
        }
    }

    public function test_from_array_rejects_leech_attention_missing_sub_mode(): void
    {
        $this->expectException(CustomStudyValidationException::class);
        CustomStudyCriteria::fromArray([
            'mode' => 'leech_attention',
            'parameters' => [],
        ]);
    }

    public function test_from_array_rejects_leech_attention_missing_sub_mode_with_stable_field_and_reason(): void
    {
        try {
            CustomStudyCriteria::fromArray([
                'mode' => 'leech_attention',
                'parameters' => [],
            ]);
            $this->fail('Expected CustomStudyValidationException was not thrown');
        } catch (CustomStudyValidationException $e) {
            $this->assertSame('sub_mode', $e->getField());
            $this->assertSame('missing_sub_mode', $e->getReason());
        }
    }

    public function test_from_array_rejects_leech_attention_with_invalid_sub_mode(): void
    {
        $this->expectException(CustomStudyValidationException::class);
        CustomStudyCriteria::fromArray([
            'mode' => 'leech_attention',
            'parameters' => ['sub_mode' => 'all'],
        ]);
    }

    public function test_from_array_rejects_leech_attention_with_invalid_sub_mode_with_stable_field_and_reason(): void
    {
        try {
            CustomStudyCriteria::fromArray([
                'mode' => 'leech_attention',
                'parameters' => ['sub_mode' => 'all'],
            ]);
            $this->fail('Expected CustomStudyValidationException was not thrown');
        } catch (CustomStudyValidationException $e) {
            $this->assertSame('sub_mode', $e->getField());
            $this->assertSame('invalid_sub_mode', $e->getReason());
        }
    }

    // ---------- Task 2000-17: field/reason is the machine protocol, message is human-only ----------

    public function test_field_and_reason_are_stable_regardless_of_message_text_for_unknown_mode(): void
    {
        // The same logical error (unknown mode) must always produce the same
        // field/reason pair, no matter what the human-readable message says.
        // Two different invalid modes produce different messages but identical
        // field/reason — proving field/reason is NOT derived from message text.
        $reasons = [];
        foreach (['marked', 'filtered_deck', 'nonexistent', 'RANDOM'] as $badMode) {
            try {
                CustomStudyCriteria::fromArray(['mode' => $badMode, 'parameters' => []]);
                $this->fail("Expected exception for mode={$badMode}");
            } catch (CustomStudyValidationException $e) {
                $reasons[] = $e->getField() . '/' . $e->getReason();
            }
        }
        $this->assertSame(
            ['mode/unknown_mode', 'mode/unknown_mode', 'mode/unknown_mode', 'mode/unknown_mode'],
            $reasons
        );
    }

    public function test_field_and_reason_are_stable_regardless_of_message_text_for_invalid_chapter_id(): void
    {
        // Different invalid chapter_id values (0, -5, '42', null) all produce
        // different messages but identical field/reason — proving field/reason
        // is set at the throw site, not parsed from message.
        $reasons = [];
        foreach ([0, -5, '42', null, 3.14, false] as $badChapterId) {
            try {
                CustomStudyCriteria::fromArray([
                    'mode' => 'source_chapter',
                    'parameters' => ['chapter_id' => $badChapterId],
                ]);
                $this->fail("Expected exception for chapter_id=" . var_export($badChapterId, true));
            } catch (CustomStudyValidationException $e) {
                $reasons[] = $e->getField() . '/' . $e->getReason();
            }
        }
        $this->assertSame(
            [
                'chapter_id/invalid_chapter_id',
                'chapter_id/invalid_chapter_id',
                'chapter_id/invalid_chapter_id',
                'chapter_id/invalid_chapter_id',
                'chapter_id/invalid_chapter_id',
                'chapter_id/invalid_chapter_id',
            ],
            $reasons
        );
    }

    public function test_exception_is_not_invalid_argument_exception_subclass(): void
    {
        // CustomStudyValidationException extends Exception directly, NOT
        // InvalidArgumentException. This proves the error contract is
        // structural (field/reason), not string-message-based.
        try {
            CustomStudyCriteria::fromArray(['mode' => 'bad']);
            $this->fail('Expected CustomStudyValidationException');
        } catch (CustomStudyValidationException $e) {
            $this->assertFalse(
                $e instanceof \InvalidArgumentException,
                'CustomStudyValidationException must NOT extend InvalidArgumentException — '
                . 'the error contract is structural, not message-based.'
            );
        }
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
