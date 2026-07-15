<?php

namespace App\Http\Controllers;

use App\Exceptions\ReviewSettingsPresetException;
use App\Services\Settings\Presets\ReviewSettingsPresetManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReviewSettingsPresetController extends Controller
{
    public function __construct(private ReviewSettingsPresetManagementService $management)
    {
    }

    public function index(): JsonResponse
    {
        return $this->respond(fn () => $this->state());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);
        return $this->respond(fn () => $this->management->create(
            Auth::id(), Auth::user()->selected_language, $validated['name']
        ));
    }

    public function clone(Request $request, int $presetId): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);
        return $this->respond(fn () => $this->management->clone(
            Auth::id(), Auth::user()->selected_language, $presetId, $validated['name']
        ));
    }

    public function rename(Request $request, int $presetId): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);
        return $this->respond(fn () => $this->management->rename(
            Auth::id(), Auth::user()->selected_language, $presetId, $validated['name']
        ));
    }

    public function destroy(int $presetId): JsonResponse
    {
        return $this->respond(fn () => $this->management->delete(
            Auth::id(), Auth::user()->selected_language, $presetId
        ));
    }

    public function switchCurrentLanguage(Request $request): JsonResponse
    {
        $validated = $request->validate(['preset_id' => ['required', 'integer', 'min:1']]);
        return $this->respond(fn () => $this->management->switchCurrentLanguage(
            Auth::id(), Auth::user()->selected_language, (int) $validated['preset_id']
        ));
    }

    private function state(): array
    {
        return $this->management->state(Auth::id(), Auth::user()->selected_language);
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            return response()->json($operation());
        } catch (ReviewSettingsPresetException $exception) {
            return response()->json($exception->response(), $exception->status());
        }
    }
}
