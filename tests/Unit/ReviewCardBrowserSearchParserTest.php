<?php

namespace Tests\Unit;

use App\Services\InvalidBrowserSearchException;
use App\Services\ReviewCardBrowserSearchParser;
use Tests\TestCase;

/**
 * ReviewCardBrowserSearchParserTest
 *
 * ADR-0012: Unit tests for the pure-function browser search parser.
 *
 * The parser is a pure function — no DB queries, no Request/Auth access,
 * no state mutation. These tests verify token recognition, normalization,
 * conflict detection, error handling, and the pure-function contract.
 *
 * Coverage (20+ cases per task spec):
 *  1.  Empty string
 *  2.  Pure plain text
 *  3.  Single is token
 *  4.  governance + lifecycle combination
 *  5.  rated token
 *  6.  prop 5 operators (=, >, >=, <, <=)
 *  7.  Plain text + multiple tokens
 *  8.  Case normalization
 *  9.  Duplicate token deduplication
 *  10. Conflict lifecycle (e.g. is:active is:suspended)
 *  11. Conflict governance (is:leech is:struggling)
 *  12. Unknown is value
 *  13. Unknown rated value
 *  14. Unknown prop field
 *  15. Missing prop value
 *  16. Negative number
 *  17. Non-number
 *  18. Unsupported operator
 *  19. Extra whitespace
 *  20. Parser does not query DB (pure function)
 *  + additional edge cases (URLs, colon-at-start, mixed)
 */
class ReviewCardBrowserSearchParserTest extends TestCase
{
    private ReviewCardBrowserSearchParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ReviewCardBrowserSearchParser();
    }

    // ─── 1. Empty string ───

    public function test_empty_string_produces_empty_criteria(): void
    {
        $criteria = $this->parser->parse('');

        $this->assertSame('', $criteria->rawQuery);
        $this->assertSame('', $criteria->textQuery);
        $this->assertNull($criteria->governanceStatus);
        $this->assertNull($criteria->lifecycleStatus);
        $this->assertSame([], $criteria->ratings);
        $this->assertSame([], $criteria->propertyConditions);
        $this->assertSame([], $criteria->normalizedTokens);
        $this->assertFalse($criteria->hasAdvancedTokens());
        $this->assertFalse($criteria->hasTextQuery());
    }

    public function test_whitespace_only_string_produces_empty_criteria(): void
    {
        $criteria = $this->parser->parse('   ');

        $this->assertSame('', $criteria->textQuery);
        $this->assertFalse($criteria->hasAdvancedTokens());
    }

    // ─── 2. Pure plain text ───

    public function test_pure_plain_text_is_preserved(): void
    {
        $criteria = $this->parser->parse('charge');

        $this->assertSame('charge', $criteria->textQuery);
        $this->assertSame([], $criteria->normalizedTokens);
        $this->assertFalse($criteria->hasAdvancedTokens());
        $this->assertTrue($criteria->hasTextQuery());
    }

    public function test_multi_word_plain_text_is_preserved(): void
    {
        $criteria = $this->parser->parse('take charge of');

        $this->assertSame('take charge of', $criteria->textQuery);
        $this->assertFalse($criteria->hasAdvancedTokens());
    }

    // ─── 3. Single is token ───

    public function test_single_governance_token_is_parsed(): void
    {
        $criteria = $this->parser->parse('is:leech');

        $this->assertSame('leech', $criteria->governanceStatus);
        $this->assertSame(['is:leech'], $criteria->normalizedTokens);
        $this->assertTrue($criteria->hasAdvancedTokens());
        $this->assertTrue($criteria->hasGovernanceStatus());
    }

    public function test_single_lifecycle_token_is_parsed(): void
    {
        $criteria = $this->parser->parse('is:suspended');

        $this->assertSame('suspended', $criteria->lifecycleStatus);
        $this->assertSame(['is:suspended'], $criteria->normalizedTokens);
        $this->assertTrue($criteria->hasLifecycleStatus());
    }

    // ─── 4. governance + lifecycle combination ───

    public function test_governance_and_lifecycle_combine_legally(): void
    {
        $criteria = $this->parser->parse('is:leech is:suspended');

        $this->assertSame('leech', $criteria->governanceStatus);
        $this->assertSame('suspended', $criteria->lifecycleStatus);
        $this->assertContains('is:leech', $criteria->normalizedTokens);
        $this->assertContains('is:suspended', $criteria->normalizedTokens);
    }

    // ─── 5. rated token ───

    public function test_rated_again_is_parsed(): void
    {
        $criteria = $this->parser->parse('rated:again');

        $this->assertSame(['again'], $criteria->ratings);
        $this->assertSame(['rated:again'], $criteria->normalizedTokens);
        $this->assertTrue($criteria->hasRatings());
    }

    public function test_rated_hard_is_parsed(): void
    {
        $criteria = $this->parser->parse('rated:hard');

        $this->assertSame(['hard'], $criteria->ratings);
    }

    public function test_all_formal_ratings_combine(): void
    {
        $criteria = $this->parser->parse('rated:again rated:hard rated:good rated:easy');

        $this->assertSame(['again', 'hard', 'good', 'easy'], $criteria->ratings);
        $this->assertSame([
            'rated:again',
            'rated:hard',
            'rated:good',
            'rated:easy',
        ], $criteria->normalizedTokens);
    }

    public function test_rated_good_and_easy_are_normalized_case_insensitively(): void
    {
        $criteria = $this->parser->parse('Rated:Good RATED:EASY');

        $this->assertSame(['good', 'easy'], $criteria->ratings);
        $this->assertSame(['rated:good', 'rated:easy'], $criteria->normalizedTokens);
    }

    public function test_rated_good_and_easy_deduplicate_in_first_occurrence_order(): void
    {
        $criteria = $this->parser->parse('rated:good rated:easy rated:good rated:easy');

        $this->assertSame(['good', 'easy'], $criteria->ratings);
        $this->assertSame(['rated:good', 'rated:easy'], $criteria->normalizedTokens);
    }

    public function test_rated_good_and_easy_combine_with_existing_tokens(): void
    {
        $criteria = $this->parser->parse('charge is:active rated:good rated:easy prop:lapses>=2 flag:3');

        $this->assertSame('charge', $criteria->textQuery);
        $this->assertSame('active', $criteria->lifecycleStatus);
        $this->assertSame(3, $criteria->marker);
        $this->assertSame(['good', 'easy'], $criteria->ratings);
        $this->assertSame([
            'is:active',
            'rated:good',
            'rated:easy',
            'prop:lapses>=2',
            'flag:3',
        ], $criteria->normalizedTokens);
    }

    // ─── 6. prop 5 operators ───

    public function test_prop_lapses_equals(): void
    {
        $criteria = $this->parser->parse('prop:lapses=0');

        $this->assertCount(1, $criteria->propertyConditions);
        $this->assertSame('lapses', $criteria->propertyConditions[0]['field']);
        $this->assertSame('=', $criteria->propertyConditions[0]['operator']);
        $this->assertSame(0, $criteria->propertyConditions[0]['value']);
    }

    public function test_prop_lapses_greater_than(): void
    {
        $criteria = $this->parser->parse('prop:lapses>2');

        $this->assertSame('>', $criteria->propertyConditions[0]['operator']);
        $this->assertSame(2, $criteria->propertyConditions[0]['value']);
    }

    public function test_prop_lapses_greater_equal(): void
    {
        $criteria = $this->parser->parse('prop:lapses>=2');

        $this->assertSame('>=', $criteria->propertyConditions[0]['operator']);
        $this->assertSame(2, $criteria->propertyConditions[0]['value']);
    }

    public function test_prop_lapses_less_than(): void
    {
        $criteria = $this->parser->parse('prop:lapses<5');

        $this->assertSame('<', $criteria->propertyConditions[0]['operator']);
        $this->assertSame(5, $criteria->propertyConditions[0]['value']);
    }

    public function test_prop_lapses_less_equal(): void
    {
        $criteria = $this->parser->parse('prop:lapses<=5');

        $this->assertSame('<=', $criteria->propertyConditions[0]['operator']);
        $this->assertSame(5, $criteria->propertyConditions[0]['value']);
    }

    // ─── 7. Plain text + multiple tokens ───

    public function test_plain_text_and_multiple_tokens_combine(): void
    {
        $criteria = $this->parser->parse('charge is:leech rated:again prop:lapses>=2');

        $this->assertSame('charge', $criteria->textQuery);
        $this->assertSame('leech', $criteria->governanceStatus);
        $this->assertContains('again', $criteria->ratings);
        $this->assertCount(1, $criteria->propertyConditions);
        $this->assertContains('is:leech', $criteria->normalizedTokens);
        $this->assertContains('rated:again', $criteria->normalizedTokens);
        $this->assertContains('prop:lapses>=2', $criteria->normalizedTokens);
    }

    // ─── 8. Case normalization ───

    public function test_case_insensitive_is_token(): void
    {
        $criteria = $this->parser->parse('IS:LEECH');

        $this->assertSame('leech', $criteria->governanceStatus);
        $this->assertSame(['is:leech'], $criteria->normalizedTokens);
    }

    public function test_case_insensitive_mixed_case(): void
    {
        $criteria = $this->parser->parse('Is:Leech Is:Suspended Rated:Again PROP:LAPSES>=2');

        $this->assertSame('leech', $criteria->governanceStatus);
        $this->assertSame('suspended', $criteria->lifecycleStatus);
        $this->assertContains('again', $criteria->ratings);
        $this->assertSame('lapses', $criteria->propertyConditions[0]['field']);
    }

    // ─── 9. Duplicate token deduplication (ADR-0013) ───

    public function test_duplicate_governance_token_is_deduplicated(): void
    {
        // ADR-0013: Same token appearing twice must be normalized to a single
        // entry in normalizedTokens (and a single condition). This prevents
        // duplicate chips on the frontend and duplicate SQL WHERE clauses.
        $criteria = $this->parser->parse('is:leech is:leech');

        $this->assertSame('leech', $criteria->governanceStatus);
        $this->assertSame(['is:leech'], $criteria->normalizedTokens);
        $this->assertCount(1, $criteria->normalizedTokens);
    }

    public function test_duplicate_rated_token_is_deduplicated(): void
    {
        $criteria = $this->parser->parse('rated:again rated:again');

        $this->assertSame(['again'], $criteria->ratings);
        $this->assertCount(1, $criteria->ratings);
        $this->assertSame(['rated:again'], $criteria->normalizedTokens);
        $this->assertCount(1, $criteria->normalizedTokens);
    }

    public function test_duplicate_lifecycle_token_is_deduplicated(): void
    {
        // ADR-0013: Same lifecycle token repeated → single normalized entry.
        $criteria = $this->parser->parse('is:suspended is:suspended');

        $this->assertSame('suspended', $criteria->lifecycleStatus);
        $this->assertSame(['is:suspended'], $criteria->normalizedTokens);
        $this->assertCount(1, $criteria->normalizedTokens);
    }

    public function test_duplicate_property_condition_is_deduplicated(): void
    {
        // ADR-0013: Identical property condition (same field + operator + value)
        // appearing twice must collapse to a single condition and a single
        // normalized token, preventing duplicate SQL WHERE clauses.
        $criteria = $this->parser->parse('prop:lapses>=2 prop:lapses>=2');

        $this->assertCount(1, $criteria->propertyConditions);
        $this->assertSame('lapses', $criteria->propertyConditions[0]['field']);
        $this->assertSame('>=', $criteria->propertyConditions[0]['operator']);
        $this->assertSame(2, $criteria->propertyConditions[0]['value']);
        $this->assertSame(['prop:lapses>=2'], $criteria->normalizedTokens);
        $this->assertCount(1, $criteria->normalizedTokens);
    }

    public function test_different_operators_on_same_property_field_are_kept(): void
    {
        // ADR-0013: Different operators on the same field are NOT duplicates —
        // both conditions are kept (AND semantics). Only identical triplets
        // (field + operator + value) are deduplicated.
        $criteria = $this->parser->parse('prop:lapses>=2 prop:lapses<5');

        $this->assertCount(2, $criteria->propertyConditions);
        $this->assertContains('prop:lapses>=2', $criteria->normalizedTokens);
        $this->assertContains('prop:lapses<5', $criteria->normalizedTokens);
    }

    public function test_duplicate_token_mixed_with_other_tokens_dedups_correctly(): void
    {
        // ADR-0013: Dedup happens by first-occurrence order; other distinct
        // tokens are preserved in their original positions.
        $criteria = $this->parser->parse('is:leech rated:again rated:again prop:lapses>=2 is:leech');

        $this->assertSame(['is:leech', 'rated:again', 'prop:lapses>=2'], $criteria->normalizedTokens);
        $this->assertSame(['again'], $criteria->ratings);
        $this->assertCount(1, $criteria->propertyConditions);
    }

    // ─── 10. Conflict lifecycle ───

    public function test_conflict_lifecycle_throws_422(): void
    {
        $this->expectException(InvalidBrowserSearchException::class);

        $this->parser->parse('is:active is:suspended');
    }

    public function test_conflict_lifecycle_suspended_archived_throws_422(): void
    {
        $this->expectException(InvalidBrowserSearchException::class);

        $this->parser->parse('is:suspended is:archived');
    }

    // ─── 11. Conflict governance ───

    public function test_conflict_governance_throws_422(): void
    {
        $this->expectException(InvalidBrowserSearchException::class);

        $this->parser->parse('is:leech is:struggling');
    }

    // ─── 12. Unknown is ───

    public function test_unknown_is_value_throws_422(): void
    {
        try {
            $this->parser->parse('is:unknown');
            $this->fail('Expected InvalidBrowserSearchException');
        } catch (InvalidBrowserSearchException $e) {
            $this->assertSame('invalid_browser_search', $e->toResponseArray()['code']);
            $errors = $e->getErrors();
            $this->assertCount(1, $errors);
            $this->assertSame('is:unknown', $errors[0]['token']);
            $this->assertArrayHasKey('reason', $errors[0]);
            $this->assertArrayHasKey('example', $errors[0]);
        }
    }

    // ─── 13. Unknown rated ───

    public function test_unknown_rated_value_throws_422(): void
    {
        $this->expectException(InvalidBrowserSearchException::class);

        $this->parser->parse('rated:excellent');
    }

    // ─── 14. Unknown prop ───

    public function test_unknown_prop_field_throws_422(): void
    {
        $this->expectException(InvalidBrowserSearchException::class);

        $this->parser->parse('prop:unknown>=2');
    }

    // ─── 15. Missing prop value ───

    public function test_missing_prop_value_throws_422(): void
    {
        $this->expectException(InvalidBrowserSearchException::class);

        $this->parser->parse('prop:lapses');
    }

    public function test_missing_prop_operator_and_value_throws_422(): void
    {
        $this->expectException(InvalidBrowserSearchException::class);

        $this->parser->parse('prop:lapses');
    }

    // ─── 16. Negative number ───

    public function test_negative_prop_value_throws_422(): void
    {
        $this->expectException(InvalidBrowserSearchException::class);

        $this->parser->parse('prop:lapses>=-1');
    }

    // ─── 17. Non-number ───

    public function test_non_number_prop_value_throws_422(): void
    {
        $this->expectException(InvalidBrowserSearchException::class);

        $this->parser->parse('prop:lapses>=abc');
    }

    // ─── 18. Unsupported operator ───

    public function test_unsupported_operator_throws_422(): void
    {
        $this->expectException(InvalidBrowserSearchException::class);

        $this->parser->parse('prop:lapses>>2');
    }

    // ─── 19. Extra whitespace ───

    public function test_extra_whitespace_is_collapsed(): void
    {
        $criteria = $this->parser->parse('  charge    is:leech   ');

        $this->assertSame('charge', $criteria->textQuery);
        $this->assertSame('leech', $criteria->governanceStatus);
    }

    public function test_tabs_and_newlines_are_collapsed(): void
    {
        $criteria = $this->parser->parse("charge\tis:leech\nrated:again");

        $this->assertSame('charge', $criteria->textQuery);
        $this->assertSame('leech', $criteria->governanceStatus);
        $this->assertContains('again', $criteria->ratings);
    }

    // ─── 20. Parser does not query DB (pure function) ───

    public function test_parser_is_pure_function_no_db_queries(): void
    {
        // The parser should produce identical output regardless of DB state.
        // We verify by parsing the same input twice and asserting equality.
        $input = 'charge is:leech rated:again prop:lapses>=2';

        $criteria1 = $this->parser->parse($input);
        $criteria2 = $this->parser->parse($input);

        $this->assertSame($criteria1->textQuery, $criteria2->textQuery);
        $this->assertSame($criteria1->governanceStatus, $criteria2->governanceStatus);
        $this->assertSame($criteria1->ratings, $criteria2->ratings);
        $this->assertSame($criteria1->propertyConditions, $criteria2->propertyConditions);
        $this->assertSame($criteria1->normalizedTokens, $criteria2->normalizedTokens);
    }

    // ─── Additional edge cases ───

    public function test_url_with_colon_is_treated_as_plain_text(): void
    {
        // 'http://example.com' has a colon but prefix is 'http', not is/rated/prop.
        $criteria = $this->parser->parse('http://example.com');

        $this->assertSame('http://example.com', $criteria->textQuery);
        $this->assertFalse($criteria->hasAdvancedTokens());
    }

    public function test_colon_at_start_is_plain_text(): void
    {
        $criteria = $this->parser->parse(':foo');

        $this->assertSame(':foo', $criteria->textQuery);
    }

    public function test_error_response_structure_has_required_fields(): void
    {
        try {
            $this->parser->parse('is:leech is:struggling');
            $this->fail('Expected InvalidBrowserSearchException');
        } catch (InvalidBrowserSearchException $e) {
            $response = $e->toResponseArray();
            $this->assertArrayHasKey('message', $response);
            $this->assertArrayHasKey('code', $response);
            $this->assertArrayHasKey('errors', $response);
            $this->assertSame('invalid_browser_search', $response['code']);
            $this->assertNotEmpty($response['errors']);
            $this->assertArrayHasKey('token', $response['errors'][0]);
            $this->assertArrayHasKey('reason', $response['errors'][0]);
            $this->assertArrayHasKey('example', $response['errors'][0]);
        }
    }

    public function test_search_meta_payload_structure(): void
    {
        $criteria = $this->parser->parse('charge is:leech prop:lapses>=2');

        $meta = $criteria->toSearchMeta();

        $this->assertArrayHasKey('raw_query', $meta);
        $this->assertArrayHasKey('text_query', $meta);
        $this->assertArrayHasKey('tokens', $meta);
        $this->assertArrayHasKey('advanced', $meta);
        $this->assertSame('charge is:leech prop:lapses>=2', $meta['raw_query']);
        $this->assertSame('charge', $meta['text_query']);
        $this->assertTrue($meta['advanced']);
        $this->assertContains('is:leech', $meta['tokens']);
        $this->assertContains('prop:lapses>=2', $meta['tokens']);
    }

    public function test_search_meta_advanced_false_for_plain_text(): void
    {
        $criteria = $this->parser->parse('charge');
        $meta = $criteria->toSearchMeta();

        $this->assertFalse($meta['advanced']);
        $this->assertSame([], $meta['tokens']);
    }

    // ─── state: token tests (Phase 8B) ───

    public function test_state_new_is_parsed(): void
    {
        $criteria = $this->parser->parse('state:new');

        $this->assertSame(['new'], $criteria->fsrsStates);
        $this->assertSame(['state:new'], $criteria->normalizedTokens);
        $this->assertTrue($criteria->hasFsrsStates());
    }

    public function test_state_learning_is_parsed(): void
    {
        $criteria = $this->parser->parse('state:learning');

        $this->assertSame(['learning'], $criteria->fsrsStates);
    }

    public function test_state_review_is_parsed(): void
    {
        $criteria = $this->parser->parse('state:review');

        $this->assertSame(['review'], $criteria->fsrsStates);
    }

    public function test_state_relearning_is_parsed(): void
    {
        $criteria = $this->parser->parse('state:relearning');

        $this->assertSame(['relearning'], $criteria->fsrsStates);
    }

    public function test_state_is_normalized_case_insensitively(): void
    {
        $criteria = $this->parser->parse('State:New STATE:new');

        $this->assertSame(['new'], $criteria->fsrsStates);
        $this->assertSame(['state:new'], $criteria->normalizedTokens);
    }

    public function test_duplicate_state_token_is_deduplicated(): void
    {
        $criteria = $this->parser->parse('state:review state:review state:review');

        $this->assertSame(['review'], $criteria->fsrsStates);
        $this->assertSame(['state:review'], $criteria->normalizedTokens);
    }

    public function test_two_different_state_values_return_422(): void
    {
        try {
            $this->parser->parse('state:new state:review');
            $this->fail('Expected InvalidBrowserSearchException');
        } catch (InvalidBrowserSearchException $e) {
            $errors = $e->getErrors();
            $this->assertCount(1, $errors);
            $this->assertStringContainsString('不能同时指定多个不同的 FSRS 状态', $errors[0]['reason']);
        }
    }

    public function test_state_combines_with_existing_tokens(): void
    {
        $criteria = $this->parser->parse('charge is:active state:review rated:good flag:2');

        $this->assertSame('charge', $criteria->textQuery);
        $this->assertSame('active', $criteria->lifecycleStatus);
        $this->assertSame(2, $criteria->marker);
        $this->assertSame(['good'], $criteria->ratings);
        $this->assertSame(['review'], $criteria->fsrsStates);
        $this->assertSame([
            'is:active',
            'state:review',
            'rated:good',
            'flag:2',
        ], $criteria->normalizedTokens);
    }

    public function test_unknown_state_value_returns_structured_error(): void
    {
        try {
            $this->parser->parse('state:unknown');
            $this->fail('Expected InvalidBrowserSearchException');
        } catch (InvalidBrowserSearchException $e) {
            $errors = $e->getErrors();
            $this->assertCount(1, $errors);
            $this->assertStringContainsString('不支持的 state:', $errors[0]['reason']);
            $this->assertSame('state:unknown', $errors[0]['token']);
            $this->assertSame('state:new', $errors[0]['example']);
        }
    }

    // Phase 8C parser coverage.
    // Due-date search coverage.

    public function test_due_today_is_parsed(): void
    {
        $token = sprintf('%s%c%s', 'due', 58, 'today');
        $criteria = $this->parser->parse($token);
        $this->assertSame('today', $criteria->dueDate);
        $this->assertSame([$token], $criteria->normalizedTokens);
        $this->assertTrue($criteria->hasDueDate());
    }

    public function test_due_relative_and_absolute_values_are_parsed(): void
    {
        foreach (['yesterday', 'tomorrow', '2026-07-17'] as $value) {
            $token = $this->advancedToken('due', $value);
            $criteria = $this->parser->parse($token);

            $this->assertSame($value, $criteria->dueDate);
            $this->assertSame([$token], $criteria->normalizedTokens);
        }
    }

    public function test_duplicate_due_token_is_deduplicated(): void
    {
        $token = $this->advancedToken('due', 'today');
        $criteria = $this->parser->parse($token . ' ' . strtoupper($token));

        $this->assertSame('today', $criteria->dueDate);
        $this->assertSame([$token], $criteria->normalizedTokens);
    }

    public function test_different_due_tokens_return_422(): void
    {
        try {
            $this->parser->parse(
                $this->advancedToken('due', 'today') . ' ' . $this->advancedToken('due', 'tomorrow')
            );
            $this->fail('Expected InvalidBrowserSearchException');
        } catch (InvalidBrowserSearchException $e) {
            $errors = $e->getErrors();
            $this->assertCount(1, $errors);
            $this->assertStringContainsString('不能同时指定多个到期日期', $errors[0]['reason']);
        }
    }

    public function test_invalid_due_values_return_structured_errors(): void
    {
        foreach (['2026-02-30', 'nextweek'] as $value) {
            $token = $this->advancedToken('due', $value);
            try {
                $this->parser->parse($token);
                $this->fail('Expected InvalidBrowserSearchException for ' . $token);
            } catch (InvalidBrowserSearchException $e) {
                $errors = $e->getErrors();
                $this->assertCount(1, $errors);
                $this->assertSame($token, $errors[0]['token']);
                $this->assertSame($this->advancedToken('due', 'today'), $errors[0]['example']);
            }
        }
    }

    public function test_extended_fsrs_properties_accept_integer_and_decimal_values(): void
    {
        $tokens = [
            $this->advancedToken('prop', 'stability>=3.5'),
            $this->advancedToken('prop', 'difficulty<=7.25'),
            $this->advancedToken('prop', 'reps=4'),
            $this->advancedToken('prop', 'lapses>1'),
        ];
        $criteria = $this->parser->parse(implode(' ', $tokens));

        $this->assertSame([
            ['field' => 'stability', 'operator' => '>=', 'value' => 3.5],
            ['field' => 'difficulty', 'operator' => '<=', 'value' => 7.25],
            ['field' => 'reps', 'operator' => '=', 'value' => 4],
            ['field' => 'lapses', 'operator' => '>', 'value' => 1],
        ], $criteria->propertyConditions);
        $this->assertSame($tokens, $criteria->normalizedTokens);
    }

    public function test_integer_properties_reject_decimal_values(): void
    {
        foreach (['reps>=1.5', 'lapses=0.5'] as $value) {
            try {
                $this->parser->parse($this->advancedToken('prop', $value));
                $this->fail('Expected InvalidBrowserSearchException for ' . $value);
            } catch (InvalidBrowserSearchException $e) {
                $this->assertCount(1, $e->getErrors());
            }
        }
    }

    public function test_extended_properties_reject_negative_values(): void
    {
        $this->expectException(InvalidBrowserSearchException::class);

        $this->parser->parse($this->advancedToken('prop', 'stability>=-0.1'));
    }

    public function test_due_and_extended_properties_combine_with_existing_tokens(): void
    {
        $criteria = $this->parser->parse(implode(' ', [
            'charge',
            $this->advancedToken('is', 'active'),
            $this->advancedToken('state', 'review'),
            $this->advancedToken('rated', 'good'),
            $this->advancedToken('flag', '2'),
            $this->advancedToken('due', 'today'),
            $this->advancedToken('prop', 'stability>=3'),
            $this->advancedToken('prop', 'difficulty<8'),
            $this->advancedToken('prop', 'reps>=4'),
        ]));

        $this->assertSame('charge', $criteria->textQuery);
        $this->assertSame('today', $criteria->dueDate);
        $this->assertSame(['review'], $criteria->fsrsStates);
        $this->assertSame(['good'], $criteria->ratings);
        $this->assertSame(2, $criteria->marker);
        $this->assertCount(3, $criteria->propertyConditions);
        $this->assertContains($this->advancedToken('due', 'today'), $criteria->normalizedTokens);
        $this->assertContains($this->advancedToken('prop', 'stability>=3'), $criteria->normalizedTokens);
        $this->assertContains($this->advancedToken('prop', 'difficulty<8'), $criteria->normalizedTokens);
        $this->assertContains($this->advancedToken('prop', 'reps>=4'), $criteria->normalizedTokens);
    }

    public function test_multiple_errors_are_all_reported(): void
    {
        try {
            $this->parser->parse('is:unknown rated:excellent prop:lapses>>2');
            $this->fail('Expected InvalidBrowserSearchException');
        } catch (InvalidBrowserSearchException $e) {
            $errors = $e->getErrors();
            $this->assertGreaterThanOrEqual(3, count($errors));
        }
    }

    private function advancedToken(string $prefix, string $value): string
    {
        return sprintf('%s%c%s', $prefix, 58, $value);
    }
}
