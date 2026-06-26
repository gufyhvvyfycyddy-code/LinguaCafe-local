<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

// services
use App\Services\FsrsReschedulePreviewService;
use App\Services\FsrsRescheduleSnapshotService;
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

    /**
     * D.4-a: Read-only preview of FSRS reschedule impact.
     *
     * Computes what would happen if eligible sense cards were rescheduled
     * using the currently active FSRS parameters. Does NOT write to the
     * database or create any ReviewLog entries.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function reschedulePreview() {
        $user = Auth::user();

        $service = app(FsrsReschedulePreviewService::class);
        $result = $service->preview($user->id, $user->selected_language);

        return response()->json($result, 200);
    }

    public function rescheduleConfirm(Request $request)
    {
        $validated = $request->validate([
            'preview_hash' => 'required|string',
            'confirm' => 'required|boolean',
            'risk_confirm' => 'sometimes|boolean',
            'apply' => 'sometimes|boolean',
        ]);
        $user = Auth::user();
        $service = app(FsrsReschedulePreviewService::class);

        if ($request->boolean('apply')) {
            $result = $service->confirmAndApply(
                $user->id, $user->selected_language,
                $validated['preview_hash'], $validated['confirm'],
                $request->boolean('risk_confirm')
            );
        } else {
            $result = $service->confirmPreflight(
                $user->id, $user->selected_language,
                $validated['preview_hash'], $validated['confirm']
            );
        }

        $statusCode = 200;
        if (!$result['success']) {
            if (isset($result['preview_hash'])) {
                $statusCode = 409;
            } elseif (isset($result['risk_level'])) {
                $statusCode = 422;
            } else {
                $statusCode = 422;
            }
        }
        return response()->json($result, $statusCode);
    }

    public function rescheduleUndo(Request $request)
    {
        $validated = $request->validate([
            'confirm' => 'required|boolean',
        ]);
        $user = Auth::user();
        $service = app(FsrsRescheduleSnapshotService::class);
        $result = $service->undoLatestForUserLanguage(
            $user->id,
            $user->selected_language,
            $validated['confirm']
        );
        if (!$result['success'] && ($result['undo_available'] ?? true) === false) {
            return response()->json($result, 422);
        }
        return response()->json($result, $result['success'] ? 200 : 422);
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
