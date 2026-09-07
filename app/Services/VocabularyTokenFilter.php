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

        if ($token === '' || $token === 'NEWLINE' || $token === 'PARAGRAPH_BREAK') {
            return true;
        }

        // 跳过 [A] [B] [C] ... [Z] 段落标记（新格式）
        if (preg_match('/^\[[A-Z]\]$/u', $token)) {
            return true;
        }

        // 兼容旧格式 _SECT_X_（旧导入可能残留）
        if (preg_match('/^_SECT_[A-Z]_$/u', $token)) {
            return true;
        }

        // 跳过 tokenizer 安全标记（不应出现在 processed_text，兜底）
        if (preg_match('/^ZZPARAZZ$|^ZZNEWLZZ$|^ZZSECT[A-Z]Z$/u', $token)) {
            return true;
        }

        $lowerToken = mb_strtolower($token, 'UTF-8');

        // URLs, email addresses and file-system paths are source metadata,
        // not vocabulary. Keep the policy conservative so linguistic slash
        // compounds such as "and/or" and "he/she" remain learnable.
        if (filter_var($token, FILTER_VALIDATE_EMAIL) !== false) {
            return true;
        }

        if (preg_match('~^[a-z][a-z0-9+.-]*://~iu', $token)
            || preg_match('~^www\\.[^\\s]+$~iu', $token)
            || preg_match('~^[\\pL\\pN](?:[\\pL\\pN-]*\\.)+[\\pL]{2,}(?:[/:?#].*)?$~u', $token)) {
            return true;
        }

        if (preg_match('~^(?:[A-Za-z]:[\\\\/]|/|\\./|\\.\\./|\\x7E/)[^\\s]+$~u', $token)
            || preg_match('~^[^\\s/\\\\]+(?:[/\\\\][^\\s/\\\\]+)+\\.[A-Za-z0-9]{1,12}$~u', $token)) {
            return true;
        }

        if (in_array($lowerToken, self::CONTRACTION_FRAGMENTS, true)) {
            return true;
        }

        if (in_array($token, config('linguacafe.words_to_skip', []), true)) {
            return true;
        }

        // 非 CJK 学习语言中跳过含中文字符的 token（不影响日语/中文学习场景）
        $languagesWithoutSpaces = config('linguacafe.languages.languages_without_spaces', []);
        if (!in_array($language, $languagesWithoutSpaces, true) && preg_match('/\p{Han}/u', $token)) {
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
