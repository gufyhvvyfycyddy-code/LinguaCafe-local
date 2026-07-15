<?php

namespace App\Services\Settings\Presets;

use App\Models\ReviewSettingPreset;
use App\Models\ReviewSettingPresetBinding;
use Illuminate\Support\Facades\DB;

class ReviewSettingsPresetBindingService
{
    public function bind(int $userId, string $language, ReviewSettingPreset $preset): ReviewSettingPresetBinding
    {
        $language = $this->normalizeLanguage($language);
        if ((int) $preset->user_id !== $userId) {
            throw new \DomainException('Cannot bind a preset owned by another user.');
        }

        $existing = ReviewSettingPresetBinding::where('user_id', $userId)
            ->where('language_id', $language)
            ->first();
        if ($existing) {
            if ((int) $existing->preset_id !== (int) $preset->id) {
                throw new \DomainException('The language already has a different preset binding.');
            }
            return $existing;
        }

        $binding = ReviewSettingPresetBinding::query()->createOrFirst(
            ['user_id' => $userId, 'language_id' => $language],
            ['preset_id' => $preset->id],
        );
        if ((int) $binding->preset_id !== (int) $preset->id) {
            throw new \DomainException('The language binding changed during initialization.');
        }
        return $binding;
    }

    public function rebind(int $userId, string $language, ReviewSettingPreset $preset): ReviewSettingPresetBinding
    {
        $language = $this->normalizeLanguage($language);
        if ((int) $preset->user_id !== $userId) {
            throw new \DomainException('Cannot bind a preset owned by another user.');
        }

        return DB::transaction(function () use ($userId, $language, $preset): ReviewSettingPresetBinding {
            $binding = ReviewSettingPresetBinding::where('user_id', $userId)
                ->where('language_id', $language)
                ->lockForUpdate()
                ->first();

            if (!$binding) {
                return ReviewSettingPresetBinding::create([
                    'user_id' => $userId,
                    'language_id' => $language,
                    'preset_id' => $preset->id,
                ]);
            }

            $binding->preset_id = $preset->id;
            $binding->save();
            return $binding->fresh();
        });
    }

    public function normalizeLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        if ($language === '' || strlen($language) > 64 || !preg_match('/^[a-z][a-z0-9_-]*$/', $language)) {
            throw new \InvalidArgumentException('Invalid learning language.');
        }
        return $language;
    }
}
