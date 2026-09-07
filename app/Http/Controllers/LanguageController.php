<?php

namespace App\Http\Controllers;

use App\Exceptions\LanguageSelectionException;
use App\Http\Requests\Languages\ChangeLanguageRequest;
// services
use App\Http\Requests\Languages\InstallLanguageRequest;
// request classes
use App\Services\LanguageService;
use Illuminate\Support\Facades\Auth;

class LanguageController extends Controller
{
    private $languageService;

    public function __construct(LanguageService $languageService)
    {
        $this->languageService = $languageService;
    }

    public function getLanguageSelectionDialogData()
    {
        $supportedSourceLanguages = config('linguacafe.languages.supported_languages');
        $installableLanguages = config('linguacafe.languages.supported_languages_with_required_install');

        try {
            $languageData = $this->languageService->getLanguageSelectionDialogData($supportedSourceLanguages, $installableLanguages);
        } catch (\Exception $e) {
            return response()->json($e->getMessage(), 500);
        }

        return response()->json($languageData, 200);
    }

    public function getAdminLanguageSettingsData()
    {
        $installableLanguages = config('linguacafe.languages.supported_languages_with_required_install');

        try {
            $installedLanguages = $this->languageService->getInstalledLanguages();
        } catch (\Exception $e) {
            return response()->json($e->getMessage(), 500);
        }

        $responseData = new \stdClass();
        $responseData->languages = $installableLanguages;
        $responseData->installedLanguages = $installedLanguages;

        return response()->json($responseData, 200);
    }

    public function selectLanguage(ChangeLanguageRequest $request)
    {
        $user = Auth::user();
        $language = $request->validated('language');

        try {
            $selectedLanguage = $this->languageService->selectLanguage($user, $language);
        } catch (LanguageSelectionException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ],
            ], $exception->httpStatus);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'The study language could not be changed.',
                'error' => [
                    'code' => 'LANGUAGE_SELECTION_FAILED',
                    'message' => 'The study language could not be changed.',
                ],
            ], 500);
        }

        return response()->json([
            'message' => 'Language has been changed successfully.',
            'language' => $selectedLanguage,
        ], 200);
    }

    public function getInstalledLanguages()
    {
        try {
            $installedLanguages = $this->languageService->getInstalledLanguages();
        } catch (\Exception $e) {
            return response()->json($e->getMessage(), 500);
        }

        return response()->json($installedLanguages, 200);
    }

    public function installLanguage(InstallLanguageRequest $request)
    {
        $installableLanguages = config('linguacafe.languages.supported_languages_with_required_install');
        $language = $request->post('language');

        try {
            $installResult = $this->languageService->installLanguage($language, $installableLanguages);
        } catch (\Exception $e) {
            return response()->json($e->getMessage(), 500);
        }

        if ($installResult->getStatusCode() !== 200) {
            return response()->json('An error has occured.', 500);
        }

        return response()->json('Language has been installed successfully.', 200);
    }

    public function deleteInstalledLanguages()
    {
        $installableLanguages = config('linguacafe.languages.supported_languages_with_required_install');
        $user = Auth::user();

        try {
            $uninstallResult = $this->languageService->deleteInstalledLanguages($user, $installableLanguages);
        } catch (\Exception $e) {
            return response()->json($e->getMessage(), 500);
        }

        if ($uninstallResult->getStatusCode() !== 200 && $uninstallResult->getStatusCode() !== 202) {
            return response()->json('An error has occured.', 500);
        }

        return response()->json('Installed languages has been deleted successfully.', 200);
    }
}
