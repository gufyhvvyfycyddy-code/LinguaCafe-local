<?php

namespace App\Services;

use App\Models\WordSense;

class WordSenseLibraryQueryService
{
    public function page(int $userId, string $language, ?string $q = null, int $page = 1, int $perPage = 20): array
    {
        $q = trim($q ?? '');

        $query = WordSense::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', WordSense::STATUS_CONFIRMED);

        if ($q !== '') {
            $likeQuery = addcslashes($q, '\\%_');

            $query->where(function ($query) use ($likeQuery) {
                $query->where('lemma', 'like', "%{$likeQuery}%")
                    ->orWhere('sense_zh', 'like', "%{$likeQuery}%")
                    ->orWhere('sense_en', 'like', "%{$likeQuery}%");
            });
        }

        $paginator = $query
            ->orderBy('lemma')
            ->orderBy('id')
            ->paginate($perPage, ['id', 'lemma', 'pos', 'sense_zh', 'sense_en'], 'page', $page);

        if ($page > $paginator->lastPage()) {
            $paginator = $query->paginate(
                $perPage,
                ['id', 'lemma', 'pos', 'sense_zh', 'sense_en'],
                'page',
                $paginator->lastPage(),
            );
        }

        $data = array_map(static fn (WordSense $sense): array => [
            'sense_id' => $sense->id,
            'lemma' => $sense->lemma,
            'pos' => $sense->pos,
            'sense_zh' => $sense->sense_zh,
            'sense_en' => $sense->sense_en,
        ], $paginator->items());

        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
