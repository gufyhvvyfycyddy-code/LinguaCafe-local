<?php

namespace App\Services;

use App\Models\WordSense;
use App\Models\WordSenseOccurrence;

class SenseOccurrenceExampleService
{
    /**
     * Return example sentences for a WordSense owned by the given user + language.
     *
     * @return array{sense_id: int, lemma: string, occurrences: array}
     */
    public function getExamples(int $userId, string $language, int $senseId): array
    {
        $sense = WordSense::where('id', $senseId)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->firstOrFail();

        $occurrences = WordSenseOccurrence::where('user_id', $userId)
            ->where('language_id', $language)
            ->where('word_sense_id', $sense->id)
            ->whereNotNull('sentence_en')
            ->where('sentence_en', '<>', '')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        return [
            'sense_id' => $sense->id,
            'lemma' => $sense->lemma,
            'occurrences' => $occurrences->map(fn (WordSenseOccurrence $o) => [
                'occurrence_id' => $o->id,
                'sentence_en' => $o->sentence_en,
                'sentence_zh' => $o->sentence_zh,
                'surface' => $o->surface,
                'chapter_id' => $o->chapter_id,
                'status' => $o->status,
                'created_at' => $o->created_at?->toISOString(),
            ])->values()->all(),
        ];
    }
}
