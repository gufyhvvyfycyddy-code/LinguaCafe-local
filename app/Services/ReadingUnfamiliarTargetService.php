<?php

namespace App\Services;

use App\Models\ReadingUnfamiliarTarget;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReadingUnfamiliarTargetService
{
    public const ERROR_STALE_SOURCE = 'READING_TARGET_STALE_SOURCE';

    public function __construct(
        private ReadingChapterTextService $chapterTextService,
    ) {
    }

    public function listCurrentTargets(int $userId, string $language, int $chapterId): array
    {
        $chapter = $this->chapterTextService->chapterForUser($userId, $language, $chapterId);
        $sourceRevision = $this->chapterTextService->sourceRevision($chapter);
        $targets = $this->currentModels($userId, $language, $chapterId, $sourceRevision);

        return [
            'snapshot_version' => $this->snapshotVersion($targets, $sourceRevision),
            'targets' => $targets->map(fn (ReadingUnfamiliarTarget $target) => $this->serializeTarget($target))->all(),
        ];
    }

    public function currentModels(int $userId, string $language, int $chapterId, string $sourceRevision): Collection
    {
        return ReadingUnfamiliarTarget::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('chapter_id', $chapterId)
            ->where('source_revision', $sourceRevision)
            ->orderBy('start_word_index')
            ->orderBy('end_word_index')
            ->get();
    }

    public function createTarget(
        int $userId,
        string $language,
        int $chapterId,
        string $kind,
        int $startWordIndex,
        int $endWordIndex,
        ?string $expectedSourceRevision = null,
    ): array {
        return DB::transaction(function () use (
            $userId,
            $language,
            $chapterId,
            $kind,
            $startWordIndex,
            $endWordIndex,
            $expectedSourceRevision,
        ) {
            $chapter = $this->chapterTextService->lockChapterForUser($userId, $language, $chapterId);
            $currentSourceRevision = $this->chapterTextService->sourceRevision($chapter);
            if ($expectedSourceRevision !== null && !hash_equals($currentSourceRevision, $expectedSourceRevision)) {
                throw new \InvalidArgumentException(self::ERROR_STALE_SOURCE);
            }
            $canonical = $this->chapterTextService->canonicalSpan(
                $chapter,
                $userId,
                $language,
                $kind,
                $startWordIndex,
                $endWordIndex
            );

            $target = ReadingUnfamiliarTarget::query()->updateOrCreate(
                [
                    'user_id' => $canonical['user_id'],
                    'language_id' => $canonical['language_id'],
                    'chapter_id' => $canonical['chapter_id'],
                    'source_revision' => $canonical['source_revision'],
                    'occurrence_id' => $canonical['occurrence_id'],
                ],
                [
                    'kind' => $canonical['kind'],
                    'start_word_index' => $canonical['start_word_index'],
                    'end_word_index' => $canonical['end_word_index'],
                    'sentence_index' => $canonical['sentence_index'],
                    'surface' => $canonical['surface'],
                    'lemma' => $canonical['lemma'],
                    'pos' => $canonical['pos'],
                    'source_sentence' => $canonical['source_sentence'],
                ]
            );

            return $this->serializeTarget($target) + [
                'source_revision' => $canonical['source_revision'],
            ];
        });
    }

    public function deleteCurrentTarget(int $userId, string $language, int $chapterId, string $occurrenceId): void
    {
        DB::transaction(function () use ($userId, $language, $chapterId, $occurrenceId) {
            $chapter = $this->chapterTextService->lockChapterForUser($userId, $language, $chapterId);
            $sourceRevision = $this->chapterTextService->sourceRevision($chapter);
            $target = ReadingUnfamiliarTarget::query()
                ->lockForUpdate()
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->where('chapter_id', $chapterId)
                ->where('source_revision', $sourceRevision)
                ->where('occurrence_id', $occurrenceId)
                ->first();

            if (!$target) {
                throw ValidationException::withMessages([
                    'occurrence_id' => ['Reading unfamiliar target does not exist in the current chapter revision.'],
                ]);
            }

            $target->delete();
        });
    }

    private function snapshotVersion(Collection $targets, string $sourceRevision): string
    {
        $identity = $targets->map(fn (ReadingUnfamiliarTarget $target) => [
            'occurrence_id' => $target->occurrence_id,
            'kind' => $target->kind,
            'start_word_index' => (int) $target->start_word_index,
            'end_word_index' => (int) $target->end_word_index,
        ])->values()->all();
        usort($identity, fn (array $left, array $right) => strcmp($left['occurrence_id'], $right['occurrence_id']));

        return 'sha256:' . hash('sha256', json_encode([
            'source_revision' => $sourceRevision,
            'targets' => $identity,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function serializeTarget(ReadingUnfamiliarTarget $target): array
    {
        return [
            'occurrence_id' => $target->occurrence_id,
            'kind' => $target->kind,
            'start_word_index' => (int) $target->start_word_index,
            'end_word_index' => (int) $target->end_word_index,
        ];
    }
}
