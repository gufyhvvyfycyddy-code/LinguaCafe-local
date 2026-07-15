<?php

namespace App\Services\Settings;

use App\Exceptions\DailyLimitsValidationException;
use App\Services\Settings\Presets\ReviewSettingsResolver;

class FsrsDailyLimitsSettingsService
{
    private const DEFAULTS = [
        'daily_new_limit_enabled' => true,
        'daily_new_limit' => 20,
        'daily_review_limit_enabled' => true,
        'daily_review_limit' => 200,
        'new_cards_ignore_review_limit' => false,
    ];

    private const BOOLEAN_KEYS = [
        'daily_new_limit_enabled',
        'daily_review_limit_enabled',
        'new_cards_ignore_review_limit',
    ];

    public function __construct(private ReviewSettingsResolver $reviewSettings)
    {
    }

    public function get(int $userId, string $language): array
    {
        $limits = $this->reviewSettings->resolve($userId, $language)->dailyLimitsForApi();

        $limits['is_queue_enforced'] = true;
        $limits['message'] = '每日上限设置已保存；复习队列按以上限制显示卡。';

        return $limits;
    }

    public function update(int $userId, string $language, array $input): array
    {
        $errors = [];

        foreach ($input as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

            if (in_array($key, self::BOOLEAN_KEYS, true)) {
                $boolValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($boolValue === null) {
                    $errors[$key] = '此设置必须是启用或关闭状态。';
                }
            }

            if ($key === 'daily_new_limit') {
                $intValue = (int) $value;
                if ($intValue < 0 || $intValue > 999) {
                    $errors[$key] = '每日新学上限必须在 0 到 999 之间。';
                }
            }

            if ($key === 'daily_review_limit') {
                $intValue = (int) $value;
                if ($intValue < 0 || $intValue > 9999) {
                    $errors[$key] = '每日复习上限必须在 0 到 9999 之间。';
                }
            }
        }

        if ($errors !== []) {
            throw new DailyLimitsValidationException($errors);
        }

        $map = [
            'daily_new_limit_enabled' => 'new_cards_enabled',
            'daily_new_limit' => 'new_cards_per_day',
            'daily_review_limit_enabled' => 'reviews_enabled',
            'daily_review_limit' => 'maximum_reviews_per_day',
            'new_cards_ignore_review_limit' => 'new_cards_ignore_review_limit',
        ];
        $patch = [];
        foreach ($input as $key => $value) {
            if (!isset($map[$key])) continue;
            $patch[$map[$key]] = in_array($key, self::BOOLEAN_KEYS, true)
                ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : (int) $value;
        }
        if ($patch !== []) {
            $this->reviewSettings->mutate($userId, $language, ['daily_limits' => $patch]);
        }

        return $this->get($userId, $language);
    }
}
