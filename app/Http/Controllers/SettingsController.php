<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

// services
use App\Services\SettingsService;

// request classes
use App\Http\Requests\Settings\GetGlobalSettingsByNameRequest;
use App\Http\Requests\Settings\UpdateGlobalSettingsRequest;
use App\Http\Requests\Settings\GetUserSettingsByNameRequest;
use App\Http\Requests\Settings\UpdateUserSettingsRequest;

class SettingsController extends Controller
{
    private $settingsService;

    public function __construct(SettingsService $settingsService) {
        $this->settingsService = $settingsService;
    }

    public function isJellyfinEnabled() {
        try {
            $isJellyfinEnabled = $this->settingsService->isJellyfinEnabled();
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json($isJellyfinEnabled, 200);
    }

    public function getAnkiSettings() {
        try {
            $ankiSettings = $this->settingsService->getAnkiSettings();
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json($ankiSettings, 200);
    }

    // returns an array of global settings
    public function getGlobalSettingsByName(GetGlobalSettingsByNameRequest $request) {
        $settingNames = $request->post('settingNames');

        try {
            $settings = $this->settingsService->getGlobalSettingsByName($settingNames);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json($settings, 200);
    }

    // saves an array of global settings
    public function updateGlobalSettings(UpdateGlobalSettingsRequest $request) {
        $settings = $request->post('settings');

        try {
            $settings = $this->settingsService->updateGlobalSettings($settings);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json('Settings have been updated successfully.', 200);
    }

    public function getFsrsOptimizationStatus() {
        $user = Auth::user();

        return response()->json(
            $this->settingsService->getFsrsOptimizationStatus($user->id, $user->selected_language),
            200
        );
    }

    public function optimizeFsrsParameters(Request $request) {
        $user = Auth::user();

        try {
            if ($request->boolean('confirm')) {
                $result = $this->settingsService->applyFsrsOptimizedParameters(
                    $user->id, $user->selected_language
                );
            } else {
                $result = $this->settingsService->computeFsrsOptimizationPreview($user->id, $user->selected_language);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '参数优化计算失败：' . $e->getMessage(),
            ], 200);
        }

        return response()->json($result, 200);
    }

    // returns an array of user settings
    public function getUserSettingsByName(GetUserSettingsByNameRequest $request) {
        $userId = Auth::user()->id;
        $settingNames = $request->post('settingNames');

        try {
            $settings = $this->settingsService->getUserSettingsByName($userId, $settingNames);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json($settings, 200);
    }

    // saves an array of user settings
    public function updateUserSettings(UpdateUserSettingsRequest $request) {
        $userId = Auth::user()->id;
        $settings = $request->post('settings');

        try {
            $settings = $this->settingsService->updateUserSettings($userId, $settings);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json('Settings have been updated successfully.', 200);
    }
}
