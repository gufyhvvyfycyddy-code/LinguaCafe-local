<?php

namespace App\Services;

final class SenseExampleIdentityResolver
{
    /**
     * Resolve the stable identity of the already-selected example candidate.
     * Malformed sentence ids fail closed instead of falling back to a guess.
     */
    public function resolve(array $candidate, int $userId, string $language): ?array
    {
        $sentenceId = $candidate['sentence_id'] ?? null;
        $chapterId = $candidate['chapter_id'] ?? null;
        if (!is_int($sentenceId) && !is_string($sentenceId)) {
            return null;
        }

        $sentenceId = (string) $sentenceId;
        $sourceType = ($candidate['is_card_fallback'] ?? false) ? 'card_fallback' : 'occurrence';
        if (preg_match('/^\d+$/D', $sentenceId) === 1) {
            $sentenceIndex = (int) $sentenceId;
        } elseif (preg_match('/^ai-study-card:([1-9]\d*):(\d+):(\d+):([^:]+)$/D', $sentenceId, $matches) === 1) {
            if ($chapterId === null || (int) $matches[1] !== (int) $chapterId) {
                return null;
            }
            $sentenceIndex = (int) $matches[3];
            $sourceType = 'ai_study_card';
        } else {
            return null;
        }

        return [
            'user_id' => $userId,
            'language' => $language,
            'chapter_id' => $chapterId === null ? null : (int) $chapterId,
            'occurrence_id' => isset($candidate['occurrence_id']) ? (int) $candidate['occurrence_id'] : null,
            'sentence_id' => $sentenceId,
            'sentence_index' => $sentenceIndex,
            'normalized_source_text' => $this->normalizeSourceText((string) ($candidate['sentence_en'] ?? '')),
            'source_type' => $sourceType,
        ];
    }

    /**
     * Resolve only a translation that belongs to the selected identity.
     * Duplicate exact assist rows are ambiguous and therefore fail closed.
     */
    public function translationFor(array $candidate, ?array $identity, array $sentenceTranslations): array
    {
        $explicit = trim((string) ($candidate['sentence_zh'] ?? ''));
        if ($explicit !== '') {
            return [
                $explicit,
                ($candidate['is_card_fallback'] ?? false) ? 'card_fallback' : 'occurrence',
            ];
        }

        if ($identity === null || $identity['chapter_id'] === null || $identity['normalized_source_text'] === '') {
            return [null, null];
        }

        $matches = array_values(array_filter($sentenceTranslations, function ($row) use ($identity): bool {
            return is_array($row)
                && array_key_exists('sentence_index', $row)
                && (string) $row['sentence_index'] === (string) $identity['sentence_index']
                && isset($row['source_text'])
                && $this->normalizeSourceText((string) $row['source_text']) === $identity['normalized_source_text']
                && trim((string) ($row['translation_zh'] ?? '')) !== '';
        }));

        return count($matches) === 1
            ? [trim((string) $matches[0]['translation_zh']), 'chapter_ai_reading_assist']
            : [null, null];
    }

    private function normalizeSourceText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($text)) ?? '');
    }
}
