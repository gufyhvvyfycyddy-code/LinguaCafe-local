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

    private function exampleSentenceTokenPayload(WordSense $sense): array
    {
        // 1. 优先查 WordSenseOccurrence
        $occurrence = WordSenseOccurrence::query()
            ->where('user_id', $sense->user_id)
            ->where('language_id', $sense->language_id)
            ->where('word_sense_id', $sense->id)
            ->where('status', WordSenseOccurrence::STATUS_BOUND)
            ->whereNotNull('chapter_id')
            ->orderByRaw('CASE WHEN source = ? THEN 0 ELSE 1 END', [WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD])
            ->orderByDesc('id')
            ->first();

        // 2. 取定位信息（用 ?? 而非 ?:，因为 sentence_id 可能是 "0" 这类 falsy 值）
        $chapterId = $occurrence?->chapter_id ?? $sense->source_chapter_id;
        $sentenceId = $occurrence?->sentence_id ?? $sense->sentence_id;
        $sentenceHash = $occurrence?->sentence_hash ?? $sense->sentence_hash;

        // 3. 如果没有 chapterId，或 sentenceId / sentenceHash 都没有
        if ($chapterId === null || ($sentenceId === null && $sentenceHash === null)) {
            return ['tokens' => null, 'source' => null];
        }

        // 4. 查 Chapter
        $chapter = Chapter::query()
            ->where('id', $chapterId)
            ->where('user_id', $sense->user_id)
            ->where('language', $sense->language_id)
            ->first();

        if (!$chapter) {
            return ['tokens' => null, 'source' => null];
        }

        // 5. 提取句子 tokens
        $tokens = $this->extractSentenceTokensFromChapter($chapter, $sentenceId, $sentenceHash);

        return [
            'tokens' => $tokens,
            'source' => $occurrence ? 'occurrence' : 'word_sense',
        ];
    }

    private function extractSentenceTokensFromChapter(Chapter $chapter, $sentenceId, $sentenceHash): ?array
    {
        $processedText = $chapter->getProcessedText();

        // 兼容 processed_text 是对象且有 words 字段，或 processed_text 本身是数组
        $words = [];
        if (is_object($processedText) && isset($processedText->words)) {
            $words = $processedText->words;
        } elseif (is_array($processedText)) {
            $words = $processedText;
        }

        if (empty($words)) {
            return null;
        }

        $tokens = [];
        foreach ($words as $word) {
            $wordObj = is_array($word) ? (object) $word : $word;

            // 匹配 sentence_id / sentence_index / si / sentence_hash
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

            // 跳过 NEWLINE / PARAGRAPH_BREAK
            $tokenWord = $wordObj->word ?? '';
            if ($tokenWord === 'NEWLINE' || $tokenWord === 'PARAGRAPH_BREAK') {
                continue;
            }

            $tokens[] = [
                'word' => $tokenWord,
                'stage' => $wordObj->stage ?? 2,
                'spaceAfter' => $wordObj->spaceAfter ?? false,
                'is_structure' => $wordObj->is_structure ?? false,
                'sentence_index' => $wordObj->sentence_index ?? $wordObj->si ?? 0,
                'wordIndex' => $wordObj->wordIndex ?? 0,
            ];
        }

        return !empty($tokens) ? $tokens : null;
    }
}
