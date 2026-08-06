<?php

namespace App\Services\SpecialStudy;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\WordSenseTag;

final class SpecialStudyOptionsService
{
    public function get(int $userId, string $language): array
    {
        $articles = Book::query()
            ->where('user_id', $userId)
            ->where('language', $language)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);
        $articleNames = $articles->pluck('name', 'id');

        return [
            'tags' => WordSenseTag::query()
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->orderBy('normalized_name')
                ->get(['id', 'name'])
                ->values()
                ->all(),
            'markers' => [
                ['value' => ReviewCard::MARKER_NONE, 'label' => '无标记'],
                ['value' => ReviewCard::MARKER_RED, 'label' => '红色'],
                ['value' => ReviewCard::MARKER_ORANGE, 'label' => '橙色'],
                ['value' => ReviewCard::MARKER_GREEN, 'label' => '绿色'],
                ['value' => ReviewCard::MARKER_BLUE, 'label' => '蓝色'],
                ['value' => ReviewCard::MARKER_PINK, 'label' => '粉色'],
                ['value' => ReviewCard::MARKER_TURQUOISE, 'label' => '青色'],
                ['value' => ReviewCard::MARKER_PURPLE, 'label' => '紫色'],
            ],
            'articles' => $articles->map(fn (Book $book) => [
                'id' => (int) $book->id,
                'name' => $book->name,
            ])->values()->all(),
            'chapters' => Chapter::query()
                ->where('user_id', $userId)
                ->where('language', $language)
                ->orderBy('book_id')
                ->orderBy('id')
                ->get(['id', 'book_id', 'name'])
                ->map(fn (Chapter $chapter) => [
                    'id' => (int) $chapter->id,
                    'article_id' => (int) $chapter->book_id,
                    'article_name' => $articleNames->get($chapter->book_id),
                    'name' => $chapter->name,
                ])->values()->all(),
        ];
    }
}
