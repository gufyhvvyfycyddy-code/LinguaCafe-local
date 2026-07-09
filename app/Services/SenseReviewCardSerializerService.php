<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\ReviewLog;

class SenseReviewCardSerializerService
{
    public function __construct(
        private SenseTokenPayloadService $senseTokenPayloadService,
        private WordSenseExamplePoolService $examplePoolService,
    ) {
    }

    /**
     * Rating value → Chinese label shown in the learning feedback block.
     * Keep in sync with the frontend score-button labels (SenseReview.vue).
     * 'reset' is intentionally absent — reset logs are excluded from the
     * feedback aggregate, so they never need a label here.
     */
    private const RATING_LABELS = [
        'again' => '忘了',
        'hard'  => '勉强',
        'good'  => '记得',
        'easy'  => '很熟',
    ];

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
            // this card's ReviewLog history. Summarizes total counts, the
            // latest 5 non-reset reviews (newest first), and a recent-forget
            // count used by the frontend "容易忘记" hint. READ-ONLY: never
            // writes ReviewLog, never touches any FSRS field. Multi-user
            // isolation is guaranteed by review_card_id scoping (a card
            // belongs to exactly one user). Reset-type logs are excluded,
            // matching SenseReviewQueryService::nonResetSenseReviewLogQuery.
            'learning_feedback' => $this->buildLearningFeedback($card->id),
        ];
    }

    /**
     * Build the read-only learning feedback aggregate for one review card.
     *
     * Pulls only from the ReviewLog table; never writes. Excludes reset-type
     * logs (rating='reset' OR source='reset') so the aggregate reflects real
     * review attempts only, matching nonResetSenseReviewLogQuery.
     *
     * Shape:
     *   total_reviews:        int   — count of non-reset logs for this card
     *   forget_count:         int   — count where rating='again'
     *   hard_count:           int   — count where rating='hard'
     *   good_count:           int   — count where rating='good'
     *   easy_count:           int   — count where rating='easy'
     *   recent_reviews:       list  — latest 5 non-reset logs, newest first;
     *                                 each {rating, rating_label, date(Y-m-d)}
     *   recent_forget_count:  int   — count of 'again' among recent_reviews
     *
     * @param  int  $reviewCardId  The card whose logs to aggregate.
     * @return array{total_reviews: int, forget_count: int, hard_count: int,
     *               good_count: int, easy_count: int, recent_reviews: list,
     *               recent_forget_count: int}
     */
    private function buildLearningFeedback(int $reviewCardId): array
    {
        $baseQuery = ReviewLog::query()
            ->where('review_card_id', $reviewCardId)
            ->where('rating', '!=', 'reset')
            ->where('source', '!=', 'reset');

        $total = (clone $baseQuery)->count();
        $forgetCount = (clone $baseQuery)->where('rating', 'again')->count();
        $hardCount = (clone $baseQuery)->where('rating', 'hard')->count();
        $goodCount = (clone $baseQuery)->where('rating', 'good')->count();
        $easyCount = (clone $baseQuery)->where('rating', 'easy')->count();

        $recent = (clone $baseQuery)
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['rating', 'reviewed_at']);

        $recentReviews = [];
        $recentForgetCount = 0;
        foreach ($recent as $log) {
            $rating = $log->rating;
            if ($rating === 'again') {
                $recentForgetCount++;
            }
            $recentReviews[] = [
                'rating' => $rating,
                'rating_label' => self::RATING_LABELS[$rating] ?? $rating,
                'date' => $log->reviewed_at?->format('Y-m-d'),
            ];
        }

        // SenseReview-ForgettingPattern-1000-2: read-only forgetting-pattern
        // analysis derived from ReviewLog only. NEVER writes ReviewLog and
        // NEVER touches any FSRS field. Multi-user isolation is guaranteed by
        // the review_card_id scoping on $baseQuery (a card belongs to one user).
        //
        // forget_rate = again_count / total_reviews (0.0 when no reviews).
        $forgetRate = $total > 0 ? round($forgetCount / $total, 4) : 0.0;

        // Most recent 'again' log date (Y-m-d), null when never forgotten.
        $lastAgain = (clone $baseQuery)
            ->where('rating', 'again')
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->first();
        $lastForgetDate = $lastAgain?->reviewed_at?->format('Y-m-d');

        // Trend: take the latest 6 non-reset logs (newest first), reverse to
        // old→new, then split into early/late halves and compare 'again'
        // counts. <4 logs → 'insufficient' (not enough to compare halves).
        // Uses a symmetric split (3+3 when 6) so neither half is biased.
        $trendLogs = (clone $baseQuery)
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get(['rating'])
            ->reverse()
            ->values();
        $trend = $this->computeForgettingTrend($trendLogs);

        return [
            'total_reviews' => $total,
            'forget_count' => $forgetCount,
            'hard_count' => $hardCount,
            'good_count' => $goodCount,
            'easy_count' => $easyCount,
            'recent_reviews' => $recentReviews,
            'recent_forget_count' => $recentForgetCount,
            // forgetting_pattern summarizes how often and how recently the
            // user forgot this sense, plus a factual trend. The frontend uses
            // it to show an "遗忘情况" block with appropriate empty-state
            // handling for new cards and data-insufficient cards.
            'forgetting_pattern' => [
                'total_forget' => $forgetCount,
                'recent_forget_count' => $recentForgetCount,
                'forget_rate' => $forgetRate,
                'last_forget_date' => $lastForgetDate,
                'trend' => $trend,
            ],
        ];
    }

    /**
     * Compute the forgetting trend from a collection of recent non-reset
     * ReviewLog ratings ordered old→new.
     *
     * The collection is split into two halves:
     *   - early half: the older reviews (first floor(n/2) items)
     *   - late half:  the newer reviews (remaining items)
     * 'again' counts in each half are compared:
     *   - late < early → 'improving' (forgetting less over time)
     *   - late > early → 'declining' (forgetting more over time)
     *   - equal        → 'stable'
     *
     * When there are fewer than 4 logs the trend is 'insufficient' because
     * the two halves would be too small to compare meaningfully. This is a
     * factual comparison only — no AI, no guessing causes.
     *
     * @param  \Illuminate\Support\Collection  $logs  Ratings ordered old→new.
     * @return string  'improving' | 'declining' | 'stable' | 'insufficient'
     */
    private function computeForgettingTrend($logs): string
    {
        $n = $logs->count();
        if ($n < 4) {
            return 'insufficient';
        }

        $half = intdiv($n, 2);
        $earlyForget = $logs->slice(0, $half)->where('rating', 'again')->count();
        $lateForget = $logs->slice($half)->where('rating', 'again')->count();

        if ($lateForget < $earlyForget) {
            return 'improving';
        }
        if ($lateForget > $earlyForget) {
            return 'declining';
        }
        return 'stable';
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
