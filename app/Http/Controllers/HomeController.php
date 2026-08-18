<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\GoalService;
use App\Services\HomeStudySummaryQueryService;
use App\Services\LanguageService;

use App\Services\SettingsService;
use App\Services\SafeFilePathService;
use App\Services\StatisticsService;
use App\Services\StatisticsExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// request classes
use App\Http\Requests\Home\GetConfigRequest;

class HomeController extends Controller {

    public function __construct(
        private StatisticsService $statisticsService, 
        private StatisticsExportService $statisticsExportService,
        private GoalService $goalService,
        private LanguageService $languageService,
        private SettingsService $settingsService,
        private HomeStudySummaryQueryService $homeStudySummaryQueryService,
    ) {
        //
    }

    public function index() {
        $user = Auth::user();
        $selectedLanguage = $this->languageService->ensureEnglishMainlineSelection($user);

        $uiLanguage = $this->settingsService->getUserSettingsByName($user->id, ['uiLanguage']);
        if (!$uiLanguage || !$uiLanguage->has('uiLanguage')) {
            $this->settingsService->updateUserSettings($user->id, [
                'uiLanguage' => 'zh-CN',
            ]);
        }

        $userCount = User::count();
        $userName = $user->name;
        $userEmail = $user->email;
        $isAdmin = $user->is_admin === 1;
        $theme = $_COOKIE['theme'] ?? 'dark';
        $themeSettings = $this->settingsService->getUserSettingsByName(
            $user->id,
            ['textStyling', 'vuetifyThemes']
        );
        
        return view('home', [
            'language' => $selectedLanguage,
            'userCount' => $userCount,
            'userName' => $userName,
            'userEmail' => $userEmail,
            'isAdmin' => $isAdmin,
            'theme' => $theme,
            'themeSettings' => $themeSettings,
            'userUuid' => $user->uuid,
        ]);
    }

    public function studySummary(Request $request) {
        $user = $request->user();

        return response()->json(
            $this->homeStudySummaryQueryService->build(
                $user->id,
                $user->selected_language,
            ),
            200,
        );
    }

    public function getStatistics(Request $request) {
        $user = Auth::user();
        $request->validate([
            'period_days' => 'sometimes|integer|in:7,30,90,365',
        ]);

        try {
            $statistics = $this->statisticsService->getStatistics(
                $user->id,
                $user->selected_language,
                $request,
                $user->timezone ?? 'UTC',
            );
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json($statistics, 200);
    }

    public function exportStatistics(Request $request, string $format) {
        abort_unless(in_array($format, ['csv', 'pdf'], true), 404);
        $user = Auth::user();
        $request->validate([
            'period_days' => 'sometimes|integer|in:7,30,90,365',
        ]);
        $report = $this->statisticsService->getStatistics(
            $user->id,
            $user->selected_language,
            $request,
            $user->timezone ?? 'UTC',
        );
        $body = $format === 'csv'
            ? $this->statisticsExportService->csv($report)
            : $this->statisticsExportService->pdf($report);
        $contentType = $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/pdf';

        return response($body, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => "attachment; filename=\"linguacafe-statistics.{$format}\"",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function getConfig($configPath, GetConfigRequest $request) {
        if (strpos($configPath, 'linguacafe') !== 0) {
            abort(500, 'The requested config is not publicly available.');
        }
        
        if (!config()->has($configPath)) {
            abort(500, 'Requested config value does not exist.');
        }

        $config = config($configPath);
        return response()->json($config, 200);
    }

    public function getUserManualTree() {
        $manualTree = [];

        $path = public_path('./../manual/');
        $files = scandir($path);

        $index = 0;
        foreach ($files as $file) {
            // skip
            if ($file === '.' || $file === '..') {
                continue;
            }

            // create page;
            $page = new \stdClass();
            $page->id = $index;
            $page->name = str_replace('.md', '', $file);
            $page->fileName = str_replace('.md', '', $file);
            $page->level = 0;
            $index ++;

            // get subpages
            $subPages = [];
            $handle = fopen('./../manual/' . $file, "r");
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    // if line starts with "# "
                    if (strpos($line, '# ') === 0) {
                        $subPageName = substr($line, 2);
                        $subPageName = str_replace("\r\n", '', $subPageName);
                        $subPageName = str_replace("\n", '', $subPageName);
                        $subPageName = str_replace("\n", '', $subPageName);

                        $subPage = new \stdClass();
                        $subPage->id = $index;
                        $subPage->name = $subPageName;
                        $subPage->fileName = str_replace('.md', '', $file) . '#' . $subPageName;
                        $subPage->level = 1;
                        $subPages[] = $subPage;
                        $index ++;
                    }
                }

                fclose($handle);
            }

            if (count($subPages)) {
                $page->children = $subPages;
            }

            $manualTree[] = $page;
        }

        return response()->json($manualTree, 200);
    }

    public function getUserManualFile($fileName, SafeFilePathService $files) {
        return response()->file($files->resolveExistingDirectChild(
            public_path('../manual'),
            $fileName . '.md'
        ));
    }
}
