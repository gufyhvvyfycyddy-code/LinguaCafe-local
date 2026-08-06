<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseTag;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WordSenseTagService
{
    public const MAX_PER_LANGUAGE = 500;
    public const MAX_BULK_CARDS = 200;
    public const MAX_BULK_TAGS = 20;

    public function list(int $userId, string $language): Collection
    {
        return WordSenseTag::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->withCount(['senses' => function ($query) use ($userId, $language) {
                $query->where('word_senses.user_id', $userId)
                    ->where('word_senses.language_id', $language);
            }])
            ->orderBy('normalized_name')
            ->orderBy('id')
            ->get();
    }

    public function create(int $userId, string $language, string $name): WordSenseTag
    {
        [$displayName, $normalizedName] = $this->normalizeName($name);

        return DB::transaction(function () use ($userId, $language, $displayName, $normalizedName) {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

            if (WordSenseTag::query()
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->count() >= self::MAX_PER_LANGUAGE
            ) {
                throw ValidationException::withMessages([
                    'name' => 'You can create at most 500 tags per language.',
                ]);
            }

            return $this->persist(
                new WordSenseTag(),
                $userId,
                $language,
                $displayName,
                $normalizedName,
            );
        });
    }

    public function rename(int $tagId, int $userId, string $language, string $name): WordSenseTag
    {
        [$displayName, $normalizedName] = $this->normalizeName($name);

        return DB::transaction(function () use ($tagId, $userId, $language, $displayName, $normalizedName) {
            $tag = $this->findScoped($tagId, $userId, $language, true);

            return $this->persist($tag, $userId, $language, $displayName, $normalizedName);
        });
    }

    public function delete(int $tagId, int $userId, string $language): void
    {
        DB::transaction(function () use ($tagId, $userId, $language) {
            $tag = $this->findScoped($tagId, $userId, $language, true);

            DB::table('word_sense_tag_assignments')
                ->where('word_sense_tag_id', $tag->id)
                ->delete();
            $tag->delete();
        });
    }

    /**
     * @return array{review_card_count: int, word_sense_count: int, tag_count: int, action: string}
     */
    public function applyToReviewCards(
        int $userId,
        string $language,
        array $reviewCardIds,
        array $tagIds,
        string $action,
    ): array {
        if (!in_array($action, ['add', 'remove'], true)) {
            throw ValidationException::withMessages(['action' => 'The action must be add or remove.']);
        }

        $reviewCardIds = $this->boundedIds(
            $reviewCardIds,
            'review_card_ids',
            self::MAX_BULK_CARDS,
        );
        $tagIds = $this->boundedIds($tagIds, 'tag_ids', self::MAX_BULK_TAGS);

        return DB::transaction(function () use ($userId, $language, $reviewCardIds, $tagIds, $action) {
            $tags = WordSenseTag::query()
                ->whereIn('id', $tagIds)
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->lockForUpdate()
                ->get();

            if ($tags->count() !== count($tagIds)) {
                throw ValidationException::withMessages([
                    'tag_ids' => 'One or more tags are unavailable in the selected language.',
                ]);
            }

            $cards = ReviewCard::query()
                ->whereIn('id', $reviewCardIds)
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->where('target_type', ReviewCard::TARGET_SENSE)
                ->whereHas('sense', function ($query) use ($userId, $language) {
                    $query->where('user_id', $userId)
                        ->where('language_id', $language)
                        ->where('status', WordSense::STATUS_CONFIRMED);
                })
                ->lockForUpdate()
                ->get(['id', 'target_id']);

            if ($cards->count() !== count($reviewCardIds)) {
                throw ValidationException::withMessages([
                    'review_card_ids' => 'One or more review cards are unavailable in the selected language.',
                ]);
            }

            $senseIds = $cards->pluck('target_id')->unique()->values()->all();

            if ($action === 'add') {
                $now = now();
                $rows = [];
                foreach ($senseIds as $senseId) {
                    foreach ($tagIds as $tagId) {
                        $rows[] = [
                            'word_sense_id' => $senseId,
                            'word_sense_tag_id' => $tagId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
                DB::table('word_sense_tag_assignments')->insertOrIgnore($rows);
            } else {
                DB::table('word_sense_tag_assignments')
                    ->whereIn('word_sense_id', $senseIds)
                    ->whereIn('word_sense_tag_id', $tagIds)
                    ->delete();
            }

            return [
                'review_card_count' => count($reviewCardIds),
                'word_sense_count' => count($senseIds),
                'tag_count' => count($tagIds),
                'action' => $action,
            ];
        });
    }

    private function findScoped(
        int $tagId,
        int $userId,
        string $language,
        bool $lock = false,
    ): WordSenseTag {
        $query = WordSenseTag::query()
            ->whereKey($tagId)
            ->where('user_id', $userId)
            ->where('language_id', $language);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function persist(
        WordSenseTag $tag,
        int $userId,
        string $language,
        string $displayName,
        string $normalizedName,
    ): WordSenseTag {
        $tag->fill([
            'user_id' => $userId,
            'language_id' => $language,
            'name' => $displayName,
            'normalized_name' => $normalizedName,
        ]);

        try {
            $tag->save();
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages([
                    'name' => 'A tag with this name already exists.',
                ]);
            }
            throw $exception;
        }

        return $tag->fresh();
    }

    /**
     * @return array{string, string}
     */
    private function normalizeName(string $name): array
    {
        $displayName = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        $displayName = preg_replace('/\s*::\s*/u', '::', $displayName) ?? '';

        if ($displayName === '' || mb_strlen($displayName, 'UTF-8') > 80) {
            throw ValidationException::withMessages([
                'name' => 'The tag name must contain between 1 and 80 characters.',
            ]);
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $displayName)
            || collect(explode('::', $displayName))->contains(fn ($part) => $part === '')
        ) {
            throw ValidationException::withMessages([
                'name' => 'The tag name contains an invalid hierarchy segment.',
            ]);
        }

        $normalizedName = mb_strtolower($displayName, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $normalizedName = \Normalizer::normalize(
                $normalizedName,
                \Normalizer::FORM_KC,
            ) ?: $normalizedName;
        }

        return [$displayName, $normalizedName];
    }

    private function boundedIds(array $ids, string $field, int $maximum): array
    {
        if ($ids === [] || count($ids) > $maximum) {
            throw ValidationException::withMessages([
                $field => "The {$field} field must contain between 1 and {$maximum} IDs.",
            ]);
        }

        $normalized = [];
        foreach ($ids as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                throw ValidationException::withMessages([$field => "The {$field} field contains an invalid ID."]);
            }
            $value = (int) $id;
            if ($value < 1) {
                throw ValidationException::withMessages([$field => "The {$field} field contains an invalid ID."]);
            }
            $normalized[$value] = $value;
        }

        if (count($normalized) !== count($ids)) {
            throw ValidationException::withMessages([$field => "The {$field} field must not contain duplicate IDs."]);
        }

        ksort($normalized);

        return array_values($normalized);
    }
}
