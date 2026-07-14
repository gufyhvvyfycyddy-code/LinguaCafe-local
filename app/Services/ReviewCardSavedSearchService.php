<?php

namespace App\Services;

use App\Models\ReviewCardSavedSearch;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewCardSavedSearchService
{
    public const MAX_PER_LANGUAGE = 50;
    public const FILTER_STATE_VERSION = 1;

    public function list(int $userId, string $language)
    {
        $rows = ReviewCardSavedSearch::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        if ($rows->contains(fn ($row) => $row->filter_state_version !== self::FILTER_STATE_VERSION)) {
            throw ValidationException::withMessages([
                'saved_searches' => 'A saved search uses an unsupported filter state version.',
            ]);
        }

        return $rows;
    }

    public function create(int $userId, string $language, string $name, array $filterState): ReviewCardSavedSearch
    {
        return DB::transaction(function () use ($userId, $language, $name, $filterState) {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

            if (ReviewCardSavedSearch::where('user_id', $userId)->where('language_id', $language)->count() >= self::MAX_PER_LANGUAGE) {
                throw ValidationException::withMessages(['name' => 'You can save at most 50 searches per language.']);
            }

            return $this->persist(new ReviewCardSavedSearch(), $userId, $language, $name, $filterState);
        });
    }

    public function update(int $id, int $userId, string $language, ?string $name, ?array $filterState): ReviewCardSavedSearch
    {
        return DB::transaction(function () use ($id, $userId, $language, $name, $filterState) {
            $row = $this->findScoped($id, $userId, $language, true);

            return $this->persist(
                $row,
                $userId,
                $language,
                $name ?? $row->name,
                $filterState ?? $row->filter_state,
            );
        });
    }

    public function delete(int $id, int $userId, string $language): void
    {
        $this->findScoped($id, $userId, $language)->delete();
    }

    private function findScoped(int $id, int $userId, string $language, bool $lock = false): ReviewCardSavedSearch
    {
        $query = ReviewCardSavedSearch::query()
            ->whereKey($id)
            ->where('user_id', $userId)
            ->where('language_id', $language);

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->firstOrFail();
        if ($row->filter_state_version !== self::FILTER_STATE_VERSION) {
            throw ValidationException::withMessages([
                'saved_searches' => 'This saved search uses an unsupported filter state version.',
            ]);
        }

        return $row;
    }

    private function persist(
        ReviewCardSavedSearch $row,
        int $userId,
        string $language,
        string $name,
        array $filterState,
    ): ReviewCardSavedSearch {
        $displayName = preg_replace('/\s+/u', ' ', trim($name));
        if ($displayName === '') {
            throw ValidationException::withMessages(['name' => 'The saved search name cannot be blank.']);
        }
        $normalizedName = mb_strtolower($displayName, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $normalizedName = \Normalizer::normalize($normalizedName, \Normalizer::FORM_KC) ?: $normalizedName;
        }
        $canonical = ReviewCardManageFilterState::fromArray($filterState)->toArray();

        $row->fill([
            'user_id' => $userId,
            'language_id' => $language,
            'name' => $displayName,
            'normalized_name' => $normalizedName,
            'filter_state_version' => self::FILTER_STATE_VERSION,
            'filter_state' => $canonical,
        ]);

        try {
            $row->save();
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages(['name' => 'A saved search with this name already exists.']);
            }
            throw $e;
        }

        return $row->fresh();
    }
}
