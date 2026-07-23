<?php

namespace App\Services;

class EnglishFallbackTokenizerService
{
    /**
     * @param callable(): bool $ecdictAvailable
     * @param callable(string): bool $lemmaInEcdict
     * @return array<int, \stdClass>
     */
    public function tokenize(
        string $text,
        callable $ecdictAvailable,
        callable $lemmaInEcdict
    ): array {
        $tokens = [];
        $sentenceIndex = 0;

        $sentences = preg_split(
            '/((?<=[.!?])\s+)/u',
            trim($text),
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        foreach ($sentences as $sentence) {
            preg_match_all('/[A-Za-z]+(?:[\'-][A-Za-z]+)?|[0-9]+|[^\sA-Za-z0-9]/u', $sentence, $matches);

            foreach ($matches[0] ?? [] as $surface) {
                if ($surface === '') {
                    continue;
                }

                $tokens[] = $this->makeToken(
                    $surface,
                    $sentenceIndex,
                    $ecdictAvailable,
                    $lemmaInEcdict,
                );
            }

            $sentenceIndex++;
        }

        if (count($tokens) === 0) {
            throw new \Exception('基础英文分词没有得到可导入的词。');
        }

        return $tokens;
    }

    public function irregularLemma(string $word): ?string
    {
        $map = [
            'am' => 'be', 'is' => 'be', 'are' => 'be', 'was' => 'be', 'were' => 'be',
            'been' => 'be', 'being' => 'be',
            'has' => 'have', 'had' => 'have', 'having' => 'have',
            'does' => 'do', 'did' => 'do', 'done' => 'do', 'doing' => 'do',
            'goes' => 'go', 'went' => 'go', 'gone' => 'go', 'going' => 'go',
            'says' => 'say', 'said' => 'say',
            'got' => 'get', 'gotten' => 'get',
            'made' => 'make', 'makes' => 'make',
            'knew' => 'know', 'known' => 'know',
            'thought' => 'think',
            'took' => 'take', 'taken' => 'take',
            'saw' => 'see', 'seen' => 'see',
            'came' => 'come',
            'gave' => 'give', 'given' => 'give',
            'found' => 'find',
            'told' => 'tell',
            'became' => 'become',
            'left' => 'leave',
            'felt' => 'feel',
            'put' => 'put',
            'brought' => 'bring',
            'began' => 'begin', 'begun' => 'begin',
            'kept' => 'keep',
            'held' => 'hold',
            'wrote' => 'write', 'written' => 'write',
            'stood' => 'stand',
            'heard' => 'hear',
            'let' => 'let',
            'meant' => 'mean',
            'set' => 'set',
            'met' => 'meet',
            'ran' => 'run',
            'paid' => 'pay',
            'sat' => 'sit',
            'spoke' => 'speak', 'spoken' => 'speak',
            'lay' => 'lie', 'lain' => 'lie',
            'led' => 'lead',
            'read' => 'read',
            'grew' => 'grow', 'grown' => 'grow',
            'lost' => 'lose',
            'fell' => 'fall', 'fallen' => 'fall',
            'sent' => 'send',
            'built' => 'build',
            'understood' => 'understand',
            'drew' => 'draw', 'drawn' => 'draw',
            'broke' => 'break', 'broken' => 'break',
            'spent' => 'spend',
            'cut' => 'cut',
            'rose' => 'rise', 'risen' => 'rise',
            'drove' => 'drive', 'driven' => 'drive',
            'bought' => 'buy',
            'wore' => 'wear', 'worn' => 'wear',
            'chose' => 'choose', 'chosen' => 'choose',
            'ate' => 'eat', 'eaten' => 'eat',
            'drank' => 'drink', 'drunk' => 'drink',
            'slept' => 'sleep',
            'sang' => 'sing', 'sung' => 'sing',
            'taught' => 'teach',
            'sold' => 'sell',
            'caught' => 'catch',
            'fought' => 'fight',
            'swam' => 'swim', 'swum' => 'swim',
            'flew' => 'fly', 'flown' => 'fly',
            'threw' => 'throw', 'thrown' => 'throw',
            'rode' => 'ride', 'ridden' => 'ride',
            'shut' => 'shut',
            'won' => 'win',
            'forgot' => 'forget', 'forgotten' => 'forget',
            'hung' => 'hang',
            'cost' => 'cost',
            'spread' => 'spread',
            'hit' => 'hit',
            'hurt' => 'hurt',
            'children' => 'child',
            'men' => 'man',
            'women' => 'woman',
            'people' => 'person',
            'teeth' => 'tooth',
            'feet' => 'foot',
            'mice' => 'mouse',
            'geese' => 'goose',
            'oxen' => 'ox',
            'lives' => 'life',
            'wives' => 'wife',
            'knives' => 'knife',
            'leaves' => 'leaf',
            'shelves' => 'shelf',
            'thieves' => 'thief',
            'wolves' => 'wolf',
            'halves' => 'half',
            'selves' => 'self',
            'elves' => 'elf',
            'calves' => 'calf',
            'loaves' => 'loaf',
            'scarves' => 'scarf',
            'hooves' => 'hoof',
        ];

        return $map[$word] ?? null;
    }

    /**
     * @param callable(): bool $ecdictAvailable
     * @param callable(string): bool $lemmaInEcdict
     */
    private function makeToken(
        string $surface,
        int $sentenceIndex,
        callable $ecdictAvailable,
        callable $lemmaInEcdict
    ): \stdClass {
        $token = new \stdClass();
        $token->w = $surface;
        $token->r = '';

        if (preg_match('/^[A-Za-z]+(?:[\'-][A-Za-z]+)?$/u', $surface)) {
            $token->l = $this->conservativeLemma(
                $surface,
                $ecdictAvailable,
                $lemmaInEcdict,
            );
            $token->pos = 'X';
        } else {
            $token->l = $surface;
            $token->pos = 'PUNCT';
        }

        $token->lr = '';
        $token->si = $sentenceIndex;
        $token->g = '';

        return $token;
    }

    /**
     * @param callable(): bool $ecdictAvailable
     * @param callable(string): bool $lemmaInEcdict
     */
    private function conservativeLemma(
        string $surface,
        callable $ecdictAvailable,
        callable $lemmaInEcdict
    ): string {
        $lower = mb_strtolower($surface, 'UTF-8');

        if (preg_match('/^zz(para|newl|sect)/i', $lower)) {
            return $lower;
        }

        if (mb_strlen($lower) < 3) {
            return $lower;
        }

        $irregular = $this->irregularLemma($lower);
        if ($irregular !== null) {
            return $irregular;
        }

        if ($ecdictAvailable() && preg_match('/^(.+)ies$/u', $lower, $matches) && mb_strlen($matches[1]) >= 2) {
            $candidateY = $matches[1] . 'y';
            if ($lemmaInEcdict($candidateY)) {
                return $candidateY;
            }

            $candidateIe = $matches[1] . 'ie';
            if ($lemmaInEcdict($candidateIe)) {
                return $candidateIe;
            }
        }

        if (preg_match('/^(.+)(?:ch|sh|x|z)es$/u', $lower, $matches) && mb_strlen($matches[1]) >= 1) {
            return preg_replace('/es$/u', '', $lower);
        }

        return $lower;
    }
}
