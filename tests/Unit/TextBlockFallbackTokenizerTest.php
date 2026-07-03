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
     * -ches/-shes/-xes/-zes → strip -es is an ultra-safe rule applied
     * regardless of ECDICT availability. Without ECDICT the fallback must
     * still lemmatize watches/boxes/fixes/buzzes/washes correctly.
     *
     * Covers the OpenCode-RealClickFinalAudit-3 defect: when the Python
     * tokenizer was down, `watches` was previously kept as `watches`.
     */
    public function test_fallback_tokenizer_strips_es_for_ultra_safe_endings_without_ecdict(): void
    {
        $tokens = $this->tokenize('watches fixes boxes buzzes washes catches pushes.');

        $this->assertSame('watch', $this->tokenBySurface($tokens, 'watches')->l);
        $this->assertSame('fix', $this->tokenBySurface($tokens, 'fixes')->l);
        $this->assertSame('box', $this->tokenBySurface($tokens, 'boxes')->l);
        $this->assertSame('buzz', $this->tokenBySurface($tokens, 'buzzes')->l);
        $this->assertSame('wash', $this->tokenBySurface($tokens, 'washes')->l);
        $this->assertSame('catch', $this->tokenBySurface($tokens, 'catches')->l);
        $this->assertSame('push', $this->tokenBySurface($tokens, 'pushes')->l);
    }

    /**
     * -oes is handled by the irregular table (does→do, goes→go), NOT by the
     * ultra-safe -es strip. This test confirms `goes`/`does` still lemmatize
     * via the irregular table even without ECDICT.
     */
    public function test_fallback_tokenizer_handles_oes_via_irregular_table(): void
    {
        $tokens = $this->tokenize('He goes and does it.');

        $this->assertSame('go', $this->tokenBySurface($tokens, 'goes')->l);
        $this->assertSame('do', $this->tokenBySurface($tokens, 'does')->l);
    }

    /**
     * Without ECDICT, the -ies rule is NOT applied (it is ECDICT-gated to
     * avoid mishandling -ie plurals like brownies→browny). The fallback must
     * keep the surface form for technologies/stories/bodies/studies.
     *
     * This documents the conservative behavior: when both Python tokenizer
     * AND ECDICT are unavailable, -ies words keep their surface form.
     */
    public function test_fallback_tokenizer_keeps_ies_surface_without_ecdict(): void
    {
        $tokens = $this->tokenize('technologies stories bodies studies brownies.');

        $this->assertSame('technologies', $this->tokenBySurface($tokens, 'technologies')->l);
        $this->assertSame('stories', $this->tokenBySurface($tokens, 'stories')->l);
        $this->assertSame('bodies', $this->tokenBySurface($tokens, 'bodies')->l);
        $this->assertSame('studies', $this->tokenBySurface($tokens, 'studies')->l);
        $this->assertSame('brownies', $this->tokenBySurface($tokens, 'brownies')->l);
    }

    /**
     * With ECDICT available (simulated via a test subclass), the -ies rule
     * fires and recovers common morphology: technologies→technology,
     * stories→story, bodies→body, studies→study. -ie plurals are recovered
     * via the -ie fallback: brownies→brownie, cookies→cookie, movies→movie.
     *
     * Singular -ies words (series, species) keep their surface form because
     * neither "sery"/"serie" (for series) nor "specy"/"specie-as-lemma" is
     * accepted — see ECDICT simulation below.
     */
    public function test_fallback_tokenizer_applies_ies_rule_with_ecdict_available(): void
    {
        $tokens = $this->tokenizeWithEcdict(
            'technologies stories bodies studies brownies cookies movies series species.',
            [
                'technology', 'story', 'body', 'study',
                'brownie', 'cookie', 'movie',
                // Note: 'serie', 'sery', 'specy', 'specie' are intentionally
                // NOT in the simulated dictionary so series/species keep surface.
            ]
        );

        $this->assertSame('technology', $this->tokenBySurface($tokens, 'technologies')->l);
        $this->assertSame('story', $this->tokenBySurface($tokens, 'stories')->l);
        $this->assertSame('body', $this->tokenBySurface($tokens, 'bodies')->l);
        $this->assertSame('study', $this->tokenBySurface($tokens, 'studies')->l);
        $this->assertSame('brownie', $this->tokenBySurface($tokens, 'brownies')->l);
        $this->assertSame('cookie', $this->tokenBySurface($tokens, 'cookies')->l);
        $this->assertSame('movie', $this->tokenBySurface($tokens, 'movies')->l);
        // Singular -ies words keep surface (no valid -y/-ie candidate in dict).
        $this->assertSame('series', $this->tokenBySurface($tokens, 'series')->l);
        $this->assertSame('species', $this->tokenBySurface($tokens, 'species')->l);
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
     * Tokenize with a simulated ECDICT that knows exactly the given words.
     *
     * @param string   $text
     * @param string[] $knownWords
     * @return array<int, \stdClass>
     */
    private function tokenizeWithEcdict(string $text, array $knownWords): array
    {
        $service = new class(1, 'english', $knownWords) extends TextBlockService {
            /** @var array<string, true> */
            private array $dict;

            /**
             * @param int      $userId
             * @param string   $language
             * @param string[] $knownWords
             */
            public function __construct($userId, $language, array $knownWords)
            {
                parent::__construct($userId, $language);
                $this->dict = [];
                foreach ($knownWords as $w) {
                    $this->dict[mb_strtolower($w, 'UTF-8')] = true;
                }
            }

            protected function ecdictAvailable(): bool
            {
                return true;
            }

            protected function lemmaInEcdict(string $word): bool
            {
                return isset($this->dict[mb_strtolower($word, 'UTF-8')]);
            }
        };

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
