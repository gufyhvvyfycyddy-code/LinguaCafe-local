<?php

namespace Tests\Unit;

use App\Services\ReviewQueueOrderPolicy;
use App\Services\ReviewQueueOrderOptions;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ReviewQueueOrderPolicy.
 *
 * Verifies intraday priority, interday/review ordering, new/review ordering,
 * mix determinism, edge cases (empty/unequal), and no card loss/duplication.
 */
class ReviewQueueOrderPolicyTest extends TestCase
{
    private function item(string $category, float $sortKey, int $cardId): array
    {
        return [
            'category' => $category,
            'sort_key' => $sortKey,
            'card_id' => $cardId,
            'card' => (object) ['id' => $cardId],
        ];
    }

    private function ids(array $ordered): array
    {
        return array_map(fn ($i) => $i['card_id'], $ordered);
    }

    public function test_intraday_always_first(): void
    {
        $items = [
            $this->item('review', 1.0, 101),
            $this->item('intraday', 5.0, 102),
            $this->item('new', 1.0, 103),
        ];

        $result = (new ReviewQueueOrderPolicy())->order($items, ReviewQueueOrderOptions::defaults());
        $ids = $this->ids($result);

        $this->assertSame(102, $ids[0]);
    }

    public function test_interday_before(): void
    {
        $items = [
            $this->item('review', 1.0, 201),
            $this->item('interday', 1.0, 202),
        ];

        $opts = ReviewQueueOrderOptions::fromArray([
            'interday_learning_review_order' => 'before',
            'new_review_order' => 'after',
        ]);

        $result = (new ReviewQueueOrderPolicy())->order($items, $opts);
        $this->assertSame([202, 201], $this->ids($result));
    }

    public function test_interday_after(): void
    {
        $items = [
            $this->item('review', 1.0, 201),
            $this->item('interday', 1.0, 202),
        ];

        $opts = ReviewQueueOrderOptions::fromArray([
            'interday_learning_review_order' => 'after',
            'new_review_order' => 'after',
        ]);

        $result = (new ReviewQueueOrderPolicy())->order($items, $opts);
        $this->assertSame([201, 202], $this->ids($result));
    }

    public function test_interday_mix_distributes_evenly(): void
    {
        $items = [
            $this->item('review', 1.0, 301),
            $this->item('review', 2.0, 302),
            $this->item('review', 3.0, 303),
            $this->item('interday', 1.0, 304),
            $this->item('interday', 2.0, 305),
        ];

        $opts = ReviewQueueOrderOptions::fromArray([
            'interday_learning_review_order' => 'mix',
            'new_review_order' => 'after',
        ]);

        $result = (new ReviewQueueOrderPolicy())->order($items, $opts);
        $ids = $this->ids($result);

        $this->assertCount(5, $ids);

        // interday items should be spread, not all clustered together
        $pos304 = array_search(304, $ids);
        $pos305 = array_search(305, $ids);
        $this->assertGreaterThan(1, abs($pos304 - $pos305), 'interday items should not be adjacent');
    }

    public function test_new_before(): void
    {
        $items = [
            $this->item('review', 1.0, 401),
            $this->item('new', 1.0, 402),
        ];

        $opts = ReviewQueueOrderOptions::fromArray([
            'new_review_order' => 'before',
        ]);

        $result = (new ReviewQueueOrderPolicy())->order($items, $opts);
        $this->assertSame([402, 401], $this->ids($result));
    }

    public function test_new_after(): void
    {
        $items = [
            $this->item('review', 1.0, 501),
            $this->item('new', 1.0, 502),
        ];

        $opts = ReviewQueueOrderOptions::fromArray([
            'new_review_order' => 'after',
        ]);

        $result = (new ReviewQueueOrderPolicy())->order($items, $opts);
        $this->assertSame([501, 502], $this->ids($result));
    }

    public function test_new_mix_distributes_evenly(): void
    {
        $items = [
            $this->item('review', 1.0, 601),
            $this->item('review', 2.0, 602),
            $this->item('review', 3.0, 603),
            $this->item('new', 1.0, 604),
            $this->item('new', 2.0, 605),
        ];

        $opts = ReviewQueueOrderOptions::fromArray([
            'new_review_order' => 'mix',
        ]);

        $result = (new ReviewQueueOrderPolicy())->order($items, $opts);
        $ids = $this->ids($result);

        $this->assertCount(5, $ids);
        // new items should be spread, not clustered at end
        $pos604 = array_search(604, $ids);
        $this->assertNotEquals(4, $pos604); // not last
    }

    public function test_double_mix_intraday_first(): void
    {
        $items = [
            $this->item('intraday', 1.0, 701),
            $this->item('interday', 1.0, 702),
            $this->item('review', 1.0, 703),
            $this->item('new', 1.0, 704),
        ];

        $opts = ReviewQueueOrderOptions::fromArray([
            'interday_learning_review_order' => 'mix',
            'new_review_order' => 'mix',
        ]);

        $result = (new ReviewQueueOrderPolicy())->order($items, $opts);
        $ids = $this->ids($result);

        // intraday always first
        $this->assertSame(701, $ids[0]);
        $this->assertCount(4, $ids);
    }

    public function test_empty_input_returns_empty(): void
    {
        $result = (new ReviewQueueOrderPolicy())->order([], ReviewQueueOrderOptions::defaults());
        $this->assertSame([], $result);
    }

    public function test_only_intraday(): void
    {
        $items = [
            $this->item('intraday', 2.0, 801),
            $this->item('intraday', 1.0, 802),
        ];

        $result = (new ReviewQueueOrderPolicy())->order($items, ReviewQueueOrderOptions::defaults());
        $this->assertSame([802, 801], $this->ids($result));
    }

    public function test_only_new(): void
    {
        $items = [
            $this->item('new', 2.0, 901),
            $this->item('new', 1.0, 902),
        ];

        $result = (new ReviewQueueOrderPolicy())->order($items, ReviewQueueOrderOptions::defaults());
        $this->assertSame([902, 901], $this->ids($result));
    }

    public function test_no_card_lost(): void
    {
        $items = [];
        for ($i = 1; $i <= 10; $i++) {
            $categories = ['intraday', 'interday', 'review', 'new'];
            $items[] = $this->item($categories[$i % 4], (float) $i, 1000 + $i);
        }

        $result = (new ReviewQueueOrderPolicy())->order($items, ReviewQueueOrderOptions::defaults());
        $this->assertCount(10, $result);

        $inputIds = array_map(fn ($i) => $i['card_id'], $items);
        $outputIds = $this->ids($result);
        sort($inputIds);
        sort($outputIds);
        $this->assertSame($inputIds, $outputIds);
    }

    public function test_no_card_duplicated(): void
    {
        $items = [
            $this->item('review', 1.0, 1101),
            $this->item('review', 2.0, 1102),
            $this->item('new', 1.0, 1103),
        ];

        $result = (new ReviewQueueOrderPolicy())->order($items, ReviewQueueOrderOptions::defaults());
        $ids = $this->ids($result);
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_internal_relative_order_preserved(): void
    {
        $items = [
            $this->item('review', 3.0, 1201),
            $this->item('review', 1.0, 1202),
            $this->item('review', 2.0, 1203),
            $this->item('new', 1.0, 1204),
        ];

        $opts = ReviewQueueOrderOptions::fromArray(['new_review_order' => 'after']);
        $result = (new ReviewQueueOrderPolicy())->order($items, $opts);
        $ids = $this->ids($result);

        // review cards should be in sort_key order: 1202, 1203, 1201
        $reviewIds = array_slice($ids, 0, 3);
        $this->assertSame([1202, 1203, 1201], $reviewIds);
    }

    public function test_extremely_unequal_counts(): void
    {
        $items = [];
        // 10 review, 1 new
        for ($i = 1; $i <= 10; $i++) {
            $items[] = $this->item('review', (float) $i, 1300 + $i);
        }
        $items[] = $this->item('new', 1.0, 1399);

        $opts = ReviewQueueOrderOptions::fromArray(['new_review_order' => 'mix']);
        $result = (new ReviewQueueOrderPolicy())->order($items, $opts);
        $this->assertCount(11, $result);

        // new card should be somewhere in the middle, not at the very end
        $ids = $this->ids($result);
        $pos = array_search(1399, $ids);
        $this->assertGreaterThan(0, $pos);
        $this->assertLessThan(10, $pos, 'new card should not be at the very end');
    }

    public function test_mix_is_deterministic(): void
    {
        $items = [
            $this->item('review', 1.0, 1401),
            $this->item('review', 2.0, 1402),
            $this->item('interday', 1.0, 1403),
        ];

        $opts = ReviewQueueOrderOptions::fromArray([
            'interday_learning_review_order' => 'mix',
            'new_review_order' => 'after',
        ]);

        $policy = new ReviewQueueOrderPolicy();
        $result1 = $policy->order($items, $opts);
        $result2 = $policy->order($items, $opts);

        $this->assertSame($this->ids($result1), $this->ids($result2));
    }

    public function test_sort_key_with_card_id_tiebreaker(): void
    {
        $items = [
            $this->item('review', 1.0, 1502),
            $this->item('review', 1.0, 1501),
            $this->item('review', 1.0, 1503),
        ];

        $result = (new ReviewQueueOrderPolicy())->order($items, ReviewQueueOrderOptions::defaults());
        $this->assertSame([1501, 1502, 1503], $this->ids($result));
    }
}
