<?php

namespace App\Services\CustomStudy;

use App\Services\CustomStudy\Queries\SourceChapterQuery;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CustomStudyChapterOptionsService
{
    public function __construct(
        private readonly SourceChapterQuery $sourceChapterQuery,
    ) {
    }

    /**
     * Return every owned chapter that has at least one eligible sense card.
     * The union keeps one review card from being counted twice when its sense
     * is both directly sourced from and bound to the same chapter.
     */
    public function forUser(int $userId, string $language, Carbon $now): array
    {
        return DB::query()
            ->fromSub($this->sourceChapterQuery->eligibleChapterMatches($userId, $language, $now), 'chapter_matches')
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
