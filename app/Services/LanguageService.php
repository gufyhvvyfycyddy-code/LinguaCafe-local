<?php

namespace App\Services;

use App\Exceptions\LanguageSelectionException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class LanguageService
{
    // stores the python service container's name
    private $pythonService;

    public function __construct(private GoalService $goalService)
    {
        $this->pythonService = env('PYTHON_CONTAINER_NAME', 'linguacafe-python-service');
    }

    public function selectLanguage(User $user, string $language): string
    {
        $language = $this->normalizeLanguage($language);
        $supportedLanguages = $this->normalizedLanguages(
            config('linguacafe.languages.supported_languages', [])
        );

        if (! in_array($language, $supportedLanguages, true)) {
            throw new LanguageSelectionException(
                'UNSUPPORTED_LANGUAGE',
                'This study language is not supported.',
                422,
            );
        }

        $requiresInstall = in_array(
            $language,
            $this->normalizedLanguages(
                config('linguacafe.languages.supported_languages_with_required_install', [])
            ),
            true,
        );

        // Keep the external availability check outside the database transaction.
        if ($requiresInstall) {
            $installedLanguages = $this->normalizedLanguages($this->getInstalledLanguages());
            if (! in_array($language, $installedLanguages, true)) {
                throw new LanguageSelectionException(
                    'LANGUAGE_NOT_INSTALLED',
                    'This study language is not installed.',
                    409,
                );
            }
        }

        return DB::transaction(function () use ($user, $language): string {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->goalService->ensureDefaultGoalsForLockedUser(
                (int) $lockedUser->id,
                $language,
            );

            $lockedUser->selected_language = $language;
            $lockedUser->save();

            return $language;
        }, 3);
    }

    public function ensureEnglishMainlineSelection(User $user): string
    {
        return DB::transaction(function () use ($user): string {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->goalService->ensureDefaultGoalsForLockedUser(
                (int) $lockedUser->id,
                'english',
            );

            if ($lockedUser->selected_language !== 'english') {
                $lockedUser->selected_language = 'english';
                $lockedUser->save();
            }

            return 'english';
        }, 3);
    }

    public function getLanguageSelectionDialogData($supportedSourceLanguages, $installableLanguages)
    {
        $installedLanguages = $this->getInstalledLanguages();

        // select installed languages only
        $languages = [];
        $notInstalledLanguages = 0;
        foreach ($supportedSourceLanguages as $supportedLanguage) {
            // if it is a language that must be installed, and it is not installed currently
            if (in_array($supportedLanguage, $installableLanguages, true)
                && ! in_array($supportedLanguage, $installedLanguages)) {
                $notInstalledLanguages++;

                continue;
            }

            $languages[] = $supportedLanguage;
        }

        $responseData = new \stdClass();
        $responseData->languages = $languages;
        $responseData->notInstalledLanguages = $notInstalledLanguages;

        return $responseData;
    }

    public function getInstalledLanguages()
    {
        try {
            $installedLanguages = Http::timeout(2)->get($this->pythonServiceUrl().'/models/list');

            if (! $installedLanguages->successful()) {
                return [];
            }

            $decodedLanguages = json_decode($installedLanguages->body());

            return is_array($decodedLanguages) ? $decodedLanguages : [];
        } catch (\Throwable $exception) {
            return [];
        }
    }

    public function installLanguage($language, $installableLanguages)
    {
        if (! in_array($language, $installableLanguages, true)) {
            throw new \Exception('This language does not require install.');
        }

        $installResult = Http::post($this->pythonServiceUrl().'/models/install', [
            'language' => $language,
        ]);

        // Download KanjiVG
        if ($language == 'Japanese') {
            $filePath = Storage::path('temp/kanjivg.zip');
            $extractPath = Storage::path('temp/kanjivg');
            File::delete($filePath);
            Storage::deleteDirectory('temp/kanjivg');
            Storage::deleteDirectory('images/kanjivg');

            $file = file_get_contents('https://github.com/KanjiVG/kanjivg/archive/master.zip');
            file_put_contents($filePath, $file);

            $zip = new \ZipArchive();
            $zipFile = $zip->open($filePath);
            if ($zipFile === true) {
                $zip->extractTo($extractPath);
                $zip->close();

                Storage::move('temp/kanjivg/kanjivg-master/kanji', 'images/kanjivg');
                Storage::deleteDirectory('temp/kanjivg');
                File::delete($filePath);
            } else {
                throw new \Exception('KanjiVG zip file could not be extracted.');
            }
        }

        return $installResult;
    }

    public function deleteInstalledLanguages($user, $installableLanguages)
    {
        /*
            Reset selected language to the default english,
            so the user won't have a language selected that has been uninstalled.
        */
        if (in_array(ucfirst($user->selected_language), $installableLanguages)) {
            $user->selected_language = 'english';
            $user->save();
        }

        // delete KanjiVG files
        Storage::deleteDirectory('images/kanjivg');

        // delete python language models
        $uninstallResult = Http::delete($this->pythonServiceUrl().'/models/remove');

        return $uninstallResult;
    }

    private function normalizeLanguage(string $language): string
    {
        return mb_strtolower(trim($language), 'UTF-8');
    }

    private function normalizedLanguages(array $languages): array
    {
        return array_values(array_unique(array_map(
            fn ($language) => $this->normalizeLanguage((string) $language),
            $languages,
        )));
    }

    private function pythonServiceUrl(): string
    {
        if (str_starts_with($this->pythonService, 'http://') || str_starts_with($this->pythonService, 'https://')) {
            return rtrim($this->pythonService, '/');
        }

        return 'http://'.rtrim($this->pythonService, '/').':8678';
    }
}
