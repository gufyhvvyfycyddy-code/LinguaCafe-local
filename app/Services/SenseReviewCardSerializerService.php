<?php

namespace App\Services;

use App\Models\ReviewCard;

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
     * @param  array  $options  Optional: ['preferred_occurrence_id' => int|null]
     * @return array{review_card_id: int, word_sense_id: int, lemma: string, ...}
     */
    public function serialize(ReviewCard $card, array $options = []): array
    {
        $sense = $card->sense;
        $tokenPayload = $this->senseTokenPayloadService->exampleSentenceTokenPayload($sense);
        $candidates = $this->examplePoolService->exampleCandidates($sense);

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

        // Use the rotated question example's sentence for the question side.
        // Fall back to the legacy single sentence if no candidates were found.
        $exampleSentenceEn = $questionExample['sentence_en'] ?? $sense->example_sentence_en;
        $exampleSentenceZh = $questionExample['sentence_zh'] ?? $sense->example_sentence_zh;

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
        $understandingAid = $this->mergeOccurrenceEvidence($understandingAid, $displayedOccurrenceId, $sense);

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
            'learning_feedback' => $this->feedbackService->buildForCard($card->id),
        ];
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
    private function mergeOccurrenceEvidence(array $senseAid, ?int $occurrenceId, $sense): array
    {
        if ($occurrenceId === null) {
            return $senseAid;
        }

        // Load the occurrence scoped to the sense to prevent cross-sense leak.
        $occurrence = \App\Models\WordSenseOccurrence::query()
            ->where('id', $occurrenceId)
            ->where('word_sense_id', $sense->id)
            ->first();

        if (!$occurrence || !is_array($occurrence->evidence)) {
            return $senseAid;
        }

        $evidence = $occurrence->evidence;

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
