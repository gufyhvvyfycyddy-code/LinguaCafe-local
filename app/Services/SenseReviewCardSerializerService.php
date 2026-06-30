<?php

namespace App\Services;

use App\Models\ReviewCard;

class SenseReviewCardSerializerService
{
    public function __construct(
        private SenseTokenPayloadService $senseTokenPayloadService,
    ) {
    }

    /**
     * Serialize a ReviewCard (with loaded 'sense' relation) into the
     * frontend review card payload.
     *
     * @return array{review_card_id: int, word_sense_id: int, lemma: string, ...}
     */
    public function serialize(ReviewCard $card): array
    {
        $sense = $card->sense;
        $tokenPayload = $this->senseTokenPayloadService->exampleSentenceTokenPayload($sense);

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
            'example_sentence_en' => $sense->example_sentence_en,
            'example_sentence_zh' => $sense->example_sentence_zh,
            'example_sentence_tokens' => $tokenPayload['tokens'],
            'example_sentence_token_source' => $tokenPayload['source'],
            'fsrs_state' => $card->fsrs_state,
            'fsrs_due_at' => $card->fsrs_due_at,
            'fsrs_stability' => $card->fsrs_stability,
            'fsrs_difficulty' => $card->fsrs_difficulty,
            'fsrs_reps' => $card->fsrs_reps,
            'fsrs_lapses' => $card->fsrs_lapses,
        ];
    }
}
