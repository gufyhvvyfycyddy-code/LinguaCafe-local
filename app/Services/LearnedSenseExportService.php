<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\WordSense;
use Carbon\Carbon;

class LearnedSenseExportService
{
    public function payload(int $userId, string $language, ?int $limit = null): array
    {
        $senses = $this->query($userId, $language)
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();

        return [
            'schema_version' => 1,
            'exported_at' => Carbon::now()->toIso8601String(),
            'user_id' => $userId,
            'language' => $language,
            'senses' => $senses->map(fn (WordSense $sense) => $this->serializeSense($sense))->values()->all(),
            'total_available' => $this->count($userId, $language),
        ];
    }

    public function count(int $userId, string $language): int
    {
        return $this->query($userId, $language)->count();
    }

    private function query(int $userId, string $language)
    {
        return WordSense::query()
            ->select('word_senses.*')
            ->join('review_cards', function ($join) {
                $join->on('review_cards.target_id', '=', 'word_senses.id')
                    ->where('review_cards.target_type', ReviewCard::TARGET_SENSE);
            })
            ->where('word_senses.user_id', $userId)
            ->where('word_senses.language_id', $language)
            ->where('word_senses.status', WordSense::STATUS_CONFIRMED)
            ->with('reviewCard')
            ->orderBy('word_senses.lemma')
            ->orderBy('word_senses.id');
    }

    private function serializeSense(WordSense $sense): array
    {
        $card = $sense->reviewCard;

        return [
            'sense_id' => $sense->id,
            'lemma' => $sense->lemma,
            'surface_examples' => array_values(array_filter([$sense->surface_form])),
            'pos' => $sense->pos,
            'sense_key' => $sense->sense_key,
            'sense_zh' => $sense->sense_zh,
            'aliases_zh' => $sense->aliases_zh ?: [],
            'sense_en' => $sense->sense_en,
            'collocations' => $sense->collocations ?: [],
            'example_sentences' => array_values(array_filter([
                [
                    'en' => $sense->example_sentence_en,
                    'zh' => $sense->example_sentence_zh,
                ],
            ], fn ($sentence) => $sentence['en'] !== null || $sentence['zh'] !== null)),
            'fsrs_state' => $card?->fsrs_state,
            'learned_status' => $card && $card->fsrs_reps > 0 ? 'reviewed' : 'scheduled',
        ];
    }
}
