<?php

namespace App\Services\Settings\Presets;

use App\Exceptions\ReviewSettingsPresetException;
use App\Models\ReviewSettingPreset;
use Illuminate\Database\QueryException;

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

    public function findOwnedOrFail(int $userId, int $presetId, bool $lock = false): ReviewSettingPreset
    {
        $query = ReviewSettingPreset::where('user_id', $userId)->whereKey($presetId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $preset = $query->first();
        if (!$preset) {
            throw ReviewSettingsPresetException::notFound();
        }
        ReviewSettingsPresetConfig::fromArray($preset->config);
        return $preset;
    }

    public function createNamed(int $userId, string $name): ReviewSettingPreset
    {
        return $this->createWithConfig($userId, $name, ReviewSettingsPresetConfig::defaults());
    }

    public function cloneNamed(int $userId, int $sourcePresetId, string $name): ReviewSettingPreset
    {
        $source = $this->findOwnedOrFail($userId, $sourcePresetId);
        return $this->createWithConfig(
            $userId,
            $name,
            ReviewSettingsPresetConfig::fromArray($source->config),
        );
    }

    public function rename(int $userId, int $presetId, string $name): ReviewSettingPreset
    {
        $preset = $this->findOwnedOrFail($userId, $presetId, true);
        if ($preset->is_default) {
            throw ReviewSettingsPresetException::validation('Default Preset 不能重命名。');
        }
        $preset->name = $this->normalizeName($name);
        try {
            $preset->save();
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw ReviewSettingsPresetException::validation('该 Preset 名称已存在。');
            }
            throw $exception;
        }
        return $preset->fresh();
    }

    public function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw ReviewSettingsPresetException::validation('Preset 名称必须为 1–120 个字符。');
        }
        if (mb_strtolower($name) === mb_strtolower(self::DEFAULT_NAME)) {
            throw ReviewSettingsPresetException::validation('Default 是系统保留名称。');
        }
        return $name;
    }

    private function createWithConfig(
        int $userId,
        string $name,
        ReviewSettingsPresetConfig $config,
    ): ReviewSettingPreset {
        $name = $this->normalizeName($name);
        try {
            return ReviewSettingPreset::create([
                'user_id' => $userId,
                'name' => $name,
                'config' => $config->toArray(),
                'is_default' => null,
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw ReviewSettingsPresetException::validation('该 Preset 名称已存在。');
            }
            throw $exception;
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || (int) ($exception->errorInfo[1] ?? 0) === 1062;
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
