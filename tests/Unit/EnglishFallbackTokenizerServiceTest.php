<?php

namespace Tests\Unit;

use App\Services\EnglishFallbackTokenizerService;
use Tests\TestCase;

class EnglishFallbackTokenizerServiceTest extends TestCase
{
    public function test_tokenize_preserves_token_shape_order_and_sentence_indexes(): void
    {
        $tokens = $this->service()->tokenize(
            'Hello 123! Next.',
            fn (): bool => false,
            fn (string $word): bool => false,
        );

        $this->assertSame(['Hello', '123', '!', 'Next', '.'], array_column(
            array_map(fn (\stdClass $token): array => get_object_vars($token), $tokens),
            'w',
        ));
        $this->assertSame([0, 0, 0, 2, 2], array_map(
            fn (\stdClass $token): int => $token->si,
            $tokens,
        ));
        $this->assertSame(['w', 'r', 'l', 'pos', 'lr', 'si', 'g'], array_keys(get_object_vars($tokens[0])));
        $this->assertSame('hello', $tokens[0]->l);
        $this->assertSame('X', $tokens[0]->pos);
        $this->assertSame('123', $tokens[1]->l);
        $this->assertSame('PUNCT', $tokens[1]->pos);
    }

    public function test_tokenize_preserves_conservative_and_ultra_safe_rules_without_ecdict(): void
    {
        $tokens = $this->service()->tokenize(
            'Facts opened walking children watches boxes goes.',
            fn (): bool => false,
            fn (string $word): bool => false,
        );
        $lemmas = [];
        foreach ($tokens as $token) {
            $lemmas[$token->w] = $token->l;
        }

        $this->assertSame('facts', $lemmas['Facts']);
        $this->assertSame('opened', $lemmas['opened']);
        $this->assertSame('walking', $lemmas['walking']);
        $this->assertSame('child', $lemmas['children']);
        $this->assertSame('watch', $lemmas['watches']);
        $this->assertSame('box', $lemmas['boxes']);
        $this->assertSame('go', $lemmas['goes']);
    }

    public function test_tokenize_uses_read_only_ecdict_callbacks_for_ies_candidates(): void
    {
        $known = array_fill_keys(['technology', 'brownie'], true);
        $availabilityCalls = 0;
        $lemmaCalls = [];

        $tokens = $this->service()->tokenize(
            'technologies brownies series.',
            function () use (&$availabilityCalls): bool {
                $availabilityCalls++;
                return true;
            },
            function (string $word) use (&$lemmaCalls, $known): bool {
                $lemmaCalls[] = $word;
                return isset($known[$word]);
            },
        );
        $lemmas = [];
        foreach ($tokens as $token) {
            $lemmas[$token->w] = $token->l;
        }

        $this->assertSame('technology', $lemmas['technologies']);
        $this->assertSame('brownie', $lemmas['brownies']);
        $this->assertSame('series', $lemmas['series']);
        $this->assertGreaterThanOrEqual(3, $availabilityCalls);
        $this->assertContains('technology', $lemmaCalls);
        $this->assertContains('browny', $lemmaCalls);
        $this->assertContains('brownie', $lemmaCalls);
    }

    public function test_irregular_lemma_mapping_remains_exact_for_representative_entries(): void
    {
        $service = $this->service();

        $this->assertSame('be', $service->irregularLemma('was'));
        $this->assertSame('leave', $service->irregularLemma('left'));
        $this->assertSame('break', $service->irregularLemma('broken'));
        $this->assertSame('child', $service->irregularLemma('children'));
        $this->assertSame('goose', $service->irregularLemma('geese'));
        $this->assertNull($service->irregularLemma('regular'));
    }

    public function test_tokenize_throws_the_established_exception_for_blank_text(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('基础英文分词没有得到可导入的词。');

        $this->service()->tokenize(
            '   ',
            fn (): bool => false,
            fn (string $word): bool => false,
        );
    }

    public function test_text_block_service_keeps_facade_hooks_and_delegates_fallback_implementation(): void
    {
        $source = file_get_contents(app_path('Services/TextBlockService.php'));
        $fallbackStart = strpos($source, 'private function fallbackEnglishTokenize');
        $fallbackEnd = strpos($source, 'private function suggestEnglishLemmaForDoctorOnly');
        $fallbackSection = substr($source, $fallbackStart, $fallbackEnd - $fallbackStart);

        $this->assertStringContainsString('private EnglishFallbackTokenizerService $englishFallbackTokenizer;', $source);
        $this->assertStringContainsString('$this->englishFallbackTokenizer = new EnglishFallbackTokenizerService();', $source);
        $this->assertStringContainsString('private function fallbackEnglishTokenize(string $text): array', $source);
        $this->assertStringContainsString('$this->englishFallbackTokenizer->tokenize(', $fallbackSection);
        $this->assertStringContainsString('fn (): bool => $this->ecdictAvailable()', $fallbackSection);
        $this->assertStringContainsString('fn (string $word): bool => $this->lemmaInEcdict($word)', $fallbackSection);
        $this->assertStringNotContainsString('preg_match_all(', $fallbackSection);
        $this->assertStringNotContainsString("'children' => 'child'", $source);
        $this->assertStringContainsString('protected function lemmaInEcdict(string $word): bool', $source);
        $this->assertStringContainsString('protected function ecdictAvailable(): bool', $source);
        $this->assertStringContainsString('return $this->englishFallbackTokenizer->irregularLemma($word);', $source);
    }

    private function service(): EnglishFallbackTokenizerService
    {
        return new EnglishFallbackTokenizerService();
    }
}
