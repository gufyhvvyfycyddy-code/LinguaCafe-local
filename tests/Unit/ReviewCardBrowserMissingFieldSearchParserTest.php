<?php

namespace Tests\Unit;

use App\Services\InvalidBrowserSearchException;
use App\Services\ReviewCardBrowserSearchParser;
use Tests\TestCase;

class ReviewCardBrowserMissingFieldSearchParserTest extends TestCase
{
    private ReviewCardBrowserSearchParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ReviewCardBrowserSearchParser();
    }

    public function test_missing_tokens_are_parsed_case_insensitively_and_normalized(): void
    {
        $definition = $this->token('definition');
        $example = $this->token('example');
        $source = $this->token('source');
        $criteria = $this->parser->parse(implode(' ', [ucfirst($definition), strtoupper($example), $source]));

        $this->assertSame(['definition', 'example', 'source'], $criteria->missingFields);
        $this->assertSame([$definition, $example, $source], $criteria->normalizedTokens);
        $this->assertTrue($criteria->hasMissingFields());
    }

    public function test_duplicate_missing_tokens_are_deduplicated_in_first_occurrence_order(): void
    {
        $definition = $this->token('definition');
        $source = $this->token('source');
        $example = $this->token('example');
        $criteria = $this->parser->parse(implode(' ', [
            $definition,
            strtoupper($definition),
            $source,
            $example,
            strtoupper($source),
        ]));

        $this->assertSame(['definition', 'source', 'example'], $criteria->missingFields);
        $this->assertSame([$definition, $source, $example], $criteria->normalizedTokens);
    }

    public function test_missing_tokens_combine_with_plain_text_and_existing_grammar(): void
    {
        $criteria = $this->parser->parse(implode(' ', [
            'charge',
            $this->token('definition'),
            $this->token('example'),
            $this->advancedToken('state', 'review'),
            $this->advancedToken('prop', 'lapses>=2'),
            $this->advancedToken('source', implode(chr(58), ['book', '12'])),
        ]));

        $this->assertSame('charge', $criteria->textQuery);
        $this->assertSame(['definition', 'example'], $criteria->missingFields);
        $this->assertSame(['review'], $criteria->fsrsStates);
        $this->assertCount(1, $criteria->propertyConditions);
        $this->assertSame([['kind' => 'book', 'id' => 12]], $criteria->sourceTargets);
    }

    public function test_invalid_missing_tokens_return_structured_errors(): void
    {
        foreach (['', 'definitions', 'any', implode(chr(58), ['source', 'extra'])] as $value) {
            $token = $this->token($value);

            try {
                $this->parser->parse($token);
                $this->fail('Expected InvalidBrowserSearchException for ' . $token);
            } catch (InvalidBrowserSearchException $exception) {
                $errors = $exception->getErrors();
                $this->assertCount(1, $errors);
                $this->assertSame($token, $errors[0]['token']);
                $this->assertSame($this->token('definition'), $errors[0]['example']);
            }
        }
    }

    private function token(string $value): string
    {
        return $this->advancedToken('missing', $value);
    }

    private function advancedToken(string $prefix, string $value): string
    {
        return sprintf('%s%c%s', $prefix, 58, $value);
    }
}
