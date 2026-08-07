<?php

namespace App\Services;

use App\Models\ReadingUnfamiliarTarget;

class ReadingTargetCatalogService
{
    public function __construct(
        private ReadingChapterTextService $chapterTextService,
        private ReadingUnfamiliarTargetService $unfamiliarTargetService,
        private WordSenseKnownSenseService $knownSenseService,
    ) {
    }

    /**
     * Build the authoritative current V2 target catalog.
     *
     * @return array{
     *   chapter:\App\Models\Chapter,
     *   source_revision:string,
     *   sentences:array<int,string>,
     *   targets:array<int,array>,
     *   targets_by_id:array<string,array>
     * }
     */
    public function build(int $userId, string $language, int $chapterId): array
    {
        $chapter = $this->chapterTextService->chapterForUser($userId, $language, $chapterId);
        $sourceRevision = $this->chapterTextService->sourceRevision($chapter);
        $tokens = $this->chapterTextService->tokenMap($chapter);
        $sentences = $this->chapterTextService->sentenceMap($chapter);
        $marked = $this->unfamiliarTargetService->currentModels($userId, $language, $chapterId, $sourceRevision);

        $lemmas = [];
        foreach ($tokens as $token) {
            if ($this->isStructure($token)) {
                continue;
            }
            $lemma = $this->normalizeLemma($token);
            if ($lemma !== '') {
                $lemmas[] = $lemma;
            }
        }
        foreach ($marked as $target) {
            if ($target->kind === ReadingUnfamiliarTarget::KIND_WORD && trim((string) $target->lemma) !== '') {
                $lemmas[] = mb_strtolower(trim((string) $target->lemma));
            }
        }

        $candidatesByLemma = $this->knownSenseService->confirmedCandidatesByLemma($userId, $language, $lemmas);
        $targetsById = [];

        foreach ($marked as $target) {
            $lemma = mb_strtolower(trim((string) $target->lemma));
            $serialized = [
                'occurrence_id' => $target->occurrence_id,
                'kind' => $target->kind,
                'purpose' => 'marked_unknown',
                'start_word_index' => (int) $target->start_word_index,
                'end_word_index' => (int) $target->end_word_index,
                'sentence_index' => (int) $target->sentence_index,
                'surface' => $target->surface,
                'lemma' => $lemma,
                'pos' => $target->pos,
                'source_sentence' => $target->source_sentence,
                'candidate_word_senses' => $target->kind === ReadingUnfamiliarTarget::KIND_WORD
                    ? ($candidatesByLemma[$lemma] ?? [])
                    : [],
            ];
            $targetsById[$target->occurrence_id] = $serialized;
        }

        foreach ($tokens as $wordIndex => $token) {
            if ($this->isStructure($token) || !isset($token->sentence_index)) {
                continue;
            }

            $lemma = $this->normalizeLemma($token);
            $candidates = $candidatesByLemma[$lemma] ?? [];
            if ($lemma === '' || empty($candidates)) {
                continue;
            }

            $occurrenceId = $this->chapterTextService->occurrenceId(
                $userId,
                $language,
                $chapterId,
                $sourceRevision,
                'word',
                (int) $wordIndex,
                (int) $wordIndex,
            );

            // Explicit unfamiliar intent always wins over passive disambiguation.
            if (isset($targetsById[$occurrenceId])) {
                continue;
            }

            $targetsById[$occurrenceId] = [
                'occurrence_id' => $occurrenceId,
                'kind' => 'word',
                'purpose' => 'passive_disambiguation',
                'start_word_index' => (int) $wordIndex,
                'end_word_index' => (int) $wordIndex,
                'sentence_index' => (int) $token->sentence_index,
                'surface' => (string) ($token->word ?? ''),
                'lemma' => $lemma,
                'pos' => isset($token->pos) && trim((string) $token->pos) !== '' ? (string) $token->pos : null,
                'source_sentence' => $sentences[(int) $token->sentence_index] ?? '',
                'candidate_word_senses' => $candidates,
            ];
        }

        $targets = array_values($targetsById);
        usort($targets, function (array $left, array $right): int {
            $cmp = $left['start_word_index'] <=> $right['start_word_index'];
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = $left['end_word_index'] <=> $right['end_word_index'];
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($left['occurrence_id'], $right['occurrence_id']);
        });

        $targetsById = [];
        foreach ($targets as $target) {
            $targetsById[$target['occurrence_id']] = $target;
        }

        return [
            'chapter' => $chapter,
            'source_revision' => $sourceRevision,
            'sentences' => $sentences,
            'targets' => $targets,
            'targets_by_id' => $targetsById,
        ];
    }

    private function normalizeLemma(object $token): string
    {
        return mb_strtolower(trim((string) ($token->lemma ?? $token->word ?? '')));
    }

    private function isStructure(object $token): bool
    {
        return (($token->is_structure ?? false) === true)
            || (($token->pos ?? null) === 'STRUCT')
            || in_array((string) ($token->word ?? ''), ['NEWLINE', 'PARAGRAPH_BREAK'], true);
    }
}
