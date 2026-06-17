<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\WordSense;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class WordSenseService
{
    public function __construct(private ReviewCardService $reviewCardService)
    {
    }

    public function createSense(array $data): WordSense
    {
        $data = $this->normalizeSenseData($data);

        return WordSense::create($data);
    }

    public function findBySenseKey(int $userId, string $language, string $senseKey): ?WordSense
    {
        return WordSense::where('user_id', $userId)
            ->where('language_id', $language)
            ->where('sense_key', $senseKey)
            ->first();
    }

    public function findByAlias(int $userId, string $language, string $alias): ?WordSense
    {
        $normalizedAlias = $this->normalizeText($alias);

        return WordSense::where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', '<>', WordSense::STATUS_REJECTED)
            ->get()
            ->first(function (WordSense $sense) use ($normalizedAlias) {
                foreach ($sense->aliases_zh ?: [] as $alias) {
                    if ($this->normalizeText($alias) === $normalizedAlias) {
                        return true;
                    }
                }

                return false;
            });
    }

    public function createOrFindSense(array $data): WordSense
    {
        $data = $this->normalizeSenseData($data);
        $existing = $this->findBySenseKey($data['user_id'], $data['language_id'], $data['sense_key']);

        if ($existing) {
            return $existing;
        }

        foreach ($data['aliases_zh'] ?: [] as $alias) {
            $existing = $this->findByAlias($data['user_id'], $data['language_id'], $alias);
            if ($existing) {
                return $existing;
            }
        }

        return WordSense::create($data);
    }

    public function createReviewCardForSense(WordSense $sense): ?ReviewCard
    {
        return $this->reviewCardService->ensureSenseCard($sense);
    }

    public function rejectSense(WordSense $sense): WordSense
    {
        $sense->status = WordSense::STATUS_REJECTED;
        $sense->save();

        return $sense;
    }

    public function confirmSense(WordSense $sense): WordSense
    {
        $sense->status = WordSense::STATUS_CONFIRMED;
        $sense->save();

        return $sense;
    }

    private function normalizeSenseData(array $data): array
    {
        $language = Arr::get($data, 'language_id', Arr::get($data, 'language'));
        $data['language'] = $language;
        $data['language_id'] = $language;
        $data['lemma'] = $this->normalizeText($data['lemma']);
        $data['surface_form'] = Arr::get($data, 'surface_form', $data['lemma']);
        $data['pos'] = Arr::get($data, 'pos');
        $data['aliases_zh'] = $this->normalizeArray(Arr::get($data, 'aliases_zh', []));
        $data['collocations'] = $this->normalizeArray(Arr::get($data, 'collocations', []));
        $data['status'] = Arr::get($data, 'status', WordSense::STATUS_CONFIRMED);
        $data['is_context_specific'] = Arr::get($data, 'is_context_specific', true);
        $data['sense_key'] = Arr::get($data, 'sense_key') ?: $this->generateSenseKey($data);

        return $data;
    }

    private function generateSenseKey(array $data): string
    {
        $parts = [
            $data['language_id'],
            $data['lemma'],
            $data['pos'] ?: '',
            Arr::get($data, 'sense_zh', ''),
            Arr::get($data, 'sense_en', ''),
        ];

        return hash('sha256', Str::lower(implode('|', array_map([$this, 'normalizeText'], $parts))));
    }

    private function normalizeArray(array $values): array
    {
        return array_values(array_filter(array_map([$this, 'normalizeText'], $values), fn ($value) => $value !== ''));
    }

    private function normalizeText(?string $value): string
    {
        return trim(mb_strtolower((string) $value));
    }
}
