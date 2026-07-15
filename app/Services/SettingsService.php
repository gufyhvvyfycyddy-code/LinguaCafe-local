<?php

namespace App\Services;

use App\Services\Settings\FsrsDailyLimitsSettingsService;
use App\Services\Settings\FsrsOptimizationSettingsService;
use App\Services\Settings\FsrsQueueOrderSettingsService;
use App\Services\Settings\SettingValueService;

/**
 * Backward-compatible settings facade.
 *
 * Controllers and existing callers keep the historical public interface while
 * persistence, FSRS optimization, daily limits, and queue order each live in a
 * focused settings module.
 */
class SettingsService
{
    public const FSRS_OPTIMIZATION_MIN_REQUIRED = FsrsOptimizationSettingsService::MIN_REQUIRED;
    public const FSRS_OPTIMIZATION_INSUFFICIENT_MESSAGE = FsrsOptimizationSettingsService::INSUFFICIENT_MESSAGE;
    public const FSRS_OPTIMIZATION_PENDING_MESSAGE = FsrsOptimizationSettingsService::PENDING_MESSAGE;

    public function __construct(
        private SettingValueService $settingValues,
        private FsrsOptimizationSettingsService $fsrsOptimization,
        private FsrsDailyLimitsSettingsService $fsrsDailyLimits,
        private FsrsQueueOrderSettingsService $fsrsQueueOrder,
    ) {
    }

    public function isJellyfinEnabled()
    {
        return $this->settingValues->isJellyfinEnabled();
    }

    public function getAnkiSettings()
    {
        return $this->settingValues->getAnkiSettings();
    }

    public function getGlobalSettingsByName($settingNames, ?int $userId = null, ?string $language = null)
    {
        return $this->settingValues->getGlobalSettingsByName($settingNames, $userId, $language);
    }

    public function updateGlobalSettings($settings, ?int $userId = null, ?string $language = null): bool
    {
        return $this->settingValues->updateGlobalSettings($settings, $userId, $language);
    }

    public function getUserSettingsByName($userId, $settingNames)
    {
        return $this->settingValues->getUserSettingsByName($userId, $settingNames);
    }

    public function updateUserSettings($userId, $settings): bool
    {
        return $this->settingValues->updateUserSettings($userId, $settings);
    }

    public function getFsrsOptimizationStatus(int $userId, string $language): array
    {
        return $this->fsrsOptimization->getStatus($userId, $language);
    }

    public function preflightFsrsOptimization(int $userId, string $language): array
    {
        return $this->fsrsOptimization->preflight($userId, $language);
    }

    public function computeFsrsOptimizationPreview(int $userId, string $language): array
    {
        return $this->fsrsOptimization->computePreview($userId, $language);
    }

    public function applyFsrsOptimizedParameters(int $userId, string $language): array
    {
        return $this->fsrsOptimization->apply($userId, $language);
    }

    public function getFsrsDailyLimits(int $userId, string $language): array
    {
        return $this->fsrsDailyLimits->get($userId, $language);
    }

    public function updateFsrsDailyLimits(int $userId, string $language, array $input): array
    {
        return $this->fsrsDailyLimits->update($userId, $language, $input);
    }

    public function getFsrsQueueOrder(int $userId, string $language): array
    {
        return $this->fsrsQueueOrder->get($userId, $language);
    }

    public function updateFsrsQueueOrder(int $userId, string $language, array $input): array
    {
        return $this->fsrsQueueOrder->update($userId, $language, $input);
    }

    public function restoreFsrsDefaultParameters(int $userId, string $language): array
    {
        return $this->fsrsOptimization->restoreDefaults($userId, $language);
    }
}
