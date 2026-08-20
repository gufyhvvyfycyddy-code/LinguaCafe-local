<?php

namespace App\Services;

use App\Models\Chapter;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Support\Collection;

/**
 * Builds the real-source example pool for a WordSense.
 *
 * Sources (in priority order):
 *  1. WordSenseOccurrence rows (status=BOUND, sentence_en non-empty) — preferred.
 *  2. WordSense.example_sentence_en as a fallback card example.
 *
 * Rules:
 *  - Examples come from real reading material only. AI is never used to
 *    generate sentences.
 *  - A single WordSense may yield multiple distinct examples.
 *  - Duplicate sentences (same chapter + normalized sentence text) are
 *    collapsed — different positions in the same chapter count as the
 *    same example for display purposes.
 *  - When only one example exists, no supplementary example is returned.
 *
 * This service is read-only: it does not write ReviewLog, ReviewCard, or
 * WordSense rows, and does not invoke FSRS scheduling.
 */
class WordSenseExamplePoolService
{
    /**
     * Collect distinct real-source example candidates for a sense.
     *
     * @return list<array{
     *     occurrence_id: int|null,
     *     sentence_en: string,
     *     sentence_zh: string|null,
     *     chapter_id: int|null,
     *     chapter_title: string|null,
     *     sentence_id: string|null,
     *     source_label: string,
     *     is_card_fallback: bool
     * }>
     */
    public function exampleCandidates(WordSense $sense): array
    {
        $batch = $this->exampleCandidateBatch(collect([$sense]));

        return $batch['candidates'][$sense->id] ?? [];
    }

    /**
     * Batch-load candidate occurrences and chapters for serializeMany().
     * The returned chapter/evidence maps are also reused by token and
     * understanding-aid resolution so those layers do not re-query per card.
     */
    public function exampleCandidateBatch(Collection $senses): array
    {
        if ($senses->isEmpty()) {
            return ['candidates' => [], 'chapters' => [], 'occurrence_evidence' => []];
        }

        $sensesById = $senses->keyBy('id');
        $occurrences = WordSenseOccurrence::query()
            ->whereIn('word_sense_id', $sensesById->keys()->all())
            ->where('status', WordSenseOccurrence::STATUS_BOUND)
            ->whereNotNull('sentence_en')
            ->where('sentence_en', '<>', '')
            ->orderByRaw('CASE WHEN source = ? THEN 0 ELSE 1 END', [WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD])
            ->orderByDesc('id')
            ->get()
            ->filter(function (WordSenseOccurrence $occurrence) use ($sensesById): bool {
                $sense = $sensesById->get($occurrence->word_sense_id);

                return $sense !== null
                    && (int) $occurrence->user_id === (int) $sense->user_id
                    && (string) $occurrence->language_id === (string) $sense->language_id;
            });

        $chapterIds = $occurrences->pluck('chapter_id')->filter()->unique()->values()->all();
        $chaptersById = [];
        if (!empty($chapterIds)) {
            $chaptersById = Chapter::query()
                ->whereIn('id', $chapterIds)
                ->get()
                ->keyBy('id')
                ->all();
        }

        $candidateMap = [];
        foreach ($senses as $sense) {
            $senseOccurrences = $occurrences
                ->where('word_sense_id', $sense->id)
                ->values();
            $candidateMap[$sense->id] = $this->buildCandidates($sense, $senseOccurrences, $chaptersById);
        }

        return [
            'candidates' => $candidateMap,
            'chapters' => $chaptersById,
            'occurrence_evidence' => $occurrences->mapWithKeys(
                fn (WordSenseOccurrence $occurrence) => [$occurrence->id => $occurrence->evidence],
            )->all(),
        ];
    }

    private function buildCandidates(WordSense $sense, Collection $occurrences, array $chaptersById): array
    {

        $candidates = [];
        $seenKeys = [];
        $seenSentences = []; // sentence-only index, used to dedupe card fallback

        foreach ($occurrences as $occurrence) {
            $sentenceEn = $occurrence->sentence_en ?? '';
            if ($sentenceEn === '') {
                continue;
            }

            // Ownership guard: when an occurrence references a chapter_id,
            // the chapter must belong to the same user and language. The
            // batch-loaded $chaptersById above already filters by
            // user_id + language, so a missing entry means the occurrence
            // points to another user's (or another language's) chapter.
            // Such an occurrence is untrusted: skip it entirely so its
            // sentence_en does not leak into the candidate pool.
            $chapter = $occurrence->chapter_id === null ? null : ($chaptersById[$occurrence->chapter_id] ?? null);
            if ($occurrence->chapter_id !== null) {
                if ($chapter === null
                    || (int) $chapter->user_id !== (int) $sense->user_id
                    || (string) $chapter->language !== (string) $sense->language_id) {
                    continue;
                }
            }

            $chapterName = $chapter?->name;

            $dedupeKey = $this->dedupeKey($occurrence->chapter_id, $sentenceEn);
            if (isset($seenKeys[$dedupeKey])) {
                continue;
            }
            $seenKeys[$dedupeKey] = true;
            $seenSentences[$this->sentenceKey($sentenceEn)] = true;

            $candidates[] = [
                'candidate_key' => $this->candidateKey($occurrence->chapter_id, $sentenceEn),
                'occurrence_id' => $occurrence->id,
                'sentence_en' => $sentenceEn,
                'sentence_zh' => $occurrence->sentence_zh ?: null,
                'chapter_id' => $occurrence->chapter_id,
                'chapter_title' => $chapterName,
                'sentence_id' => $occurrence->sentence_id,
                'source_label' => $chapterName !== null ? 'chapter' : 'occurrence',
                'is_card_fallback' => false,
            ];
        }

        // Fallback: card example sentence. Skip if the sentence already
        // appears among occurrence candidates (regardless of chapter).
        if ($sense->example_sentence_en) {
            $cardSentenceKey = $this->sentenceKey($sense->example_sentence_en);
            if (!isset($seenSentences[$cardSentenceKey])) {
                $candidates[] = [
                    'candidate_key' => $this->candidateKey($sense->source_chapter_id, $sense->example_sentence_en),
                    'occurrence_id' => null,
                    'sentence_en' => $sense->example_sentence_en,
                    'sentence_zh' => $sense->example_sentence_zh ?: null,
                    'chapter_id' => $sense->source_chapter_id,
                    'chapter_title' => null,
                    'sentence_id' => $sense->sentence_id,
                    'source_label' => 'card_example',
                    'is_card_fallback' => true,
                ];
            }
        }

        return $candidates;
    }

    /**
     * Select the formal question from a deterministic shuffled full-pool cycle.
     *
     * The ordinal counts completed, non-undone formal questions carrying a
     * question_example_key. A stable pool therefore yields one complete
     * permutation per cycle. The latest completed key is excluded when at
     * least two candidates exist, including across cycle boundaries.
     */
    public function pickQuestionIndex(
        array $candidates,
        int $reviewCardId,
        int $formalQuestionOrdinal,
        ?string $previousQuestionExampleKey = null,
    ): int {
        $total = count($candidates);
        if ($total <= 1) {
            return 0;
        }

        $order = $this->shuffledIndexes($candidates, $reviewCardId, $formalQuestionOrdinal);
        $position = $formalQuestionOrdinal % $total;
        $questionIndex = $order[$position];

        if ($previousQuestionExampleKey === null
            || ($candidates[$questionIndex]['candidate_key'] ?? null) !== $previousQuestionExampleKey) {
            return $questionIndex;
        }

        for ($offset = 1; $offset < $total; $offset++) {
            $candidateIndex = $order[($position + $offset) % $total];
            if (($candidates[$candidateIndex]['candidate_key'] ?? null) !== $previousQuestionExampleKey) {
                return $candidateIndex;
            }
        }

        return $questionIndex;
    }

    /**
     * Explicit preferred occurrence support remains available for direct
     * serializer callers. The normal formal review path does not pass a
     * preference and therefore always uses the shuffled rotation above.
     */
    public function pickQuestionIndexWithContext(
        array $candidates,
        int $reviewCardId,
        int $formalQuestionOrdinal,
        ?string $previousQuestionExampleKey = null,
        ?int $preferredOccurrenceId = null,
    ): int {
        if ($preferredOccurrenceId !== null) {
            foreach ($candidates as $index => $candidate) {
                if (($candidate['occurrence_id'] ?? null) === $preferredOccurrenceId) {
                    return $index;
                }
            }
        }

        return $this->pickQuestionIndex(
            $candidates,
            $reviewCardId,
            $formalQuestionOrdinal,
            $previousQuestionExampleKey,
        );
    }

    /**
     * Pick a deterministic supplementary example from the same shuffled pool.
     */
    public function pickSupplementaryIndex(
        array $candidates,
        int $questionIndex,
        int $reviewCardId,
        int $formalQuestionOrdinal,
    ): ?int {
        $total = count($candidates);
        if ($total < 2) {
            return null;
        }

        $order = $this->shuffledIndexes($candidates, $reviewCardId, $formalQuestionOrdinal);
        $questionPosition = array_search($questionIndex, $order, true);
        if ($questionPosition === false) {
            $questionPosition = 0;
        }

        for ($offset = 1; $offset < $total; $offset++) {
            $candidateIndex = $order[($questionPosition + $offset) % $total];
            if ($candidateIndex !== $questionIndex) {
                return $candidateIndex;
            }
        }

        return null;
    }

    /** @return list<int> */
    private function shuffledIndexes(array $candidates, int $reviewCardId, int $formalQuestionOrdinal): array
    {
        $total = count($candidates);
        $indexes = array_keys($candidates);
        if ($total <= 1) {
            return $indexes;
        }

        $keys = array_map(
            fn (array $candidate) => (string) ($candidate['candidate_key'] ?? ''),
            $candidates,
        );
        $canonicalKeys = $keys;
        sort($canonicalKeys, SORT_STRING);
        $poolFingerprint = hash('sha256', implode('|', $canonicalKeys));
        $cycle = intdiv($formalQuestionOrdinal, $total);
        $ranks = [];

        foreach ($indexes as $index) {
            $ranks[$index] = hash('sha256', implode('|', [
                'sense-example-v2',
                $reviewCardId,
                $poolFingerprint,
                $cycle,
                $keys[$index],
            ]));
        }

        usort($indexes, function (int $left, int $right) use ($ranks, $keys): int {
            $rankComparison = strcmp($ranks[$left], $ranks[$right]);

            return $rankComparison !== 0
                ? $rankComparison
                : strcmp($keys[$left], $keys[$right]);
        });

        return $indexes;
    }

    private function candidateKey(?int $chapterId, string $sentenceEn): string
    {
        return hash('sha256', 'sense-example-v2|' . $this->dedupeKey($chapterId, $sentenceEn));
    }

    /**
     * Build a dedupe key that collapses identical sentences within the same
     * chapter (or both without a chapter).
     */
    private function dedupeKey(?int $chapterId, string $sentenceEn): string
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $sentenceEn) ?? ''));
        return ($chapterId ?? 0) . '|' . $normalized;
    }

    /**
     * Sentence-only key (chapter-agnostic). Used to dedupe the card example
     * fallback against any occurrence with the same sentence, regardless of
     * which chapter that occurrence belongs to.
     */
    private function sentenceKey(string $sentenceEn): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $sentenceEn) ?? ''));
    }
}
