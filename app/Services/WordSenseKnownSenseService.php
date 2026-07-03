<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\ReadingInlineSenseConfirmationService;

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
    public function __construct(
        private readonly ReadingInlineSenseConfirmationService $confirmationService,
    )
    {
    }
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

    /**
     * Build a READ-ONLY inline preview payload for the reading page
     * (GLM-ReadingInlinePreview-First-1, extended GLM-ReadingInlineConfirmationPersistence-1000-1).
     *
     * Product role:
     *  - When the user clicks a token in the reading page, the frontend can
     *    call this payload to render a preview panel that shows:
     *      * current surface form (e.g. "geese");
     *      * current lemma (e.g. "goose");
     *      * the sentence the token appears in (passed through for display);
     *      * confirmed WordSense candidates for this lemma;
     *      * each candidate's sense text + whether it has a sense ReviewCard;
     *      * a read-only FSRS status summary per candidate;
     *      * (new) each candidate's persisted inline confirmation choice
     *        for THIS occurrence, if any (match / not_match / null).
     *
     * Safety contract — this method:
     *  - does NOT call ReviewLog::create / ReviewCardService::recordReview /
     *    ReviewCardService::resetCard / FsrsSchedulingService::schedule;
     *  - does NOT create WordSense / ReviewCard / WordSenseOccurrence;
     *  - does NOT call AI;
     *  - does NOT perform any DB write.
     *  - DOES read from `reading_inline_sense_confirmations` (read-only).
     *
     * The returned safety_flags are a hard contract that the frontend and
     * tests can rely on. If a future round wants to turn "是这个意思" into a
     * real FSRS write, it MUST remove the corresponding safety flag and
     * pass an Architecture Gate + ADR first (see ADR-0003).
     *
     * @param string $surface The surface form clicked by the user (e.g. "geese").
     * @param string $sentence The sentence the token appears in (display only).
     * @param int|null $chapterId The chapter the token appears in (for echo lookup).
     * @param int|null $sentenceIndex The sentence index (for echo lookup).
     */
    public function previewInlineSenseCandidates(
        int $userId,
        string $language,
        string $lemma,
        string $surface = '',
        string $sentence = '',
        ?int $chapterId = null,
        ?int $sentenceIndex = null
    ): array {
        $normalizedLemma = mb_strtolower(trim($lemma));
        $normalizedSurface = trim($surface);
        $trimmedSentence = trim($sentence);

        $candidates = $this->listConfirmedSensesForLemma($userId, $language, $normalizedLemma);

        // Read-only echo of persisted inline confirmations for this occurrence.
        // Only returns choices owned by the current user + language; does not
        // leak other users' confirmations.
        $persistedMap = [];
        if (!empty($candidates) && ($chapterId !== null || $sentenceIndex !== null || $normalizedSurface !== '')) {
            $senseIds = array_map(fn ($c) => $c['sense_id'], $candidates);
            $persistedMap = $this->confirmationService->listConfirmationsForOccurrence(
                $userId,
                $language,
                $chapterId,
                $sentenceIndex,
                $normalizedSurface,
                $normalizedLemma,
                $senseIds
            );
        }

        // Attach persisted_choice / confirmation_id / confirmed_at to each candidate.
        foreach ($candidates as &$candidate) {
            $sid = $candidate['sense_id'];
            $entry = $persistedMap[$sid] ?? null;
            $candidate['persisted_choice'] = $entry['choice'] ?? null;
            $candidate['confirmation_id'] = $entry['confirmation_id'] ?? null;
            $candidate['confirmed_at'] = $entry['updated_at'] ?? null;
        }
        unset($candidate);

        return [
            'lemma' => $normalizedLemma,
            'surface' => $normalizedSurface,
            'sentence' => $trimmedSentence,
            'chapter_id' => $chapterId,
            'sentence_index' => $sentenceIndex,
            'has_confirmed_senses' => !empty($candidates),
            'candidates' => $candidates,
            'candidate_count' => count($candidates),
            'persisted_summary' => [
                'match_count' => count(array_filter($candidates, fn ($c) => ($c['persisted_choice'] ?? null) === 'match')),
                'not_match_count' => count(array_filter($candidates, fn ($c) => ($c['persisted_choice'] ?? null) === 'not_match')),
                'pending_count' => count(array_filter($candidates, fn ($c) => ($c['persisted_choice'] ?? null) === null)),
            ],
            'safety_flags' => [
                'read_only' => true,
                'no_review_log_created' => true,
                'no_fsrs_changed' => true,
                'no_review_card_created' => true,
                'no_word_sense_created' => true,
                'no_ai_called' => true,
            ],
            'ui_hint' => '「是这个意思 / 不是这个意思」按钮会保存为阅读位置级别的确认，不是复习评分，不会写入复习记录，不会改变 FSRS，不会创建词义或复习卡。',
        ];
    }
}
