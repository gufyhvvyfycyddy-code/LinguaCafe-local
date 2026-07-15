<?php

namespace App\Services\Settings;

use App\Models\Setting;

class SettingValueService
{
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

    public function getGlobalSettingsByName($settingNames)
    {
        $settings = Setting::select('value', 'name')
            ->where('user_id', -1)
            ->whereIn('name', $settingNames)
            ->get()
            ->keyBy('name')
            ->map(fn ($item) => json_decode($item->value));

        if ($settings->isEmpty()) {
            throw new \Exception('No settings were found in the database.');
        }

        return $settings;
    }

    public function updateGlobalSettings($settings): bool
    {
        foreach ($settings as $settingName => $settingValue) {
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
}
