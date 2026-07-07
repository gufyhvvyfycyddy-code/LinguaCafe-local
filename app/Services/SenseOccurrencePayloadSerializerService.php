<?php

namespace App\Services;

use App\Models\WordSense;
use App\Models\WordSenseOccurrence;

/**
 * Stable HTTP payload serializer for sense occurrence management endpoints.
 *
 * Kept outside SenseOccurrenceController so the controller remains a request
 * boundary and does not own response shape details.
 */
class SenseOccurrencePayloadSerializerService
{
    public function serializeOccurrence(WordSenseOccurrence $occurrence): array
    {
        return [
            'occurrence_id' => $occurrence->id,
            'sentence_en' => $occurrence->sentence_en,
            'sentence_zh' => $occurrence->sentence_zh,
            'surface' => $occurrence->surface,
            'lemma' => $occurrence->lemma,
            'pos' => $occurrence->pos,
            'decision' => $occurrence->decision,
            'confidence' => $occurrence->confidence,
            'evidence' => $occurrence->evidence,
            'status' => $occurrence->status,
            'auto_fsrs_allowed' => $occurrence->auto_fsrs_allowed,
            'sense' => $occurrence->wordSense ? $this->serializeSense($occurrence->wordSense) : null,
            'raw_payload' => $occurrence->raw_payload,
        ];
    }

    public function serializeSense(WordSense $sense): array
    {
        return [
            'sense_id' => $sense->id,
            'lemma' => $sense->lemma,
            'pos' => $sense->pos,
            'sense_key' => $sense->sense_key,
            'sense_zh' => $sense->sense_zh,
            'sense_en' => $sense->sense_en,
            'aliases_zh' => $sense->aliases_zh ?: [],
            'collocations' => $sense->collocations ?: [],
            'status' => $sense->status,
            'fsrs_state' => $sense->reviewCard?->fsrs_state,
            'review_card_id' => $sense->reviewCard?->id,
            'fsrs_enabled' => $sense->reviewCard?->fsrs_enabled,
            'fsrs_due_at' => $sense->reviewCard?->fsrs_due_at,
            'fsrs_stability' => $sense->reviewCard?->fsrs_stability,
            'fsrs_difficulty' => $sense->reviewCard?->fsrs_difficulty,
            'fsrs_reps' => $sense->reviewCard?->fsrs_reps,
            'fsrs_lapses' => $sense->reviewCard?->fsrs_lapses,
        ];
    }

    public function normalizeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value), fn ($item) => $item !== ''));
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($item) => $item !== ''));
    }
}
