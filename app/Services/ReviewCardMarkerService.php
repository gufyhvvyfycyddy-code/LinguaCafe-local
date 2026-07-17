<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\WordSense;
use Illuminate\Support\Facades\DB;

class ReviewCardMarkerService
{
    public function set(ReviewCard $card, int $marker): ReviewCard
    {
        $card->marker = $marker;
        $card->save();

        return $card->fresh();
    }

    /**
     * @param list<int> $ids
     * @return array{marker: int, applied_ids: list<int>, failed_ids: list<int>}
     */
    public function setBulk(array $ids, int $marker, int $userId, string $language): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return DB::transaction(function () use ($ids, $marker, $userId, $language) {
            $accessibleIds = ReviewCard::query()
                ->whereIn('id', $ids)
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->where('target_type', ReviewCard::TARGET_SENSE)
                ->whereHas('sense', function ($query) use ($userId, $language) {
                    $query->where('user_id', $userId)
                        ->where('language_id', $language)
                        ->where('status', WordSense::STATUS_CONFIRMED);
                })
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $accessible = array_fill_keys($accessibleIds, true);
            $appliedIds = array_values(array_filter($ids, fn ($id) => isset($accessible[$id])));
            $failedIds = array_values(array_filter($ids, fn ($id) => !isset($accessible[$id])));

            if ($appliedIds !== []) {
                ReviewCard::query()->whereIn('id', $appliedIds)->update(['marker' => $marker]);
            }

            return [
                'marker' => $marker,
                'applied_ids' => $appliedIds,
                'failed_ids' => $failedIds,
            ];
        });
    }
}
