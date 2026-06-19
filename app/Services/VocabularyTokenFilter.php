<?php

namespace App\Services;

class VocabularyTokenFilter
{
    private const CONTRACTION_FRAGMENTS = [
        "'s", "'re", "'ve", "'ll", "'d", "'m", "n't",
        "’s", "’re", "’ve", "’ll", "’d", "’m", "n’t",
    ];

    public static function shouldSkip(?string $token, string $language = 'english'): bool
    {
        $token = trim((string) $token);

        if ($token === '' || $token === 'NEWLINE') {
            return true;
        }

        $lowerToken = mb_strtolower($token, 'UTF-8');

        if (in_array($lowerToken, self::CONTRACTION_FRAGMENTS, true)) {
            return true;
        }

        if (in_array($token, config('linguacafe.words_to_skip', []), true)) {
            return true;
        }

        if (!preg_match('/\pL/u', $token)) {
            return true;
        }

        if (preg_match('/^[\pP\pS]+$/u', $token)) {
            return true;
        }

        if (preg_match('/^\d+(?:[.,]\d+)*(?:%|％)?$/u', $token)) {
            return true;
        }

        if (preg_match('/^[\pP\pS]*\d+(?:[.,]\d+)*(?:%|％)?[\pP\pS]*$/u', $token)) {
            return true;
        }

        return false;
    }
}
