<?php

namespace App\Services\Settings;

use App\Models\Setting;
use App\Services\Settings\Presets\ReviewSettingsResolver;

class SettingValueService
{
    private const PRESET_KEYS = [
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
        'reviewSettingsPresetMetadata',
    ];

    public function __construct(private ReviewSettingsResolver $reviewSettings)
    {
    }

    public function isJellyfinEnabled()
    {
        $setting = Setting::select('value', 'name')
            ->where('user_id', -1)
            ->where('name', 'jellyfinEnabled')
            ->first();

        if (!$setting) {
            throw new \Exception('Missing jellyfinEnabled setting. This should never occur.');
        }

        return json_decode($setting->value);
    }

    public function getAnkiSettings()
    {
        $settings = Setting::select('value', 'name')
            ->where('user_id', -1)
            ->whereIn('name', ['ankiAutoAddCards', 'ankiShowNotifications'])
            ->get()
            ->keyBy('name')
            ->map(fn ($item) => json_decode($item->value));

        if ($settings->isEmpty()) {
            throw new \Exception('Missing anki settings. This should never occur.');
        }

        return $settings;
    }

    public function getGlobalSettingsByName($settingNames, ?int $userId = null, ?string $language = null)
    {
        $settingNames = array_values($settingNames);
        $presetNames = array_values(array_intersect($settingNames, self::PRESET_KEYS));
        $globalNames = array_values(array_diff($settingNames, self::PRESET_KEYS));
        $result = collect();

        if ($presetNames !== []) {
            $this->requirePresetContext($userId, $language);
            $result = $result->merge($this->presetValues($userId, $language, $presetNames));
        }

        if ($globalNames === []) {
            return $result;
        }

        $settings = Setting::select('value', 'name')
            ->where('user_id', -1)
            ->whereIn('name', $globalNames)
            ->get()
            ->keyBy('name')
            ->map(fn ($item) => json_decode($item->value));

        if ($settings->isEmpty() && $result->isEmpty()) {
            throw new \Exception('No settings were found in the database.');
        }

        return $result->merge($settings);
    }

    public function updateGlobalSettings($settings, ?int $userId = null, ?string $language = null): bool
    {
        if (array_key_exists('reviewSettingsPresetMetadata', $settings)) {
            throw new \InvalidArgumentException('Review settings preset metadata is read-only.');
        }

        $presetSettings = array_intersect_key($settings, array_flip(self::PRESET_KEYS));
        if ($presetSettings !== []) {
            $this->requirePresetContext($userId, $language);
            $this->reviewSettings->mutate($userId, $language, $this->presetPatch($presetSettings));
        }

        foreach (array_diff_key($settings, array_flip(self::PRESET_KEYS)) as $settingName => $settingValue) {
            $setting = Setting::where('name', $settingName)
                ->where('user_id', -1)
                ->first();

            if ($setting) {
                $setting->value = json_encode($settingValue);
                $setting->save();
            }
        }

        return true;
    }

    private function presetValues(int $userId, string $language, array $names): array
    {
        $config = $this->reviewSettings->resolve($userId, $language);
        $array = $config->toArray();
        $limits = $config->dailyLimitsForApi();
        $queue = $config->queueOrderForApi();
        $available = [
            'fsrsDesiredRetention' => $config->fsrsDesiredRetention(),
            'fsrs_parameters' => $config->fsrsParameters(),
            'fsrs_parameters_source' => $array['fsrs']['parameters_source'],
            'fsrs_parameters_optimized_at' => $array['fsrs']['parameters_optimized_at'],
            'daily_new_limit_enabled' => $limits['daily_new_limit_enabled'],
            'daily_new_limit' => $limits['daily_new_limit'],
            'daily_review_limit_enabled' => $limits['daily_review_limit_enabled'],
            'daily_review_limit' => $limits['daily_review_limit'],
            'new_cards_ignore_review_limit' => $limits['new_cards_ignore_review_limit'],
            'fsrs_queue_interday_learning_review_order' => $queue['interday_learning_review_order'],
            'fsrs_queue_new_review_order' => $queue['new_review_order'],
            'fsrs_queue_review_sort_order' => $queue['review_sort_order'],
            'fsrs_queue_new_sort_order' => $queue['new_sort_order'],
        ];
        if (in_array('reviewSettingsPresetMetadata', $names, true)) {
            $available['reviewSettingsPresetMetadata'] = $this->reviewSettings->metadata($userId, $language);
        }
        return array_intersect_key($available, array_flip($names));
    }

    private function presetPatch(array $settings): array
    {
        $patch = [];
        $map = [
            'fsrsDesiredRetention' => ['fsrs', 'desired_retention'],
            'fsrs_parameters' => ['fsrs', 'parameters'],
            'fsrs_parameters_source' => ['fsrs', 'parameters_source'],
            'fsrs_parameters_optimized_at' => ['fsrs', 'parameters_optimized_at'],
            'daily_new_limit_enabled' => ['daily_limits', 'new_cards_enabled'],
            'daily_new_limit' => ['daily_limits', 'new_cards_per_day'],
            'daily_review_limit_enabled' => ['daily_limits', 'reviews_enabled'],
            'daily_review_limit' => ['daily_limits', 'maximum_reviews_per_day'],
            'new_cards_ignore_review_limit' => ['daily_limits', 'new_cards_ignore_review_limit'],
            'fsrs_queue_interday_learning_review_order' => ['queue_order', 'interday_learning_review_order'],
            'fsrs_queue_new_review_order' => ['queue_order', 'new_review_order'],
            'fsrs_queue_review_sort_order' => ['queue_order', 'review_sort_order'],
            'fsrs_queue_new_sort_order' => ['queue_order', 'new_sort_order'],
        ];
        foreach ($settings as $name => $value) {
            [$section, $key] = $map[$name];
            $patch[$section][$key] = $value;
        }
        return $patch;
    }

    private function requirePresetContext(?int $userId, ?string $language): void
    {
        if (!$userId || !$language) {
            throw new \LogicException('Preset-owned settings require user and language context.');
        }
    }

    public function getUserSettingsByName($userId, $settingNames)
    {
        $settings = Setting::select('value', 'name')
            ->where('user_id', $userId)
            ->whereIn('name', $settingNames)
            ->get()
            ->keyBy('name')
            ->map(fn ($item) => json_decode($item->value));

        return $settings->isEmpty() ? null : $settings;
    }

    public function updateUserSettings($userId, $settings): bool
    {
        foreach ($settings as $settingName => $settingValue) {
            $setting = Setting::where('name', $settingName)
                ->where('user_id', $userId)
                ->first();

            if (!$setting) {
                $setting = new Setting();
                $setting->user_id = $userId;
                $setting->name = $settingName;
            }

            $setting->value = json_encode($settingValue);
            $setting->save();
        }

        return true;
    }

    public function decodeGlobal(string $name, mixed $fallback = null): mixed
    {
        $setting = Setting::where('name', $name)
            ->where('user_id', -1)
            ->first();

        if (!$setting || $setting->value === null) {
            return $fallback;
        }

        $decoded = json_decode($setting->value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $setting->value;
    }

    public function upsertGlobal(string $name, mixed $value): void
    {
        $setting = Setting::where('name', $name)
            ->where('user_id', -1)
            ->first();

        if (!$setting) {
            $setting = new Setting();
            $setting->user_id = -1;
            $setting->name = $name;
        }

        $setting->value = json_encode($value);
        $setting->save();
    }

    public function deleteGlobal(string $name): int
    {
        return Setting::where('user_id', -1)->where('name', $name)->delete();
    }
}
