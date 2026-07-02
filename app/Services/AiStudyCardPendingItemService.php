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

        $baseLookup = [
            'user_id' => $user->id,
            'language_id' => $language,
            'chapter_id' => $chapterId,
            'text_block_index' => $textBlockIndex,
            'normalized_word' => $normalizedWord,
        ];

        $pendingLookup = array_merge($baseLookup, [
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ]);

        $existingPending = AiStudyCardPendingItem::where($pendingLookup)->first();
        if ($existingPending) {
            return [
                'success' => true,
                'created' => false,
                'item' => $existingPending,
                'message' => '已在待 AI 解释列表中。',
            ];
        }

        // V2: 若同一 key 存在 dismissed 项，恢复为 pending，而不是新建。
        // 这样避免同一 key 同时存在 dismissed + pending 两条记录，
        // 也避免无限新建 dismissed 历史行。
        $dismissedItem = AiStudyCardPendingItem::where(array_merge($baseLookup, [
            'status' => AiStudyCardPendingItem::STATUS_DISMISSED,
        ]))->first();

        if ($dismissedItem) {
            $dismissedItem->update([
                'status' => AiStudyCardPendingItem::STATUS_PENDING,
                'word' => $word,
                'surface' => $data['surface'] ?? $word,
                'lemma' => $data['lemma'] ?? null,
                'sentence_text' => $data['sentence_text'] ?? null,
                'source_payload' => $data['source_payload'] ?? [],
            ]);

            return [
                'success' => true,
                'created' => false,
                'item' => $dismissedItem->fresh(),
                'message' => '已重新加入待 AI 解释。',
            ];
        }

        try {
            $item = AiStudyCardPendingItem::create(array_merge($pendingLookup, [
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
            $item = AiStudyCardPendingItem::where($pendingLookup)->first();
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

    /**
     * V2: 列出当前用户的待 AI 解释项。
     * 只返回 pending 状态，不返回 dismissed。
     * 支持按 chapter_id 过滤（可选）。
     */
    public function listPending(User $user, ?int $chapterId = null): array
    {
        $language = $user->selected_language;

        $query = AiStudyCardPendingItem::where('user_id', $user->id)
            ->where('language_id', $language)
            ->where('status', AiStudyCardPendingItem::STATUS_PENDING);

        if ($chapterId !== null) {
            // 仅当章节属于当前用户当前语言时才过滤；否则忽略过滤返回空集，避免泄露。
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
            $query->where('chapter_id', $chapterId);
        }

        $items = $query->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return [
            'success' => true,
            'items' => $items,
        ];
    }

    /**
     * V2: 取消（dismiss）一个待解释项。
     * 不物理删除，状态从 pending 改为 dismissed。
     */
    public function dismiss(User $user, int $itemId): array
    {
        $item = AiStudyCardPendingItem::where('id', $itemId)
            ->where('user_id', $user->id)
            ->where('language_id', $user->selected_language)
            ->first();

        if (!$item) {
            return [
                'success' => false,
                'status' => 404,
                'message' => '待解释项不存在或不属于当前用户。',
            ];
        }

        if ($item->status === AiStudyCardPendingItem::STATUS_DISMISSED) {
            return [
                'success' => true,
                'item' => $item,
                'message' => '已取消。',
            ];
        }

        $item->update([
            'status' => AiStudyCardPendingItem::STATUS_DISMISSED,
        ]);

        return [
            'success' => true,
            'item' => $item->fresh(),
            'message' => '已取消。',
        ];
    }

    /**
     * V2: 恢复一个已 dismissed 的待解释项为 pending。
     * 用于用户在待解释列表中误取消后的恢复。
     */
    public function restore(User $user, int $itemId): array
    {
        $item = AiStudyCardPendingItem::where('id', $itemId)
            ->where('user_id', $user->id)
            ->where('language_id', $user->selected_language)
            ->first();

        if (!$item) {
            return [
                'success' => false,
                'status' => 404,
                'message' => '待解释项不存在或不属于当前用户。',
            ];
        }

        if ($item->status === AiStudyCardPendingItem::STATUS_PENDING) {
            return [
                'success' => true,
                'item' => $item,
                'message' => '已在待 AI 解释列表中。',
            ];
        }

        // 恢复前先检查是否已存在 pending 行（避免 unique 冲突）
        $existingPending = AiStudyCardPendingItem::where([
            'user_id' => $user->id,
            'language_id' => $user->selected_language,
            'chapter_id' => $item->chapter_id,
            'text_block_index' => $item->text_block_index,
            'normalized_word' => $item->normalized_word,
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ])->first();

        if ($existingPending) {
            // 已有 pending 行，直接把 dismissed 行物理删除保持干净
            // （这种情况理论上不应发生，但作为兜底）
            $item->delete();
            return [
                'success' => true,
                'item' => $existingPending,
                'message' => '已重新加入待 AI 解释。',
            ];
        }

        $item->update([
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ]);

        return [
            'success' => true,
            'item' => $item->fresh(),
            'message' => '已重新加入待 AI 解释。',
        ];
    }

    private function normalizeWord(string $word): string
    {
        return mb_strtolower(trim($word), 'UTF-8');
    }
}
