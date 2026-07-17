<?php

namespace App\Services;

use App\Models\WordSenseOccurrence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Owns the canonical Browser predicates for missing definition, example,
 * and source fields. Both the top-level management filters and the advanced
 * search token pipeline delegate here so their membership cannot drift.
 */
final class ReviewCardMissingFieldQueryApplier
{
    public const DEFINITION = 'definition';
    public const EXAMPLE = 'example';
    public const SOURCE = 'source';

    public const FIELDS = [
        self::DEFINITION,
        self::EXAMPLE,
        self::SOURCE,
    ];

    public function apply(
        Builder $query,
        string $field,
        int $userId,
        string $language,
    ): void {
        switch ($field) {
            case self::DEFINITION:
                $query->whereHas('sense', function ($senseQuery) {
                    $senseQuery
                        ->where(function ($definitionQuery) {
                            $definitionQuery->whereNull('sense_zh')->orWhere('sense_zh', '');
                        })
                        ->where(function ($definitionQuery) {
                            $definitionQuery->whereNull('sense_en')->orWhere('sense_en', '');
                        });
                });
                return;

            case self::EXAMPLE:
                $query->whereHas('sense', function ($senseQuery) {
                    $senseQuery->where(function ($exampleQuery) {
                        $exampleQuery
                            ->whereNull('example_sentence_en')
                            ->orWhere('example_sentence_en', '');
                    });
                });
                return;

            case self::SOURCE:
                $query
                    ->whereHas('sense', function ($senseQuery) {
                        $senseQuery->whereNull('source_chapter_id');
                    })
                    ->whereNotExists(function ($occurrenceQuery) use ($userId, $language) {
                        $occurrenceQuery
                            ->select(DB::raw(1))
                            ->from('word_sense_occurrences')
                            ->whereColumn('word_sense_occurrences.word_sense_id', 'review_cards.target_id')
                            ->where('word_sense_occurrences.user_id', $userId)
                            ->where('word_sense_occurrences.language_id', $language)
                            ->where('word_sense_occurrences.status', WordSenseOccurrence::STATUS_BOUND)
                            ->whereNotNull('word_sense_occurrences.chapter_id');
                    });
                return;

            default:
                $query->whereRaw('1 = 0');
        }
    }
}
