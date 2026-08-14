<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

if (app()->environment('testing')) {
    Route::get('/__testing/acceptance-sentinel', function () {
        $requestIp = request()->ip();
        $serverIp = request()->server('SERVER_ADDR');
        $hostIps = gethostbynamel(gethostname()) ?: [];
        $isLocalRequest = in_array($requestIp, ['127.0.0.1', '::1'], true)
            || (is_string($serverIp) && hash_equals($serverIp, $requestIp))
            || in_array($requestIp, $hostIps, true);
        abort_unless($isLocalRequest, 404);

        $sentinel = env('LINGUACAFE_TEST_SENTINEL');
        $database = DB::connection()->getDatabaseName();
        $databaseIsTesting = is_string($database)
            && str_contains(strtolower($database), 'test');
        $sentinelPresent = is_string($sentinel)
            && str_starts_with($sentinel, '__testing_acceptance_sentinel_')
            && DB::table('migrations')->where('migration', $sentinel)->exists();

        return response()->json([
            'environment' => app()->environment(),
            'database_is_testing' => $databaseIsTesting,
            'sentinel_present' => $sentinelPresent,
        ], $databaseIsTesting && $sentinelPresent ? 200 : 503);
    });
}

require __DIR__.'/auth.php';

/*
    This function's authentication is inside the controller, because
    the first user can be created without being logged in.
*/
Route::group(['middleware' => 'web'], function () {
    Route::post('/users/create', [App\Http\Controllers\UserController::class, 'createUser']);
});

// login routes
Route::get('/login', [App\Http\Controllers\UserController::class, 'showLoginForm'])->name('login');
Route::get('/setup', [App\Http\Controllers\UserController::class, 'showSetupForm'])->name('setup');
Route::get('/register', [App\Http\Controllers\UserController::class, 'showRegisterForm'])->name('register');
Route::post('/login', [App\Http\Controllers\UserController::class, 'authenticateUser']);

Route::group(['middleware' => ['auth', 'auth.session', 'web']], function () {

    // backup (equal privilege for every authenticated user; no admin boundary)
    Route::get('/backups', [App\Http\Controllers\BackupController::class, 'index']);
    Route::post('/backups', [App\Http\Controllers\BackupController::class, 'store']);
    Route::post('/backups/{backupId}/restore', [App\Http\Controllers\BackupController::class, 'restore']);
    Route::get('/backup-restores/{operationId}', [App\Http\Controllers\BackupController::class, 'restoreStatus']);

    // backup page is reachable by every authenticated user; it is not an admin boundary
    Route::get('/admin/{page?}', [App\Http\Controllers\HomeController::class, 'index']);

    Route::group(['middleware' => 'admin'], function () {
        Route::get('/dev', [App\Http\Controllers\HomeController::class, 'index']);
        
        // users
        Route::get ('/users/get', [App\Http\Controllers\UserController::class, 'getUsers']);
        Route::post('/users/update', [App\Http\Controllers\UserController::class, 'updateUser']);

        // languages
        Route::post('/languages/install', [App\Http\Controllers\LanguageController::class, 'installLanguage']);
        Route::get ('/languages/installed/list', [App\Http\Controllers\LanguageController::class, 'getInstalledLanguages']);
        Route::delete ('/languages/installed/delete', [App\Http\Controllers\LanguageController::class, 'deleteInstalledLanguages']);
        Route::get('/languages/get-admin-language-settings-data', [App\Http\Controllers\LanguageController::class, 'getAdminLanguageSettingsData']);
        
        // dictionaries
        Route::post('/dictionary/update', [App\Http\Controllers\DictionaryController::class, 'updateDictionary']);
        
        // fonts
        Route::get ('/fonts/get', [App\Http\Controllers\FontTypeController::class, 'getInstalledFontTypes']);
        Route::post('/fonts/upload', [App\Http\Controllers\FontTypeController::class, 'uploadFontType']);
        Route::post('/fonts/update', [App\Http\Controllers\FontTypeController::class, 'updateFontType']);
        Route::post('/fonts/delete', [App\Http\Controllers\FontTypeController::class, 'deleteFontType']);

        // settings
        Route::get('/settings/fsrs/optimization-status', [App\Http\Controllers\SettingsController::class, 'getFsrsOptimizationStatus']);
        Route::post('/settings/fsrs/optimize', [App\Http\Controllers\SettingsController::class, 'optimizeFsrsParameters']);
        Route::post('/settings/fsrs/reschedule-preview', [App\Http\Controllers\SettingsController::class, 'reschedulePreview']);
        Route::post('/settings/fsrs/reschedule-confirm', [App\Http\Controllers\SettingsController::class, 'rescheduleConfirm'])->name('settings.fsrs.reschedule-confirm');
        Route::post('/settings/fsrs/reschedule-undo', [App\Http\Controllers\SettingsController::class, 'rescheduleUndo'])->name('settings.fsrs.reschedule-undo');
        Route::post('/settings/fsrs/restore-default', [App\Http\Controllers\SettingsController::class, 'restoreFsrsDefaultParameters']);
        Route::get('/settings/fsrs/daily-limits', [App\Http\Controllers\SettingsController::class, 'getFsrsDailyLimits']);
        Route::post('/settings/fsrs/daily-limits', [App\Http\Controllers\SettingsController::class, 'updateFsrsDailyLimits']);
        Route::get('/settings/fsrs/queue-order', [App\Http\Controllers\SettingsController::class, 'getFsrsQueueOrder']);
        Route::post('/settings/fsrs/queue-order', [App\Http\Controllers\SettingsController::class, 'updateFsrsQueueOrder']);
        Route::get('/settings/fsrs/advanced-settings', [App\Http\Controllers\SettingsController::class, 'getAdvancedReviewSettings']);
        Route::put('/settings/fsrs/advanced-settings', [App\Http\Controllers\SettingsController::class, 'updateAdvancedReviewSettings']);
        Route::post('/settings/fsrs/retention-workload-simulation', [App\Http\Controllers\SettingsController::class, 'retentionWorkloadSimulation']);
        Route::post('/settings/global/update', [App\Http\Controllers\SettingsController::class, 'updateGlobalSettings']);
        Route::post('/settings/global/get', [App\Http\Controllers\SettingsController::class, 'getGlobalSettingsByName']);

        // dictionaries
        Route::post('/dictionaries/get-supported-dictionary-file-information', [App\Http\Controllers\DictionaryController::class, 'getDictionaryFileInformation']);
        Route::post('/dictionaries/import', [App\Http\Controllers\DictionaryController::class, 'importSupportedDictionary']);
        Route::get('/dictionaries/get-record-count/{dictionaryTableName}', [App\Http\Controllers\DictionaryController::class, 'getDictionaryRecordCount']);
        Route::get('/dictionaries/deepl/get-usage', [App\Http\Controllers\DictionaryController::class, 'getDeeplCharacterLimit']);
        Route::get('/dictionaries/get', [App\Http\Controllers\DictionaryController::class, 'getDictionaries']);
        Route::get('/dictionaries/get/{dictionaryId}', [App\Http\Controllers\DictionaryController::class, 'getDictionary']);
        Route::post('/dictionaries/update', [App\Http\Controllers\DictionaryController::class, 'updateDictionary']);
        Route::post('/dictionaries/test-csv-file', [App\Http\Controllers\DictionaryController::class, 'testDictionaryCsvFile']);
        Route::post('/dictionaries/import-csv-file', [App\Http\Controllers\DictionaryController::class, 'importDictionaryCsvFile']);
        Route::post('/dictionaries/create-deepl', [App\Http\Controllers\DictionaryController::class, 'createDeeplDictionary']);
        Route::post('/dictionaries/create-my-memory', [App\Http\Controllers\DictionaryController::class, 'createMyMemoryDictionary']);
        Route::post('/dictionaries/create-custom-api', [App\Http\Controllers\DictionaryController::class, 'createCustomApiDictionary']);
        Route::post('/dictionaries/create-libre-translate', [App\Http\Controllers\DictionaryController::class, 'createLibreTranslateDictionary']);
        Route::delete('/dictionaries/delete/{dictionaryId}', [App\Http\Controllers\DictionaryController::class, 'deleteDictionary']);
        Route::get('/jmdict/xml-to-text', [App\Http\Controllers\DictionaryController::class, 'jmdictXmlToText']);
    });

    // languages
    Route::get('/languages/get-language-selection-dialog-data', [App\Http\Controllers\LanguageController::class, 'getLanguageSelectionDialogData']);
    Route::put('/languages/select/{language}', [App\Http\Controllers\LanguageController::class, 'selectLanguage']);

    // users
    Route::post('/users/update-password', [App\Http\Controllers\UserController::class, 'updatePassword']);
    Route::get ('/users/is-password-changed', [App\Http\Controllers\UserController::class, 'isUserPasswordChanged']);
    Route::delete('/users/delete-language-data/{language}', [App\Http\Controllers\UserController::class, 'deleteUserLanguageData']);

    // jellyfin
    Route::get('/jellyfin/subtitles', [App\Http\Controllers\JellyfinController::class, 'getJellyfinCurrentlyPlayedSubtitles']);

    // vue routes    
    Route::get('/', [App\Http\Controllers\HomeController::class, 'index']);
    Route::get('/user-settings', [App\Http\Controllers\HomeController::class, 'index']);
    Route::get('/user-manual/{currentPage?}', [App\Http\Controllers\HomeController::class, 'index']);
    Route::get('/attributions', [App\Http\Controllers\HomeController::class, 'index']);
    Route::get('/patch-notes', [App\Http\Controllers\HomeController::class, 'index']);
    Route::get('/books/{bookId?}', [App\Http\Controllers\HomeController::class, 'index']);
    Route::get('/book/create', [App\Http\Controllers\HomeController::class, 'index']);
    Route::get('/chapters/{id}', [App\Http\Controllers\HomeController::class, 'index']);
    Route::get('/chapters/read/{id}', [App\Http\Controllers\HomeController::class, 'index']);
    Route::get('/chapters/create/{bookId}', [App\Http\Controllers\HomeController::class, 'index']);
    Route::get('/chapters/edit/{bookId}/{chapterId}', [App\Http\Controllers\HomeController::class, 'index']);
    Route::redirect('/review/{practiceMode?}/{bookId?}/{chapterId?}', '/reviews/senses');
    Route::get('/custom-study', [App\Http\Controllers\HomeController::class, 'index']);
    Route::get('/study-overview', [App\Http\Controllers\StudyOverviewController::class, 'index']);
    Route::get('/study-overview/data', [App\Http\Controllers\StudyOverviewController::class, 'data']);
    Route::get('/article-health', [App\Http\Controllers\HomeController::class, 'index']);
    Route::get('/article-health/data', [App\Http\Controllers\ArticleHealthController::class, 'show']);
    Route::get('/reviews/senses', [App\Http\Controllers\SenseReviewController::class, 'index']);
    Route::get('/word-senses', [App\Http\Controllers\HomeController::class, 'index']);
    Route::get('/word-senses/data', [App\Http\Controllers\WordSenseLibraryController::class, 'data']);
    Route::post('/word-senses/{wordSense}/media', [App\Http\Controllers\MediaController::class, 'store'])->whereNumber('wordSense');
    Route::delete('/media/references/{referenceId}', [App\Http\Controllers\MediaController::class, 'destroy']);
    Route::get('/media/assets/{assetId}', [App\Http\Controllers\MediaController::class, 'download'])->name('media.download');
    Route::get('/media/check', [App\Http\Controllers\MediaController::class, 'check']);
    Route::get('/reviews/senses/today-limits', [App\Http\Controllers\ReviewTodayLimitsController::class, 'show']);
    Route::put('/reviews/senses/today-limits', [App\Http\Controllers\ReviewTodayLimitsController::class, 'update']);
    Route::delete('/reviews/senses/today-limits', [App\Http\Controllers\ReviewTodayLimitsController::class, 'destroy']);
    Route::get('/reviews/senses/daily-report', [App\Http\Controllers\SenseReviewController::class, 'dailyReport']);
    Route::get('/reviews/senses/seven-day-trend', [App\Http\Controllers\SenseReviewController::class, 'sevenDayTrend']);
    Route::get('/reviews/senses/thirty-day-calendar', [App\Http\Controllers\SenseReviewController::class, 'thirtyDayCalendar']);
    Route::get('/reviews/senses/session-actions', [App\Http\Controllers\SenseReviewController::class, 'sessionActions']);
    Route::get('/reviews/senses/{reviewCardId}/interval-preview', [App\Http\Controllers\SenseReviewController::class, 'intervalPreview']);
    Route::post('/reviews/senses/review-actions/{reviewLog}/undo', [App\Http\Controllers\SenseReviewController::class, 'undo']);
    Route::get('/senses/review', [App\Http\Controllers\HomeController::class, 'index']);
    Route::get('/vocabulary/search', [App\Http\Controllers\HomeController::class, 'index']);
    Route::get('/vocabulary/search/{text}/{stage}/{book}/{chapter}/{translation}/{phrases}/{orderBy}/{page}', [App\Http\Controllers\HomeController::class, 'index']);
    Route::get('/kanji/search', [App\Http\Controllers\HomeController::class, 'index']);
    Route::get('/kanji/{character}', [App\Http\Controllers\HomeController::class, 'index']);
    Route::get('/review-cards/manage', [App\Http\Controllers\HomeController::class, 'index']);
    Route::get('/mobile-sync-simulator', [App\Http\Controllers\HomeController::class, 'index']);

    // home
    Route::get('/home/study-summary', [App\Http\Controllers\HomeController::class, 'studySummary']);
    Route::post('/statistics/get', [App\Http\Controllers\HomeController::class, 'getStatistics']);
    Route::post('/statistics/export/{format}', [App\Http\Controllers\HomeController::class, 'exportStatistics']);
    Route::get('/review-cards/knowledge-hygiene/preferences', [App\Http\Controllers\KnowledgeHygieneController::class, 'preferences']);
    Route::put('/review-cards/knowledge-hygiene/preferences', [App\Http\Controllers\KnowledgeHygieneController::class, 'savePreferences']);
    Route::post('/review-cards/knowledge-hygiene/find-replace/preview', [App\Http\Controllers\KnowledgeHygieneController::class, 'findReplacePreview']);
    Route::post('/review-cards/knowledge-hygiene/find-replace/apply', [App\Http\Controllers\KnowledgeHygieneController::class, 'applyFindReplace']);
    Route::post('/review-cards/knowledge-hygiene/duplicates', [App\Http\Controllers\KnowledgeHygieneController::class, 'duplicates']);
    Route::post('/review-cards/knowledge-hygiene/merge/preview', [App\Http\Controllers\KnowledgeHygieneController::class, 'mergePreview']);
    Route::post('/review-cards/knowledge-hygiene/merge/apply', [App\Http\Controllers\KnowledgeHygieneController::class, 'applyMerge']);
    Route::get('/review-cards/knowledge-hygiene/recent-deletes', [App\Http\Controllers\KnowledgeHygieneController::class, 'recentDeletes']);
    Route::post('/review-cards/knowledge-hygiene/operations/{operationId}/undo', [App\Http\Controllers\KnowledgeHygieneController::class, 'undo']);
    Route::get('/config/get/{configPath}', [App\Http\Controllers\HomeController::class, 'getConfig']);

    // user manual
    Route::get('/manual/get-menu-tree', [App\Http\Controllers\HomeController::class, 'getUserManualTree']);
    Route::get('/manual/get-manual-file/{fileName}', [App\Http\Controllers\HomeController::class, 'getUserManualFile']);

    // goals
    Route::post('/goals/get', [App\Http\Controllers\GoalController::class, 'getGoals']);
    Route::post('/goal/update', [App\Http\Controllers\GoalController::class, 'updateGoal']);
    Route::post('/goals/get-calendar-data', [App\Http\Controllers\GoalController::class, 'getCalendarData']);
    Route::post('/goals/achievement/update', [App\Http\Controllers\GoalController::class, 'updateCalendarData']);
    Route::post('/goals/achievement/review/update', [App\Http\Controllers\GoalController::class, 'updateReviewGoalAchievement']);

    // fonts
    Route::get('/fonts/get-fonts-for-language/{language}', [App\Http\Controllers\FontTypeController::class, 'getFontTypesForLanguage']);
    Route::get('/fonts/file/{fileName}', [App\Http\Controllers\FontTypeController::class, 'getFontTypeFile']);

    // settings
    Route::post('/settings/user/get', [App\Http\Controllers\SettingsController::class, 'getUserSettingsByName']);
    Route::post('/settings/user/update', [App\Http\Controllers\SettingsController::class, 'updateUserSettings']);
    Route::get('/settings/is-jellyfin-enabled', [App\Http\Controllers\SettingsController::class, 'isJellyfinEnabled']);
    Route::get('/settings/get-anki-settings', [App\Http\Controllers\SettingsController::class, 'getAnkiSettings']);

    // images
    Route::get('/images/book_images/{fileName}', [App\Http\Controllers\ImageController::class, 'getBookImage']);
    Route::get('/images/kanji/{fileName}', [App\Http\Controllers\ImageController::class, 'getKanjiImage']);

    // dictionaries
    Route::post('/dictionaries/api/search', [App\Http\Controllers\DictionaryController::class, 'searchApiDictionaries']);
    Route::get('/dictionaries/api/is-enabled', [App\Http\Controllers\DictionaryController::class, 'isAnyApiDictionaryEnabled']);
    Route::post('/dictionaries/search', [App\Http\Controllers\DictionaryController::class, 'searchDefinitions']);
    Route::post('/dictionaries/search-for-hover-vocabulary', [App\Http\Controllers\DictionaryController::class, 'searchDefinitionsForHoverVocabulary']);
    Route::post('/dictionaries/search/inflections', [App\Http\Controllers\DictionaryController::class, 'searchInflections']);

    // vocabulary
    Route::get ('/vocabulary/words/get/{wordId}', [App\Http\Controllers\VocabularyController::class, 'getUniqueWord']);
    Route::post('/vocabulary/word/update', [App\Http\Controllers\VocabularyController::class, 'updateWord']);
    Route::post('/vocabulary/word/delete', [App\Http\Controllers\VocabularyController::class, 'deleteWord']);
    Route::post('/vocabulary/words/batch-ignore', [App\Http\Controllers\VocabularyController::class, 'batchIgnoreWords']);
    Route::post('/vocabulary/words/batch-hard-delete', [App\Http\Controllers\VocabularyController::class, 'batchHardDeleteWords']);
    Route::post('/vocabulary/words/bulk-hard-delete-count', [App\Http\Controllers\VocabularyController::class, 'bulkHardDeleteWordsCount']);
    Route::post('/vocabulary/words/bulk-hard-delete', [App\Http\Controllers\VocabularyController::class, 'bulkHardDeleteWords']);
    Route::get ('/vocabulary/phrases/get/{phraseId}', [App\Http\Controllers\VocabularyController::class, 'getPhrase']);
    Route::post('/vocabulary/phrases/create', [App\Http\Controllers\VocabularyController::class, 'createPhrase']);
    Route::post('/vocabulary/phrases/update', [App\Http\Controllers\VocabularyController::class, 'updatePhrase']);
    Route::post('/vocabulary/phrases/delete', [App\Http\Controllers\VocabularyController::class, 'deletePhrase']);
    Route::post('/vocabulary/example-sentence/create-or-update', [App\Http\Controllers\VocabularyController::class, 'createOrUpdateExampleSentence']);
    Route::post('/vocabulary/search', [App\Http\Controllers\VocabularyController::class, 'searchVocabulary']);
    Route::post('/vocabulary/export-to-csv', [App\Http\Controllers\VocabularyController::class, 'exportToCsv']);
    Route::post('/vocabulary/import-from-csv', [App\Http\Controllers\VocabularyController::class, 'importFromCsv']);
    Route::post('/kanji/search', [App\Http\Controllers\VocabularyController::class, 'searchKanji']);
    Route::post('/kanji/details', [App\Http\Controllers\VocabularyController::class, 'getKanjiDetails']);

    // review
    Route::post('/reviews', [App\Http\Controllers\ReviewController::class, 'getReviewItems']);
    Route::post('/reviews/rate', [App\Http\Controllers\ReviewController::class, 'rateReviewCard']);
    Route::post('/reviews/senses/{reviewCardId}/rate', [App\Http\Controllers\SenseReviewController::class, 'rate']);

    // Custom Study preview sessions (Task 2000-22 — Phase 4B).
    // Three POST routes only — no fourth, no token URL, no exclude query param.
    // Token is always in the request body; user/language come from Auth::user().
    // Controller is the single HTTP boundary; SessionService is stateless.
    Route::get('/custom-study/chapter-options', [App\Http\Controllers\CustomStudyController::class, 'chapterOptions']);
    Route::post('/custom-study/sessions', [App\Http\Controllers\CustomStudyController::class, 'openSession']);
    Route::post('/custom-study/sessions/answer', [App\Http\Controllers\CustomStudyController::class, 'answer']);
    Route::post('/custom-study/sessions/resume', [App\Http\Controllers\CustomStudyController::class, 'resume']);

    // M12 server-authoritative Special Study sessions. Legacy Custom Study
    // token routes above remain unchanged for compatibility.
    Route::get('/special-study/options', [App\Http\Controllers\SpecialStudySessionController::class, 'options']);
    Route::get('/special-study/sessions', [App\Http\Controllers\SpecialStudySessionController::class, 'index']);
    Route::post('/special-study/sessions', [App\Http\Controllers\SpecialStudySessionController::class, 'store']);
    Route::get('/special-study/sessions/{sessionId}', [App\Http\Controllers\SpecialStudySessionController::class, 'show']);
    Route::put('/special-study/sessions/{sessionId}/save', [App\Http\Controllers\SpecialStudySessionController::class, 'save']);
    Route::post('/special-study/sessions/{sessionId}/answer', [App\Http\Controllers\SpecialStudySessionController::class, 'answer']);
    Route::post('/special-study/sessions/{sessionId}/rebuild', [App\Http\Controllers\SpecialStudySessionController::class, 'rebuild']);
    Route::post('/special-study/sessions/{sessionId}/end', [App\Http\Controllers\SpecialStudySessionController::class, 'end']);

    // sense mapping review
    Route::get('/senses/occurrences', [App\Http\Controllers\SenseOccurrenceController::class, 'index']);
    Route::get('/senses/candidates', [App\Http\Controllers\SenseOccurrenceController::class, 'candidates']);
    Route::get('/senses/known-sense-lookup', [App\Http\Controllers\SenseOccurrenceController::class, 'knownSenseLookup']);
    Route::get('/senses/inline-preview', [App\Http\Controllers\SenseOccurrenceController::class, 'inlinePreview']);
    Route::post('/senses/inline-confirmation', [App\Http\Controllers\ReadingInlineSenseConfirmationController::class, 'storeInlineConfirmation']);
    Route::get('/senses/inline-confirmations', [App\Http\Controllers\ReadingInlineSenseConfirmationController::class, 'listInlineConfirmations']);
    Route::get('/senses/inline-confirmations/manage', [App\Http\Controllers\HomeController::class, 'index']);
    Route::post('/senses/inline-confirmations/undo', [App\Http\Controllers\ReadingInlineSenseConfirmationController::class, 'undoInlineConfirmation']);
    Route::delete('/senses/inline-confirmations/{id}', [App\Http\Controllers\ReadingInlineSenseConfirmationController::class, 'revokeInlineConfirmation']);
    Route::get('/senses/possible-duplicates', [App\Http\Controllers\SenseOccurrenceController::class, 'possibleDuplicates']);
    Route::post('/senses/manual', [App\Http\Controllers\ManualWordSenseController::class, 'storeManualSense']);
    Route::put('/senses/{id}/manual', [App\Http\Controllers\ManualWordSenseController::class, 'updateManualSense']);
    Route::put('/senses/{id}/archive', [App\Http\Controllers\ManualWordSenseController::class, 'archiveSense']);
    Route::get('/senses/{id}/examples', [App\Http\Controllers\SenseOccurrenceController::class, 'examples']);
    Route::get('/senses/{id}/source-context', [App\Http\Controllers\SenseSourceContextController::class, 'sourceContext']);
    Route::get('/senses/{id}/source-context-list', [App\Http\Controllers\SenseSourceContextController::class, 'sourceContextList']);
    Route::post('/senses/occurrences/bulk-confirm', [App\Http\Controllers\SenseOccurrenceBulkActionController::class, 'bulkConfirm']);
    Route::post('/senses/occurrences/bulk-ignore', [App\Http\Controllers\SenseOccurrenceBulkActionController::class, 'bulkIgnore']);
    Route::post('/senses/occurrences/bulk-reject', [App\Http\Controllers\SenseOccurrenceBulkActionController::class, 'bulkReject']);
    Route::post('/senses/occurrences/bulk-confirm-high-confidence', [App\Http\Controllers\SenseOccurrenceBulkActionController::class, 'bulkConfirmHighConfidence']);
    Route::post('/senses/occurrences/{id}/confirm', [App\Http\Controllers\SenseOccurrenceActionController::class, 'confirm']);
    Route::post('/senses/occurrences/{id}/bind', [App\Http\Controllers\SenseOccurrenceActionController::class, 'bind']);
    Route::post('/senses/occurrences/{id}/create-sense', [App\Http\Controllers\SenseOccurrenceActionController::class, 'createSense']);
    Route::post('/senses/occurrences/{id}/reject', [App\Http\Controllers\SenseOccurrenceActionController::class, 'reject']);
    Route::post('/senses/occurrences/{id}/ignore', [App\Http\Controllers\SenseOccurrenceActionController::class, 'ignore']);

    // review card management
    Route::get('/review-cards/stats', [App\Http\Controllers\ReviewStatsController::class, 'index']);
    Route::get('/review-cards/manage/data', [App\Http\Controllers\ReviewCardManageController::class, 'data']);
    Route::get('/review-cards/manage/export', [App\Http\Controllers\ReviewCardManageController::class, 'export']);
    Route::get('/review-cards/manage/export-anki-tsv', [App\Http\Controllers\ReviewCardManageController::class, 'exportAnkiTsv']);
    Route::get('/review-cards/manage/export-csv', [App\Http\Controllers\ReviewCardManageController::class, 'exportCsv']);
    Route::get('/review-cards/manage/portable/export-anki', [App\Http\Controllers\PortableDataController::class, 'exportAnki']);
    Route::get('/review-cards/manage/portable/export-json', [App\Http\Controllers\PortableDataController::class, 'exportContentJson']);
    Route::get('/review-cards/manage/portable/export-csv', [App\Http\Controllers\PortableDataController::class, 'exportContentCsv']);
    Route::get('/review-cards/manage/portable/export-full', [App\Http\Controllers\PortableDataController::class, 'exportFullPackage']);
    Route::post('/review-cards/manage/portable/import-preview', [App\Http\Controllers\PortableDataController::class, 'previewImport']);
    Route::post('/review-cards/manage/portable/import-apply', [App\Http\Controllers\PortableDataController::class, 'applyImport']);
    Route::get('/review-cards/manage/saved-searches', [App\Http\Controllers\ReviewCardSavedSearchController::class, 'index']);
    Route::post('/review-cards/manage/saved-searches', [App\Http\Controllers\ReviewCardSavedSearchController::class, 'store']);
    Route::patch('/review-cards/manage/saved-searches/{savedSearch}', [App\Http\Controllers\ReviewCardSavedSearchController::class, 'update']);
    Route::delete('/review-cards/manage/saved-searches/{savedSearch}', [App\Http\Controllers\ReviewCardSavedSearchController::class, 'destroy']);
    Route::get('/review-cards/manage/tags', [App\Http\Controllers\WordSenseTagController::class, 'index']);
    Route::post('/review-cards/manage/tags', [App\Http\Controllers\WordSenseTagController::class, 'store']);
    Route::post('/review-cards/manage/tags/bulk-assignments', [App\Http\Controllers\WordSenseTagController::class, 'bulkAssignments']);
    Route::patch('/review-cards/manage/tags/{tag}', [App\Http\Controllers\WordSenseTagController::class, 'update']);
    Route::delete('/review-cards/manage/tags/{tag}', [App\Http\Controllers\WordSenseTagController::class, 'destroy']);
    Route::post('/review-cards/manage/bulk-marker', [App\Http\Controllers\ReviewCardManageController::class, 'bulkMarker']);
    Route::get('/review-cards/manage/{reviewCard}/logs', [App\Http\Controllers\ReviewCardManageController::class, 'logs']);
    Route::get('/review-cards/manage/{reviewCard}/detail', [App\Http\Controllers\ReviewCardManageController::class, 'detail']);
    Route::patch('/review-cards/manage/{reviewCard}/marker', [App\Http\Controllers\ReviewCardManageController::class, 'marker']);
    Route::patch('/review-cards/manage/{reviewCard}', [App\Http\Controllers\ReviewCardManageController::class, 'update']);
    Route::patch('/review-cards/manage/{reviewCard}/enabled', [App\Http\Controllers\ReviewCardManageController::class, 'enabled']);
    Route::post('/review-cards/manage/{reviewCard}/due-now', [App\Http\Controllers\ReviewCardManageController::class, 'dueNow']);
    Route::post('/review-cards/manage/{reviewCard}/reset', [App\Http\Controllers\ReviewCardManageController::class, 'reset']);
    Route::post('/review-cards/{reviewCard}/manual-operations/preview', [App\Http\Controllers\ReviewCardManualOperationController::class, 'preview']);
    Route::post('/review-cards/{reviewCard}/manual-operations/apply', [App\Http\Controllers\ReviewCardManualOperationController::class, 'apply']);
    Route::post('/review-card-operations/{operationId}/undo', [App\Http\Controllers\ReviewCardManualOperationController::class, 'undo']);
    Route::post('/review-card-operations/{operationId}/redo', [App\Http\Controllers\ReviewCardManualOperationController::class, 'redo']);
    Route::delete('/review-cards/manage/{reviewCard}', [App\Http\Controllers\ReviewCardManageController::class, 'destroy']);
    Route::post('/review-cards/manage/bulk-enabled', [App\Http\Controllers\ReviewCardManageController::class, 'bulkEnabled']);
    Route::post('/review-cards/manage/bulk-delete', [App\Http\Controllers\ReviewCardManageController::class, 'bulkDestroy']);
    Route::post('/review-cards/manage/bulk-lifecycle', [App\Http\Controllers\ReviewCardLifecycleController::class, 'bulkAct']);

    // sense leech governance (ADR-0011)
    Route::get('/review-cards/manage/leech-summary', [App\Http\Controllers\SenseReviewLeechController::class, 'summary']);
    Route::post('/review-cards/manage/bulk-leech-rewrite-packages', [App\Http\Controllers\SenseReviewLeechController::class, 'bulkRewritePackages']);
    Route::get('/reviews/senses/{reviewCard}/leech', [App\Http\Controllers\SenseReviewLeechController::class, 'show']);
    Route::post('/reviews/senses/{reviewCard}/leech/rewrite-package', [App\Http\Controllers\SenseReviewLeechController::class, 'rewritePackage']);

    // review card lifecycle (ADR-0010)
    Route::get('/review-cards/{reviewCard}/lifecycle', [App\Http\Controllers\ReviewCardLifecycleController::class, 'show']);
    Route::post('/review-cards/{reviewCard}/lifecycle-actions', [App\Http\Controllers\ReviewCardLifecycleController::class, 'act']);
    Route::get('/review-cards/{reviewCard}/lifecycle-events', [App\Http\Controllers\ReviewCardLifecycleController::class, 'events']);

    // anki
    Route::post('/anki/add-card', [App\Http\Controllers\AnkiController::class, 'addCardToAnki']);

    // books
    Route::post('/books', [App\Http\Controllers\BookController::class, 'getBooks']);
    Route::get ('/books/get-word-counts/{bookId}', [App\Http\Controllers\BookController::class, 'getBookWordCounts']);
    Route::post('/books/create', [App\Http\Controllers\BookController::class, 'createBook']);
    Route::post('/books/update', [App\Http\Controllers\BookController::class, 'updateBook']);
    Route::post('/books/delete', [App\Http\Controllers\BookController::class, 'deleteBook']);

    // chapters
    Route::post('/chapters', [App\Http\Controllers\ChapterController::class, 'getChaptersForBook']);
    Route::get ('/chapters/word-counts/{bookId}', [App\Http\Controllers\ChapterController::class, 'getChaptersBookCount']);
    Route::post('/chapters/get/reader', [App\Http\Controllers\ChapterController::class, 'getChapterForReader']);
    Route::post('/chapters/get/editor', [App\Http\Controllers\ChapterController::class, 'getChapterForEditor']);
    Route::post('/chapters/delete', [App\Http\Controllers\ChapterController::class, 'deleteChapter']);
    Route::post('/chapters/finish', [App\Http\Controllers\ChapterController::class, 'finishChapter']);
    Route::post('/chapters/update', [App\Http\Controllers\ChapterController::class, 'updateChapter']);
    Route::post('/chapters/create', [App\Http\Controllers\ChapterController::class, 'createChapter']);
    Route::post('/chapters/retry-failed-chapters/{bookId}', [App\Http\Controllers\ChapterController::class, 'retryFailedChapters']);

    // AI reading assist
    Route::post('/chapters/ai-assist/source', [App\Http\Controllers\AiReadingAssistController::class, 'source']);
    Route::post('/chapters/ai-assist/preview', [App\Http\Controllers\AiReadingAssistController::class, 'preview']);
    Route::post('/chapters/ai-assist/confirm', [App\Http\Controllers\AiReadingAssistController::class, 'confirm']);
    Route::get('/chapters/ai-assist/current/{chapterId}', [App\Http\Controllers\AiReadingAssistController::class, 'current']);
    Route::get('/chapters/ai-assist/lookup/{chapterId}', [App\Http\Controllers\AiReadingAssistController::class, 'lookup']);
    Route::get('/chapters/{chapterId}/reading-unfamiliar-targets', [App\Http\Controllers\ReadingUnfamiliarTargetController::class, 'index']);
    Route::post('/chapters/{chapterId}/reading-unfamiliar-targets', [App\Http\Controllers\ReadingUnfamiliarTargetController::class, 'store']);
    Route::delete('/chapters/{chapterId}/reading-unfamiliar-targets/{occurrenceId}', [App\Http\Controllers\ReadingUnfamiliarTargetController::class, 'destroy']);
    Route::get('/chapters/{chapterId}/reading-occurrence-evidence', [App\Http\Controllers\ReadingOccurrenceEvidenceController::class, 'index']);
    Route::post('/chapters/{chapterId}/reading-occurrence-evidence', [App\Http\Controllers\ReadingOccurrenceEvidenceController::class, 'store']);
    Route::post('/chapters/{chapterId}/reading-sessions', [App\Http\Controllers\ReadingSessionController::class, 'store']);
    Route::post('/chapters/reading-sessions/interactions', [App\Http\Controllers\ReadingSessionController::class, 'recordInteraction']);

    // AI study card pending markers
    Route::get('/ai-study-card/pending-items', [App\Http\Controllers\AiStudyCardPendingItemController::class, 'index']);
    Route::post('/ai-study-card/pending-items', [App\Http\Controllers\AiStudyCardPendingItemController::class, 'store']);
    // V3: preview-package route must be before {id} wildcard routes
    Route::post('/ai-study-card/pending-items/preview-package', [App\Http\Controllers\AiStudyCardPendingItemController::class, 'previewPackage']);
    // V4: final-candidates-package route must be before {id} wildcard routes
    Route::post('/ai-study-card/pending-items/final-candidates-package', [App\Http\Controllers\AiStudyCardPendingItemController::class, 'finalCandidatesPackage']);
    // V6-1: provider-disabled request package. No real AI provider call.
    Route::post('/ai-study-card/v6/recommendations/request-package', [App\Http\Controllers\AiStudyCardV6RecommendationController::class, 'requestPackage']);
    Route::post('/ai-study-card/v6/recommendations/provider-preview', [App\Http\Controllers\AiStudyCardV6RecommendationController::class, 'providerPreview']);
    Route::post('/ai-study-card/pending-items/{id}/dismiss', [App\Http\Controllers\AiStudyCardPendingItemController::class, 'dismiss']);
    Route::post('/ai-study-card/pending-items/{id}/restore', [App\Http\Controllers\AiStudyCardPendingItemController::class, 'restore']);
    // V5: generate sense review cards from user-confirmed AI study candidates
    Route::post('/ai-study-card/generate-cards', [App\Http\Controllers\AiStudyCardPendingItemController::class, 'generateCards']);

    // library import
    Route::post('/import', [App\Http\Controllers\ImportController::class, 'import']);
    Route::post('/youtube/get-subtitle-list', [App\Http\Controllers\ImportController::class, 'getYoutubeSubtitles']);
    Route::post('/subtitle/get-subtitle-file-content', [App\Http\Controllers\ImportController::class, 'getSubtitleFileContent']);
    Route::post('/website/get-text', [App\Http\Controllers\ImportController::class, 'getWebsiteText']);
});
