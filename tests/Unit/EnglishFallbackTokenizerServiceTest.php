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

    public function test_source_links_and_emails_are_restored_as_single_non_vocabulary_tokens(): void
    {
        $service = (new \ReflectionClass(\App\Services\TextBlockService::class))
            ->newInstanceWithoutConstructor();
        $reflection = new \ReflectionClass($service);
        [$masked, $fragments] = $reflection->getMethod('protectSourceFragments')->invoke(
            $service,
            'Read https://plato.stanford.edu/entries/technicity. Contact editor@example.org.'
        );
        $this->assertCount(2, $fragments);
        $this->assertStringNotContainsString('plato.stanford', $masked);

        $tokens = $this->service()->tokenize($masked, fn (): bool => false, fn (string $w): bool => false);
        $restored = $reflection->getMethod('mapStructuralTokens')->invoke($service, $tokens, $fragments);
        $words = array_map(fn (\stdClass $token): string => $token->w, $restored);
        $this->assertSame([
            'Read', 'https://plato.stanford.edu/entries/technicity', '.',
            'Contact', 'editor@example.org', '.',
        ], $words);
        $this->assertSame([0, 0, 0, 2, 2, 2], array_map(fn (\stdClass $token): int => $token->si, $restored));
        $this->assertSame('PUNCT', $restored[1]->pos);
        $this->assertSame('PUNCT', $restored[4]->pos);
        $this->assertTrue(\App\Services\VocabularyTokenFilter::shouldSkip($restored[1]->w));
        $this->assertTrue(\App\Services\VocabularyTokenFilter::shouldSkip($restored[4]->w));
        $processed = array_map(fn (\stdClass $token): \stdClass => (object) [
            'word' => $token->w,
        ], $restored);
        $this->assertSame(['read', 'contact'],
            (new \App\Services\ReaderDataService(1, 'english'))->collectUniqueWords($processed));

        [$bracketed, $bracketedSources] = $reflection->getMethod('protectSourceFragments')->invoke(
            $service,
            '(https://en.wikipedia.org/wiki/Function_(mathematics)).'
        );
        $this->assertSame(['https://en.wikipedia.org/wiki/Function_(mathematics)'], array_values($bracketedSources));
        $this->assertStringEndsWith(').', $bracketed);

        [, $collisionSources] = $reflection->getMethod('protectSourceFragments')->invoke(
            $service,
            'ZZLCMETABZZ and editor@example.org.'
        );
        $this->assertArrayNotHasKey('ZZLCMETABZZ', $collisionSources);

        // Scholarly citations often omit https://; the domain must still stay atomic.
        [$bare, $bareSources] = $reflection->getMethod('protectSourceFragments')->invoke(
            $service,
            'See plato.stanford.edu/entries/technicity today.'
        );
        $this->assertSame(['plato.stanford.edu/entries/technicity'], array_values($bareSources));
        $bareTokens = $this->service()->tokenize($bare, fn (): bool => false, fn (string $w): bool => false);
        $bareRestored = $reflection->getMethod('mapStructuralTokens')->invoke($service, $bareTokens, $bareSources);
        $this->assertSame(['See', 'plato.stanford.edu/entries/technicity', 'today', '.'],
            array_map(fn (\stdClass $token): string => $token->w, $bareRestored));
        $this->assertTrue(\App\Services\VocabularyTokenFilter::shouldSkip($bareRestored[1]->w));
        [, $names] = $reflection->getMethod('protectSourceFragments')->invoke(
            $service,
            'Mr.Smith and Dr.Jones cite C.S.Lewis.'
        );
        $this->assertSame([], $names, 'Personal names are not bare domains');
    }

    private function service(): EnglishFallbackTokenizerService
    {
        return new EnglishFallbackTokenizerService();
    }
}
