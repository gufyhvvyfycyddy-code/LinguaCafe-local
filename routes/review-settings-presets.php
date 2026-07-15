<?php

use App\Http\Controllers\ReviewSettingsPresetController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'auth.session', 'admin'])
    ->prefix('settings/review-presets')
    ->group(function (): void {
        Route::get('/', [ReviewSettingsPresetController::class, 'index']);
        Route::post('/', [ReviewSettingsPresetController::class, 'store']);
        Route::post('/{presetId}/clone', [ReviewSettingsPresetController::class, 'clone'])
            ->whereNumber('presetId');
        Route::patch('/{presetId}', [ReviewSettingsPresetController::class, 'rename'])
            ->whereNumber('presetId');
        Route::delete('/{presetId}', [ReviewSettingsPresetController::class, 'destroy'])
            ->whereNumber('presetId');
        Route::put('/current-language', [ReviewSettingsPresetController::class, 'switchCurrentLanguage']);
    });
