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

    /**
     * @param iterable<int, \App\Models\Chapter> $chapters
     * @return array<int, array{available:bool, percentage:?float, reachedTokens:int, totalTokens:int}>
     */
    public function projectChapterProgress(int $userId, string $language, iterable $chapters): array
    {
        $projections = [];
        $revisionByChapter = [];
        $ranksByChapter = [];

        foreach ($chapters as $chapter) {
            $chapterId = (int) $chapter->id;
            $projections[$chapterId] = $this->unavailableProgressProjection();

            try {
                $ranks = $this->chapterTextService->positionableCanonicalTokenRanks($chapter);
            } catch (\InvalidArgumentException $e) {
                continue;
            }
            if ($ranks === []) {
                continue;
            }

            $revisionByChapter[$chapterId] = $this->chapterTextService->sourceRevision($chapter);
            $ranksByChapter[$chapterId] = $ranks;
            $projections[$chapterId] = $this->progressProjection(0, count($ranks));
        }

        if ($revisionByChapter === []) {
            return $projections;
        }

        $progressRows = ReadingProgress::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where(function ($query) use ($revisionByChapter) {
                foreach ($revisionByChapter as $chapterId => $sourceRevision) {
                    $query->orWhere(function ($scope) use ($chapterId, $sourceRevision) {
                        $scope->where('chapter_id', $chapterId)
                            ->where('source_revision', $sourceRevision);
                    });
                }
            })
            ->get()
            ->keyBy(fn (ReadingProgress $progress): string => $this->progressScopeKey(
                (int) $progress->chapter_id,
                (string) $progress->source_revision,
            ));

        foreach ($revisionByChapter as $chapterId => $sourceRevision) {
            $ranks = $ranksByChapter[$chapterId];
            $progress = $progressRows->get($this->progressScopeKey($chapterId, $sourceRevision));
            if (!$progress) {
                continue;
            }

            $furthest = (int) $progress->furthest_canonical_token_index;
            if (!array_key_exists($furthest, $ranks)) {
                continue;
            }

            $projections[$chapterId] = $this->progressProjection(
                $ranks[$furthest] + 1,
                count($ranks),
            );
        }

        return $projections;
    }

    /**
     * @param iterable<int, array{available:bool, percentage:?float, reachedTokens:int, totalTokens:int}> $projections
     * @return array{available:bool, percentage:?float, reachedTokens:int, totalTokens:int}
     */
    public function aggregateProgress(iterable $projections): array
    {
        $reachedTokens = 0;
        $totalTokens = 0;

        foreach ($projections as $projection) {
            if (($projection['available'] ?? false) !== true) {
                continue;
            }
            $reachedTokens += (int) $projection['reachedTokens'];
            $totalTokens += (int) $projection['totalTokens'];
        }

        return $totalTokens === 0
            ? $this->unavailableProgressProjection()
            : $this->progressProjection($reachedTokens, $totalTokens);
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

    /**
     * @return array{available:bool, percentage:?float, reachedTokens:int, totalTokens:int}
     */
    private function unavailableProgressProjection(): array
    {
        return [
            'available' => false,
            'percentage' => null,
            'reachedTokens' => 0,
            'totalTokens' => 0,
        ];
    }

    /**
     * @return array{available:bool, percentage:float, reachedTokens:int, totalTokens:int}
     */
    private function progressProjection(int $reachedTokens, int $totalTokens): array
    {
        return [
            'available' => true,
            'percentage' => round($reachedTokens / $totalTokens * 100, 1),
            'reachedTokens' => $reachedTokens,
            'totalTokens' => $totalTokens,
        ];
    }

    private function progressScopeKey(int $chapterId, string $sourceRevision): string
    {
        return $chapterId.'|'.$sourceRevision;
    }
}
