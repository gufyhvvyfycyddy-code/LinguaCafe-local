<?php

namespace App\Services;

use App\Models\ChapterAiReadingAssist;
use App\Models\ReviewCard;
use Illuminate\Support\Collection;

/**
 * SenseReviewCardSerializerService
 *
 * SenseReview-Serializer-1000-1 (refactored)
 *
 * Responsibilities (after SenseReview-LearningFeedbackService extraction):
 *  - Pick the question/supplementary examples from the sense's occurrence
 *    pool using linear sequence rotation (card_id + reps + lapses) and an
 *    optional preferred_occurrence_id override.
 *  - Normalize the sense-level understanding_aid and merge occurrence-level
 *    evidence (context_hint / judgment_basis / related_collocations).
 *  - Assemble the final review-card payload (lemma, sense_zh, FSRS fields,
 *    occurrence metadata, etc.).
 *  - Delegate learning_feedback aggregation to the dedicated
 *    SenseReviewLearningFeedbackService (single source of truth for
 *    ReviewLog aggregation, rating labels, and forgetting-pattern trend).
 *
 * The serializer NO LONGER directly queries ReviewLog. All ReviewLog reads
 * live in SenseReviewLearningFeedbackService::buildForCard(). This keeps the
 * feedback algorithm in one place and makes the serializer a thin payload
 * assembler.
 *
 * Invariants preserved:
 *  - READ-ONLY: never writes ReviewLog, never touches any FSRS field.
 *  - learning_feedback payload shape and semantics are 100% backward
 *    compatible with the pre-refactor contract.
 *  - occurrence rotation and understanding_aid logic are unchanged.
 */
class SenseReviewCardSerializerService
{
    public function __construct(
        private SenseTokenPayloadService $senseTokenPayloadService,
        private WordSenseExamplePoolService $examplePoolService,
        private SenseReviewLearningFeedbackService $feedbackService,
        private SenseExampleIdentityResolver $exampleIdentityResolver,
    ) {
    }

    /**
     * Serialize a ReviewCard (with loaded 'sense' relation) into the
     * frontend review card payload.
     *
     * The question example is rotated across the sense's real-source example
     * pool using linear sequence rotation (review_card_id + fsrs_reps +
     * fsrs_lapses) so consecutive reviews cycle through examples in order
     * (A -> B -> C -> A ...) and a failed review (lapses increment) shifts
     * to a different example. A supplementary example (different from the
     * question) is also included for the answer side; it is null when the
     * pool has only one example.
     *
     * Rotation does NOT persist a "last shown occurrence id": the linear
     * sequence is deterministic from (card_id, reps, lapses) and shifts
     * naturally after each review (reps increments on success, lapses
     * increments on failure). This avoids any new migration / write path
     * while satisfying "first A, second B, third C" and "failed review
     * shows a different example".
     *
     * Smart selection (GM52-SenseReviewContextualUnderstanding-1000-10):
     * When a preferred_occurrence_id is supplied via $options, the serializer
     * prefers that occurrence as the question example (priority 1). This lets
     * the caller keep the currently-displayed occurrence stable across page
     * reloads without persisting state. When the preferred id is not in the
     * candidate pool, linear rotation is used as fallback. This is read-only
     * and never writes to ReviewLog or touches FSRS.
     *
     * Contextual understanding aid: when the selected occurrence has evidence
     * JSON with context_hint / judgment_basis / related_collocations, those
     * occurrence-level values are merged into the payload's understanding_aid
     * (overriding sense-level values for the same keys). This makes the
     * understanding aid follow the currently-displayed occurrence while
     * keeping sense-level values as fallback.
     *
     * Payload contract (SenseMultiExampleBindingAndReviewRotation-1000-6):
     *   - displayed_occurrence_id: id of the occurrence shown this round
     *     (null when the example comes from the card fallback or is empty).
     *   - occurrence_count: total number of distinct source examples
     *     currently bound to this sense (occurrences + card fallback).
     *   - example_source_status: 'occurrence' | 'card_fallback' | 'empty'.
     *
     * @param  array  $options  Optional: ['preferred_occurrence_id' => int|null,
     *                          'learning_feedback' => array|null (precomputed
     *                          feedback payload; when present the serializer
     *                          skips the per-card buildForCard() call)]
     * @return array{review_card_id: int, word_sense_id: int, lemma: string, ...}
     */
    public function serialize(ReviewCard $card, array $options = []): array
    {
        $sense = $card->sense;
        $candidates = $options['example_candidates'] ?? $this->examplePoolService->exampleCandidates($sense);

        $preferredOccurrenceId = $options['preferred_occurrence_id'] ?? null;

        $questionIndex = $this->examplePoolService->pickQuestionIndexWithContext(
            $candidates,
            $card->id,
            (int) ($card->fsrs_reps ?? 0),
            (int) ($card->fsrs_lapses ?? 0),
            $preferredOccurrenceId,
        );

        $questionExample = $candidates[$questionIndex] ?? null;
        $supplementaryIndex = $this->examplePoolService->pickSupplementaryIndex(
            count($candidates),
            $questionIndex,
            $card->id,
            (int) ($card->fsrs_reps ?? 0),
            (int) ($card->fsrs_lapses ?? 0),
        );
        $supplementaryExample = $supplementaryIndex !== null ? $candidates[$supplementaryIndex] ?? null : null;

        $questionIdentity = $questionExample === null
            ? null
            : $this->exampleIdentityResolver->resolve($questionExample, (int) $sense->user_id, (string) $sense->language_id);
        $tokenExample = $questionExample;
        if ($tokenExample !== null && $questionIdentity !== null) {
            $tokenExample['resolved_sentence_index'] = $questionIdentity['sentence_index'];
        }
        $tokenPayload = $this->senseTokenPayloadService->exampleSentenceTokenPayload(
            $sense,
            $tokenExample,
            $options['token_chapters'] ?? null,
        );

        // The selected example is the only translation source. Sense-level
        // text is never borrowed for a different occurrence.
        $exampleSentenceEn = $questionExample['sentence_en'] ?? $sense->example_sentence_en;
        [$exampleSentenceZh, $translationSource] = $this->translationForExample(
            $sense,
            $questionExample,
            $options['translation_assists'] ?? null,
        );
        if ($supplementaryExample !== null) {
            [$supplementaryExample['sentence_zh'], $supplementaryExample['translation_source']] = $this->translationForExample(
                $sense,
                $supplementaryExample,
                $options['translation_assists'] ?? null,
            );
        }

        // SenseMultiExampleBindingAndReviewRotation-1000-6: surface which
        // occurrence the current display comes from, how many distinct source
        // examples exist, and whether the display is a real occurrence, the
        // card fallback, or an empty state.
        $displayedOccurrenceId = $questionExample['occurrence_id'] ?? null;
        $occurrenceCount = count($candidates);
        if ($questionExample === null) {
            $exampleSourceStatus = $sense->example_sentence_en ? 'card_fallback' : 'empty';
        } elseif (($questionExample['is_card_fallback'] ?? false) === true) {
            $exampleSourceStatus = 'card_fallback';
        } else {
            $exampleSourceStatus = 'occurrence';
        }

        // Contextual understanding aid (GM52-SenseReviewContextualUnderstanding-1000-10):
        // Start from sense-level understanding_aid, then merge occurrence-level
        // evidence (context_hint / judgment_basis / related_collocations) when
        // the displayed occurrence has them. Occurrence-level values override
        // sense-level values for the same keys; sense-level values remain as
        // fallback for keys the occurrence doesn't provide.
        $understandingAid = $this->normalizeUnderstandingAid($sense->understanding_aid);
        $understandingAid = $this->mergeOccurrenceEvidence(
            $understandingAid,
            $displayedOccurrenceId,
            $sense,
            $options['occurrence_evidence'] ?? null,
        );

        return [
            'review_card_id' => $card->id,
            'word_sense_id' => $sense->id,
            'lemma' => $sense->lemma,
            'surface_form' => $sense->surface_form,
            'pos' => $sense->pos,
            'sense_zh' => $sense->sense_zh,
            'sense_en' => $sense->sense_en,
            'aliases_zh' => $sense->aliases_zh ?: [],
            'collocations' => $sense->collocations ?: [],
            // Sense-level + occurrence-level merged understanding aid.
            // SenseReviewUnderstandingAid-1000-7 + SenseReviewContextualUnderstanding-1000-10.
            // Null-safe: always returns a stable normalized structure so the
            // frontend can render unconditionally even when both sense and
            // occurrence data are empty.
            'understanding_aid' => $understandingAid,
            'example_sentence_en' => $exampleSentenceEn,
            'example_sentence_zh' => $exampleSentenceZh,
            'example_sentence_translation_source' => $translationSource,
            'example_sentence_tokens' => $tokenPayload['tokens'],
            'example_sentence_token_source' => $tokenPayload['source'],
            'example_candidates' => $candidates,
            'example_candidates_count' => count($candidates),
            'supplementary_example' => $supplementaryExample,
            // SenseMultiExampleBindingAndReviewRotation-1000-6 fields.
            // displayed_occurrence_id lets the client (and future source
            // context dialog) know which occurrence is currently shown.
            // occurrence_count is the distinct source-example count.
            // example_source_status signals 'occurrence' / 'card_fallback' / 'empty'.
            'displayed_occurrence_id' => $displayedOccurrenceId,
            'occurrence_count' => $occurrenceCount,
            'example_source_status' => $exampleSourceStatus,
            'fsrs_state' => $card->fsrs_state,
            'fsrs_due_at' => $card->fsrs_due_at,
            'fsrs_stability' => $card->fsrs_stability,
            'fsrs_difficulty' => $card->fsrs_difficulty,
            'fsrs_reps' => $card->fsrs_reps,
            'fsrs_lapses' => $card->fsrs_lapses,
            // SenseReview-LearningFeedback-1000-1: read-only aggregate of
            // this card's ReviewLog history. Delegated to the dedicated
            // SenseReviewLearningFeedbackService (single source of truth for
            // ReviewLog aggregation, rating labels, and forgetting-pattern
            // trend). READ-ONLY: never writes ReviewLog, never touches any
            // FSRS field. Multi-user isolation is guaranteed by
            // review_card_id scoping (a card belongs to exactly one user).
            // Reset-type logs are excluded. Payload shape is unchanged.
            //
            // SenseReview-BatchFeedback-1000-1: when the caller supplies a
            // precomputed 'learning_feedback' in $options (produced by
            // buildForCards()), the serializer reuses it instead of issuing
            // a per-card query. This is how serializeMany() eliminates the
            // N+1 on the initial queue load.
            'learning_feedback' => $options['learning_feedback']
                ?? $this->feedbackService->buildForCard($card->id),
        ];
    }

    /**
     * Serialize a collection of ReviewCards into the frontend review card
     * payload list, using a SINGLE batch ReviewLog query for all cards'
     * learning feedback.
     *
     * SenseReview-BatchFeedback-1000-1
     *
     * This eliminates the per-card N+1 that occurred when the controller
     * mapped serialize() over the due-card queue. The batch query is issued
     * once via SenseReviewLearningFeedbackService::buildForCards(); each
     * card's precomputed feedback is then passed into serialize() via the
     * 'learning_feedback' option so the serializer never re-queries
     * ReviewLog.
     *
     * Query profile: exactly 1 ReviewLog query regardless of card count
     * (0 when the collection is empty). This is a constant-time improvement
     * over the old N-query pattern.
     *
     * @param  Collection  $cards   ReviewCards with loaded 'sense' relation.
     * @param  array       $options Optional: ['preferred_occurrence_id' => int|null]
     * @return list<array>  Serialized payloads, same shape as serialize().
     */
    public function serializeMany(Collection $cards, array $options = []): array
    {
        if ($cards->isEmpty()) {
            return [];
        }

        $cardIds = $cards->map(fn (ReviewCard $card) => $card->id)->all();
        $feedbackMap = $this->feedbackService->buildForCards($cardIds);
        $exampleBatch = $this->examplePoolService->exampleCandidateBatch(
            $cards->map(fn (ReviewCard $card) => $card->sense),
        );
        $candidateBySense = $exampleBatch['candidates'];
        $candidateMap = [];
        $chapterIds = [];
        $userIds = [];
        $languages = [];
        foreach ($cards as $card) {
            $candidates = $candidateBySense[$card->sense->id] ?? [];
            $candidateMap[$card->id] = $candidates;
            $userIds[] = $card->sense->user_id;
            $languages[] = $card->sense->language_id;
            foreach ($candidates as $candidate) {
                if (($candidate['chapter_id'] ?? null) !== null) {
                    $chapterIds[] = $candidate['chapter_id'];
                }
            }
        }
        $translationAssists = $this->translationAssistMap($chapterIds, $userIds, $languages);

        return $cards->map(function (ReviewCard $card) use ($feedbackMap, $candidateMap, $translationAssists, $exampleBatch, $options) {
            $perCardOptions = $options;
            $perCardOptions['learning_feedback'] = $feedbackMap[$card->id] ?? null;
            $perCardOptions['example_candidates'] = $candidateMap[$card->id];
            $perCardOptions['translation_assists'] = $translationAssists;
            $perCardOptions['token_chapters'] = $exampleBatch['chapters'];
            $perCardOptions['occurrence_evidence'] = $exampleBatch['occurrence_evidence'];

            return $this->serialize($card, $perCardOptions);
        })->values()->all();
    }

    private function translationForExample($sense, ?array $example, ?array $assists): array
    {
        if ($example === null) {
            return [null, null];
        }

        $identity = $this->exampleIdentityResolver->resolve(
            $example,
            (int) $sense->user_id,
            (string) $sense->language_id,
        );
        $chapterId = $identity['chapter_id'] ?? null;

        $key = $chapterId === null ? null : $this->translationAssistKey($sense->user_id, $sense->language_id, $chapterId);
        $assist = $assists === null ? null : ($assists[$key] ?? null);
        if ($assists === null && $chapterId !== null) {
            $assist = ChapterAiReadingAssist::query()
                ->where('user_id', $sense->user_id)
                ->where('language', $sense->language_id)
                ->where('chapter_id', $chapterId)
                ->first();
        }
        return $this->exampleIdentityResolver->translationFor(
            $example,
            $identity,
            $assist?->sentence_translations ?? [],
        );
    }

    private function translationAssistMap(array $chapterIds, array $userIds, array $languages): array
    {
        $chapterIds = array_values(array_unique(array_filter($chapterIds)));
        if ($chapterIds === []) {
            return [];
        }

        return ChapterAiReadingAssist::query()
            ->whereIn('chapter_id', $chapterIds)
            ->whereIn('user_id', array_values(array_unique($userIds)))
            ->whereIn('language', array_values(array_unique($languages)))
            ->get()
            ->mapWithKeys(fn (ChapterAiReadingAssist $assist) => [
                $this->translationAssistKey($assist->user_id, $assist->language, $assist->chapter_id) => $assist,
            ])
            ->all();
    }

    private function translationAssistKey(int $userId, string $language, int $chapterId): string
    {
        return $userId . '|' . $language . '|' . $chapterId;
    }

    /**
     * Normalize the understanding_aid JSON column into a stable structure so
     * the frontend can render unconditionally. Missing keys (when the column
     * is null or only partially populated) are filled with defaults. This is
     * read-only and never touches the database; it only shapes the payload.
     *
     * @param  array|null  $value  Raw value from the WordSense model.
     * @return array{explanation: ?string, meaning_boundary: ?string, context_hint: ?string, usage_keywords: array, related_collocations: array}
     */
    private function normalizeUnderstandingAid(?array $value): array
    {
        $value = is_array($value) ? $value : [];

        return [
            'explanation' => $value['explanation'] ?? null,
            'meaning_boundary' => $value['meaning_boundary'] ?? null,
            'context_hint' => $value['context_hint'] ?? null,
            'usage_keywords' => isset($value['usage_keywords']) && is_array($value['usage_keywords'])
                ? array_values($value['usage_keywords'])
                : [],
            'related_collocations' => isset($value['related_collocations']) && is_array($value['related_collocations'])
                ? array_values($value['related_collocations'])
                : [],
        ];
    }

    /**
     * Merge occurrence-level evidence into the sense-level understanding_aid.
     *
     * Occurrence evidence JSON may contain:
     *  - context_hint: overrides sense-level context_hint
     *  - judgment_basis: array of keywords → overrides usage_keywords
     *  - related_collocations: array → overrides related_collocations
     *
     * Keys NOT present in occurrence evidence keep their sense-level values.
     * This is read-only: it only reads the occurrence from the database (via
     * the sense's already-loaded relation or a fresh query) and shapes the
     * payload — it never writes.
     *
     * @param  array  $senseAid  Already-normalized sense-level aid.
     * @param  int|null  $occurrenceId  The displayed occurrence id (null = fallback).
     * @param  WordSense  $sense  The sense (for scoping the occurrence query).
     * @return array  Merged aid with occurrence-level overrides applied.
     */
    private function mergeOccurrenceEvidence(
        array $senseAid,
        ?int $occurrenceId,
        $sense,
        ?array $preloadedEvidence = null,
    ): array
    {
        if ($occurrenceId === null) {
            return $senseAid;
        }

        if ($preloadedEvidence !== null) {
            $evidence = $preloadedEvidence[$occurrenceId] ?? null;
        } else {
            $occurrence = \App\Models\WordSenseOccurrence::query()
                ->where('id', $occurrenceId)
                ->where('word_sense_id', $sense->id)
                ->first();
            $evidence = $occurrence?->evidence;
        }

        if (!is_array($evidence)) {
            return $senseAid;
        }

        // Occurrence-level overrides (only when the key exists and is non-null).
        if (isset($evidence['context_hint']) && $evidence['context_hint'] !== null) {
            $senseAid['context_hint'] = $evidence['context_hint'];
        }
        if (isset($evidence['judgment_basis']) && is_array($evidence['judgment_basis'])) {
            $senseAid['usage_keywords'] = array_values($evidence['judgment_basis']);
        }
        if (isset($evidence['related_collocations']) && is_array($evidence['related_collocations'])) {
            $senseAid['related_collocations'] = array_values($evidence['related_collocations']);
        }

        return $senseAid;
    }
}
