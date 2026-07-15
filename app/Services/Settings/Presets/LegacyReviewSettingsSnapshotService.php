<?php

namespace App\Services\Settings\Presets;

use App\Models\Setting;

class LegacyReviewSettingsSnapshotService
{
    private const KEYS = [
        'fsrsDesiredRetention',
        'fsrs_parameters',
        'fsrs_parameters_source',
        'fsrs_parameters_optimized_at',
        'daily_new_limit_enabled',
        'daily_new_limit',
        'daily_review_limit_enabled',
        'daily_review_limit',
        'new_cards_ignore_review_limit',
        'fsrs_queue_interday_learning_review_order',
        'fsrs_queue_new_review_order',
        'fsrs_queue_review_sort_order',
        'fsrs_queue_new_sort_order',
    ];

    public function capture(): ReviewSettingsPresetConfig
    {
        $values = Setting::where('user_id', -1)
            ->whereIn('name', self::KEYS)
            ->get(['name', 'value'])
            ->mapWithKeys(fn (Setting $setting): array => [$setting->name => $this->decode($setting->value)])
            ->all();

        $defaults = ReviewSettingsPresetConfig::defaults();
        $base = $defaults->toArray();
        $legacyParameters = $values['fsrs_parameters'] ?? null;
        $parametersValid = $this->parametersValid($legacyParameters);
        $source = $parametersValid && in_array($values['fsrs_parameters_source'] ?? null, ['default', 'optimized', 'custom'], true)
            ? $values['fsrs_parameters_source'] : 'default';
        $patch = [
            'fsrs' => [
                'desired_retention' => $this->retention($values['fsrsDesiredRetention'] ?? null),
                'parameters' => $this->parameters($legacyParameters, $base['fsrs']['parameters']),
                'parameters_source' => $source,
                'parameters_optimized_at' => $source === 'optimized' && is_string($values['fsrs_parameters_optimized_at'] ?? null)
                    ? $values['fsrs_parameters_optimized_at'] : null,
            ],
            'daily_limits' => [
                'new_cards_enabled' => $this->boolean($values['daily_new_limit_enabled'] ?? null, true),
                'new_cards_per_day' => $this->integer($values['daily_new_limit'] ?? null, 20, 0, 999),
                'reviews_enabled' => $this->boolean($values['daily_review_limit_enabled'] ?? null, true),
                'maximum_reviews_per_day' => $this->integer($values['daily_review_limit'] ?? null, 200, 0, 9999),
                'new_cards_ignore_review_limit' => $this->boolean($values['new_cards_ignore_review_limit'] ?? null, false),
            ],
            'queue_order' => [
                'interday_learning_review_order' => $this->enum($values['fsrs_queue_interday_learning_review_order'] ?? null, ['mix', 'before', 'after'], 'mix'),
                'new_review_order' => $this->enum($values['fsrs_queue_new_review_order'] ?? null, ['mix', 'before', 'after'], 'mix'),
                'review_sort_order' => $this->enum($values['fsrs_queue_review_sort_order'] ?? null, ['due_random', 'due_stable', 'ascending_retrievability', 'random'], 'due_random'),
                'new_sort_order' => $this->enum($values['fsrs_queue_new_sort_order'] ?? null, ['created_asc', 'created_desc', 'random'], 'created_asc'),
            ],
        ];

        try {
            return $defaults->withPatch($patch);
        } catch (\InvalidArgumentException) {
            return $defaults;
        }
    }

    private function decode(?string $value): mixed
    {
        $decoded = json_decode((string) $value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function retention(mixed $value): float
    {
        return is_numeric($value) ? max(0.70, min(0.97, (float) $value)) : 0.90;
    }

    private function parameters(mixed $value, array $fallback): array
    {
        if (!$this->parametersValid($value)) return $fallback;
        return array_map('floatval', array_values($value));
    }

    private function parametersValid(mixed $value): bool
    {
        if (!is_array($value) || count($value) < 19 || count($value) > 21) return false;
        foreach ($value as $item) {
            if (!is_numeric($item) || !is_finite((float) $item) || abs((float) $item) > 1000) return false;
        }
        return true;
    }

    private function boolean(mixed $value, bool $fallback): bool
    {
        return is_bool($value) ? $value : $fallback;
    }

    private function integer(mixed $value, int $fallback, int $min, int $max): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) return $fallback;
        return max($min, min($max, (int) $value));
    }

    private function enum(mixed $value, array $allowed, string $fallback): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
    }
}
