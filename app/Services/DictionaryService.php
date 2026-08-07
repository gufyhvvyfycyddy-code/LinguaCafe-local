<?php

namespace App\Services;

use App\Exceptions\DictionaryReadException;
use App\Services\Dictionaries\DictionaryHealthService;
use App\Services\Dictionaries\DictionaryLookupResultPolicy;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\Pool;
use League\Csv\Reader;

// models
use App\Models\Dictionary;
use App\Models\Setting;
use App\Models\DeeplCache;
use App\Models\ImportedDictionary;
use App\Models\VocabularyJmdict;

class DictionaryService {

    public function __construct(
        private DictionaryHealthService $dictionaryHealthService,
        private DictionaryLookupResultPolicy $dictionaryLookupResultPolicy,
    ) {
    }

    public function getDictionaries() {
        $dictionaries = Dictionary::query()->orderBy('id')->get();
        $healthById = $this->dictionaryHealthService->classifyCollection($dictionaries);

        foreach ($dictionaries as $dictionary) {
            $health = $healthById[(int) $dictionary->id];
            $dictionary->health = $health->toArray();
            $dictionary->records = $this->recordCountForDictionary($dictionary, $health);
        }

        return $dictionaries;
    }

    public function getDictionary($dictionaryId) {
        $dictionary = Dictionary::query()->where('id', $dictionaryId)->first();

        if (! $dictionary) {
            throw DictionaryReadException::notFound();
        }

        $dictionaries = Dictionary::query()->get();
        $health = $this->dictionaryHealthService->classifyCollection($dictionaries)[(int) $dictionary->id];
        $dictionary->health = $health->toArray();
        $dictionary->records = $this->recordCountForDictionary($dictionary, $health);

        return $dictionary;
    }

    private function recordCountForDictionary(Dictionary $dictionary, $health): int|string|null
    {
        if ($dictionary->database_table_name === 'API') {
            return '-';
        }

        if (! $health->canCountRecords()) {
            return null;
        }

        try {
            return DB::table($dictionary->database_table_name)->count();
        } catch (\Throwable $exception) {
            report($exception);

            $dictionary->health = [
                'status' => 'unknown',
                'code' => 'DICTIONARY_HEALTH_UNKNOWN',
                'message' => '词典状态暂时无法确认。',
                'query_available' => false,
                'repair_required' => true,
            ];

            return null;
        }
    }

    public function updateDictionary($dictionaryId, $dictionaryData) {
        $dictionary = Dictionary
            ::where('id', $dictionaryId)
            ->first();

        if (!$dictionary) {
            throw new \Exception('Dictionary not found.');
        }

        // update dictionary data
        foreach ($dictionaryData as $field => $value) {
            $dictionary->$field = $value;
        }

        $dictionary->save();

        return true;
    }

    public function isAnyApiDictionaryEnabled($language): bool
    {
        $apiDictionary = Dictionary::query()
            ->where('enabled', true)
            ->where('database_table_name','API')
            ->where('source_language', $language)
            ->first();

        return boolval($apiDictionary);
    }

    public function getDeeplCharacterLimit() {
        // retrieve api key from database
        $deeplApiKeySetting = Setting::where('name', 'deeplApiKey')->first();
        $deeplApiKey = json_decode($deeplApiKeySetting->value);
        $deeplHost = Setting::where('name', 'deeplHost')->first()->decode();

        $response = HTTP::withHeaders([
            'Authorization' => 'DeepL-Auth-Key ' . $deeplApiKey,
            'Content-Type' => 'application/json',
        ])->get($deeplHost . '/usage');

        return [
            'limits' => json_decode($response->body()),
            'cachedDeeplTranslations' => DeeplCache::select('id')->count('id'),
        ];
    }

    public function searchDefinitions(string $language, string $term): array
    {
        $dictionaries = $this->applicableLocalDictionaries($language);
        if ($dictionaries->isEmpty()) {
            return [
                'term' => $term,
                'results' => [],
                'warnings' => [],
                'configured' => false,
            ];
        }

        $healthById = $this->dictionaryHealthService->classifyCollection(Dictionary::query()->get());
        $results = [];
        $warnings = [];
        $successfulQueries = 0;

        foreach ($dictionaries as $dictionary) {
            $health = $healthById[(int) $dictionary->id];
            if (! $health->queryAvailable) {
                $warnings[] = $this->warningFromHealth($dictionary, $health);
                continue;
            }

            try {
                $result = [
                    'name' => $dictionary->name,
                    'color' => $dictionary->color,
                ];

                if ($dictionary->database_table_name === DictionaryHealthService::JMDICT_TARGET) {
                    $result['jmdictRecords'] = $this->searchJmDict($term);
                } else {
                    $result['records'] = $this->searchImportedDictionary(
                        $dictionary->database_table_name,
                        $term,
                    );
                }

                $results[] = $result;
                $successfulQueries++;
            } catch (\Throwable $exception) {
                report($exception);
                $warnings[] = $this->runtimeWarning($dictionary);
            }
        }

        if ($successfulQueries === 0) {
            throw DictionaryReadException::lookupUnavailable();
        }

        return [
            'term' => $term,
            'results' => $results,
            'warnings' => $warnings,
            'configured' => true,
        ];
    }

    public function searchDefinitionsForHoverVocabulary(string $language, string $term): array
    {
        $dictionaries = $this->applicableLocalDictionaries($language);
        if ($dictionaries->isEmpty()) {
            return [
                'term' => $term,
                'definitions' => [],
                'warnings' => [],
                'configured' => false,
            ];
        }

        $healthById = $this->dictionaryHealthService->classifyCollection(Dictionary::query()->get());
        $definitions = [];
        $warnings = [];
        $successfulQueries = 0;

        foreach ($dictionaries as $dictionary) {
            $health = $healthById[(int) $dictionary->id];
            if (! $health->queryAvailable) {
                $warnings[] = $this->warningFromHealth($dictionary, $health);
                continue;
            }

            try {
                $records = $dictionary->database_table_name === DictionaryHealthService::JMDICT_TARGET
                    ? $this->searchJmDict($term, true)
                    : $this->searchImportedDictionary($dictionary->database_table_name, $term, true);

                foreach ($records as $record) {
                    foreach ($record->definitions as $definition) {
                        $definitions[] = $definition;
                    }
                }

                $successfulQueries++;
            } catch (\Throwable $exception) {
                report($exception);
                $warnings[] = $this->runtimeWarning($dictionary);
            }
        }

        if ($successfulQueries === 0) {
            throw DictionaryReadException::lookupUnavailable();
        }

        return [
            'term' => $term,
            'definitions' => $this->dictionaryLookupResultPolicy->dedupeAndCap($definitions),
            'warnings' => $warnings,
            'configured' => true,
        ];
    }
    
    public function searchApiDictionaries(string $sourceLanguage, string $term): array
    {
        return $this->searchApiDictionariesIsolated($sourceLanguage, $term);
    }

    private function searchApiDictionariesLegacy(string $sourceLanguage, string $term): array
    {
        $definitions = [];
        $termHash = md5(mb_strtolower($term, 'UTF-8'));
        $apiDictionaries = Dictionary::query()
            ->whereIn('type', ['my_memory', 'deepl', 'libre_translate', 'custom_api'])
            ->where('enabled', true)
            ->where('source_language', $sourceLanguage)
            ->get();

        $responseAdditionalInfo = [];
        $responses = Http::pool(function (Pool $pool) use (
                $apiDictionaries, 
                $term,
                $termHash,
                &$definitions,
                &$responseAdditionalInfo,
        ) {
            foreach ($apiDictionaries as $dictionary) {

                // deepl
                if ($dictionary->type === 'deepl') {
                    // check if search term is already cached
                    $cache = DeeplCache::query()
                        ->where('source_language', $dictionary->source_language)
                        ->where('target_language', $dictionary->target_language)
                        ->where('hash', $termHash)
                        ->first();
                
                    if ($cache) {
                        $definitions[] = [
                            'dictionary' => $dictionary->name,
                            'dictionaryColor' => $dictionary->color,
                            'definitions' => [$cache->definition],
                            'term' => $cache->definition,
                        ];
                    } else {
                        $responseAdditionalInfo[] = [
                            'dictionary' => $dictionary->name,
                            'dictionaryColor' => $dictionary->color,
                            'dictionaryType' => $dictionary->type,
                            'targetLanguage' => $dictionary->target_language,
                            'term' => $term,
                        ];
                        
                        $this->buildDeeplRequest($pool, $dictionary, $term);
                    }
                }

                // my memory api
                if ($dictionary->type === 'my_memory') {
                    $responseAdditionalInfo[] = [
                        'dictionary' => $dictionary->name,
                        'dictionaryColor' => $dictionary->color,
                        'dictionaryType' => $dictionary->type,
                        'term' => $term,
                    ];

                    $this->buildMyMemoryRequest($pool, $dictionary, $term);
                }

                // libre translate
                if ($dictionary->type === 'libre_translate') {
                    $responseAdditionalInfo[] = [
                        'dictionary' => $dictionary->name,
                        'dictionaryColor' => $dictionary->color,
                        'dictionaryType' => $dictionary->type,
                        'term' => $term,
                    ];

                    $this->buildLibreTranslateRequest($pool, $dictionary, $term);
                }

                // custom api translate
                if ($dictionary->type === 'custom_api') {
                    $responseAdditionalInfo[] = [
                        'dictionary' => $dictionary->name,
                        'dictionaryColor' => $dictionary->color,
                        'dictionaryType' => $dictionary->type,
                        'term' => $term,
                    ];

                    $this->buildCustomApiTranslateRequest($pool, $dictionary, $term);
                }
            }
        });

        // format dictionary search responses to a unified format
        foreach($responses as $responseIndex => $response) {
            if (
                !$response instanceof \Illuminate\Http\Client\Response || 
                is_null($response->toPsrResponse()) || 
                !$response->ok()
            ) {
                $definitions[] = [
                    'definitions' => ['error'],
                    ...$responseAdditionalInfo[$responseIndex]
                ];

                continue;
            }

            $dictionaryType = $responseAdditionalInfo[$responseIndex]['dictionaryType'];
            unset($responseAdditionalInfo[$responseIndex]['dictionaryType']);

            if ($dictionaryType === 'deepl') {
                $definition = json_decode($response->body())->translations[0]->text;

                $deeplCache = new DeeplCache();
                $deeplCache->source_language = $sourceLanguage;
                $deeplCache->target_language = $responseAdditionalInfo[$responseIndex]['targetLanguage'];
                $deeplCache->hash = $termHash;
                $deeplCache->definition = $definition;
                $deeplCache->save();

                unset($responseAdditionalInfo[$responseIndex]['targetLanguage']);

                $definitions[] = [
                    'definitions' => [$definition],
                    ...$responseAdditionalInfo[$responseIndex]
                ];
            }
            
            
            if ($dictionaryType === 'my_memory') {
                $myMemoryDefinitions = [];
                $matches = json_decode($response->body());
                foreach($matches->matches as $match) {
                    if(!str_contains($match->segment, $responseAdditionalInfo[$responseIndex]['term'])) {
                        continue;
                    }

                    // updates term in case it found translation only for a similar search term
                    $responseAdditionalInfo[$responseIndex]['term'] = $match->segment;

                    $myMemoryDefinitions[] = $match->translation;
                }

                if (!count($myMemoryDefinitions)) {
                    continue;
                }

                $definitions[] = [
                    'definitions' => $myMemoryDefinitions,
                    ...$responseAdditionalInfo[$responseIndex]
                ];
            }

            if ($dictionaryType === 'libre_translate') {
                $response = json_decode($response->body());
                $definitions[] = [
                    'definitions' => [$response->translatedText],
                    ...$responseAdditionalInfo[$responseIndex]
                ];
            }

            if ($dictionaryType === 'custom_api') {
                $response = json_decode($response->body());
                $definitions[] = [
                    'definitions' => [$response->translatedText],
                    ...$responseAdditionalInfo[$responseIndex]
                ];
            }
        }

        return $definitions;
    }

    private function searchApiDictionariesIsolated(string $sourceLanguage, string $term): array
    {
        $dictionaries = Dictionary::query()
            ->whereIn('type', ['my_memory', 'deepl', 'libre_translate', 'custom_api'])
            ->where('enabled', true)
            ->where('source_language', $sourceLanguage)
            ->orderBy('id')
            ->get();

        if ($dictionaries->isEmpty()) {
            return [
                'term' => $term,
                'results' => [],
                'warnings' => [],
                'configured' => false,
            ];
        }

        $results = [];
        $warnings = [];
        $successfulProviders = 0;

        foreach ($dictionaries as $dictionary) {
            try {
                $providerResult = $this->queryApiProvider($dictionary, $term);
                $successfulProviders++;
                if ($providerResult !== null) {
                    $results[] = $providerResult;
                }
            } catch (\Throwable $exception) {
                report($exception);
                $warnings[] = [
                    'dictionary_id' => (int) $dictionary->id,
                    'dictionary_name' => (string) $dictionary->name,
                    'code' => 'DICTIONARY_API_PROVIDER_FAILED',
                    'message' => '该在线词典暂时无法查询。',
                ];
            }
        }

        if ($successfulProviders === 0) {
            throw DictionaryReadException::lookupUnavailable();
        }

        return [
            'term' => $term,
            'results' => $results,
            'warnings' => $warnings,
            'configured' => true,
        ];
    }

    private function queryApiProvider(Dictionary $dictionary, string $term): ?array
    {
        $definition = null;
        $responseTerm = $term;

        if ($dictionary->type === 'deepl') {
            $termHash = md5(mb_strtolower($term, 'UTF-8'));
            $cache = DeeplCache::query()
                ->where('source_language', $dictionary->source_language)
                ->where('target_language', $dictionary->target_language)
                ->where('hash', $termHash)
                ->first();

            if ($cache) {
                $definition = (string) $cache->definition;
            } else {
                $codes = config('linguacafe.languages.deepl_language_codes', []);
                $sourceCode = $codes[$dictionary->source_language] ?? null;
                $targetCode = $codes[$dictionary->target_language] ?? null;
                $hostSetting = Setting::where('name', 'deeplHost')->first();
                $keySetting = Setting::where('name', 'deeplApiKey')->first();
                if (! $sourceCode || ! $targetCode || ! $hostSetting || ! $keySetting) {
                    throw new \RuntimeException('DeepL provider configuration is incomplete.');
                }

                $sourceCode = $sourceCode === 'EN-US' ? 'EN' : $sourceCode;
                $sourceCode = $sourceCode === 'PT-PT' ? 'PT' : $sourceCode;
                $response = Http::withHeaders([
                    'Authorization' => 'DeepL-Auth-Key '.$keySetting->decode(),
                    'Content-Type' => 'application/json',
                ])->post($hostSetting->decode().'/translate', [
                    'text' => [$term],
                    'source_lang' => $sourceCode,
                    'target_lang' => $targetCode,
                ]);
                $data = $this->validatedJsonResponse($response);
                $definition = $data['translations'][0]['text'] ?? null;
                if (! is_string($definition) || trim($definition) === '') {
                    throw new \RuntimeException('DeepL provider response is invalid.');
                }

                $deeplCache = new DeeplCache();
                $deeplCache->source_language = $dictionary->source_language;
                $deeplCache->target_language = $dictionary->target_language;
                $deeplCache->hash = $termHash;
                $deeplCache->definition = $definition;
                $deeplCache->save();
            }
        } elseif ($dictionary->type === 'my_memory') {
            $codes = config('linguacafe.languages.my_memory_supported_target_languages', []);
            $sourceCode = $codes[$dictionary->source_language] ?? null;
            $targetCode = $codes[$dictionary->target_language] ?? null;
            if (! $sourceCode || ! $targetCode) {
                throw new \RuntimeException('MyMemory provider configuration is incomplete.');
            }

            $response = Http::get('https://api.mymemory.translated.net/get', [
                'q' => $term,
                'langpair' => $sourceCode.'|'.$targetCode,
            ]);
            $data = $this->validatedJsonResponse($response);
            $definitions = [];
            foreach (($data['matches'] ?? []) as $match) {
                if (! is_array($match)) {
                    continue;
                }
                $segment = $match['segment'] ?? null;
                $translation = $match['translation'] ?? null;
                if (! is_string($segment) || ! is_string($translation) || ! str_contains($segment, $term)) {
                    continue;
                }
                $responseTerm = $segment;
                $definitions[] = $translation;
            }

            $definitions = $this->dictionaryLookupResultPolicy->dedupeAndCap($definitions);
            if ($definitions === []) {
                return null;
            }

            return [
                'dictionary' => $dictionary->name,
                'dictionaryColor' => $dictionary->color,
                'definitions' => $definitions,
                'term' => $responseTerm,
            ];
        } elseif ($dictionary->type === 'libre_translate') {
            $codes = config('linguacafe.languages.libre_translate_language_codes', []);
            $host = Setting::where('name', 'libreTranslateHost')->first()?->decode();
            if (! $host || ! isset($codes[$dictionary->source_language], $codes[$dictionary->target_language])) {
                throw new \RuntimeException('LibreTranslate provider configuration is incomplete.');
            }
            $response = Http::post($host, [
                'q' => $term,
                'source' => $codes[$dictionary->source_language],
                'target' => $codes[$dictionary->target_language],
            ]);
            $data = $this->validatedJsonResponse($response);
            $definition = $data['translatedText'] ?? null;
        } elseif ($dictionary->type === 'custom_api') {
            if (! is_string($dictionary->api_host) || trim($dictionary->api_host) === '') {
                throw new \RuntimeException('Custom provider configuration is incomplete.');
            }
            $response = Http::post($dictionary->api_host, [
                'q' => $term,
                'source' => strtolower($dictionary->source_language),
                'target' => strtolower($dictionary->target_language),
            ]);
            $data = $this->validatedJsonResponse($response);
            $definition = $data['translatedText'] ?? null;
        } else {
            throw new \RuntimeException('Unsupported dictionary provider.');
        }

        if (! is_string($definition) || trim($definition) === '') {
            throw new \RuntimeException('Dictionary provider response is invalid.');
        }

        return [
            'dictionary' => $dictionary->name,
            'dictionaryColor' => $dictionary->color,
            'definitions' => [trim($definition)],
            'term' => $responseTerm,
        ];
    }

    private function validatedJsonResponse($response): array
    {
        if (! $response instanceof \Illuminate\Http\Client\Response || ! $response->ok()) {
            throw new \RuntimeException('Dictionary provider request failed.');
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('Dictionary provider response is invalid.');
        }

        return $data;
    }

    private function buildDeeplRequest(Pool $pool, Dictionary $dictionary, string $term): void
    {
        $deeplApiKey = Setting::where('name', 'deeplApiKey')->first()->decode();
        $deeplHost = Setting::where('name', 'deeplHost')->first()->decode();
        $deeplLanguageCodes = config('linguacafe.languages.deepl_language_codes');

        // DeepL does not support 'EN-US' for source language 
        // and 'PT-PT' for language, so I replace them
        $sourceLanguageCode = $deeplLanguageCodes[$dictionary->source_language];
        if ($sourceLanguageCode === 'EN-US') {
            $sourceLanguageCode = 'EN';
        }

        if ($sourceLanguageCode === 'PT-PT') {
            $sourceLanguageCode = 'PT';
        }

        $pool->withHeaders([
            'Authorization' => 'DeepL-Auth-Key ' . $deeplApiKey,
            'Content-Type' => 'application/json',
        ])->post($deeplHost . '/translate', [
            'text' => [$term],
            "source_lang" => $sourceLanguageCode,
            "target_lang" => $deeplLanguageCodes[$dictionary->target_language],
        ]);
    }

    private function buildMyMemoryRequest(Pool $pool, Dictionary $dictionary, string $term): void
    {
        $myMemoryLanguageCodes = config('linguacafe.languages.my_memory_supported_target_languages');
        $sourceLanguageCode = $myMemoryLanguageCodes[$dictionary->source_language];
        $targetLanguageCode = $myMemoryLanguageCodes[$dictionary->target_language];
        $pool->get('https://api.mymemory.translated.net/get?q=' . urlencode($term) . '!&langpair=' . $sourceLanguageCode . '|' . $targetLanguageCode);
    }

    private function buildLibreTranslateRequest(Pool $pool, Dictionary $dictionary, string $term): void
    {
        $myMemoryLanguageCodes = config('linguacafe.languages.libre_translate_language_codes');
        $sourceLanguageCode = $myMemoryLanguageCodes[$dictionary->source_language];
        $targetLanguageCode = $myMemoryLanguageCodes[$dictionary->target_language];
        $libreTranslateHost = json_decode(Setting::where('name', 'libreTranslateHost')->first()->value);
        $pool->post($libreTranslateHost, [
            'q' => $term,
            'source' => $sourceLanguageCode,
            'target' => $targetLanguageCode,
        ]);
    }

    private function buildCustomApiTranslateRequest(Pool $pool, Dictionary $dictionary, string $term): void
    {
        $pool->post($dictionary->api_host, [
            'q' => $term,
            'source' => strtolower($dictionary->source_language),
            'target' => strtolower($dictionary->target_language),
        ]);
    }
    
    public function searchInflections(string $language, string $term): array
    {
        $dictionaries = Dictionary::query()
            ->where('enabled', true)
            ->where('source_language', $language)
            ->where('database_table_name', DictionaryHealthService::JMDICT_TARGET)
            ->get();

        if ($dictionaries->isEmpty()) {
            return [
                'term' => $term,
                'inflections' => [],
                'warnings' => [],
                'configured' => false,
            ];
        }

        $healthById = $this->dictionaryHealthService->classifyCollection(Dictionary::query()->get());
        $warnings = [];

        foreach ($dictionaries as $dictionary) {
            $health = $healthById[(int) $dictionary->id];
            if (! $health->queryAvailable) {
                $warnings[] = $this->warningFromHealth($dictionary, $health);
                continue;
            }

            try {
                return [
                    'term' => $term,
                    'inflections' => $this->searchInflectionsFromJmdict($term),
                    'warnings' => $warnings,
                    'configured' => true,
                ];
            } catch (\Throwable $exception) {
                report($exception);
                $warnings[] = $this->runtimeWarning($dictionary);
            }
        }

        throw DictionaryReadException::lookupUnavailable();
    }

    private function searchInflectionsFromJmdict(string $term): array|string
    {
        $ids = [];
        
        // exact word matches
        $search = VocabularyJmdict
            ::select('id')
            ->whereRelation('words', 'word', 'like', $term)
            ->get()
            ->toArray();

        foreach ($search as $result) {
            if (count($ids)) {
                break;
            }

            if (!in_array($result, $ids, true)) {
                array_push($ids, $result);
            }
        }

        // exact reading matches
        $search = VocabularyJmdict
            ::select('id')
            ->whereRelation('readings', 'reading', 'like', $term)
            ->get()
            ->toArray();

        foreach ($search as $result) {
            if (count($ids)) {
                break;
            }

            if (!in_array($result, $ids, true)) {
                array_push($ids, $result);
            }
        }

        $search = VocabularyJmdict
            ::select('conjugations')
            ->whereIn('id', $ids)
            ->first();
        
        if ($search) {
            return $search->conjugations;
        } else {
            return [];
        }
    }

    public function getDictionaryRecordCount(string $dictionaryTableName): int
    {
        if (
            preg_match('/_(stage|backup|failed)_[a-f0-9]+\z/', $dictionaryTableName) === 1
            || $dictionaryTableName === 'API'
        ) {
            throw DictionaryReadException::recordCountNotAllowed();
        }

        $dictionaries = Dictionary::query()->get();
        $matching = $dictionaries->where('database_table_name', $dictionaryTableName);
        if ($matching->count() !== 1) {
            throw DictionaryReadException::recordCountNotAllowed();
        }

        $dictionary = $matching->first();
        $health = $this->dictionaryHealthService->classifyCollection($dictionaries)[(int) $dictionary->id];
        if (! $health->canCountRecords()) {
            throw DictionaryReadException::recordCountNotAllowed();
        }

        if (
            $dictionaryTableName !== DictionaryHealthService::JMDICT_TARGET
            && ! str_starts_with($dictionaryTableName, 'dict_')
        ) {
            throw DictionaryReadException::recordCountNotAllowed();
        }

        return DB::table($dictionaryTableName)->count();
    }

    public function deleteDictionary($dictionaryId) {
        $dictionary = Dictionary
            ::where('id', $dictionaryId)
            ->first();

        if (!$dictionary) {
            throw new \Exception('Dictionary does not exist.');
        }

        if ($dictionary->database_table_name === 'dict_jp_jmdict') {
            throw new \DomainException('JMDict cannot be deleted.');
        }

        if($dictionary->database_table_name !== 'API') {
            Schema::drop($dictionary->database_table_name);
        }
        
        Dictionary::where('id', $dictionaryId)->delete();

        return true;
    }

    private function applicableLocalDictionaries(string $language)
    {
        return Dictionary::query()
            ->where('enabled', true)
            ->where('source_language', $language)
            ->whereIn('type', ['supported', 'custom_csv'])
            ->where('database_table_name', '<>', 'API')
            ->orderBy('id')
            ->get();
    }

    private function warningFromHealth(Dictionary $dictionary, $health): array
    {
        return [
            'dictionary_id' => (int) $dictionary->id,
            'dictionary_name' => (string) $dictionary->name,
            'code' => $health->code,
            'message' => $health->message,
        ];
    }

    private function runtimeWarning(Dictionary $dictionary): array
    {
        return [
            'dictionary_id' => (int) $dictionary->id,
            'dictionary_name' => (string) $dictionary->name,
            'code' => 'DICTIONARY_QUERY_FAILED',
            'message' => '该词典暂时无法查询。',
        ];
    }

    private function searchImportedDictionary($dictionaryTable, $term, $strict = false) {
        $records = [];
        
        // if strict is true, only return exact matches
        if ($strict) {
            $dictionaryWords = ImportedDictionary
                ::fromTable($dictionaryTable)
                ->where('word', $term)
                ->limit(40)
                ->get();
        } else {
            $dictionaryWords = ImportedDictionary
                ::fromTable($dictionaryTable)
                ->where('word', 'LIKE', $term . '%')
                ->orderByRaw('LENGTH(word)')
                ->limit(40)
                ->get();
        }

        foreach ($dictionaryWords as $word) {
            $definitions = $this->dictionaryLookupResultPolicy->splitImportedDefinitions(
                (string) $word->definitions,
            );
            if ($definitions === []) {
                continue;
            }

            $recordIndex = null;
            foreach ($records as $index => $record) {
                if ($record->word === $word->word) {
                    $recordIndex = $index;
                    break;
                }
            }

            if ($recordIndex === null) {
                $record = new \stdClass();
                $record->word = $word->word;
                $record->definitions = $definitions;
                $records[] = $record;
                continue;
            }

            $records[$recordIndex]->definitions = $this->dictionaryLookupResultPolicy->dedupeAndCap(
                array_merge($records[$recordIndex]->definitions, $definitions),
                40,
            );
        }

        return $records;
    }

    private function searchJmDict($term, $strict = false) {
        $ids = [];
        // exact word matches
        $search = VocabularyJmdict::select('id')->whereRelation('words', 'word', $term)->get()->toArray();
        foreach ($search as $result) {
            if (!in_array($result, $ids, true)) {
                array_push($ids, $result);
            }
        }

        // exact reading matches
        $search = VocabularyJmdict::select('id')->whereRelation('readings', 'reading', $term)->get()->toArray();
        foreach ($search as $result) {
            if (!in_array($result, $ids, true)) {
                array_push($ids, $result);
            }
        }

        // if strict is true, do not return partial matches
        if (!$strict) {
            // partial word matches, max 10
            $search = VocabularyJmdict::select('id')->whereRelation('words', 'word', 'like', $term . '%')->get()->toArray();
            foreach ($search as $result) {
                if (!in_array($result, $ids, true) && count($ids) < 10) {
                    array_push($ids, $result);
                }
            }

            // partial reading matches, max 10
            $search = VocabularyJmdict::select('id')->whereRelation('readings', 'reading', 'like', $term . '%')->get()->toArray();
            foreach ($search as $result) {
                if (!in_array($result, $ids, true) && count($ids) < 10) {
                    array_push($ids, $result);
                }
            }
        }

        $search = VocabularyJmdict::with('words:word,id,dict_jp_jmdict_id')->with('readings:reading,word_restrictions,id,dict_jp_jmdict_id')->whereIn('id', $ids)->get();
        
        $translations = [];
        foreach ($search as $result) {
            $translation = new \stdClass();
            $translation->words = [];
            $translation->definitions = [];
            $translation->conjugations = $result->conjugations == '' ? [] : json_decode($result->conjugations);

            $dictionaryDefinitions = json_decode($result->translations);
            foreach ($dictionaryDefinitions as $definition) {
                if (count($definition->restrictions)) {
                    array_push($translation->definitions, '(' . implode(', ', $definition->restrictions) . ') ' . $definition->definition);
                } else {
                    array_push($translation->definitions, $definition->definition);
                }
            }

            // make each word form a result
            foreach ($result->words as $word) {
                // get all possible readings for each word forms
                foreach ($result->readings as $reading) {
                    array_push($translation->words, $word->word . ' (' . $reading->reading . ')');
                }
            }

            array_push($translations, $translation);
        }

        return $translations;
    }
}