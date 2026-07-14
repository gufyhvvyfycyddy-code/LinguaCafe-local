<?php

namespace App\Services\CustomStudy;

use App\Services\SenseReviewQueryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CustomStudyChapterOptionsService
{
    public function __construct(
        private readonly SenseReviewQueryService $senseReviewQueryService,
    ) {
    }

    /**
     * Return every owned chapter that has at least one eligible sense card.
     * The union keeps one review card from being counted twice when its sense
     * is both directly sourced from and bound to the same chapter.
     */
    public function forUser(int $userId, string $language, Carbon $now): array
    {
        $base = $this->senseReviewQueryService
            ->confirmedSenseCardQuery($userId, $language)
            ->senseReviewEligible($userId, $language, $now);

        $direct = (clone $base)
            ->select('review_cards.id as review_card_id', 'word_senses.source_chapter_id as chapter_id')
            ->whereNotNull('word_senses.source_chapter_id');

        $boundOccurrence = (clone $base)
            ->join('word_sense_occurrences as occurrences', function ($join) use ($userId, $language) {
                $join->on('occurrences.word_sense_id', '=', 'review_cards.target_id')
                    ->where('occurrences.user_id', $userId)
                    ->where('occurrences.language_id', $language)
                    ->where('occurrences.status', 'bound');
            })
            ->select('review_cards.id as review_card_id', 'occurrences.chapter_id as chapter_id')
            ->whereNotNull('occurrences.chapter_id');

        return DB::query()
            ->fromSub($direct->union($boundOccurrence), 'chapter_matches')
            ->join('chapters', 'chapters.id', '=', 'chapter_matches.chapter_id')
            ->leftJoin('books', function ($join) use ($userId, $language) {
                $join->on('books.id', '=', 'chapters.book_id')
                    ->where('books.user_id', $userId)
                    ->where('books.language', $language);
            })
            ->where('chapters.user_id', $userId)
            ->where('chapters.language', $language)
            ->groupBy('chapters.id', 'chapters.name', 'books.id', 'books.name')
            ->orderByRaw("COALESCE(books.name, '')")
            ->orderBy('chapters.name')
            ->orderBy('chapters.id')
            ->get([
                'chapters.id as chapter_id',
                'chapters.name as chapter_name',
                'books.id as book_id',
                'books.name as book_name',
                DB::raw('COUNT(DISTINCT chapter_matches.review_card_id) as candidate_count'),
            ])
            ->map(static fn ($row) => [
                'chapter_id' => (int) $row->chapter_id,
                'chapter_name' => $row->chapter_name,
                'book_id' => $row->book_id === null ? null : (int) $row->book_id,
                'book_name' => $row->book_name,
                'candidate_count' => (int) $row->candidate_count,
            ])
            ->all();
    }
}
