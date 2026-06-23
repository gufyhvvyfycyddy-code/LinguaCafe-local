<?php

namespace App\Services;

use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Carbon\Carbon;

class SenseReviewService
{
    public function dueCards(int $userId, string $language)
    {
        return ReviewCard::query()
            ->select('review_cards.*')
            ->join('word_senses', function ($join) {
                $join->on('word_senses.id', '=', 'review_cards.target_id')
                    ->where('review_cards.target_type', ReviewCard::TARGET_SENSE);
            })
            ->where('review_cards.user_id', $userId)
            ->where('review_cards.language_id', $language)
            ->where('review_cards.fsrs_enabled', true)
            ->where('review_cards.fsrs_due_at', '<=', Carbon::now())
            ->where('word_senses.user_id', $userId)
            ->where('word_senses.language_id', $language)
            ->where('word_senses.status', WordSense::STATUS_CONFIRMED)
            ->with('sense')
            ->orderBy('review_cards.fsrs_due_at')
            ->orderBy('review_cards.id')
            ->get();
    }

    public function nextDueCard(int $userId, string $language): ?array
    {
        $card = $this->dueCards($userId, $language)->first();

        return $card ? $this->serializeCard($card) : null;
    }

    public function summary(int $userId, string $language): array
    {
        return [
            'due_count' => $this->dueCards($userId, $language)->count(),
        ];
    }

    public function serializeCard(ReviewCard $card): array
    {
        $sense = $card->sense;
        $tokenPayload = $this->exampleSentenceTokenPayload($sense);

        return [
            'review_card_id' => $card->id,
            'word_sense_id' => $sense->id,
            'lemma' => $sense->lemma,
            'surface_form' => $sense->surface_form,
            'pos' => $sense->pos,
            'sense_zh' => $sense->sense_zh,
            'sense_en' => $sense->sense_en,
            'aliases_zh' => $sense->aliases_zh ?: [],
            'collocations' => $sense->collocations ?: [],
            'example_sentence_en' => $sense->example_sentence_en,
            'example_sentence_zh' => $sense->example_sentence_zh,
            'example_sentence_tokens' => $tokenPayload['tokens'],
            'example_sentence_token_source' => $tokenPayload['source'],
            'fsrs_state' => $card->fsrs_state,
            'fsrs_due_at' => $card->fsrs_due_at,
            'fsrs_stability' => $card->fsrs_stability,
            'fsrs_difficulty' => $card->fsrs_difficulty,
            'fsrs_reps' => $card->fsrs_reps,
            'fsrs_lapses' => $card->fsrs_lapses,
        ];
    }

    // ==================== Token payload: three-layer strategy ====================

    /**
     * Layer 1: Real source tokens from chapter processed_text via occurrence or sense.
     * Layer 2: Text match — find the sentence in processed_text by comparing example_sentence_en.
     * Layer 3: Synthetic tokens generated from example_sentence_en string.
     *
     * Only returns null tokens when there is NO example_sentence_en at all.
     */
    private function exampleSentenceTokenPayload(WordSense $sense): array
    {
        // 1. Look up WordSenseOccurrence (prefer manual_sense_add source)
        $occurrence = WordSenseOccurrence::query()
            ->where('user_id', $sense->user_id)
            ->where('language_id', $sense->language_id)
            ->where('word_sense_id', $sense->id)
            ->where('status', WordSenseOccurrence::STATUS_BOUND)
            ->whereNotNull('chapter_id')
            ->orderByRaw('CASE WHEN source = ? THEN 0 ELSE 1 END', [WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD])
            ->orderByDesc('id')
            ->first();

        // 2. Determine positioning info (use ?? not ?: because "0" is falsy in PHP)
        $chapterId = $occurrence?->chapter_id ?? $sense->source_chapter_id;
        $sentenceId = $occurrence?->sentence_id ?? $sense->sentence_id;
        $sentenceHash = $occurrence?->sentence_hash ?? $sense->sentence_hash;

        // === Layer 1: Real source tokens ===
        if ($chapterId !== null && ($sentenceId !== null || $sentenceHash !== null)) {
            $chapter = Chapter::query()
                ->where('id', $chapterId)
                ->where('user_id', $sense->user_id)
                ->where('language', $sense->language_id)
                ->first();

            if ($chapter) {
                $tokens = $this->extractSentenceTokensFromChapter($chapter, $sentenceId, $sentenceHash);
                if ($tokens !== null) {
                    return [
                        'tokens' => $tokens,
                        'source' => $occurrence ? 'occurrence' : 'word_sense',
                    ];
                }
            }
        }

        // === Layer 2: Text match — reverse-lookup sentence in processed_text by example_sentence_en ===
        if ($sense->example_sentence_en && $chapterId !== null) {
            $chapter = $chapter ?? Chapter::query()
                ->where('id', $chapterId)
                ->where('user_id', $sense->user_id)
                ->where('language', $sense->language_id)
                ->first();

            if ($chapter) {
                $tokens = $this->matchSentenceTokensByText($chapter, $sense->example_sentence_en);
                if ($tokens !== null) {
                    return [
                        'tokens' => $tokens,
                        'source' => 'sentence_text_match',
                    ];
                }
            }
        }

        // === Layer 3: Synthetic tokens ===
        if ($sense->example_sentence_en) {
            $tokens = $this->syntheticSentenceTokens($sense->example_sentence_en, $sense, $occurrence);
            return [
                'tokens' => $tokens,
                'source' => 'synthetic',
            ];
        }

        // No example_sentence_en — truly no tokens
        return ['tokens' => null, 'source' => null];
    }

    // ==================== Layer 1 helpers ====================

    /**
     * Extract tokens for a specific sentence from chapter processed_text.
     */
    private function extractSentenceTokensFromChapter(Chapter $chapter, $sentenceId, $sentenceHash): ?array
    {
        $processedText = $chapter->getProcessedText();
        $words = $this->flattenProcessedWords($processedText);

        if (empty($words)) {
            return null;
        }

        $tokens = [];
        foreach ($words as $index => $word) {
            $wordObj = is_array($word) ? (object) $word : $word;

            // Match by sentence_id / sentence_index / si / sentence_hash
            $matches = false;
            if ($sentenceId !== null) {
                if (
                    (isset($wordObj->sentence_id) && (string) $wordObj->sentence_id === (string) $sentenceId) ||
                    (isset($wordObj->sentence_index) && (string) $wordObj->sentence_index === (string) $sentenceId) ||
                    (isset($wordObj->si) && (string) $wordObj->si === (string) $sentenceId)
                ) {
                    $matches = true;
                }
            }
            if (!$matches && $sentenceHash !== null && isset($wordObj->sentence_hash)) {
                $matches = (string) $wordObj->sentence_hash === (string) $sentenceHash;
            }

            if (!$matches) {
                continue;
            }

            // Skip structural tokens
            $tokenWord = $wordObj->word ?? '';
            if ($tokenWord === 'NEWLINE' || $tokenWord === 'PARAGRAPH_BREAK') {
                continue;
            }

            $tokens[] = $this->simplifyToken($wordObj, $index);
        }

        return !empty($tokens) ? $tokens : null;
    }

    // ==================== Layer 2 helpers ====================

    /**
     * Match example_sentence_en against processed_text sentences by normalized text comparison.
     */
    private function matchSentenceTokensByText(Chapter $chapter, string $exampleSentenceEn): ?array
    {
        $processedText = $chapter->getProcessedText();
        $words = $this->flattenProcessedWords($processedText);

        if (empty($words)) {
            return null;
        }

        // Group tokens by sentence key
        $groups = $this->groupTokensBySentence($words);
        $targetNormalized = $this->normalizeSentenceText($exampleSentenceEn);

        foreach ($groups as $groupTokens) {
            $groupText = $this->tokensToSentenceText($groupTokens);
            $groupNormalized = $this->normalizeSentenceText($groupText);

            if ($groupNormalized === $targetNormalized) {
                $result = [];
                foreach ($groupTokens as $index => $word) {
                    $wordObj = is_array($word) ? (object) $word : $word;
                    $result[] = $this->simplifyToken($wordObj, $index);
                }
                return $result;
            }
        }

        return null;
    }

    /**
     * Group raw word objects by sentence_index / si / sentence_id.
     */
    private function groupTokensBySentence(array $words): array
    {
        $groups = [];
        foreach ($words as $word) {
            $wordObj = is_array($word) ? (object) $word : $word;
            $key = $wordObj->sentence_index ?? $wordObj->si ?? $wordObj->sentence_id ?? '__no_sentence__';
            $groups[(string) $key][] = $wordObj;
        }
        return $groups;
    }

    /**
     * Join tokens into a sentence text string.
     */
    private function tokensToSentenceText(array $tokens): string
    {
        $parts = [];
        foreach ($tokens as $token) {
            $tokenObj = is_array($token) ? (object) $token : $token;
            $word = $tokenObj->word ?? '';
            if ($word === 'NEWLINE' || $word === 'PARAGRAPH_BREAK') {
                continue;
            }
            $spaceAfter = $tokenObj->spaceAfter ?? false;
            $parts[] = $word . ($spaceAfter ? ' ' : '');
        }
        return trim(implode('', $parts));
    }

    /**
     * Normalize sentence text for comparison: lowercase, collapse whitespace, trim.
     */
    private function normalizeSentenceText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        // Normalize common punctuation spacing
        $text = preg_replace('/\s*([.,!?;:])\s*/u', '$1 ', $text);
        $text = trim($text);
        return $text;
    }

    // ==================== Layer 3 helpers ====================

    /**
     * Generate synthetic tokens from a plain example_sentence_en string.
     */
    private function syntheticSentenceTokens(string $sentenceText, WordSense $sense, ?WordSenseOccurrence $occurrence = null): array
    {
        // Tokenize: match words (including contractions/hyphenated) and non-alphanumeric tokens
        preg_match_all(
            '/[A-Za-z0-9]+(?:[.\\-\\\'\\\x{2019}][A-Za-z0-9]+)*|[^\\sA-Za-z0-9]/u',
            $sentenceText,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        $rawTokens = $matches[0];
        $count = count($rawTokens);
        $tokens = [];

        for ($i = 0; $i < $count; $i++) {
            $word = $rawTokens[$i][0];
            $startPos = $rawTokens[$i][1];
            $endPos = $startPos + strlen($word);

            // Determine spaceAfter: check if there's whitespace before next token
            $spaceAfter = true;
            if ($i + 1 < $count) {
                $nextStart = $rawTokens[$i + 1][1];
                $gap = substr($sentenceText, $endPos, $nextStart - $endPos);
                $spaceAfter = (trim($gap) === '');
            }

            $token = [
                'word' => $word,
                'stage' => 2,
                'spaceAfter' => $spaceAfter,
                'is_structure' => false,
                'sentence_index' => null,
                'wordIndex' => $i,
                'is_target' => false,
            ];

            // Check if this token is the target word
            if ($this->tokenMatchesSenseTarget($word, $sense, $occurrence)) {
                $token['stage'] = -7;
                $token['is_target'] = true;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * Check if a token word matches the sense's target word.
     */
    private function tokenMatchesSenseTarget(string $token, WordSense $sense, ?WordSenseOccurrence $occurrence = null): bool
    {
        // Strip leading/trailing punctuation from token
        $cleanToken = mb_strtolower(trim($token, ".,!?;:'\"()[]{}\u{2018}\u{2019}\u{201C}\u{201D}"));
        if ($cleanToken === '') {
            return false;
        }

        $surface = mb_strtolower((string) ($sense->surface_form ?? $sense->lemma ?? ''));
        $lemma = mb_strtolower((string) ($sense->lemma ?? ''));

        if ($cleanToken === $surface || $cleanToken === $lemma) {
            return true;
        }

        if ($occurrence && $occurrence->surface) {
            $occSurface = mb_strtolower((string) $occurrence->surface);
            if ($cleanToken === $occSurface) {
                return true;
            }
        }

        return false;
    }

    // ==================== Shared helpers ====================

    /**
     * Recursively flatten processed_text into an array of word objects.
     * Handles nested objects/arrays — real processed_text may not be a simple $processed->words.
     */
    private function flattenProcessedWords($node): array
    {
        $result = [];

        if (is_array($node)) {
            foreach ($node as $child) {
                $result = array_merge($result, $this->flattenProcessedWords($child));
            }
            return $result;
        }

        if (is_object($node)) {
            // Leaf node: has a 'word' property
            if (isset($node->word)) {
                return [$node];
            }

            // Traverse properties
            foreach (get_object_vars($node) as $child) {
                $result = array_merge($result, $this->flattenProcessedWords($child));
            }

            return $result;
        }

        return [];
    }

    /**
     * Convert a raw processed word object into a simplified token array.
     */
    private function simplifyToken($word, int $index): array
    {
        $wordObj = is_array($word) ? (object) $word : $word;

        return [
            'word' => $wordObj->word ?? '',
            'stage' => $wordObj->stage ?? 2,
            'spaceAfter' => $wordObj->spaceAfter ?? false,
            'is_structure' => $wordObj->is_structure ?? false,
            'sentence_index' => $wordObj->sentence_index ?? ($wordObj->si ?? ($wordObj->sentence_id ?? null)),
            'wordIndex' => $index,
            'is_target' => $wordObj->is_target ?? false,
        ];
    }
}
