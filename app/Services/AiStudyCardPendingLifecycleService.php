<?php

namespace App\Services;

use App\Models\AiStudyCardPendingItem;
use App\Models\User;
use Throwable;

class AiStudyCardPendingLifecycleService
{
    public function dismiss(User $user, int $itemId): array
    {
        $item = $this->findOwnedItem($user, $itemId);
        if (!$item) {
            return $this->notFound();
        }

        if ($item->status !== AiStudyCardPendingItem::STATUS_DISMISSED) {
            $item->update(['status' => AiStudyCardPendingItem::STATUS_DISMISSED]);
            $item = $item->fresh();
        }

        return ['success' => true, 'item' => $item, 'message' => '已取消。'];
    }

    public function restore(User $user, int $itemId): array
    {
        $item = $this->findOwnedItem($user, $itemId);
        if (!$item) {
            return $this->notFound();
        }

        if ($item->status === AiStudyCardPendingItem::STATUS_PENDING) {
            return ['success' => true, 'item' => $item, 'message' => '已在待 AI 解释列表中。'];
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
            return ['success' => true, 'item' => $existingPending, 'message' => '已重新加入待 AI 解释。'];
        }

        $item->update(['status' => AiStudyCardPendingItem::STATUS_PENDING]);
        return ['success' => true, 'item' => $item->fresh(), 'message' => '已重新加入待 AI 解释。'];
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
                'pending_item_status_after' => $updated ? AiStudyCardPendingItem::STATUS_PROCESSED : AiStudyCardPendingItem::STATUS_PENDING,
                'pending_item_processed' => $updated > 0,
                'pending_item_process_reason' => $updated ? $processReason : null,
            ];
        } catch (Throwable) {
            return [
                'pending_item_id' => $itemId,
                'pending_item_status_before' => AiStudyCardPendingItem::STATUS_PENDING,
                'pending_item_status_after' => AiStudyCardPendingItem::STATUS_PENDING,
                'pending_item_processed' => false,
                'pending_item_process_reason' => null,
            ];
        }
    }

    public function emptyLifecycleInfo(): array
    {
        return [
            'pending_item_id' => null,
            'pending_item_status_before' => null,
            'pending_item_status_after' => null,
            'pending_item_processed' => false,
            'pending_item_process_reason' => null,
        ];
    }

    private function findOwnedItem(User $user, int $itemId): ?AiStudyCardPendingItem
    {
        return AiStudyCardPendingItem::where('id', $itemId)
            ->where('user_id', $user->id)
            ->where('language_id', $user->selected_language)
            ->first();
    }

    private function notFound(): array
    {
        return [
            'success' => false,
            'status' => 404,
            'message' => '待解释项不存在或不属于当前用户。',
        ];
    }
}
