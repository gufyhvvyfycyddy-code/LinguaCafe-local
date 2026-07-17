<?php

namespace Tests\Unit;

use App\Services\InvalidBrowserSearchException;
use App\Services\ReviewCardBrowserSearchParser;
use Tests\TestCase;

class ReviewCardBrowserRecentReviewSearchParserTest extends TestCase
{
    private ReviewCardBrowserSearchParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ReviewCardBrowserSearchParser();
    }

    public function test_numeric_rated_tokens_parse_normalize_and_preserve_symbolic_ratings(): void
    {
        $criteria = $this->parser->parse('RATED:007:01 rated:30 rated:again');

        $this->assertSame([
            ['days' => 7, 'rating' => 'again'],
            ['days' => 30, 'rating' => null],
        ], $criteria->recentReviewConditions);
        $this->assertSame(['again'], $criteria->ratings);
        $this->assertSame(['rated:7:1', 'rated:30', 'rated:again'], $criteria->normalizedTokens);
        $this->assertTrue($criteria->hasRecentReviewConditions());
    }

    public function test_numeric_rated_tokens_deduplicate_by_normalized_first_occurrence(): void
    {
        $criteria = $this->parser->parse('rated:007:01 rated:7:1 rated:030 rated:30');

        $this->assertSame([
            ['days' => 7, 'rating' => 'again'],
            ['days' => 30, 'rating' => null],
        ], $criteria->recentReviewConditions);
        $this->assertSame(['rated:7:1', 'rated:30'], $criteria->normalizedTokens);
    }

    public function test_rating_codes_map_one_through_four(): void
    {
        $criteria = $this->parser->parse('rated:7:1 rated:7:2 rated:7:3 rated:7:4');

        $this->assertSame([
            ['days' => 7, 'rating' => 'again'],
            ['days' => 7, 'rating' => 'hard'],
            ['days' => 7, 'rating' => 'good'],
            ['days' => 7, 'rating' => 'easy'],
        ], $criteria->recentReviewConditions);
    }

    public function test_invalid_numeric_rated_forms_return_structured_errors(): void
    {
        foreach (['rated:0', 'rated:-1', 'rated:366', 'rated:7:0', 'rated:7:5', 'rated:7:again', 'rated:7:1:2', 'rated:'] as $token) {
            try {
                $this->parser->parse($token);
                $this->fail('Expected InvalidBrowserSearchException for ' . $token);
            } catch (InvalidBrowserSearchException $exception) {
                $errors = $exception->getErrors();
                $this->assertCount(1, $errors);
                $this->assertSame($token, $errors[0]['token']);
                $this->assertSame('rated:7:1', $errors[0]['example']);
            }
        }
    }
}
