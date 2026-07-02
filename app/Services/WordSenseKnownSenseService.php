<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;

/**
 * Bridges lemma lookup to "already-learned sense" candidates.
 *
 * Product role (Trae-LemmaKnownSenseBridge-1):
 *  - When a user looks up a surface form (e.g. "ways"), the lemma (e.g. "way")
 *    is used to find any confirmed WordSense rows already saved by this user.
 *  - These are presented as "已学词义候选" — candidates the user may accept
 *    as the meaning for the current occurrence, or reject as a known-sense-
 *    new-meaning case ("熟词僻义").
 *  - This service is READ-ONLY. It does not write ReviewLog, ReviewCard,
 *    WordSense, or WordSenseOccurrence rows, and does not invoke FSRS.
 *  - It does not call AI. The "is this a known-sense-new-meaning?" judgement
 *    is left to a future AI round; here we only prepare the data structure.
 *
 * Status semantics (see WordSense model):
 *  - STATUS_CONFIRMED  → included in known-sense candidates.
 *  - STATUS_AI_SUGGESTED → excluded (not yet learned).
 *  - STATUS_REJECTED     → excluded.
 *  - There is no STATUS_ARCHIVED / STATUS_PENDING constant on the model;
 *    archive currently reuses STATUS_REJECTED.
 */
class WordSenseKnownSenseService
{
    /**
     * List confirmed WordSense rows for a given lemma.
     *
     * @return list<array{
     *     sense_id: int,
     *     lemma: string,
     *     surface_form: string|null,
     *     pos: string|null,
     *     sense_zh: string|null,
     *     sense_en: string|null,
     *     aliases_zh: list<string>,
     *     collocations: list<string>,
     *     has_review_card: bool,
     *     review_card_id: int|null,
     *     fsrs_state: string|null,
     *     fsrs_reps: int|null,
     *     fsrs_due_at: string|null,
     *     fsrs_enabled: bool|null,
     *     occurrence_count: int
     * }>
     */
    public function listConfirmedSensesForLemma(int $userId, string $language, string $lemma): array
    {
        $normalized = mb_strtolower(trim($lemma));
        if ($normalized === '') {
            return [];
        }

        $senses = WordSense::query()
            ->with('reviewCard')
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('lemma', $normalized)
            ->where('status', WordSense::STATUS_CONFIRMED)
            ->orderBy('id')
            ->get();

        if ($senses->isEmpty()) {
            return [];
        }

        // Batch-load occurrence counts per sense to avoid N+1.
        $senseIds = $senses->pluck('id')->all();
        $occurrenceCounts = WordSenseOccurrence::query()
            ->selectRaw('word_sense_id, COUNT(*) as cnt')
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->whereIn('word_sense_id', $senseIds)
            ->where('status', WordSenseOccurrence::STATUS_BOUND)
            ->groupBy('word_sense_id')
            ->pluck('cnt', 'word_sense_id')
            ->all();

        $result = [];
        foreach ($senses as $sense) {
            $reviewCard = $sense->reviewCard;
            $result[] = [
                'sense_id' => $sense->id,
                'lemma' => $sense->lemma,
                'surface_form' => $sense->surface_form,
                'pos' => $sense->pos,
                'sense_zh' => $sense->sense_zh,
                'sense_en' => $sense->sense_en,
                'aliases_zh' => $sense->aliases_zh ?: [],
                'collocations' => $sense->collocations ?: [],
                'has_review_card' => $reviewCard !== null,
                'review_card_id' => $reviewCard?->id,
                'fsrs_state' => $reviewCard?->fsrs_state,
                'fsrs_reps' => $reviewCard?->fsrs_reps,
                'fsrs_due_at' => $reviewCard?->fsrs_due_at,
                'fsrs_enabled' => $reviewCard?->fsrs_enabled,
                'occurrence_count' => $occurrenceCounts[$sense->id] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Build a compact "known sense lookup" payload for the vocabulary box.
     *
     * Returns a structure that the frontend can use to decide whether to show
     * an "已学词义候选" panel and whether to surface a "熟词僻义" prompt
     * before the user adds a brand-new sense.
     *
     * Fields:
     *  - lemma: the normalized lemma actually queried.
     *  - has_confirmed_senses: true when at least one confirmed sense exists.
     *  - confirmed_senses: list of confirmed sense payloads (may be empty).
     *  - known_sense_new_meaning_hint: true when confirmed senses exist —
     *    a UI hint that "you have learned some meanings of this word; the
     *    current sentence may be a new meaning". This is NOT an AI judgement;
     *    it is purely a structural flag.
     *  - read_only: always true here. This method does not write anything.
     */
    public function knownSenseLookupPayload(int $userId, string $language, string $lemma): array
    {
        $confirmed = $this->listConfirmedSensesForLemma($userId, $language, $lemma);

        return [
            'lemma' => mb_strtolower(trim($lemma)),
            'has_confirmed_senses' => !empty($confirmed),
            'confirmed_senses' => $confirmed,
            'known_sense_new_meaning_hint' => !empty($confirmed),
            'read_only' => true,
        ];
    }
}
