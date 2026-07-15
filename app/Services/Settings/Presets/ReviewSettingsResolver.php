<?php

namespace App\Services\Settings\Presets;

use App\Models\ReviewSettingPreset;
use App\Models\ReviewSettingPresetBinding;
use Illuminate\Support\Facades\DB;

class ReviewSettingsResolver
{
    public function __construct(
        private ReviewSettingsPresetService $presets,
        private ReviewSettingsPresetBindingService $bindings,
    ) {
    }

    public function resolve(int $userId, string $language): ReviewSettingsPresetConfig
    {
        return ReviewSettingsPresetConfig::fromArray($this->currentPreset($userId, $language)->config);
    }

    public function currentPreset(int $userId, string $language): ReviewSettingPreset
    {
        return $this->resolvePreset($userId, $language);
    }

    public function mutate(int $userId, string $language, array $patch): ReviewSettingsPresetConfig
    {
        $presetId = $this->currentPreset($userId, $language)->id;

        return DB::transaction(function () use ($userId, $presetId, $patch): ReviewSettingsPresetConfig {
            $preset = ReviewSettingPreset::whereKey($presetId)->lockForUpdate()->firstOrFail();
            if ((int) $preset->user_id !== $userId) {
                throw new \DomainException('Preset ownership changed during update.');
            }
            $config = ReviewSettingsPresetConfig::fromArray($preset->config)->withPatch($patch);
            $preset->config = $config->toArray();
            $preset->save();
            return $config;
        });
    }

    public function metadata(int $userId, string $language): array
    {
        $preset = $this->currentPreset($userId, $language);
        return [
            'name' => $preset->name,
            'is_default' => (bool) $preset->is_default,
            'language' => $this->bindings->normalizeLanguage($language),
            'schema_version' => ReviewSettingsPresetConfig::SCHEMA_VERSION,
        ];
    }

    private function resolvePreset(int $userId, string $language): ReviewSettingPreset
    {
        $language = $this->bindings->normalizeLanguage($language);
        $existing = ReviewSettingPresetBinding::with('preset')
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->first();
        if ($existing) {
            return $this->validatedBoundPreset($existing, $userId);
        }

        return DB::transaction(function () use ($userId, $language): ReviewSettingPreset {
            $binding = ReviewSettingPresetBinding::with('preset')
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->lockForUpdate()
                ->first();
            if ($binding) {
                return $this->validatedBoundPreset($binding, $userId);
            }

            $preset = $this->presets->defaultFor($userId);
            $binding = $this->bindings->bind($userId, $language, $preset);
            return $this->validatedBoundPreset($binding->load('preset'), $userId);
        });
    }

    private function validatedBoundPreset(ReviewSettingPresetBinding $binding, int $userId): ReviewSettingPreset
    {
        $preset = $binding->preset;
        if (!$preset || (int) $binding->user_id !== $userId || (int) $preset->user_id !== $userId) {
            throw new \DomainException('Invalid review settings preset binding.');
        }
        ReviewSettingsPresetConfig::fromArray($preset->config);
        return $preset;
    }
}
