<?php

namespace App\Services;

use App\Models\ReadingProgress;
use Illuminate\Support\Facades\DB;

class ReadingContinuityService
{
    public const ERROR_CHAPTER_NOT_FOUND = 'READING_CONTINUITY_CHAPTER_NOT_FOUND';
    public const ERROR_STALE_SOURCE = 'READING_CONTINUITY_STALE_SOURCE';
    public const ERROR_INVALID_TOKEN = 'READING_CONTINUITY_INVALID_TOKEN';

    public function __construct(
        private ReadingChapterTextService $chapterTextService,
    ) {
    }

    public function current(int $userId, string $language, int $chapterId): array
    {
        try {
            $chapter = $this->chapterTextService->chapterForUser($userId, $language, $chapterId);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(self::ERROR_CHAPTER_NOT_FOUND);
        }

        $sourceRevision = $this->chapterTextService->sourceRevision($chapter);
        $progress = ReadingProgress::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('chapter_id', $chapterId)
            ->where('source_revision', $sourceRevision)
            ->first();

        $resume = null;
        $furthest = null;
        if ($progress) {
            try {
                $ranks = $this->chapterTextService->positionableCanonicalTokenRanks($chapter);
                $latestIndex = (int) $progress->canonical_token_index;
                $furthestIndex = $progress->furthest_canonical_token_index === null
                    ? null
                    : (int) $progress->furthest_canonical_token_index;
                $anchorsAreValid = array_key_exists($latestIndex, $ranks)
                    && $furthestIndex !== null
                    && array_key_exists($furthestIndex, $ranks);
            } catch (\InvalidArgumentException $e) {
                $anchorsAreValid = false;
            }

            if ($anchorsAreValid) {
                $resume = $this->serializeProgress($progress);
                $furthest = $this->serializeFurthest($progress);
            }
        }

        return [
            'source_revision' => $sourceRevision,
            'resume' => $resume,
            'furthest' => $furthest,
        ];
    }

    public function saveWebPosition(
        int $userId,
        string $language,
        int $chapterId,
        string $sourceRevision,
        int $canonicalTokenIndex,
    ): array {
        return DB::transaction(function () use (
            $userId,
            $language,
            $chapterId,
            $sourceRevision,
            $canonicalTokenIndex,
        ) {
            try {
                $chapter = $this->chapterTextService->lockChapterForUser($userId, $language, $chapterId);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(self::ERROR_CHAPTER_NOT_FOUND);
            }

            $currentRevision = $this->chapterTextService->sourceRevision($chapter);
            if (!hash_equals($currentRevision, $sourceRevision)) {
                throw new \InvalidArgumentException(self::ERROR_STALE_SOURCE);
            }

            try {
                $ranks = $this->chapterTextService->positionableCanonicalTokenRanks($chapter);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(self::ERROR_INVALID_TOKEN);
            }
            if (!array_key_exists($canonicalTokenIndex, $ranks)) {
                throw new \InvalidArgumentException(self::ERROR_INVALID_TOKEN);
            }

            $scope = [
                'user_id' => $userId,
                'language_id' => $language,
                'chapter_id' => $chapterId,
                'source_revision' => $currentRevision,
            ];
            $progress = ReadingProgress::query()->where($scope)->first();
            $furthestCanonicalTokenIndex = $canonicalTokenIndex;

            if ($progress) {
                $existingFurthest = $progress->furthest_canonical_token_index === null
                    ? null
                    : (int) $progress->furthest_canonical_token_index;
                if ($existingFurthest === null || !array_key_exists($existingFurthest, $ranks)) {
                    throw new \InvalidArgumentException(self::ERROR_INVALID_TOKEN);
                }
                if ($ranks[$existingFurthest] > $ranks[$canonicalTokenIndex]) {
                    $furthestCanonicalTokenIndex = $existingFurthest;
                }
            }

            $values = [
                'canonical_token_index' => $canonicalTokenIndex,
                'furthest_canonical_token_index' => $furthestCanonicalTokenIndex,
                'position_occurred_at' => now(),
                'last_mobile_device_id' => null,
                'client_sequence' => null,
            ];

            if ($progress) {
                $progress->fill($values)->save();
            } else {
                $progress = ReadingProgress::query()->create($scope + $values);
            }

            return $this->serializeProgress($progress);
        });
    }

    private function serializeProgress(ReadingProgress $progress): array
    {
        return [
            'source_revision' => (string) $progress->source_revision,
            'canonical_token_index' => (int) $progress->canonical_token_index,
            'position_occurred_at' => $progress->position_occurred_at?->toISOString(),
        ];
    }

    private function serializeFurthest(ReadingProgress $progress): array
    {
        return [
            'source_revision' => (string) $progress->source_revision,
            'canonical_token_index' => (int) $progress->furthest_canonical_token_index,
        ];
    }
}
