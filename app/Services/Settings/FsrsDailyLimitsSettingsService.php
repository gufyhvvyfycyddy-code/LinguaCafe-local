<?php

namespace App\Services\Settings;

use App\Exceptions\DailyLimitsValidationException;
use App\Models\Setting;

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

    public function get(): array
    {
        $rows = Setting::where('user_id', -1)
            ->whereIn('name', array_keys(self::DEFAULTS))
            ->get()
            ->keyBy('name');

        $limits = [];
        foreach (self::DEFAULTS as $key => $defaultValue) {
            $row = $rows->get($key);
            $limits[$key] = $row ? json_decode($row->value) : $defaultValue;
        }

        $limits['is_queue_enforced'] = true;
        $limits['message'] = '每日上限设置已保存；复习队列按以上限制显示卡。';

        return $limits;
    }

    public function update(array $input): array
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

        foreach ($input as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

            if (in_array($key, self::BOOLEAN_KEYS, true)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            } elseif ($key === 'daily_new_limit' || $key === 'daily_review_limit') {
                $value = (int) $value;
            }

            $setting = Setting::where('name', $key)
                ->where('user_id', -1)
                ->first();

            if (!$setting) {
                $setting = new Setting();
                $setting->name = $key;
                $setting->user_id = -1;
            }

            $setting->value = json_encode($value);
            $setting->save();
        }

        return $this->get();
    }
}
