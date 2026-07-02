<?php

namespace Tests\Unit;

use App\Services\TextBlockService;
use Tests\TestCase;

class TextBlockFallbackTokenizerTest extends TestCase
{
    public function test_fallback_tokenizer_preserves_regular_inflections_and_uses_irregular_table(): void
    {
        $tokens = $this->tokenize('Facts opened called walking retailers are children.');

        $this->assertSame('facts', $this->tokenBySurface($tokens, 'Facts')->l);
        $this->assertSame('opened', $this->tokenBySurface($tokens, 'opened')->l);
        $this->assertSame('called', $this->tokenBySurface($tokens, 'called')->l);
        $this->assertSame('walking', $this->tokenBySurface($tokens, 'walking')->l);
        $this->assertSame('retailers', $this->tokenBySurface($tokens, 'retailers')->l);
        $this->assertSame('be', $this->tokenBySurface($tokens, 'are')->l);
        $this->assertSame('child', $this->tokenBySurface($tokens, 'children')->l);

        foreach (['Facts', 'opened', 'called', 'walking', 'retailers', 'are', 'children'] as $surface) {
            $this->assertSame('X', $this->tokenBySurface($tokens, $surface)->pos);
        }
    }

    public function test_fallback_tokenizer_keeps_safe_markers_numbers_and_punctuation_as_stable_tokens(): void
    {
        $tokens = $this->tokenize('Hello ZZPARAZZ world 123!');

        $this->assertSame(['Hello', 'ZZPARAZZ', 'world', '123', '!'], array_map(
            fn (\stdClass $token) => $token->w,
            $tokens
        ));

        $marker = $this->tokenBySurface($tokens, 'ZZPARAZZ');
        $this->assertSame('zzparazz', $marker->l);
        $this->assertSame('X', $marker->pos);

        $number = $this->tokenBySurface($tokens, '123');
        $this->assertSame('123', $number->l);
        $this->assertSame('PUNCT', $number->pos);

        $punctuation = $this->tokenBySurface($tokens, '!');
        $this->assertSame('!', $punctuation->l);
        $this->assertSame('PUNCT', $punctuation->pos);
    }

    public function test_fallback_tokenizer_throws_for_blank_text(): void
    {
        $this->expectException(\Exception::class);

        $this->tokenize('   ');
    }

    /**
     * @return array<int, \stdClass>
     */
    private function tokenize(string $text): array
    {
        $service = new TextBlockService(1, 'english');
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('fallbackEnglishTokenize');
        $method->setAccessible(true);

        return $method->invoke($service, $text);
    }

    /**
     * @param array<int, \stdClass> $tokens
     */
    private function tokenBySurface(array $tokens, string $surface): \stdClass
    {
        foreach ($tokens as $token) {
            if ($token->w === $surface) {
                return $token;
            }
        }

        $this->fail("Token [{$surface}] was not found.");
    }
}
