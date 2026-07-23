<?php

namespace App\Services;

use App\Models\AiStudyCardPendingItem;
use App\Models\Chapter;
use App\Models\User;
use Illuminate\Database\QueryException;
use Throwable;

class AiStudyCardPendingLifecycleService
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

    public function listPending(User $user, ?int $chapterId = null, string $statusFilter = 'pending'): array
    {
        $language = $user->selected_language;

        $query = AiStudyCardPendingItem::where('user_id', $user->id)
            ->where('language_id', $language);

        if ($statusFilter === 'all') {
            // No status condition.
        } elseif ($statusFilter === 'dismissed') {
            $query->where('status', AiStudyCardPendingItem::STATUS_DISMISSED);
        } elseif ($statusFilter === 'processed') {
            $query->where('status', AiStudyCardPendingItem::STATUS_PROCESSED);
        } else {
            $query->where('status', AiStudyCardPendingItem::STATUS_PENDING);
        }

        if ($chapterId !== null) {
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

        return [
            'success' => true,
            'items' => $query->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->get(),
        ];
    }

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

        $existingPending = AiStudyCardPendingItem::where([
            'user_id' => $user->id,
            'language_id' => $user->selected_language,
            'chapter_id' => $item->chapter_id,
            'text_block_index' => $item->text_block_index,
            'normalized_word' => $item->normalized_word,
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ])->first();

        if ($existingPending) {
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

    public function markProcessed(User $user, string $language, int $itemId, string $processReason): array
    {
        try {
            $updated = AiStudyCardPendingItem::where('id', $itemId)
                ->where('user_id', $user->id)
                ->where('language_id', $language)
                ->where('status', AiStudyCardPendingItem::STATUS_PENDING)
                ->update(['status' => AiStudyCardPendingItem::STATUS_PROCESSED, 'updated_at' => now()]);

            return [
                'pending_item_id' => $itemId,
                'pending_item_status_before' => AiStudyCardPendingItem::STATUS_PENDING,
                'pending_item_status_after' => $updated > 0
                    ? AiStudyCardPendingItem::STATUS_PROCESSED
                    : AiStudyCardPendingItem::STATUS_PENDING,
                'pending_item_processed' => $updated > 0,
                'pending_item_process_reason' => $updated > 0 ? $processReason : null,
            ];
        } catch (Throwable $e) {
            return [
                'pending_item_id' => $itemId,
                'pending_item_status_before' => AiStudyCardPendingItem::STATUS_PENDING,
                'pending_item_status_after' => AiStudyCardPendingItem::STATUS_PENDING,
                'pending_item_processed' => false,
                'pending_item_process_reason' => null,
            ];
        }
    }

    public function emptyInfo(): array
    {
        return [
            'pending_item_id' => null,
            'pending_item_status_before' => null,
            'pending_item_status_after' => null,
            'pending_item_processed' => false,
            'pending_item_process_reason' => null,
        ];
    }

    private function normalizeWord(string $word): string
    {
        return mb_strtolower(trim($word), 'UTF-8');
    }
}
