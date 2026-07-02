<?php

namespace App\Services;

use App\Models\AiStudyCardPendingItem;
use App\Models\Chapter;
use App\Models\User;
use Illuminate\Database\QueryException;

class AiStudyCardPendingItemService
{
    public function createOrGetPending(User $user, array $data): array
    {
        $language = $user->selected_language;
        $chapterId = (int) $data['chapter_id'];

        $chapter = Chapter::where('id', $chapterId)
            ->where('user_id', $user->id)
            ->where('language', $language)
            ->first();

        if (!$chapter) {
            return [
                'success' => false,
                'status' => 404,
                'message' => '章节不存在或不属于当前用户。',
            ];
        }

        $word = trim((string) $data['word']);
        $normalizedWord = $this->normalizeWord($word);
        $textBlockIndex = (int) $data['text_block_index'];

        $lookup = [
            'user_id' => $user->id,
            'language_id' => $language,
            'chapter_id' => $chapterId,
            'text_block_index' => $textBlockIndex,
            'normalized_word' => $normalizedWord,
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ];

        $existing = AiStudyCardPendingItem::where($lookup)->first();
        if ($existing) {
            return [
                'success' => true,
                'created' => false,
                'item' => $existing,
                'message' => '已在待 AI 解释列表中。',
            ];
        }

        try {
            $item = AiStudyCardPendingItem::create(array_merge($lookup, [
                'language' => $language,
                'sentence_index' => array_key_exists('sentence_index', $data) && $data['sentence_index'] !== null
                    ? (int) $data['sentence_index'] : null,
                'sentence_id' => $data['sentence_id'] ?? null,
                'word' => $word,
                'surface' => $data['surface'] ?? $word,
                'lemma' => $data['lemma'] ?? null,
                'sentence_text' => $data['sentence_text'] ?? null,
                'source_payload' => $data['source_payload'] ?? [],
            ]));
        } catch (QueryException $e) {
            $item = AiStudyCardPendingItem::where($lookup)->first();
            if (!$item) {
                throw $e;
            }

            return [
                'success' => true,
                'created' => false,
                'item' => $item,
                'message' => '已在待 AI 解释列表中。',
            ];
        }

        return [
            'success' => true,
            'created' => true,
            'item' => $item,
            'message' => '已加入待 AI 解释。',
        ];
    }

    private function normalizeWord(string $word): string
    {
        return mb_strtolower(trim($word), 'UTF-8');
    }
}
