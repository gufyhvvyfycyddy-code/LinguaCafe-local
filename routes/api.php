<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('v1/mobile')->group(function () {
    Route::post('/auth/tokens', [App\Http\Controllers\Mobile\MobileAuthController::class, 'storeToken'])
        ->middleware('throttle:6,1');

    Route::middleware(['auth:sanctum', 'mobile.device'])->group(function () {
        Route::get('/bootstrap', [App\Http\Controllers\Mobile\MobileBootstrapController::class, 'show']);
        Route::get(
            '/dictionary/lookup',
            [App\Http\Controllers\Mobile\MobileDictionaryController::class, 'show'],
        );
        Route::post(
            '/word-senses',
            [App\Http\Controllers\Mobile\MobileWordSenseController::class, 'store'],
        );
        Route::get(
            '/word-senses',
            [App\Http\Controllers\Mobile\MobileWordSenseController::class, 'index'],
        );
        Route::get(
            '/summary',
            [App\Http\Controllers\Mobile\MobileSummaryController::class, 'show'],
        );
        Route::get(
            '/review-cards/search',
            [App\Http\Controllers\Mobile\MobileReviewCardSearchController::class, 'index'],
        );
        Route::get(
            '/article-packages',
            [App\Http\Controllers\Mobile\MobileArticlePackageController::class, 'index'],
        );
        Route::get(
            '/article-packages/{book}',
            [App\Http\Controllers\Mobile\MobileArticlePackageController::class, 'show'],
        )->whereNumber('book');
        Route::get(
            '/article-packages/{book}/chapters/{chapter}',
            [App\Http\Controllers\Mobile\MobileArticlePackageController::class, 'chapter'],
        )->whereNumber(['book', 'chapter']);
        Route::get(
            '/review-packages/short-term',
            [App\Http\Controllers\Mobile\MobileReviewPackageController::class, 'shortTerm'],
        );
        Route::get('/media/manifest', [App\Http\Controllers\Mobile\MobileMediaController::class, 'index']);
        Route::get('/media/assets/{assetId}', [App\Http\Controllers\Mobile\MobileMediaController::class, 'download']);
        Route::post(
            '/imports/text',
            [App\Http\Controllers\Mobile\MobileTextImportController::class, 'store'],
        );
        Route::post(
            '/sync/actions',
            [App\Http\Controllers\Mobile\MobileSyncController::class, 'store'],
        );
        Route::post(
            '/chapters/{chapter}/reading-sessions',
            [App\Http\Controllers\Mobile\MobileReadingSessionController::class, 'store'],
        )->whereNumber('chapter');
        Route::post(
            '/chapters/{chapter}/reading-sessions/{readingSession}/finish',
            [App\Http\Controllers\Mobile\MobileReadingSessionController::class, 'finish'],
        )->whereNumber('chapter');
        Route::delete('/devices/{deviceUuid}', [App\Http\Controllers\Mobile\MobileDeviceController::class, 'destroy']);
        Route::post(
            '/review-cards/{reviewCard}/ratings',
            [App\Http\Controllers\Mobile\MobileSenseReviewController::class, 'store'],
        )->whereNumber('reviewCard');
        Route::get(
            '/operations',
            [App\Http\Controllers\Mobile\MobileOperationController::class, 'index'],
        );
        Route::post(
            '/operations/{operationId}/undo',
            [App\Http\Controllers\Mobile\MobileOperationController::class, 'undo'],
        );
        Route::post(
            '/operations/{operationId}/redo',
            [App\Http\Controllers\Mobile\MobileOperationController::class, 'redo'],
        );
        Route::post(
            '/review-cards/{reviewCard}/manual-operations/preview',
            [App\Http\Controllers\Mobile\MobileOperationController::class, 'previewManual'],
        );
        Route::post(
            '/review-cards/{reviewCard}/manual-operations/apply',
            [App\Http\Controllers\Mobile\MobileOperationController::class, 'applyManual'],
        );
    });
});
