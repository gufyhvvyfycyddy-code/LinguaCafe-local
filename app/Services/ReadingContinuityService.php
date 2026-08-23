<?php

namespace App\Services;

use App\Models\ReadingProgress;
use Carbon\CarbonInterface;
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
        $progress = $this->savePosition(
            $userId,
            $language,
            $chapterId,
            $sourceRevision,
            $canonicalTokenIndex,
            now(),
            null,
            null,
        );

        return $this->serializeProgress($progress);
    }

    public function saveMobilePosition(
        int $userId,
        string $language,
        int $chapterId,
        string $sourceRevision,
        int $canonicalTokenIndex,
        CarbonInterface $occurredAt,
        int $mobileDeviceId,
        int $clientSequence,
    ): array {
        $progress = $this->savePosition(
            $userId,
            $language,
            $chapterId,
            $sourceRevision,
            $canonicalTokenIndex,
            $occurredAt,
            $mobileDeviceId,
            $clientSequence,
        );

        return $this->serializeContinuity($progress);
    }

    private function savePosition(
        int $userId,
        string $language,
        int $chapterId,
        string $sourceRevision,
        int $canonicalTokenIndex,
        CarbonInterface $occurredAt,
        ?int $mobileDeviceId,
        ?int $clientSequence,
    ): ReadingProgress {
        return DB::transaction(function () use (
            $userId,
            $language,
            $chapterId,
            $sourceRevision,
            $canonicalTokenIndex,
            $occurredAt,
            $mobileDeviceId,
            $clientSequence,
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
            $progress = ReadingProgress::query()->where($scope)->lockForUpdate()->first();
            $furthestCanonicalTokenIndex = $canonicalTokenIndex;

            if ($progress) {
                $existingLatest = (int) $progress->canonical_token_index;
                $existingFurthest = $progress->furthest_canonical_token_index === null
                    ? null
                    : (int) $progress->furthest_canonical_token_index;
                if (!array_key_exists($existingLatest, $ranks)
                    || $existingFurthest === null
                    || !array_key_exists($existingFurthest, $ranks)) {
                    throw new \InvalidArgumentException(self::ERROR_INVALID_TOKEN);
                }
                if ($ranks[$existingFurthest] > $ranks[$canonicalTokenIndex]) {
                    $furthestCanonicalTokenIndex = $existingFurthest;
                }
            }

            if (!$progress) {
                $progress = ReadingProgress::query()->create($scope + [
                    'canonical_token_index' => $canonicalTokenIndex,
                    'furthest_canonical_token_index' => $canonicalTokenIndex,
                    'position_occurred_at' => $occurredAt,
                    'last_mobile_device_id' => $mobileDeviceId,
                    'client_sequence' => $clientSequence,
                ]);

                return $progress;
            }

            $values = [
                'furthest_canonical_token_index' => $furthestCanonicalTokenIndex,
            ];
            if ($this->incomingPositionIsLatest(
                $progress,
                $occurredAt,
                $mobileDeviceId,
                $clientSequence,
            )) {
                $values += [
                    'canonical_token_index' => $canonicalTokenIndex,
                    'position_occurred_at' => $occurredAt,
                    'last_mobile_device_id' => $mobileDeviceId,
                    'client_sequence' => $clientSequence,
                ];
            }
            $progress->fill($values)->save();

            return $progress;
        });
    }

    private function incomingPositionIsLatest(
        ReadingProgress $progress,
        CarbonInterface $occurredAt,
        ?int $mobileDeviceId,
        ?int $clientSequence,
    ): bool {
        if (!$progress->position_occurred_at || $occurredAt->gt($progress->position_occurred_at)) {
            return true;
        }
        if ($occurredAt->lt($progress->position_occurred_at)) {
            return false;
        }

        if ($mobileDeviceId !== null
            && (int) $progress->last_mobile_device_id === $mobileDeviceId) {
            return $clientSequence !== null
                && $clientSequence > (int) ($progress->client_sequence ?? 0);
        }

        return true;
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

    private function serializeContinuity(ReadingProgress $progress): array
    {
        return [
            'source_revision' => (string) $progress->source_revision,
            'resume' => $this->serializeProgress($progress),
            'furthest' => $this->serializeFurthest($progress),
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
