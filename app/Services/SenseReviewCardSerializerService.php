<?php

namespace App\Services;

use App\Models\ReviewCard;

class SenseReviewCardSerializerService
{
    public function __construct(
        private SenseTokenPayloadService $senseTokenPayloadService,
        private WordSenseExamplePoolService $examplePoolService,
    ) {
    }

    /**
     * Serialize a ReviewCard (with loaded 'sense' relation) into the
     * frontend review card payload.
     *
     * The question example is rotated across the sense's real-source example
     * pool using a stable seed (review_card_id + fsrs_reps + day-of-year) so
     * the same card does not always show the first example. A supplementary
     * example (different from the question) is also included for the answer
     * side; it is null when the pool has only one example.
     *
     * @return array{review_card_id: int, word_sense_id: int, lemma: string, ...}
     */
    public function serialize(ReviewCard $card): array
    {
        $sense = $card->sense;
        $tokenPayload = $this->senseTokenPayloadService->exampleSentenceTokenPayload($sense);
        $candidates = $this->examplePoolService->exampleCandidates($sense);

        $questionIndex = $this->examplePoolService->pickQuestionIndex(
            count($candidates),
            $card->id,
            (int) ($card->fsrs_reps ?? 0),
        );

        $questionExample = $candidates[$questionIndex] ?? null;
        $supplementaryIndex = $this->examplePoolService->pickSupplementaryIndex(
            count($candidates),
            $questionIndex,
            $card->id,
            (int) ($card->fsrs_reps ?? 0),
        );
        $supplementaryExample = $supplementaryIndex !== null ? $candidates[$supplementaryIndex] ?? null : null;

        // Use the rotated question example's sentence for the question side.
        // Fall back to the legacy single sentence if no candidates were found.
        $exampleSentenceEn = $questionExample['sentence_en'] ?? $sense->example_sentence_en;
        $exampleSentenceZh = $questionExample['sentence_zh'] ?? $sense->example_sentence_zh;

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
            'example_sentence_en' => $exampleSentenceEn,
            'example_sentence_zh' => $exampleSentenceZh,
            'example_sentence_tokens' => $tokenPayload['tokens'],
            'example_sentence_token_source' => $tokenPayload['source'],
            'example_candidates' => $candidates,
            'example_candidates_count' => count($candidates),
            'supplementary_example' => $supplementaryExample,
            'fsrs_state' => $card->fsrs_state,
            'fsrs_due_at' => $card->fsrs_due_at,
            'fsrs_stability' => $card->fsrs_stability,
            'fsrs_difficulty' => $card->fsrs_difficulty,
            'fsrs_reps' => $card->fsrs_reps,
            'fsrs_lapses' => $card->fsrs_lapses,
        ];
    }
}
