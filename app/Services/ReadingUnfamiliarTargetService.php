<?php

namespace App\Services;

use App\Models\ReadingUnfamiliarTarget;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReadingUnfamiliarTargetService
{
    public function __construct(
        private ReadingChapterTextService $chapterTextService,
    ) {
    }

    public function listCurrentTargets(int $userId, string $language, int $chapterId): array
    {
        $chapter = $this->chapterTextService->chapterForUser($userId, $language, $chapterId);
        $sourceRevision = $this->chapterTextService->sourceRevision($chapter);
        $targets = $this->currentModels($userId, $language, $chapterId, $sourceRevision);

        $staleCount = ReadingUnfamiliarTarget::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('chapter_id', $chapterId)
            ->where('source_revision', '<>', $sourceRevision)
            ->count();

        return [
            'chapter_id' => (int) $chapterId,
            'source_revision' => $sourceRevision,
            'stale_count' => $staleCount,
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

    public function syncClientSnapshot(int $userId, string $language, int $chapterId, array $targets): array
    {
        $chapter = $this->chapterTextService->chapterForUser($userId, $language, $chapterId);
        $sourceRevision = $this->chapterTextService->sourceRevision($chapter);

        return DB::transaction(function () use ($userId, $language, $chapterId, $chapter, $sourceRevision, $targets) {
            $canonicalByOccurrence = [];
            foreach ($targets as $target) {
                if (!is_array($target)
                    || !in_array($target['kind'] ?? null, [ReadingUnfamiliarTarget::KIND_WORD, ReadingUnfamiliarTarget::KIND_PHRASE], true)
                    || !isset($target['start_word_index'], $target['end_word_index'])
                    || !is_int($target['start_word_index'])
                    || !is_int($target['end_word_index'])) {
                    throw ValidationException::withMessages([
                        'marked_targets' => ['Marked target snapshot contains an invalid item.'],
                    ]);
                }

                $canonical = $this->chapterTextService->canonicalSpan(
                    $chapter,
                    $userId,
                    $language,
                    $target['kind'],
                    $target['start_word_index'],
                    $target['end_word_index'],
                );
                $canonicalByOccurrence[$canonical['occurrence_id']] = $canonical;
            }

            foreach ($canonicalByOccurrence as $canonical) {
                ReadingUnfamiliarTarget::query()->updateOrCreate(
                    [
                        'user_id' => $userId,
                        'language_id' => $language,
                        'chapter_id' => $chapterId,
                        'source_revision' => $sourceRevision,
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
            }

            $scope = ReadingUnfamiliarTarget::query()
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->where('chapter_id', $chapterId)
                ->where('source_revision', $sourceRevision);
            $occurrenceIds = array_keys($canonicalByOccurrence);
            if (empty($occurrenceIds)) {
                $scope->delete();
            } else {
                $scope->whereNotIn('occurrence_id', $occurrenceIds)->delete();
            }

            return array_values(array_map(fn (array $canonical) => [
                'occurrence_id' => $canonical['occurrence_id'],
                'kind' => $canonical['kind'],
                'start_word_index' => $canonical['start_word_index'],
                'end_word_index' => $canonical['end_word_index'],
            ], $canonicalByOccurrence));
        });
    }

    public function createTarget(
        int $userId,
        string $language,
        int $chapterId,
        string $kind,
        int $startWordIndex,
        int $endWordIndex
    ): array {
        $chapter = $this->chapterTextService->chapterForUser($userId, $language, $chapterId);
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

        return [
            'created' => $target->wasRecentlyCreated,
            'target' => $this->serializeTarget($target),
        ];
    }

    public function deleteCurrentTarget(int $userId, string $language, int $chapterId, string $occurrenceId): array
    {
        $chapter = $this->chapterTextService->chapterForUser($userId, $language, $chapterId);
        $sourceRevision = $this->chapterTextService->sourceRevision($chapter);

        $target = ReadingUnfamiliarTarget::query()
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

        $serialized = $this->serializeTarget($target);
        $target->delete();

        return [
            'deleted' => true,
            'target' => $serialized,
            'source_revision' => $sourceRevision,
        ];
    }

    private function serializeTarget(ReadingUnfamiliarTarget $target): array
    {
        return [
            'occurrence_id' => $target->occurrence_id,
            'kind' => $target->kind,
            'start_word_index' => (int) $target->start_word_index,
            'end_word_index' => (int) $target->end_word_index,
            'sentence_index' => (int) $target->sentence_index,
            'surface' => $target->surface,
            'lemma' => $target->lemma,
            'pos' => $target->pos,
            'source_sentence' => $target->source_sentence,
            'source_revision' => $target->source_revision,
        ];
    }
}
