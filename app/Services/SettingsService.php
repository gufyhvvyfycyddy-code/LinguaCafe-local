<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\WordSense;

class SettingsService {
    public const FSRS_OPTIMIZATION_MIN_REQUIRED = 300;
    public const FSRS_OPTIMIZATION_INSUFFICIENT_MESSAGE = '复习记录还不够，先继续复习一段时间再来优化。';
    public const FSRS_OPTIMIZATION_PENDING_MESSAGE = '已经有足够记录，但自动优化还需要下一步接入参数计算。';
    
    public function __construct() {
    }

    public function isJellyfinEnabled() {
        $isJellyfinEnabled = Setting
            ::select('value', 'name')
            ->where('user_id', -1)
            ->where('name', 'jellyfinEnabled')
            ->first();

        if (!$isJellyfinEnabled) {
            throw new \Exception('Missing jellyfinEnabled setting. This should never occur.');
        }

        return json_decode($isJellyfinEnabled->value);
    }

    public function getAnkiSettings() {
        $ankiSettings = Setting
            ::select('value', 'name')
            ->where('user_id', -1)
            ->whereIn('name', ['ankiAutoAddCards', 'ankiShowNotifications'])
            ->get()
            ->keyBy('name')
            ->map(function ($item, $key) {
                return json_decode($item->value);
            });

        if ($ankiSettings->isEmpty()) {
            throw new \Exception('Missing anki settings. This should never occur.');
        }

        return $ankiSettings;
    }

    public function getGlobalSettingsByName($settingNames) {
        $settings = Setting
            ::select('value', 'name')
            ->where('user_id', -1)
            ->whereIn('name', $settingNames)
            ->get()
            ->keyBy('name')
            ->map(function ($item, $key) {
                return json_decode($item->value);
            });

        if ($settings->isEmpty()) {
            throw new \Exception('No settings were found in the database.');
        }

        return $settings;
    }

    public function updateGlobalSettings($settings) {
        foreach ($settings as $settingName => $settingValue) {
            $setting = Setting
                ::where('name', $settingName)
                ->where('user_id', -1)
                ->first();

            if ($setting) {
                $setting->value = json_encode($settingValue);
                $setting->save();
            }
        }

        return true;
    }

    public function getUserSettingsByName($userId, $settingNames) {
        $settings = Setting
            ::select('value', 'name')
            ->where('user_id', $userId)
            ->whereIn('name', $settingNames)
            ->get()
            ->keyBy('name')
            ->map(function ($item, $key) {
                return json_decode($item->value);
            });

        if ($settings->isEmpty()) {
            return null;
        }

        return $settings;
    }

    public function updateUserSettings($userId, $settings) {
        foreach ($settings as $settingName => $settingValue) {
            $setting = Setting
                ::where('name', $settingName)
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

    public function getFsrsOptimizationStatus(int $userId, string $language): array {
        $reviewCount = $this->countOptimizableFsrsReviews($userId, $language);
        $canOptimize = $reviewCount >= self::FSRS_OPTIMIZATION_MIN_REQUIRED;

        return [
            'review_count' => $reviewCount,
            'min_required' => self::FSRS_OPTIMIZATION_MIN_REQUIRED,
            'can_optimize' => $canOptimize,
            'message' => $canOptimize
                ? self::FSRS_OPTIMIZATION_PENDING_MESSAGE
                : self::FSRS_OPTIMIZATION_INSUFFICIENT_MESSAGE,
            'parameters_source' => 'default',
            'parameters_source_label' => '当前使用默认参数',
            'last_optimized_at' => null,
        ];
    }

    public function preflightFsrsOptimization(int $userId, string $language): array {
        return array_merge(
            ['optimized' => false],
            $this->getFsrsOptimizationStatus($userId, $language),
        );
    }

    private function countOptimizableFsrsReviews(int $userId, string $language): int {
        return ReviewLog::query()
            ->join('review_cards', 'review_cards.id', '=', 'review_logs.review_card_id')
            ->join('word_senses', function ($join) {
                $join->on('word_senses.id', '=', 'review_cards.target_id')
                    ->where('review_cards.target_type', ReviewCard::TARGET_SENSE);
            })
            ->where('review_logs.user_id', $userId)
            ->where('review_logs.language_id', $language)
            ->where('review_logs.source', '!=', 'reset')
            ->where('review_cards.user_id', $userId)
            ->where('review_cards.language_id', $language)
            ->where('word_senses.user_id', $userId)
            ->where('word_senses.language_id', $language)
            ->where('word_senses.status', WordSense::STATUS_CONFIRMED)
            ->count('review_logs.id');
    }
}
