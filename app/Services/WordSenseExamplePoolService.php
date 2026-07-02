<?php

namespace App\Services;

use App\Models\Chapter;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;

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
        $occurrences = WordSenseOccurrence::query()
            ->where('user_id', $sense->user_id)
            ->where('language_id', $sense->language_id)
            ->where('word_sense_id', $sense->id)
            ->where('status', WordSenseOccurrence::STATUS_BOUND)
            ->whereNotNull('sentence_en')
            ->where('sentence_en', '<>', '')
            ->orderByRaw('CASE WHEN source = ? THEN 0 ELSE 1 END', [WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD])
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $candidates = [];
        $seenKeys = [];
        $seenSentences = []; // sentence-only index, used to dedupe card fallback

        foreach ($occurrences as $occurrence) {
            $sentenceEn = $occurrence->sentence_en ?? '';
            if ($sentenceEn === '') {
                continue;
            }

            $chapter = $occurrence->chapter_id
                ? Chapter::query()
                    ->where('id', $occurrence->chapter_id)
                    ->where('user_id', $sense->user_id)
                    ->where('language', $sense->language_id)
                    ->first()
                : null;

            $dedupeKey = $this->dedupeKey($occurrence->chapter_id, $sentenceEn);
            if (isset($seenKeys[$dedupeKey])) {
                continue;
            }
            $seenKeys[$dedupeKey] = true;
            $seenSentences[$this->sentenceKey($sentenceEn)] = true;

            $candidates[] = [
                'occurrence_id' => $occurrence->id,
                'sentence_en' => $sentenceEn,
                'sentence_zh' => $occurrence->sentence_zh ?: null,
                'chapter_id' => $occurrence->chapter_id,
                'chapter_title' => $chapter?->name,
                'sentence_id' => $occurrence->sentence_id,
                'source_label' => $chapter ? 'chapter' : 'occurrence',
                'is_card_fallback' => false,
            ];
        }

        // Fallback: card example sentence. Skip if the sentence already
        // appears among occurrence candidates (regardless of chapter).
        if ($sense->example_sentence_en) {
            $cardSentenceKey = $this->sentenceKey($sense->example_sentence_en);
            if (!isset($seenSentences[$cardSentenceKey])) {
                $candidates[] = [
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
     * Deterministically pick the question example index from the candidate
     * list using a stable seed (review_card_id + fsrs_reps + day-of-year).
     *
     * The rotation is stable for a given day, so the same card reviewed
     * multiple times in one day shows the same question. Across days or
     * after successful reviews (fsrs_reps increments), the question shifts.
     */
    public function pickQuestionIndex(int $total, int $reviewCardId, int $fsrsReps, ?int $dayOfYear = null): int
    {
        if ($total <= 0) {
            return 0;
        }
        if ($total === 1) {
            return 0;
        }

        $dayOfYear = $dayOfYear ?? (int) now()->format('z');
        $seed = ($reviewCardId * 31) + ($fsrsReps * 7) + $dayOfYear;
        $hash = abs(crc32((string) $seed));
        return $hash % $total;
    }

    /**
     * Deterministically pick a supplementary example index that differs from
     * the question index. Returns null when there is only one candidate.
     */
    public function pickSupplementaryIndex(int $total, int $questionIndex, int $reviewCardId, int $fsrsReps, ?int $dayOfYear = null): ?int
    {
        if ($total < 2) {
            return null;
        }

        $dayOfYear = $dayOfYear ?? (int) now()->format('z');
        // Offset seed so supplementary selection is independent of question
        // selection while still stable for the day.
        $seed = ($reviewCardId * 17) + ($fsrsReps * 13) + $dayOfYear + 1009;
        $hash = abs(crc32((string) $seed));
        $candidate = $hash % $total;

        if ($candidate === $questionIndex) {
            // Roll forward by one to avoid the question example.
            $candidate = ($candidate + 1) % $total;
        }

        return $candidate;
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
