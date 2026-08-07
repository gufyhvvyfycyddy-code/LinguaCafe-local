<?php

namespace App\Http\Controllers;

use App\Exceptions\DictionaryReadException;
use App\Http\Requests\Dictionaries\CreateCustomApiDictionaryRequest;
use App\Models\Dictionary;
use Illuminate\Http\Request;
use App\Services\DictionaryService;
use App\Services\Dictionaries\DictionaryDoctorService;
use Illuminate\Support\Facades\Auth;

// services
use App\Services\DictionaryImportService;

// request classes
use App\Http\Requests\Dictionaries\SearchApiRequest;
use App\Http\Requests\Dictionaries\GetDictionaryRequest;
use App\Http\Requests\Dictionaries\DeleteDictionaryRequest;
use App\Http\Requests\Dictionaries\UpdateDictionaryRequest;
use App\Http\Requests\Dictionaries\SearchDefinitionsRequest;
use App\Http\Requests\Dictionaries\SearchInflectionsRequest;
use App\Http\Requests\Dictionaries\CreateDeeplDictionaryRequest;
use App\Http\Requests\Dictionaries\TestDictionaryCsvFileRequest;
use App\Http\Requests\Dictionaries\ImportDictionaryCsvFileRequest;
use App\Http\Requests\Dictionaries\CreateMyMemoryDictionaryRequest;
use App\Http\Requests\Dictionaries\GetDictionaryRecordCountRequest;
use App\Http\Requests\Dictionaries\ImportSupportedDictionaryRequest;
use App\Http\Requests\Dictionaries\GetDictionaryFileInformationRequest;
use App\Http\Requests\Dictionaries\CreateLibreTranslateDictionaryRequest;
use App\Http\Requests\Dictionaries\SearchDefinitionsForHoverVocabularyRequest;

class DictionaryController extends Controller
{
    private $dictionaryService;
    private $dictionaryImportService;
    private $dictionaryDoctorService;
    
    public function __construct(
        DictionaryService $dictionaryService,
        DictionaryImportService $dictionaryImportService,
        DictionaryDoctorService $dictionaryDoctorService,
    ) {
        $this->dictionaryService = $dictionaryService;
        $this->dictionaryImportService = $dictionaryImportService;
        $this->dictionaryDoctorService = $dictionaryDoctorService;
    }

    /*
        Returns a list of dictionaries.
    */
    public function getDictionaries() {
        try {
            $dictionaries = $this->dictionaryService->getDictionaries();
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'error' => [
                    'code' => 'DICTIONARY_LIST_UNAVAILABLE',
                    'message' => 'Dictionary settings are temporarily unavailable.',
                ],
            ], 500);
        }

        return response()->json($dictionaries, 200);
    }

    public function getDictionary($dictionaryId, GetDictionaryRequest $request) {
        try {
            $dictionary = $this->dictionaryService->getDictionary($dictionaryId);
        } catch (DictionaryReadException $exception) {
            return response()->json([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->publicMessage,
                ],
            ], $exception->httpStatus);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'error' => [
                    'code' => 'DICTIONARY_READ_FAILED',
                    'message' => 'Dictionary information is temporarily unavailable.',
                ],
            ], 500);
        }

        return response()->json($dictionary, 200);
    }

    public function updateDictionary(UpdateDictionaryRequest $request) {
        $dictionaryId = $request->post('id');

        $dictionaryData = [];

        if (isset($request->name)) {
            $dictionaryData['name'] = $request->post('name');
        }

        if (isset($request->api_host)) {
            $dictionaryData['api_host'] = $request->post('api_host');
        }

        if (isset($request->source_language)) {
            $dictionaryData['source_language'] = $request->post('source_language');
        }

        if (isset($request->target_language)) {
            $dictionaryData['target_language'] = $request->post('target_language');
        }

        if (isset($request->color)) {
            $dictionaryData['color'] = $request->post('color');
        }
        
        if (isset($request->enabled)) {
            $dictionaryData['enabled'] = $request->post('enabled');
        }

        try {
            $this->dictionaryService->updateDictionary($dictionaryId, $dictionaryData);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json('Dictionary has been updated successfully.', 200);
    }

    public function isAnyApiDictionaryEnabled() {
        $language = Auth::user()->selected_language;

        try {
            $response = $this->dictionaryService->isAnyApiDictionaryEnabled($language);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }
        
        return response()->json($response, 200);
    }

    public function getDeeplCharacterLimit() {
        try {
            $deeplLimit = $this->dictionaryService->getDeeplCharacterLimit();   
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }
        
        return response()->json($deeplLimit, 200);
    }

    public function searchDefinitions(SearchDefinitionsRequest $request) {
        $language = Auth::user()->selected_language;
        $term = $request->validated('term');

        try {
            return response()->json(
                $this->dictionaryService->searchDefinitions($language, $term),
                200,
            );
        } catch (DictionaryReadException $exception) {
            return $this->dictionaryReadExceptionResponse($exception);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->dictionaryReadFailureResponse();
        }
    }

    /*
        This function returns a list of exact matches from dictionaries for the hover popup vocabulary.
    */
    public function searchDefinitionsForHoverVocabulary(SearchDefinitionsForHoverVocabularyRequest $request) {
        $language = Auth::user()->selected_language;
        $term = $request->validated('term');

        try {
            return response()->json(
                $this->dictionaryService->searchDefinitionsForHoverVocabulary($language, $term),
                200,
            );
        } catch (DictionaryReadException $exception) {
            return $this->dictionaryReadExceptionResponse($exception);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->dictionaryReadFailureResponse();
        }
    }

    public function searchApiDictionaries(SearchApiRequest $request) {
        $language = Auth::user()->selected_language;
        $term = $request->validated('term');

        try {
            return response()->json(
                $this->dictionaryService->searchApiDictionaries($language, $term),
                200,
            );
        } catch (DictionaryReadException $exception) {
            return $this->dictionaryReadExceptionResponse($exception);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->dictionaryReadFailureResponse();
        }
    }

    public function searchInflections(SearchInflectionsRequest $request) {
        $language = Auth::user()->selected_language;
        $term = $request->validated('term');

        try {
            return response()->json(
                $this->dictionaryService->searchInflections($language, $term),
                200,
            );
        } catch (DictionaryReadException $exception) {
            return $this->dictionaryReadExceptionResponse($exception);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->dictionaryReadFailureResponse();
        }
    }

    public function createDeeplDictionary(CreateDeeplDictionaryRequest $request) {
        $sourceLanguage = $request->post('sourceLanguage');
        $targetLanguage = $request->post('targetLanguage');
        $color = $request->post('color');
        $name  = $request->post('name');

        try {
            $this->dictionaryImportService->createDeeplDictionary($sourceLanguage, $targetLanguage, $color, $name);
        } catch(\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json('DeepL dictionary has been created successfully.', 200);
    }

    public function createMyMemoryDictionary(CreateMyMemoryDictionaryRequest $request) {
        $sourceLanguage = $request->validated('sourceLanguage');
        $targetLanguage = $request->validated('targetLanguage');
        $color = $request->validated('color');
        $name  = $request->validated('name');

        try {
            $this->dictionaryImportService->createMyMemoryDictionary($sourceLanguage, $targetLanguage, $color, $name);
        } catch(\Exception $e) {
            abort(500, $e->getMessage());
        }
    }
    
    public function createLibreTranslateDictionary(CreateLibreTranslateDictionaryRequest $request) {
        $sourceLanguage = $request->validated('sourceLanguage');
        $targetLanguage = $request->validated('targetLanguage');
        $color = $request->validated('color');
        $name  = $request->validated('name');

        try {
            $this->dictionaryImportService->createLibreTranslateDictionary($sourceLanguage, $targetLanguage, $color, $name);
        } catch(\Exception $e) {
            abort(500, $e->getMessage());
        }
    }

    public function createCustomApiDictionary(CreateCustomApiDictionaryRequest $request) {
        $sourceLanguage = $request->validated('sourceLanguage');
        $targetLanguage = $request->validated('targetLanguage');
        $color = $request->validated('color');
        $name  = $request->validated('name');
        $host  = $request->validated('api_host');

        try {
            $this->dictionaryImportService->createCustomApiDictionary($sourceLanguage, $targetLanguage, $color, $name, $host);
        } catch(\Exception $e) {
            abort(500, $e->getMessage());
        }
    }

    /*
        This function tests a .csv file, and returns a sample of the data.
        This makes it faster to test a file and notice any problems before
        the user actually imports a large file.
    */
    public function testDictionaryCsvFile(TestDictionaryCsvFileRequest $request) {
        $file = $request->file('dictionary');
        $delimiter = $request->post('delimiter');
        $skipHeader = boolval($request->post('skipHeader') === 'true');

        try {
            $sample = $this->dictionaryImportService->testDictionaryCsvFile($file, $delimiter, $skipHeader);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json($sample, 200);
    }

    public function importDictionaryCsvFile(ImportDictionaryCsvFileRequest $request) {
        set_time_limit(2400);
        $file = $request->file('dictionary');
        $skipHeader = boolval($request->post('skipHeader') === 'true');
        $delimiter = $request->post('delimiter');
        $dictionaryName = $request->post('dictionaryName');
        $databaseTableName = $request->post('databaseName');
        $sourceLanguage = $request->post('sourceLanguage');
        $targetLanguage = $request->post('targetLanguage');
        $color = $request->post('color');

        try {
            $this->dictionaryImportService->importDictionaryCsvFile(
                $file, 
                $skipHeader, 
                $delimiter, 
                $dictionaryName, 
                $databaseTableName, 
                $sourceLanguage, 
                $targetLanguage, 
                $color
            );

        } catch(\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json('Dictionary has been imported successfully.', 200);
    }

    public function getDictionaryFileInformation(GetDictionaryFileInformationRequest $request) {
        $dictionaryFile = $request->file('dictionaryFile');
        $dictCcLanguageCodes = config('linguacafe.languages.dict_cc_language_codes');
        $databaseLanguageCodes = config('linguacafe.languages.database_name_language_codes');
        $supportedSourceLanguages = config('linguacafe.languages.supported_languages');
        
        try {
            $dictionariesFound = $this->dictionaryImportService->getDictionaryFileInformation(
                $dictionaryFile, 
                $supportedSourceLanguages, 
                $dictCcLanguageCodes, 
                $databaseLanguageCodes
            );
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }
        
        return json_encode($dictionariesFound);
    }

    public function importSupportedDictionary(ImportSupportedDictionaryRequest $request)
    {
        set_time_limit(2400);
        $userUuid = Auth::user()->uuid;
        $validated = $request->validated();

        try {
            $imported = $this->dictionaryImportService->importSupportedDictionary(
                $userUuid,
                $validated['dictionaryName'],
                $validated['dictionaryFileName'],
                $validated['dictionarySourceLanguage'],
                $validated['dictionaryTargetLanguage'],
                $validated['dictionaryDatabaseName']
            );
        } catch (\Throwable $exception) {
            report($exception);
            abort(500, 'Dictionary import failed.');
        }

        if ($imported !== true) {
            abort(500, 'Dictionary import failed.');
        }

        return response()->json('Dictionary has been imported successfully.', 200);
    }

    public function getDictionaryRecordCount($dictionaryTableName, GetDictionaryRecordCountRequest $request) {
        try {
            return response()->json([
                'count' => $this->dictionaryService->getDictionaryRecordCount($dictionaryTableName),
            ], 200);
        } catch (DictionaryReadException $exception) {
            return $this->dictionaryReadExceptionResponse($exception);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'error' => [
                    'code' => 'DICTIONARY_RECORD_COUNT_FAILED',
                    'message' => 'Dictionary record count is temporarily unavailable.',
                ],
            ], 500);
        }
    }

    public function doctor()
    {
        try {
            return response()->json($this->dictionaryDoctorService->inspect(), 200);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'error' => [
                    'code' => 'DICTIONARY_DOCTOR_FAILED',
                    'message' => 'Dictionary diagnostics are temporarily unavailable.',
                ],
            ], 500);
        }
    }

    public function deleteDictionary($dictionaryId, DeleteDictionaryRequest $request) {
        try {
            $this->dictionaryService->deleteDictionary($dictionaryId);
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json('Dictionary has been deleted successfully.', 200);
    }

    private function dictionaryReadExceptionResponse(DictionaryReadException $exception)
    {
        return response()->json([
            'error' => [
                'code' => $exception->errorCode,
                'message' => $exception->publicMessage,
            ],
        ], $exception->httpStatus);
    }

    private function dictionaryReadFailureResponse()
    {
        return response()->json([
            'error' => [
                'code' => 'DICTIONARY_LOOKUP_FAILED',
                'message' => 'Dictionary lookup failed.',
            ],
        ], 500);
    }
}
