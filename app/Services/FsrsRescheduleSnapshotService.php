<?php

namespace App\Services;

use App\Models\RescheduleSnapshot;
use App\Models\RescheduleSnapshotItem;
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
}
