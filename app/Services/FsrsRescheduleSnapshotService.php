<?php

namespace App\Services;

use App\Models\RescheduleSnapshot;
use App\Models\RescheduleSnapshotItem;
use App\Models\ReviewCard;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * D.4-d-a: Records snapshots of FSRS reschedule operations for potential undo.
 *
 * This service is called inside the DB transaction of confirmAndApply.
 * It does NOT open its own transaction and does NOT write ReviewLog entries.
 */
class FsrsRescheduleSnapshotService
{
    /**
     * Create a snapshot header and batch-insert items.
     *
     * @param int    $userId       Current user ID.
     * @param string $language     Language code (e.g. 'english').
     * @param string $previewHash  Hash of the preview that led to this apply.
     * @param array  $summary      ['total_cards' => int, 'applied_count' => int, 'skipped_count' => int, 'newly_due_today' => int]
     * @param array  $items        Each item: ['review_card_id' => int, 'previous_due_at' => Carbon|null, 'previous_stability' => float|null, 'previous_difficulty' => float|null, 'new_due_at' => Carbon|null, 'new_stability' => float|null, 'new_difficulty' => float|null]
     *
     * @return RescheduleSnapshot
     */
    public function createSnapshotForAppliedCards(
        int $userId,
        string $language,
        string $previewHash,
        array $summary,
        array $items
    ): RescheduleSnapshot {
        $snapshot = RescheduleSnapshot::create([
            'user_id' => $userId,
            'language_id' => $language,
            'batch_id' => (string) Str::uuid(),
            'preview_hash' => $previewHash,
            'total_cards' => $summary['total_cards'] ?? 0,
            'applied_count' => $summary['applied_count'] ?? 0,
            'skipped_count' => $summary['skipped_count'] ?? 0,
            'newly_due_today' => $summary['newly_due_today'] ?? 0,
            'expires_at' => now()->addDays(7),
        ]);

        if (!empty($items)) {
            $now = now();
            $rows = [];
            foreach ($items as $item) {
                $formatDt = function ($v) {
                    if ($v instanceof \Carbon\Carbon) return $v->format('Y-m-d H:i:s');
                    if (is_string($v) && str_contains($v, 'T')) {
                        $dt = \Carbon\Carbon::parse($v);
                        return $dt->format('Y-m-d H:i:s');
                    }
                    return $v;
                };
                $rows[] = [
                    'reschedule_snapshot_id' => $snapshot->id,
                    'review_card_id' => $item['review_card_id'],
                    'previous_due_at' => $formatDt($item['previous_due_at'] ?? null),
                    'previous_stability' => $item['previous_stability'] ?? null,
                    'previous_difficulty' => $item['previous_difficulty'] ?? null,
                    'new_due_at' => $formatDt($item['new_due_at'] ?? null),
                    'new_stability' => $item['new_stability'] ?? null,
                    'new_difficulty' => $item['new_difficulty'] ?? null,
                    'skipped' => $item['skipped'] ?? false,
                    'skip_reason' => $item['skip_reason'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            RescheduleSnapshotItem::insert($rows);
        }

        return $snapshot;
    }

    /**
     * Undo the most recent reschedule snapshot for a user/language.
     *
     * @return array ['success' => bool, 'undo_available' => bool, 'restored_count' => int, 'skipped_count' => int, 'message' => string]
     */
    public function undoLatestForUserLanguage(int $userId, string $language, bool $confirmed = false): array
    {
        if ($language !== 'english') {
            return ['success' => true, 'undo_available' => false, 'restored_count' => 0, 'skipped_count' => 0, 'message' => '当前阶段只支持英语卡片重排撤销。'];
        }
        if (!$confirmed) {
            return ['success' => false, 'undo_available' => false, 'restored_count' => 0, 'skipped_count' => 0, 'message' => '缺少确认标志（confirm=true 必填）。'];
        }

        // Step 1: find the latest non-undone snapshot (without filtering by expires_at)
        $snapshot = RescheduleSnapshot::where('user_id', $userId)
            ->where('language_id', $language)
            ->whereNull('undone_at')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if (!$snapshot) {
            return ['success' => false, 'undo_available' => false, 'restored_count' => 0, 'skipped_count' => 0, 'message' => '当前没有可撤销的重排操作。'];
        }

        // Step 2: check if snapshot has expired
        if ($snapshot->expires_at !== null && $snapshot->expires_at->lt(now())) {
            return ['success' => false, 'undo_available' => false, 'restored_count' => 0, 'skipped_count' => 0, 'message' => '重排操作已超过可撤销期限。'];
        }

        // Load all items with their review cards
        $items = RescheduleSnapshotItem::where('reschedule_snapshot_id', $snapshot->id)
            ->where('undone', false)
            ->get();

        $restoreCandidateIds = [];
        $skippedCount = 0;

        foreach ($items as $item) {
            $card = ReviewCard::find($item->review_card_id);
            if (!$card) { $skippedCount++; continue; }
            if ($card->user_id !== $userId) { $skippedCount++; continue; }
            if ($card->language_id !== $language) { $skippedCount++; continue; }
            if ($card->target_type !== ReviewCard::TARGET_SENSE) { $skippedCount++; continue; }
            if ($card->fsrs_enabled !== true) { $skippedCount++; continue; }
            if ($card->fsrs_last_reviewed_at && $card->fsrs_last_reviewed_at > $snapshot->created_at) { $skippedCount++; continue; }
            if ($item->previous_due_at === null && $item->previous_stability === null && $item->previous_difficulty === null) { $skippedCount++; continue; }
            $restoreCandidateIds[] = $item->id;
        }

        if (empty($restoreCandidateIds)) {
            return ['success' => false, 'undo_available' => true, 'restored_count' => 0, 'skipped_count' => $skippedCount, 'message' => '上次重排涉及的卡片均已被复习或不可恢复，无法撤销。'];
        }

        $now = now();
        $restoredCount = 0;

        \DB::transaction(function () use ($snapshot, $items, $restoreCandidateIds, $userId, $language, $now, &$restoredCount, &$skippedCount) {
            // Lock the cards that will be restored
            $lockableItemIds = [];
            foreach ($items as $item) {
                if (in_array($item->id, $restoreCandidateIds)) {
                    $lockableItemIds[] = $item->review_card_id;
                }
            }

            $lockedCards = ReviewCard::whereIn('id', $lockableItemIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $restoredItemIds = [];

            foreach ($items as $item) {
                if (!in_array($item->id, $restoreCandidateIds)) {
                    continue;
                }
                $card = $lockedCards->get($item->review_card_id);
                if (!$card) { $skippedCount++; continue; }
                // Re-check all boundaries inside transaction (card may have changed since pre-check)
                if ($card->user_id !== $userId) { $skippedCount++; continue; }
                if ($card->language_id !== $language) { $skippedCount++; continue; }
                if ($card->target_type !== ReviewCard::TARGET_SENSE) { $skippedCount++; continue; }
                if ($card->fsrs_enabled !== true) { $skippedCount++; continue; }
                if ($card->fsrs_last_reviewed_at && $card->fsrs_last_reviewed_at > $snapshot->created_at) { $skippedCount++; continue; }
                if ($item->undone !== false) { $skippedCount++; continue; }
                if ($item->previous_due_at === null && $item->previous_stability === null && $item->previous_difficulty === null) { $skippedCount++; continue; }

                // Restore fields
                if ($item->previous_due_at !== null) {
                    $card->fsrs_due_at = $item->previous_due_at instanceof \Carbon\Carbon
                        ? $item->previous_due_at
                        : \Carbon\Carbon::parse($item->previous_due_at);
                }
                if ($item->previous_stability !== null) {
                    $card->fsrs_stability = $item->previous_stability;
                }
                if ($item->previous_difficulty !== null) {
                    $card->fsrs_difficulty = $item->previous_difficulty;
                }
                $card->save();

                // Mark item undone
                $item->undone = true;
                $item->undone_at = $now;
                $item->save();

                $restoredItemIds[] = $item->id;
                $restoredCount++;
            }

            if ($restoredCount > 0) {
                $snapshot->undone_at = $now;
                $snapshot->save();
            }
        });

        $message = $skippedCount > 0
            ? "已恢复 {$restoredCount} 张卡片，跳过 {$skippedCount} 张（已复习或不可恢复）。"
            : "已恢复 {$restoredCount} 张卡片。";

        return [
            'success' => true,
            'undo_available' => false,
            'restored_count' => $restoredCount,
            'skipped_count' => $skippedCount,
            'message' => $message,
        ];
    }
}
