<?php

namespace App\Services\Settings\Presets;

use App\Models\ReviewSettingPreset;

class ReviewSettingsPresetService
{
    public const DEFAULT_NAME = 'Default';

    public function __construct(private LegacyReviewSettingsSnapshotService $legacySnapshot)
    {
    }

    public function defaultFor(int $userId): ReviewSettingPreset
    {
        $existing = ReviewSettingPreset::where('user_id', $userId)
            ->where('is_default', true)
            ->first();
        if ($existing) {
            return $this->validateDefault($existing);
        }

        $candidate = [
            'config' => $this->legacySnapshot->capture()->toArray(),
            'is_default' => true,
        ];
        $preset = ReviewSettingPreset::query()->createOrFirst(
            ['user_id' => $userId, 'name' => self::DEFAULT_NAME],
            $candidate,
        );

        return $this->validateDefault($preset->fresh());
    }

    private function validateDefault(ReviewSettingPreset $preset): ReviewSettingPreset
    {
        if (!$preset->is_default || $preset->name !== self::DEFAULT_NAME) {
            throw new \DomainException('The user Default preset is inconsistent.');
        }
        ReviewSettingsPresetConfig::fromArray($preset->config);
        return $preset;
    }
}
