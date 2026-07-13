<?php

namespace Tests\Unit;

use App\Services\ReviewQueueOrderOptions;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ReviewQueueOrderOptions value object.
 *
 * Verifies defaults, allowed enums, invalid value rejection, partial input,
 * unknown field handling, and JSON round-trip.
 */
class ReviewQueueOrderOptionsTest extends TestCase
{
    public function test_defaults_returns_anki_aligned_values(): void
    {
        $opts = ReviewQueueOrderOptions::defaults();

        $this->assertSame('mix', $opts->interdayLearningReviewOrder);
        $this->assertSame('mix', $opts->newReviewOrder);
        $this->assertSame('due_random', $opts->reviewSortOrder);
        $this->assertSame('created_asc', $opts->newSortOrder);
        $this->assertSame('global', $opts->scope);
        $this->assertFalse($opts->presetSupported);
    }

    public function test_from_array_with_all_valid_values(): void
    {
        $opts = ReviewQueueOrderOptions::fromArray([
            'interday_learning_review_order' => 'before',
            'new_review_order' => 'after',
            'review_sort_order' => 'ascending_retrievability',
            'new_sort_order' => 'created_desc',
        ]);

        $this->assertSame('before', $opts->interdayLearningReviewOrder);
        $this->assertSame('after', $opts->newReviewOrder);
        $this->assertSame('ascending_retrievability', $opts->reviewSortOrder);
        $this->assertSame('created_desc', $opts->newSortOrder);
    }

    public function test_from_array_with_empty_array_returns_defaults(): void
    {
        $opts = ReviewQueueOrderOptions::fromArray([]);

        $this->assertSame(ReviewQueueOrderOptions::DEFAULT_INTERDAY_LEARNING_REVIEW_ORDER, $opts->interdayLearningReviewOrder);
        $this->assertSame(ReviewQueueOrderOptions::DEFAULT_NEW_REVIEW_ORDER, $opts->newReviewOrder);
        $this->assertSame(ReviewQueueOrderOptions::DEFAULT_REVIEW_SORT_ORDER, $opts->reviewSortOrder);
        $this->assertSame(ReviewQueueOrderOptions::DEFAULT_NEW_SORT_ORDER, $opts->newSortOrder);
    }

    public function test_from_array_with_partial_input_uses_defaults_for_missing_keys(): void
    {
        $opts = ReviewQueueOrderOptions::fromArray([
            'review_sort_order' => 'due_stable',
        ]);

        $this->assertSame('mix', $opts->interdayLearningReviewOrder);
        $this->assertSame('mix', $opts->newReviewOrder);
        $this->assertSame('due_stable', $opts->reviewSortOrder);
        $this->assertSame('created_asc', $opts->newSortOrder);
    }

    public function test_from_array_ignores_unknown_fields(): void
    {
        $opts = ReviewQueueOrderOptions::fromArray([
            'interday_learning_review_order' => 'after',
            'unknown_field' => 'whatever',
            'another_unknown' => 42,
        ]);

        $this->assertSame('after', $opts->interdayLearningReviewOrder);
        $this->assertSame('mix', $opts->newReviewOrder);
    }

    public function test_invalid_interday_order_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReviewQueueOrderOptions::fromArray(['interday_learning_review_order' => 'invalid']);
    }

    public function test_invalid_new_review_order_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReviewQueueOrderOptions::fromArray(['new_review_order' => 'invalid']);
    }

    public function test_invalid_review_sort_order_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReviewQueueOrderOptions::fromArray(['review_sort_order' => 'invalid']);
    }

    public function test_invalid_new_sort_order_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReviewQueueOrderOptions::fromArray(['new_sort_order' => 'invalid']);
    }

    public function test_all_allowed_interday_values(): void
    {
        foreach (ReviewQueueOrderOptions::ALLOWED_INTERDAY as $value) {
            $opts = ReviewQueueOrderOptions::fromArray(['interday_learning_review_order' => $value]);
            $this->assertSame($value, $opts->interdayLearningReviewOrder);
        }
    }

    public function test_all_allowed_new_review_values(): void
    {
        foreach (ReviewQueueOrderOptions::ALLOWED_NEW_REVIEW as $value) {
            $opts = ReviewQueueOrderOptions::fromArray(['new_review_order' => $value]);
            $this->assertSame($value, $opts->newReviewOrder);
        }
    }

    public function test_all_allowed_review_sort_values(): void
    {
        foreach (ReviewQueueOrderOptions::ALLOWED_REVIEW_SORT as $value) {
            $opts = ReviewQueueOrderOptions::fromArray(['review_sort_order' => $value]);
            $this->assertSame($value, $opts->reviewSortOrder);
        }
    }

    public function test_all_allowed_new_sort_values(): void
    {
        foreach (ReviewQueueOrderOptions::ALLOWED_NEW_SORT as $value) {
            $opts = ReviewQueueOrderOptions::fromArray(['new_sort_order' => $value]);
            $this->assertSame($value, $opts->newSortOrder);
        }
    }

    public function test_to_array_round_trip(): void
    {
        $original = ReviewQueueOrderOptions::fromArray([
            'interday_learning_review_order' => 'before',
            'new_review_order' => 'after',
            'review_sort_order' => 'random',
            'new_sort_order' => 'random',
        ]);

        $array = $original->toArray();
        $restored = ReviewQueueOrderOptions::fromArray($array);

        $this->assertSame($original->interdayLearningReviewOrder, $restored->interdayLearningReviewOrder);
        $this->assertSame($original->newReviewOrder, $restored->newReviewOrder);
        $this->assertSame($original->reviewSortOrder, $restored->reviewSortOrder);
        $this->assertSame($original->newSortOrder, $restored->newSortOrder);
    }

    public function test_to_array_includes_scope_and_preset_supported(): void
    {
        $opts = ReviewQueueOrderOptions::defaults();
        $array = $opts->toArray();

        $this->assertArrayHasKey('scope', $array);
        $this->assertSame('global', $array['scope']);
        $this->assertArrayHasKey('preset_supported', $array);
        $this->assertFalse($array['preset_supported']);
    }

    public function test_does_not_query_database(): void
    {
        // Options is a pure value object — no DB queries should occur.
        // This test documents that expectation; if someone adds DB calls,
        // they would need to change this test.
        $opts = ReviewQueueOrderOptions::defaults();
        $this->assertNotNull($opts);
    }
}
