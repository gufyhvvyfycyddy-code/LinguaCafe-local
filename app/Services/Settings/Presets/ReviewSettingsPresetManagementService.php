<?php

namespace App\Services\Settings\Presets;

use App\Exceptions\ReviewSettingsPresetException;
use App\Models\ReviewSettingPreset;
use App\Models\ReviewSettingPresetBinding;
use Illuminate\Support\Facades\DB;

class ReviewSettingsPresetManagementService
{
    public function __construct(
        private ReviewSettingsPresetService $presets,
        private ReviewSettingsPresetBindingService $bindings,
        private ReviewSettingsResolver $resolver,
    ) {
    }

    public function state(int $userId, string $language): array
    {
        $language = $this->bindings->normalizeLanguage($language);
        $current = $this->resolver->currentPreset($userId, $language);
        $presets = ReviewSettingPreset::query()
            ->where('user_id', $userId)
            ->with(['bindings' => fn ($query) => $query->orderBy('language_id')])
            ->orderByRaw('CASE WHEN is_default = 1 THEN 0 ELSE 1 END')
            ->orderBy('name')
            ->get()
            ->map(fn (ReviewSettingPreset $preset): array => [
                'id' => (int) $preset->id,
                'name' => $preset->name,
                'is_default' => (bool) $preset->is_default,
                'is_current' => (int) $preset->id === (int) $current->id,
                'bound_languages' => $preset->bindings->pluck('language_id')->values()->all(),
                'bound_language_count' => $preset->bindings->count(),
            ])
            ->values()
            ->all();

        return [
            'current_language' => $language,
            'current_preset_id' => (int) $current->id,
            'presets' => $presets,
        ];
    }

    public function create(int $userId, string $language, string $name): array
    {
        DB::transaction(fn () => $this->presets->createNamed($userId, $name));
        return $this->state($userId, $language);
    }

    public function clone(int $userId, string $language, int $sourcePresetId, string $name): array
    {
        DB::transaction(fn () => $this->presets->cloneNamed($userId, $sourcePresetId, $name));
        return $this->state($userId, $language);
    }

    public function rename(int $userId, string $language, int $presetId, string $name): array
    {
        DB::transaction(fn () => $this->presets->rename($userId, $presetId, $name));
        return $this->state($userId, $language);
    }

    public function switchCurrentLanguage(int $userId, string $language, int $presetId): array
    {
        $preset = $this->presets->findOwnedOrFail($userId, $presetId);
        $this->bindings->rebind($userId, $language, $preset);
        return $this->state($userId, $language);
    }

    public function delete(int $userId, string $language, int $presetId): array
    {
        DB::transaction(function () use ($userId, $presetId): void {
            $preset = $this->presets->findOwnedOrFail($userId, $presetId, true);
            if ($preset->is_default) {
                throw ReviewSettingsPresetException::validation('Default Preset 不能删除。');
            }

            $default = $this->presets->defaultFor($userId);
            ReviewSettingPresetBinding::where('user_id', $userId)
                ->where('preset_id', $preset->id)
                ->update(['preset_id' => $default->id, 'updated_at' => now()]);
            $preset->delete();
        });

        return $this->state($userId, $language);
    }
}
