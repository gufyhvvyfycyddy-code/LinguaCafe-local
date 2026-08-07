<?php

namespace Tests\Unit;

use App\Services\Dictionaries\DictionaryLookupRequestPolicy;
use App\Services\Dictionaries\DictionaryLookupResultPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DictionaryLookupPolicyTest extends TestCase
{
    public function test_request_policy_trims_and_accepts_unicode_up_to_100_characters(): void
    {
        $policy = new DictionaryLookupRequestPolicy();

        $this->assertSame('friendly', $policy->normalize('  friendly  '));
        $this->assertSame(str_repeat('é', 100), $policy->normalize(str_repeat('é', 100)));
        $this->assertSame('👋', $policy->normalize(' 👋 '));
        $this->assertSame("e\u{0301}", $policy->normalize(" e\u{0301} "));
    }

    /** @dataProvider invalidTerms */
    public function test_request_policy_rejects_blank_overlong_or_control_terms(string $term): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DictionaryLookupRequestPolicy())->normalize($term);
    }

    public static function invalidTerms(): array
    {
        return [
            'empty' => [''],
            'spaces' => [" \t\n "],
            'overlong' => [str_repeat('é', 101)],
            'nul' => ["bad\0term"],
            'control' => ["bad\x01term"],
        ];
    }

    public function test_result_policy_splits_imported_rows_consistently(): void
    {
        $policy = new DictionaryLookupResultPolicy();

        $this->assertSame(
            ['first', 'second'],
            $policy->splitImportedDefinitions(' first ; ; second;'),
        );
    }

    public function test_result_policy_deduplicates_before_capping_in_first_seen_order(): void
    {
        $policy = new DictionaryLookupResultPolicy();
        $raw = [
            'one', 'two', 'one', 'three', 'four', 'five', 'six', 'seven',
            'eight', 'nine', 'ten', 'eleven', 'twelve',
        ];

        $this->assertSame(
            ['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten'],
            $policy->dedupeAndCap($raw),
        );
    }

    public function test_result_policy_preserves_commas_inside_jmdict_definition(): void
    {
        $policy = new DictionaryLookupResultPolicy();

        $this->assertSame(
            ['friendly, kind, and pleasant'],
            $policy->dedupeAndCap(['friendly, kind, and pleasant']),
        );
    }
}
