<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Kanji;
use League\Csv\Reader;
use App\Models\Radical;
use App\Models\Dictionary;
use App\Models\VocabularyJmdict;
use Illuminate\Support\Facades\DB;
use App\Models\VocabularyJmdictWord;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use App\Models\VocabularyJmdictReading;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Schema\Blueprint;

class DictionaryImportService {

    public function __construct() {
    }

    private function deleteTempDictionaryFiles() {
        $tempDictionaryFiles = Storage::allFiles('temp/dictionaries');
        Storage::delete($tempDictionaryFiles);
    }

    public function getDictionaryFileInformation($dictionaryFile, $supportedSourceLanguages, $dictCcLanguageCodes, $databaseLanguageCodes) {
        // delete old files from dictionaries temp folder
        $this->deleteTempDictionaryFiles();

        // move uploaded file to the dictionaries temp folder
        $fileName = $dictionaryFile->getClientOriginalName();
        $dictionaryFile->move(storage_path('app/temp/dictionaries'), $fileName);

        // scan the new file
        $dictionary = null;
        
        // jmdict dictionary
        if ($fileName === 'jmdict.zip') {
            $dictionary = new \stdClass();
            $dictionary->name = 'JMDict';
            $dictionary->databaseName = 'dict_jp_jmdict';
            $dictionary->source_language = 'japanese';
            $dictionary->target_language = 'english';
            $dictionary->color = '#74E39A'; 
            $dictionary->expectedRecordCount = 207690;
            $dictionary->fileName = 'jmdict.zip';

            // check if jmdict is imported
            $recordCount = DB::table('dict_jp_jmdict')->count();
        }

        // cc cedict dictionary
        if ($fileName === 'cedict_ts.u8') {
            $dictionary = new \stdClass();
            $dictionary->name = 'cc-cedict';
            $dictionary->databaseName = 'dict_zh_cedict';
            $dictionary->source_language = 'chinese';
            $dictionary->target_language = 'english';
            $dictionary->color = '#EF4556'; 
            $dictionary->expectedRecordCount = 0;
            $dictionary->fileName = 'cedict_ts.u8';

            // check record count
            $handle = fopen(Storage::path('temp/dictionaries/cedict_ts.u8'), "r");
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    if (str_contains($line, '#! entries=')) {
                        $dictionary->expectedRecordCount = intval(explode('#! entries=', $line)[1]);
                        break;
                    }
                }
            }
            
            // close file
            fclose($handle);
        }

        // HanDeDict dictionary
        if ($fileName === 'handedict.u8') {
            $dictionary = new \stdClass();
            $dictionary->name = 'HanDeDict';
            $dictionary->databaseName = 'dict_zh_handedict';
            $dictionary->source_language = 'chinese';
            $dictionary->target_language = 'german';
            $dictionary->color = '#EF4556'; 
            $dictionary->expectedRecordCount = 0;
            $dictionary->fileName = 'handedict.u8';

            // check record count
            $dictionary->expectedRecordCount = $this->getFileLineCount(Storage::path('temp/dictionaries/handedict.u8'));
        }

        // kengdic dictionary
        if ($fileName === 'kengdic.tsv') {
            $dictionary = new \stdClass();
            $dictionary->name = 'kengdic';
            $dictionary->databaseName = 'dict_ko_kengdic';
            $dictionary->source_language = 'korean';
            $dictionary->target_language = 'english';
            $dictionary->color = '#DDBFE4'; 
            $dictionary->expectedRecordCount =  117509;
            $dictionary->fileName = 'kengdic.tsv';

            return $dictionary;
        }

        // eurfa welsh dictionary
        if ($fileName === 'Eurfa_Welsh_Dictionary.csv') {
            $dictionary = new \stdClass();
            $dictionary->name = 'eurfa';
            $dictionary->databaseName = 'dict_cy_eurfa';
            $dictionary->source_language = 'welsh';
            $dictionary->target_language = 'english';
            $dictionary->color = '#32DB4D'; 
            $dictionary->expectedRecordCount =  210579;
            $dictionary->fileName = 'Eurfa_Welsh_Dictionary.csv';

            return $dictionary;
        }
        
        
        // dict cc dictionaries
        if (pathinfo($fileName, PATHINFO_EXTENSION) === 'txt') {
            $supported = true;

            // get language
            $handle = fopen(Storage::path('temp/dictionaries/' . $fileName), "r");
            if ($handle) {
                if (($line = fgets($handle)) !== false) {
                    # example line:
                    # FI-EN vocabulary database	compiled by dict.cc

                    // skip file if it's not a dict cc dictionary
                    if (!str_contains($line, ' vocabulary database	compiled by dict.cc')) {
                        $supported = false;
                    }

                    // split first line by spaces
                    $words = explode(' ', $line);

                    // split second word by '-' character.
                    if (count($words) > 1) {
                        $fileLanguage = explode('-', $words[1]);

                        // skip not supported languages
                        if (
                            !isset($dictCcLanguageCodes[$fileLanguage[0]]) || 
                            !isset($dictCcLanguageCodes[$fileLanguage[1]]) || 
                            !in_array(ucfirst($dictCcLanguageCodes[$fileLanguage[0]]), $supportedSourceLanguages, true)
                            
                        ) {
                            $supported = false;
                        }
                    }
                }
            }

            // close file
            fclose($handle);

            // add the found dictionary to the list
            if ($supported) {
                $dictionary = new \stdClass();
                $dictionary->name = 'dictcc ' . $databaseLanguageCodes[$dictCcLanguageCodes[$fileLanguage[0]]] . '-'. $databaseLanguageCodes[$dictCcLanguageCodes[$fileLanguage[1]]];
                $dictionary->databaseName = 'dict_' . $databaseLanguageCodes[$dictCcLanguageCodes[$fileLanguage[0]]] . '_' . $databaseLanguageCodes[$dictCcLanguageCodes[$fileLanguage[1]]] . '_dict_cc';
                $dictionary->source_language = $dictCcLanguageCodes[$fileLanguage[0]];
                $dictionary->target_language = $dictCcLanguageCodes[$fileLanguage[1]];
                $dictionary->color = '#FF981B'; 
                $dictionary->expectedRecordCount = $this->getFileLineCount(Storage::path('temp/dictionaries/' . $fileName));
                $dictionary->fileName = $fileName;

                return $dictionary;
            }
        }

        // wiktionary dictionaries
        if (pathinfo($fileName, PATHINFO_EXTENSION) === 'tsv') {
            $supported = true;

            // get filename and split into words
            $words = explode('.', pathinfo($fileName, PATHINFO_FILENAME));

            // make sure the file is in a format that's expected
            if (count($words) < 2) {
                $supported = false;
            }

            // skip file if it's not a wiktionary
            if ($words[1] !== 'wiktionary') {
                $supported = false;
            }

            // get language
            $language = strtolower($words[0]);

            if ($supported) {
                $dictionary = new \stdClass();
                $dictionary->name = 'wiktionary ' . $databaseLanguageCodes[$language];
                $dictionary->databaseName = 'dict_' . $databaseLanguageCodes[$language] . '_wiktionary';
                $dictionary->source_language = $language;
                $dictionary->target_language = 'english';
                $dictionary->color = '#E9CDA0'; 
                $dictionary->expectedRecordCount = $this->getFileLineCount(Storage::path('temp/dictionaries/' . $fileName));
                $dictionary->fileName =  $fileName;

                return $dictionary;
            }
        }

        return $dictionary;
    }

    private function getFileLineCount($fileName) {
        $lineCount = 0;
        $file = fopen($fileName, 'r');
        
        if ($file) {
            while(!feof($file)){
                $content = fgets($file);
                if($content) {
                    $lineCount ++;
                }
            }
        } else {
            return -1;
        }

        fclose($file);

        return $lineCount;
    }

    public function testDictionaryCsvFile($file, $delimiter, $skipHeader) {
        $returnData = new \stdClass();
        $returnData->status = 'success';
        $returnData->sample = [];
        $returnData->recordCount = 0;

        // move file to a temp folder
        $fileName = bin2hex(openssl_random_pseudo_bytes(30)) . '.csv';
        $file->move(storage_path('app/temp'), $fileName);
        
        // try to read file and collect sample rows
        try {
            $csv = Reader::createFromPath(storage_path('app/temp') . '/' . $fileName, 'r');
            $csv->setDelimiter($delimiter);
            $records = $csv->getRecords();
            foreach ($records as $index => $record) {
                // ignore header
                if ($skipHeader && !$index) {
                    continue;
                }

                // check if both columns exist
                if (!isset($record[0]) || !isset($record[1])) {
                    throw new \Exception('Missing data.');
                }

                $sampleData = new \stdClass();
                $sampleData->word = mb_strtolower($record[0], 'UTF-8');
                $sampleData->translation = $record[1];

                // this loop runs through the whole file to test for errors
                if (($skipHeader && $index <= 3) || (!$skipHeader && $index < 3)) {
                    $returnData->sample[] = $sampleData;
                }

                $returnData->recordCount ++;
            }
        } catch (\Exception $exception) {
            $returnData->sample = [];
            $returnData->status = 'error';
        }

        File::delete(storage_path('app/temp') . '/' . $fileName);

        return $returnData;
    }
    
    public function importDictionaryCsvFile($file, $skipHeader, $delimiter, $dictionaryName, $databaseTableName, $sourceLanguage, $targetLanguage, $color) {
        set_time_limit(2400);

        $this->assertSafeNewDictionaryTableName($databaseTableName);

        if (mb_strlen($dictionaryName) > 16) {
            throw new \Exception('Dictionary name can only contain up to 16 characters!');
        }

        if (
            Schema::hasTable($databaseTableName)
            || DB::table('dictionaries')->where('database_table_name', $databaseTableName)->exists()
        ) {
            throw new \Exception('Database table name already exists');
        }

        $fileName = bin2hex(openssl_random_pseudo_bytes(30)).'.csv';
        $tempPath = storage_path('app/temp').'/'.$fileName;
        $file->move(storage_path('app/temp'), $fileName);

        try {
            $result = $this->replaceDictionaryFromLoader(
                $dictionaryName,
                $databaseTableName,
                $sourceLanguage,
                $targetLanguage,
                $color,
                function (string $stagingTableName) use ($tempPath, $delimiter, $skipHeader): void {
                    $baselineTransactionLevel = DB::transactionLevel();

                    try {
                        DB::beginTransaction();

                        $csv = Reader::createFromPath($tempPath, 'r');
                        $csv->setDelimiter($delimiter);
                        $records = $csv->getRecords();

                        foreach ($records as $index => $record) {
                            if ($skipHeader && ! $index) {
                                continue;
                            }

                            if (! isset($record[0]) || ! isset($record[1])) {
                                throw new \Exception('Missing data.');
                            }

                            if (mb_strlen($record[0]) > 255 || mb_strlen($record[1]) > 2047) {
                                continue;
                            }

                            DB::table($stagingTableName)->insert([
                                'word' => mb_strtolower($record[0], 'UTF-8'),
                                'definitions' => $record[1],
                                'created_at' => Carbon::now(),
                                'updated_at' => Carbon::now(),
                            ]);
                        }

                        DB::commit();
                    } catch (\Throwable $exception) {
                        $this->rollbackToTransactionLevel($baselineTransactionLevel);

                        throw $exception;
                    }
                },
                'custom_csv',
                false,
            );

            return $result === 'success';
        } finally {
            File::delete($tempPath);
        }
    }
    
    public function importSupportedDictionary($userUuid, $dictionaryName, $dictionaryFileName, $dictionarySourceLanguage, $dictionaryTargetLanguage, $dictionaryDatabaseName) {
        set_time_limit(2400);

        $this->assertSupportedDictionaryDescriptor(
            $dictionaryName,
            $dictionaryFileName,
            $dictionarySourceLanguage,
            $dictionaryTargetLanguage,
            $dictionaryDatabaseName,
        );
        
        // Import the complete Japanese dictionary set as one recoverable operation.
        if ($dictionaryName == 'JMDict') {
            return $this->importJapaneseDictionarySet($userUuid);
        }

        // import cc cedict or HanDeDict file
        if ($dictionaryName == 'cc-cedict' || $dictionaryName == 'HanDeDict') {
            return $this->importCeDictOrHanDeDict(
                $userUuid,
                $dictionaryName,
                $dictionaryTargetLanguage,
                $dictionaryDatabaseName,
                $dictionaryFileName,
            ) === 'success';
        }

        // import kengdic file
        if ($dictionaryName == 'kengdic') {
            return $this->importKengdic(
                $userUuid,
                $dictionaryName,
                $dictionaryDatabaseName,
                $dictionaryFileName,
            ) === 'success';
        }

        // import eurfa files
        if ($dictionaryName == 'eurfa') {
            return $this->importEurfa(
                $userUuid,
                $dictionaryName,
                $dictionaryDatabaseName,
                $dictionaryFileName,
            ) === 'success';
        }
        

        // import dict cc files
        if (str_contains($dictionaryName, 'dictcc')) {
            return $this->importDictCc(
                $userUuid,
                $dictionaryName,
                $dictionarySourceLanguage,
                $dictionaryTargetLanguage,
                $dictionaryFileName,
                $dictionaryDatabaseName,
            ) === 'success';
        }

        // import wiktionary files
        if (str_contains($dictionaryName, 'wiktionary')) {
            return $this->importWiktionary(
                $userUuid,
                $dictionaryName,
                $dictionarySourceLanguage,
                $dictionaryFileName,
                $dictionaryDatabaseName,
            ) === 'success';
        }

        return false;
    }
    
    /*
        Imports a cc-cedict or HanDeDict dictionary file into the database.
        They are in the same format, HanDeDict is just translated to German.
    */
    public function importCeDictOrHanDeDict($userUuid, $dictionaryName, $targetLanguage, $databaseTableName, $fileName) {
        $handle = fopen(Storage::path('temp/dictionaries/'.$fileName), 'r');

        if (! $handle) {
            throw new \RuntimeException('Dictionary source file could not be opened.');
        }

        try {
            return $this->replaceDictionaryFromLoader(
                $dictionaryName,
                $databaseTableName,
                'chinese',
                $targetLanguage,
                '#EF4556',
                function (string $stagingTableName) use ($handle, $userUuid): void {
                    $index = 0;
                    $baselineTransactionLevel = DB::transactionLevel();

                    try {
                        DB::beginTransaction();

                        while (($line = fgets($handle)) !== false) {
                            if ($line === '' || $line[0] === '#') {
                                continue;
                            }

                            $data = explode(' ', $line);

                            if (count($data) < 2) {
                                continue;
                            }

                            $definitions = explode('/', $line);
                            array_shift($definitions);
                            array_pop($definitions);

                            DB::table($stagingTableName)->insert([
                                'word' => mb_strtolower($data[1], 'UTF-8'),
                                'definitions' => mb_strtolower(implode(';', $definitions), 'UTF-8'),
                                'created_at' => Carbon::now(),
                                'updated_at' => Carbon::now(),
                            ]);

                            if ($index % 1000 === 0) {
                                DB::commit();
                                DB::beginTransaction();
                                event(new \App\Events\DictionaryImportProgressedEvent($userUuid, $index));
                            }

                            $index++;
                        }

                        DB::commit();
                    } catch (\Throwable $exception) {
                        $this->rollbackToTransactionLevel($baselineTransactionLevel);

                        throw $exception;
                    }
                },
            );
        } finally {
            fclose($handle);
        }
    }

    /*
        Imports a kengdic dictionary file into the database.
    */
    public function importKengdic($userUuid, $dictionaryName, $databaseTableName, $fileName) {
        $handle = fopen(Storage::path('temp/dictionaries/'.$fileName), 'r');

        if (! $handle) {
            throw new \RuntimeException('Dictionary source file could not be opened.');
        }

        $stagingTableName = $this->makeAuxiliaryTableName($databaseTableName, 'stage');
        $baselineTransactionLevel = DB::transactionLevel();

        try {
            $this->createDictionaryTable($stagingTableName);
            $index = 0;
            DB::beginTransaction();

        while (($line = fgets($handle)) !== false) {
            // skip first line
            if (str_contains($line, 'id	surface')) {
                continue;
            }

            $data = explode('	', $line);

            // skip possible empty rows
            if (count($data) < 4) {
                continue;
            }

            // skip empty definitions
            if (strlen(trim($data[3])) == 0) {
                continue;
            }


            DB::table($stagingTableName)->insert([
                'word' => mb_strtolower(trim($data[1]), 'UTF-8'),
                'definitions' => mb_strtolower(trim($data[3]), 'UTF-8'),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            if ($index % 1000 == 0) {
                DB::commit();
                DB::beginTransaction();

                // send progress through websockets
                event(new \App\Events\DictionaryImportProgressedEvent($userUuid, $index));
            }
            
            $index ++;
        }

            DB::commit();
            $this->publishStagedDictionary(
                $stagingTableName,
                $databaseTableName,
                $dictionaryName,
                'korean',
                'english',
                '#DDBFE4',
                'supported',
            );

            return 'success';
        } catch (\Throwable $exception) {
            $this->rollbackToTransactionLevel($baselineTransactionLevel);
            Schema::dropIfExists($stagingTableName);

            throw $exception;
        } finally {
            fclose($handle);
        }
    }

    /*
        Imports a  dictionary file into the database.
    */
    public function importEurfa($userUuid, $dictionaryName, $databaseTableName, $fileName) {
        $sourcePath = storage_path('app/temp/dictionaries').'/'.$fileName;

        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new \RuntimeException('Dictionary source file could not be opened.');
        }

        $csv = Reader::createFromPath($sourcePath, 'r');
        $stagingTableName = $this->makeAuxiliaryTableName($databaseTableName, 'stage');
        $baselineTransactionLevel = DB::transactionLevel();

        try {
            $this->createDictionaryTable($stagingTableName);
            DB::beginTransaction();
            $index = 0;
            $records = $csv->getRecords();
        foreach ($records as$record) {

            // check if both columns exist
            if (!isset($record[1]) || !isset($record[2]) || !isset($record[3])) {
                throw new \Exception('Missing data.');
            }

            // add word 
            DB::table($stagingTableName)->insert([
                'word' => mb_strtolower($record[1], 'UTF-8'),
                'definitions' => $record[3],
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            // add lemma too, because there is no lemmatisation for welsh
            DB::table($stagingTableName)->insert([
                'word' => mb_strtolower($record[2], 'UTF-8'),
                'definitions' => $record[3],
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            if ($index % 1000 == 0) {
                DB::commit();
                DB::beginTransaction();

                // send progress through websockets
                event(new \App\Events\DictionaryImportProgressedEvent($userUuid, $index));
            }
            
            $index ++;
        }

            DB::commit();
            $this->publishStagedDictionary(
                $stagingTableName,
                $databaseTableName,
                $dictionaryName,
                'welsh',
                'english',
                '#32DB4D',
                'supported',
            );

            return 'success';
        } catch (\Throwable $exception) {
            $this->rollbackToTransactionLevel($baselineTransactionLevel);
            Schema::dropIfExists($stagingTableName);

            throw $exception;
        }
    }

    /*
        Imports a dict cc dictionary file into the database.
    */
    public function importDictCc($userUuid, $dictionaryName, $sourceLanguage, $targetLanguage, $fileName, $databaseTableName) {
        $handle = fopen(Storage::path('temp/dictionaries/'.$fileName), 'r');

        if (! $handle) {
            throw new \RuntimeException('Dictionary source file could not be opened.');
        }

        $stagingTableName = $this->makeAuxiliaryTableName($databaseTableName, 'stage');
        $baselineTransactionLevel = DB::transactionLevel();

        try {
            $this->createDictionaryTable($stagingTableName);
            $index = 0;
            DB::beginTransaction();

        while (($line = fgets($handle)) !== false) {
            // skip comments
            if ($line[0] == '#') {  
                continue;
            }

            $data = explode('	', $line);

            // skip empty rows
            if (count($data) < 2) {
                continue;
            }

            DB::table($stagingTableName)->insert([
                'word' => mb_strtolower(trim($data[0]), 'UTF-8'),
                'definitions' => mb_strtolower(trim($data[1]), 'UTF-8'),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            if ($index % 1000 == 0) {
                DB::commit();
                DB::beginTransaction();

                // send progress through websockets
                event(new \App\Events\DictionaryImportProgressedEvent($userUuid, $index));
            }
            
            $index ++;
        }

            DB::commit();
            $this->publishStagedDictionary(
                $stagingTableName,
                $databaseTableName,
                $dictionaryName,
                $sourceLanguage,
                $targetLanguage,
                '#FF981B',
                'supported',
            );

            return 'success';
        } catch (\Throwable $exception) {
            $this->rollbackToTransactionLevel($baselineTransactionLevel);
            Schema::dropIfExists($stagingTableName);

            throw $exception;
        } finally {
            fclose($handle);
        }
    }

    /*
        Imports a wiktionary dictionary file into the database.
    */
    public function importWiktionary($userUuid, $dictionaryName, $sourceLanguage, $fileName, $databaseTableName) {
        $handle = fopen(Storage::path('temp/dictionaries/'.$fileName), 'r');

        if (! $handle) {
            throw new \RuntimeException('Dictionary source file could not be opened.');
        }

        $stagingTableName = $this->makeAuxiliaryTableName($databaseTableName, 'stage');
        $baselineTransactionLevel = DB::transactionLevel();

        try {
            $this->createDictionaryTable($stagingTableName);
            $index = 0;
            DB::beginTransaction();

        while (($line = fgets($handle)) !== false) {
            
            $data = explode('	', $line);

            // skip empty rows
            if (count($data) < 2) {
                continue;
            }

            // extract word
            $word = explode('|', trim($data[0]))[0];
            $word = mb_strtolower(trim($word), 'UTF-8');

            // extract definitions from <li> tags
            $filteredDefinitions = [];
            $definitions = mb_strtolower(trim($data[1]), 'UTF-8');
            $definitions = explode('<li>', $definitions);
            
            foreach ($definitions as $definitionCounter => $definition) {
                if (!$definitionCounter) {
                    continue;
                }

                $filteredDefinitions[] = explode('</li>', $definition)[0];
            }

            // join filtered definitions
            $filteredDefinitions = implode(';', $filteredDefinitions);

            // skip too long definitions
            if (strlen($filteredDefinitions) > 254) {
                continue;
            }

            DB::table($stagingTableName)->insert([
                'word' => $word,
                'definitions' => $filteredDefinitions,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            if ($index % 1000 == 0) {
                DB::commit();
                DB::beginTransaction();

                // send progress through websockets
                event(new \App\Events\DictionaryImportProgressedEvent($userUuid, $index));
            }
            
            $index ++;
        }

            DB::commit();
            $this->publishStagedDictionary(
                $stagingTableName,
                $databaseTableName,
                $dictionaryName,
                $sourceLanguage,
                'english',
                '#E9CDA0',
                'supported',
            );

            return 'success';
        } catch (\Throwable $exception) {
            $this->rollbackToTransactionLevel($baselineTransactionLevel);
            Schema::dropIfExists($stagingTableName);

            throw $exception;
        } finally {
            fclose($handle);
        }
    }

    /*
        Imports kanji radicals.
    */
    public function kanjiRadicalImport(bool $publish = true): array {
        $file = fopen(Storage::path('temp/dictionaries/radicals.txt'), 'r');
        $radicalStrokesFile = fopen(Storage::path('temp/dictionaries/radical-strokes.txt'), 'r');

        if (! $file || ! $radicalStrokesFile) {
            if (is_resource($file)) {
                fclose($file);
            }
            if (is_resource($radicalStrokesFile)) {
                fclose($radicalStrokesFile);
            }

            throw new \RuntimeException('Kanji radical source files could not be opened.');
        }

        $liveTableName = 'dict_jp_kanji_radicals';
        $stagingTableName = $this->makeAuxiliaryTableName($liveTableName, 'stage');
        $baselineTransactionLevel = DB::transactionLevel();

        try {
            $this->createTableLike($stagingTableName, $liveTableName);
            DB::beginTransaction();
            $index = 0;

        // these kanjis has to be replaced with radicals
        // based on the description of the input files
        $replacements = [
            '化' => '⺅',
            '个' => '𠆢',
            '并' => '丷',
            '刈' => '⺉',
            '込' => '⻌',
            '尚' => '⺌',
            '忙' => '⺖',
            '扎' => '扌',
            '汁' => '⺡',
            '犯' => '⺨',
            '艾' => '⺾',
            '邦' => '⻏',
            '阡' => '⻖',
            '老' => '⺹',
            '杰' => '⺣',
            '礼' => '⺭',
            '疔' => '⽧',
            '禹' => '⽱',
            '初' => '⻂',
            '買' => '⺲',
            '滴' => '啇',
            //乞 has no character, an image must be displayed
        ];


        // load radical stroke counts into an array
        $radicalStrokeCountsData = [];

        while (($line = fgets($radicalStrokesFile)) !== false) {
            $data = explode(' ', $line);
            $radicalStrokeCountsData[$data[0]] = $data[1];
        }

        // loop through the radicals files
        while (($line = fgets($file)) !== false) {
            // skip commented lines
            if ($line[0] == '#') {
                continue;
            }

            $data = explode(' : ', $line);
            $radicals = explode(' ', trim($data[1]));
            $processedRadicals = [];
            
            // collects the radicals into an array of objects
            // that contains both the radical and the stroke counts
            foreach($radicals as $radical) {
                $processedRadical = $radical;

                // replacing kanjis with radicals
                foreach($replacements as $original => $replacement) {
                    if ($processedRadical == $original) {
                        $processedRadical = $replacement;
                    }
                }

                $radicalObject = new \stdClass();
                $radicalObject->radical = $processedRadical;
                $radicalObject->strokes = $radicalStrokeCountsData[$radical];

                array_push($processedRadicals, $radicalObject);
            }

            // save radical
            $radical = new Radical();
            $radical->setTable($stagingTableName);
            $radical->kanji = trim($data[0]);
            $radical->radicals = json_encode($processedRadicals);
            $radical->save();

            $index ++;
        }

            DB::commit();
            $stagedTables = [
                $liveTableName => $stagingTableName,
            ];

            if ($publish) {
                $this->publishStagedTableSet($stagedTables);

                return [];
            }

            return $stagedTables;
        } catch (\Throwable $exception) {
            $this->rollbackToTransactionLevel($baselineTransactionLevel);
            Schema::dropIfExists($stagingTableName);

            throw $exception;
        } finally {
            fclose($file);
            fclose($radicalStrokesFile);
        }
    }

    /*
        Imports kanji.
    */
    public function kanjiImport(bool $publish = true): array {
        $jlpt = [
            '1' => 1,
            '2' => 2,
            '3' => 4,
            '4' => 5,
        ];

        $doc = new \DOMDocument();
        $reader = new \XMLReader();

        if (! $reader->open(Storage::path('temp/dictionaries/kanjidic2.xml'))) {
            throw new \RuntimeException('Kanji source file could not be opened.');
        }

        $liveTableName = 'dict_jp_kanji';
        $stagingTableName = $this->makeAuxiliaryTableName($liveTableName, 'stage');
        $baselineTransactionLevel = DB::transactionLevel();

        try {
            $this->createTableLike($stagingTableName, $liveTableName);
            $index = 0;
            DB::beginTransaction();
        while ($reader->read() && $reader->name !== 'character');
        while ($reader->name === 'character') {
            $node = simplexml_import_dom($doc->importNode($reader->expand(), true));
            
            $kanji = new Kanji();
            $kanji->setTable($stagingTableName);
            $kanji->kanji = $node->literal->__toString();
            $meanings = [];
            $readings_on = [];
            $readings_kun = [];
            $kanji->grade = 0;
            $kanji->strokes = 0;
            $kanji->frequency = 0;
            $kanji->jlpt = 0;

            // grade
            // 1-6 school
            // 8 Jouyou kanji
            // 9-10 Jinmeiyou kanji, used for names
            if (isset($node->misc->grade)) {
                $kanji->grade = intval($node->misc->grade->__toString());
                if ($kanji->grade == 9) {
                    $kanji->grade = 10;
                }
            }
            
            // stoke count
            if (isset($node->misc->stroke_count)) {
                $kanji->strokes = intval($node->misc->stroke_count->__toString());
            }

            // frequency (based on modern newspapers 1-2501)
            if (isset($node->misc->freq)) {
                $kanji->frequency = intval($node->misc->freq->__toString());
            }
            
            // jlpt level (2 is 2/3 in the new system)
            if (isset($node->misc->jlpt)) {
                $kanji->jlpt = $jlpt[$node->misc->jlpt->__toString()];
            }
            
            // readings
            if (isset($node->reading_meaning) && isset($node->reading_meaning->rmgroup) && count($node->reading_meaning->rmgroup->reading)) {
                for ($i = 0; $i < count($node->reading_meaning->rmgroup->reading); $i++) {
                    $element = $node->reading_meaning->rmgroup->reading[$i];
                    if (isset($element->attributes()->r_type)) {
                        
                        // on reading
                        if ($element->attributes()->r_type == 'ja_on') {
                            array_push($readings_on, $element->__toString());
                        }

                        // kun reading
                        if ($element->attributes()->r_type == 'ja_kun') {
                            array_push($readings_kun, $element->__toString());
                        }
                    }
                }
            }

            // meanings
            if (isset($node->reading_meaning) && isset($node->reading_meaning->rmgroup) && count($node->reading_meaning->rmgroup->meaning)) {
                for ($i = 0; $i < count($node->reading_meaning->rmgroup->meaning); $i++) {
                    $element = $node->reading_meaning->rmgroup->meaning[$i];
                    
                    // english meanings
                    if (!isset($element->attributes()->m_lang)) {
                        array_push($meanings, $element->__toString());
                    }   
                }
            }

            // save kanji in database
            $kanji->meanings = json_encode($meanings);
            $kanji->readings_on = json_encode($readings_on);
            $kanji->readings_kun = json_encode($readings_kun);
            $kanji->save();
            $index ++;
            $reader->next('character');
        }

            DB::commit();
            $stagedTables = [
                $liveTableName => $stagingTableName,
            ];

            if ($publish) {
                $this->publishStagedTableSet($stagedTables);

                return [];
            }

            return $stagedTables;
        } catch (\Throwable $exception) {
            $this->rollbackToTransactionLevel($baselineTransactionLevel);
            Schema::dropIfExists($stagingTableName);

            throw $exception;
        } finally {
            $reader->close();
        }
    }

    /*
        Imports jmdict dictionary file.
    */
    public function jmdictImport($userUuid, bool $publish = true): array {
        // extract zip file
        $filePath = Storage::path('temp/dictionaries/jmdict.zip');
        $extractPath = Storage::path('temp/dictionaries');

        $requiredFiles = [
            'jmdict_processed.txt',
            'kanjidic2.xml',
            'radicals.txt',
            'radical-strokes.txt',
        ];

        $zip = new \ZipArchive();
        $zipFile = $zip->open($filePath);

        if ($zipFile !== true) {
            throw new \Exception('JMDict zip file could not be extracted.');
        }

        try {
            foreach ($requiredFiles as $requiredFile) {
                if ($zip->locateName($requiredFile) === false) {
                    throw new \RuntimeException('Japanese dictionary package is incomplete.');
                }
            }

            if (! $zip->extractTo($extractPath, $requiredFiles)) {
                throw new \RuntimeException('Japanese dictionary package could not be extracted.');
            }
        } finally {
            $zip->close();
        }

        foreach ($requiredFiles as $requiredFile) {
            $requiredPath = Storage::path('temp/dictionaries/'.$requiredFile);

            if (! is_file($requiredPath) || ! is_readable($requiredPath)) {
                throw new \RuntimeException('Japanese dictionary package is incomplete or unreadable.');
            }
        }

        // import jmdict file
        $file = fopen(Storage::path('temp/dictionaries/jmdict_processed.txt'), 'r');

        if (! $file) {
            throw new \RuntimeException('Processed JMDict source file could not be opened.');
        }

        $liveMainTable = 'dict_jp_jmdict';
        $liveWordsTable = 'dict_jp_jmdict_words';
        $liveReadingsTable = 'dict_jp_jmdict_readings';
        $stagingMainTable = $this->makeAuxiliaryTableName($liveMainTable, 'stage');
        $stagingWordsTable = $this->makeAuxiliaryTableName($liveWordsTable, 'stage');
        $stagingReadingsTable = $this->makeAuxiliaryTableName($liveReadingsTable, 'stage');
        $baselineTransactionLevel = DB::transactionLevel();

        try {
            $this->createTableLike($stagingMainTable, $liveMainTable);
            $this->createTableLike($stagingWordsTable, $liveWordsTable);
            $this->createTableLike($stagingReadingsTable, $liveReadingsTable);

            $index = 0;
            DB::beginTransaction();
        while (($line = fgets($file)) !== false) {
            $data = explode('|', str_replace(["\r\n", "\r", "\n"], '', $line));
            
            // save main vocab model
            if (! isset($data[0], $data[1], $data[2], $data[3])) {
                throw new \Exception('Missing JMDict data.');
            }

            $vocabulary = new VocabularyJmdict();
            $vocabulary->setTable($stagingMainTable);
            $vocabulary->translations = $data[2];

            if (mb_strlen($data[3]) > 2) {
                $vocabulary->conjugations = $data[3];
            } else {
                $vocabulary->conjugations = '';
            }

            $vocabulary->save();
            
            // save vocab words
            $words = explode(';', $data[0]);
            foreach ($words as $word) {
                $jmdictWord = new VocabularyJmdictWord();
                $jmdictWord->setTable($stagingWordsTable);
                $jmdictWord->word = $word;
                $jmdictWord->dict_jp_jmdict_id = $vocabulary->id;
                $jmdictWord->save();
            }

            // save vocab readings
            $readings = explode(';', $data[1]);
            foreach ($readings as $reading) {
                $restrictions = explode('RE_RESTR', $reading);
                if (count($restrictions) > 1) {
                    $reading = array_shift($restrictions);
                    $restrictions = json_encode($restrictions);
                } else {
                    $reading = $restrictions[0];
                    $restrictions = '';
                }

                $jmdictReading = new VocabularyJmdictReading();
                $jmdictReading->setTable($stagingReadingsTable);
                $jmdictReading->reading = $reading;
                $jmdictReading->word_restrictions = $restrictions;
                $jmdictReading->dict_jp_jmdict_id = $vocabulary->id;
                $jmdictReading->save();
            }
            
            if ($index % 1000 == 0) {
                DB::commit();
                DB::beginTransaction();

                // send progress through websockets
                event(new \App\Events\DictionaryImportProgressedEvent($userUuid, $index));
            }
            
            $index ++;
        }   
        
            DB::commit();
            $stagedTables = [
                $liveMainTable => $stagingMainTable,
                $liveWordsTable => $stagingWordsTable,
                $liveReadingsTable => $stagingReadingsTable,
            ];

            if ($publish) {
                $this->publishJapaneseDictionaryTables($stagedTables);

                return [];
            }

            return $stagedTables;
        } catch (\Throwable $exception) {
            $this->rollbackToTransactionLevel($baselineTransactionLevel);
            Schema::dropIfExists($stagingReadingsTable);
            Schema::dropIfExists($stagingWordsTable);
            Schema::dropIfExists($stagingMainTable);

            throw $exception;
        } finally {
            fclose($file);
        }
    }

    /* 
        Converts jmdict to text. It is used to create the file that can be imported into linguacafe, it should be moved to python.
    */
    public function jmdictXmlToText() {
        $file = fopen(base_path() . '/storage/app/temp/dictionaries/jmdict.txt', 'w');
        $doc = new \DOMDocument();
        $reader = new \XMLReader();
        $reader->open(base_path() . '/storage/app/temp/dictionaries/JMdict_e.xml');
        $index = 0;
        

        while ($reader->read() && $reader->name !== 'entry');
        while ($reader->name === 'entry') {
            $entry = new \stdClass();
            $entry->all_words = '';
            $entry->all_readings = '';
            $node = simplexml_import_dom($doc->importNode($reader->expand(), true));
            
            // get all words
            if (isset($node->k_ele)) {
                // first word
                $entry->word = $node->k_ele[0]->keb->__toString();

                // all words
                for ($i = 0; $i < count($node->k_ele); $i++) {
                    if ($i) {
                        $entry->all_words .= ';';
                    }

                    $entry->all_words .= $node->k_ele[$i]->keb->__toString();
                }
            } else if (isset($node->r_ele)) {
                // use reading if there's no kanji word
                $entry->word = $node->r_ele[0]->reb->__toString();
            }

            // get all readings
            if (isset($node->r_ele)) {
                // all readings
                for ($i = 0; $i < count($node->r_ele); $i++) {
                    if ($i) {
                        $entry->all_readings .= ';';
                    }

                    $entry->all_readings .= $node->r_ele[$i]->reb->__toString();
                    if (isset($node->r_ele[$i]->re_restr)) {
                        for ($j = 0; $j < count($node->r_ele[$i]->re_restr); $j++) {
                            $entry->all_readings .= 'RE_RESTR' . $node->r_ele[$i]->re_restr[$j]->__toString();
                        }
                    }
                }
            }

            // get word translation and pos
            $entry->translations = [];
            $entry->pos = '';
            for ($i = 0; $i < count($node->sense); $i++) {
                // get restrictions for translation
                $restrictions = [];
                for ($j = 0; $j < count($node->sense[$i]->stagr); $j++) {
                    array_push($restrictions, $node->sense[$i]->stagr[$j]->__toString());
                }

                // definitions
                for ($j = 0; $j < count($node->sense[$i]->gloss); $j++) {
                    $translation = new \stdClass();
                    $translation->restrictions = $restrictions;
                    $translation->definition = $node->sense[$i]->gloss[$j]->__toString();
                    array_push($entry->translations, $translation);
                }

                // part of speech
                for ($j = 0; $j < count($node->sense[$i]->pos); $j++) {
                    // only need these conjugations in the output file
                    $conjugations = ["adj-i", "adj-ix", "adj-na", "v1", "v1-s", "v5aru", "v5b", "v5g", "v5k", "v5k-s", "v5m", "v5n", "v5r", "v5r-i", "v5s", "v5t", "v5u", "v5u-s", "vk", "vs", "vs-i", "vs-s"];
                    
                    if (mb_strlen($entry->word) > 1 && in_array(array_keys(get_object_vars($node->sense[$i]->pos[$j]))[0], $conjugations)) {
                        $entry->pos = array_keys(get_object_vars($node->sense[$i]->pos[$j]))[0];
                    }
                }
            }

            fwrite($file, $entry->word . '|' . $entry->all_words . '|' . $entry->all_readings . '|' . $entry->pos . '|' . json_encode($entry->translations) . "\r\n");
            $index ++;
            $reader->next('entry');
        }

        fclose($file);
        echo('finished');
    }

    public function createDeeplDictionary($sourceLanguage, $targetLanguage, $color, $name) {
        $dictionary = new Dictionary();
        $dictionary->name = $name;
        $dictionary->type = 'deepl';
        $dictionary->database_table_name = 'API';
        $dictionary->source_language = $sourceLanguage;
        $dictionary->target_language = $targetLanguage;
        $dictionary->color = $color;
        $dictionary->enabled = true;
        $dictionary->save();

        return true;
    }

    public function createMyMemoryDictionary($sourceLanguage, $targetLanguage, $color, $name) {
        $dictionary = new Dictionary();
        $dictionary->name = $name;
        $dictionary->type = 'my_memory';
        $dictionary->database_table_name = 'API';
        $dictionary->source_language = $sourceLanguage;
        $dictionary->target_language = $targetLanguage;
        $dictionary->color = $color;
        $dictionary->enabled = true;
        $dictionary->save();

        return true;
    }

    public function createLibreTranslateDictionary($sourceLanguage, $targetLanguage, $color, $name) {
        $dictionary = new Dictionary();
        $dictionary->name = $name;
        $dictionary->type = 'libre_translate';
        $dictionary->database_table_name = 'API';
        $dictionary->source_language = $sourceLanguage;
        $dictionary->target_language = $targetLanguage;
        $dictionary->color = $color;
        $dictionary->enabled = true;
        $dictionary->save();

        return true;
    }

    public function createCustomApiDictionary($sourceLanguage, $targetLanguage, $color, $name, $host) {
        $dictionary = new Dictionary();
        $dictionary->name = $name;
        $dictionary->type = 'custom_api';
        $dictionary->api_host = $host;
        $dictionary->database_table_name = 'API';
        $dictionary->source_language = $sourceLanguage;
        $dictionary->target_language = $targetLanguage;
        $dictionary->color = $color;
        $dictionary->enabled = true;
        $dictionary->save();

        return true;
    }

    private function assertSupportedDictionaryDescriptor(
        string $dictionaryName,
        string $dictionaryFileName,
        string $sourceLanguage,
        string $targetLanguage,
        string $databaseTableName,
    ): void {
        $fixedDescriptors = [
            'JMDict' => ['jmdict.zip', 'japanese', 'english', 'dict_jp_jmdict'],
            'cc-cedict' => ['cedict_ts.u8', 'chinese', 'english', 'dict_zh_cedict'],
            'HanDeDict' => ['handedict.u8', 'chinese', 'german', 'dict_zh_handedict'],
            'kengdic' => ['kengdic.tsv', 'korean', 'english', 'dict_ko_kengdic'],
            'eurfa' => ['Eurfa_Welsh_Dictionary.csv', 'welsh', 'english', 'dict_cy_eurfa'],
        ];

        if (isset($fixedDescriptors[$dictionaryName])) {
            $this->assertDictionaryDescriptorValues(
                $fixedDescriptors[$dictionaryName],
                [$dictionaryFileName, $sourceLanguage, $targetLanguage, $databaseTableName],
            );

            return;
        }

        $databaseLanguageCodes = config('linguacafe.languages.database_name_language_codes', []);
        $sourceLanguage = strtolower($sourceLanguage);
        $targetLanguage = strtolower($targetLanguage);
        $sourceCode = $databaseLanguageCodes[$sourceLanguage] ?? null;
        $targetCode = $databaseLanguageCodes[$targetLanguage] ?? null;

        if (str_starts_with($dictionaryName, 'dictcc ')) {
            if ($sourceCode === null || $targetCode === null) {
                throw new \RuntimeException('Supported dictionary import details do not match the detected dictionary.');
            }

            $this->assertDictionaryDescriptorValues(
                [
                    'dictcc '.$sourceCode.'-'.$targetCode,
                    'dict_'.$sourceCode.'_'.$targetCode.'_dict_cc',
                    'txt',
                ],
                [
                    $dictionaryName,
                    $databaseTableName,
                    strtolower(pathinfo($dictionaryFileName, PATHINFO_EXTENSION)),
                ],
            );
            $this->assertDictCcSourceLanguages(
                $dictionaryFileName,
                $sourceLanguage,
                $targetLanguage,
            );

            return;
        }

        if (str_starts_with($dictionaryName, 'wiktionary ')) {
            if ($sourceCode === null || $targetLanguage !== 'english') {
                throw new \RuntimeException('Supported dictionary import details do not match the detected dictionary.');
            }

            $this->assertDictionaryDescriptorValues(
                [
                    'wiktionary '.$sourceCode,
                    $sourceLanguage.'.wiktionary.tsv',
                    'dict_'.$sourceCode.'_wiktionary',
                ],
                [$dictionaryName, $dictionaryFileName, $databaseTableName],
            );

            return;
        }

        throw new \RuntimeException('Unsupported dictionary import descriptor.');
    }

    /** @param string[] $expected
     *  @param string[] $actual
     */
    private function assertDictionaryDescriptorValues(array $expected, array $actual): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException('Supported dictionary import details do not match the detected dictionary.');
        }
    }

    private function assertDictCcSourceLanguages(
        string $dictionaryFileName,
        string $sourceLanguage,
        string $targetLanguage,
    ): void {
        $sourcePath = Storage::path('temp/dictionaries/'.$dictionaryFileName);
        $handle = @fopen($sourcePath, 'r');

        if (! $handle) {
            throw new \RuntimeException('Dictionary source file could not be opened.');
        }

        try {
            $firstLine = fgets($handle);
        } finally {
            fclose($handle);
        }

        if (
            ! is_string($firstLine)
            || ! preg_match(
                '/\\b([A-Z]{2})-([A-Z]{2}) vocabulary database\\tcompiled by dict\\.cc/u',
                $firstLine,
                $matches,
            )
        ) {
            throw new \RuntimeException('Supported dictionary import details do not match the detected dictionary.');
        }

        $languageCodes = config('linguacafe.languages.dict_cc_language_codes', []);
        $detectedSourceLanguage = $languageCodes[$matches[1]] ?? null;
        $detectedTargetLanguage = $languageCodes[$matches[2]] ?? null;

        if (
            $detectedSourceLanguage !== $sourceLanguage
            || $detectedTargetLanguage !== $targetLanguage
        ) {
            throw new \RuntimeException('Supported dictionary import details do not match the detected dictionary.');
        }
    }

    private function importJapaneseDictionarySet($userUuid): bool
    {
        $stagedTables = [];

        try {
            $stagedTables = array_merge(
                $stagedTables,
                $this->jmdictImport($userUuid, false),
            );
            $stagedTables = array_merge(
                $stagedTables,
                $this->kanjiImport(false),
            );
            $stagedTables = array_merge(
                $stagedTables,
                $this->kanjiRadicalImport(false),
            );

            $this->publishJapaneseDictionaryTables($stagedTables);

            return true;
        } catch (\Throwable $exception) {
            foreach ($stagedTables as $stagingTableName) {
                Schema::dropIfExists($stagingTableName);
            }

            throw $exception;
        }
    }

    /** @param array<string, string> $stagingTablesByLiveTable */
    private function publishJapaneseDictionaryTables(array $stagingTablesByLiveTable): void
    {
        $metadataBefore = DB::table('dictionaries')
            ->where('database_table_name', 'dict_jp_jmdict')
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all();

        try {
            $this->publishStagedTableSet(
                $stagingTablesByLiveTable,
                function (): void {
                    DB::table('dictionaries')
                        ->where('database_table_name', 'dict_jp_jmdict')
                        ->update([
                            'enabled' => true,
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ]);
                },
            );
        } catch (\Throwable $exception) {
            $this->restoreDictionaryMetadataRows('dict_jp_jmdict', $metadataBefore);

            throw $exception;
        }
    }

    /** @param array<int, array<string, mixed>> $metadataRows */
    private function restoreDictionaryMetadataRows(string $databaseTableName, array $metadataRows): void
    {
        DB::table('dictionaries')
            ->where('database_table_name', $databaseTableName)
            ->delete();

        if ($metadataRows !== []) {
            DB::table('dictionaries')->insert($metadataRows);
        }
    }

    private function replaceDictionaryFromLoader(
        string $dictionaryName,
        string $databaseTableName,
        string $sourceLanguage,
        string $targetLanguage,
        string $color,
        callable $loader,
        string $type = 'supported',
        bool $requireDictionaryPrefix = true,
    ): string {
        if ($requireDictionaryPrefix) {
            $this->assertSafeDictionaryTableName($databaseTableName);
        } else {
            $this->assertSafeNewDictionaryTableName($databaseTableName);
        }

        $stagingTableName = $this->makeAuxiliaryTableName($databaseTableName, 'stage');
        $baselineTransactionLevel = DB::transactionLevel();

        try {
            $this->createDictionaryTable($stagingTableName);
            $loader($stagingTableName);

            if (DB::transactionLevel() !== $baselineTransactionLevel) {
                throw new \RuntimeException('Dictionary import left an unfinished database transaction.');
            }

            $this->publishStagedDictionary(
                $stagingTableName,
                $databaseTableName,
                $dictionaryName,
                $sourceLanguage,
                $targetLanguage,
                $color,
                $type,
            );

            return 'success';
        } catch (\Throwable $exception) {
            $this->rollbackToTransactionLevel($baselineTransactionLevel);
            Schema::dropIfExists($stagingTableName);

            throw $exception;
        }
    }

    private function createDictionaryTable(string $tableName): void
    {
        Schema::create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->string('word', 256)->collation('utf8mb4_bin')->index();
            $table->string('definitions', 2048)->collation('utf8mb4_bin');
            $table->timestamps();
        });
    }

    private function createTableLike(string $stagingTableName, string $liveTableName): void
    {
        $this->assertSafeInternalTableName($stagingTableName);
        $this->assertSafeInternalTableName($liveTableName);

        DB::statement(sprintf(
            'CREATE TABLE `%s` LIKE `%s`',
            $stagingTableName,
            $liveTableName,
        ));
    }

    /** @param array<string, string> $stagingTablesByLiveTable */
    private function publishStagedTableSet(
        array $stagingTablesByLiveTable,
        ?callable $afterPublish = null,
    ): void {
        $renames = [];
        $backupTablesByLiveTable = [];

        foreach ($stagingTablesByLiveTable as $liveTableName => $stagingTableName) {
            $this->assertSafeInternalTableName($liveTableName);
            $this->assertSafeInternalTableName($stagingTableName);

            $backupTableName = null;
            if (Schema::hasTable($liveTableName)) {
                $backupTableName = $this->makeAuxiliaryTableName($liveTableName, 'backup');
                $renames[$liveTableName] = $backupTableName;
            }

            $backupTablesByLiveTable[$liveTableName] = $backupTableName;
            $renames[$stagingTableName] = $liveTableName;
        }

        $this->renameTablesAtomically($renames);

        try {
            if ($afterPublish !== null) {
                $afterPublish();
            }
        } catch (\Throwable $exception) {
            try {
                $this->restorePublishedTableSet($backupTablesByLiveTable);
            } catch (\Throwable $rollbackException) {
                report($exception);

                throw new \RuntimeException(
                    'Dictionary tables were published but the previous table set could not be restored.',
                    0,
                    $rollbackException,
                );
            }

            throw $exception;
        }

        foreach ($backupTablesByLiveTable as $backupTableName) {
            if ($backupTableName === null) {
                continue;
            }

            try {
                Schema::dropIfExists($backupTableName);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }

    /** @param array<string, string|null> $backupTablesByLiveTable */
    private function restorePublishedTableSet(array $backupTablesByLiveTable): void
    {
        $renames = [];
        $failedTables = [];

        foreach ($backupTablesByLiveTable as $liveTableName => $backupTableName) {
            if (Schema::hasTable($liveTableName)) {
                $failedTableName = $this->makeAuxiliaryTableName($liveTableName, 'failed');
                $renames[$liveTableName] = $failedTableName;
                $failedTables[] = $failedTableName;
            }

            if ($backupTableName !== null) {
                if (! Schema::hasTable($backupTableName)) {
                    throw new \RuntimeException('Dictionary backup table is missing during rollback.');
                }

                $renames[$backupTableName] = $liveTableName;
            }
        }

        if ($renames !== []) {
            $this->renameTablesAtomically($renames);
        }

        foreach ($failedTables as $failedTableName) {
            Schema::dropIfExists($failedTableName);
        }
    }

    private function publishStagedDictionary(
        string $stagingTableName,
        string $databaseTableName,
        string $dictionaryName,
        string $sourceLanguage,
        string $targetLanguage,
        string $color,
        string $type,
    ): void {
        $backupTableName = null;
        $metadataBefore = DB::table('dictionaries')
            ->where('database_table_name', $databaseTableName)
            ->first();

        try {
            if (Schema::hasTable($databaseTableName)) {
                $backupTableName = $this->makeAuxiliaryTableName($databaseTableName, 'backup');
                $this->renameTablesAtomically([
                    $databaseTableName => $backupTableName,
                    $stagingTableName => $databaseTableName,
                ]);
            } else {
                $this->renameTablesAtomically([
                    $stagingTableName => $databaseTableName,
                ]);
            }

            DB::table('dictionaries')->updateOrInsert(
                ['database_table_name' => $databaseTableName],
                [
                    'name' => $dictionaryName,
                    'type' => $type,
                    'api_host' => null,
                    'source_language' => $sourceLanguage,
                    'target_language' => $targetLanguage,
                    'color' => $color,
                    'enabled' => true,
                    'created_at' => $metadataBefore->created_at ?? Carbon::now(),
                    'updated_at' => Carbon::now(),
                ],
            );

            if ($backupTableName !== null) {
                Schema::dropIfExists($backupTableName);
            }
        } catch (\Throwable $exception) {
            $this->restorePublishedDictionary(
                $databaseTableName,
                $backupTableName,
                $metadataBefore,
            );

            throw $exception;
        }
    }

    private function restorePublishedDictionary(
        string $databaseTableName,
        ?string $backupTableName,
        ?object $metadataBefore,
    ): void {
        if ($backupTableName !== null && Schema::hasTable($backupTableName)) {
            if (Schema::hasTable($databaseTableName)) {
                $failedTableName = $this->makeAuxiliaryTableName($databaseTableName, 'failed');
                $this->renameTablesAtomically([
                    $databaseTableName => $failedTableName,
                    $backupTableName => $databaseTableName,
                ]);
                Schema::dropIfExists($failedTableName);
            } else {
                $this->renameTablesAtomically([
                    $backupTableName => $databaseTableName,
                ]);
            }
        } elseif (Schema::hasTable($databaseTableName)) {
            Schema::dropIfExists($databaseTableName);
        }

        if ($metadataBefore === null) {
            DB::table('dictionaries')
                ->where('database_table_name', $databaseTableName)
                ->delete();

            return;
        }

        DB::table('dictionaries')
            ->where('id', $metadataBefore->id)
            ->update([
                'name' => $metadataBefore->name,
                'type' => $metadataBefore->type,
                'api_host' => $metadataBefore->api_host,
                'database_table_name' => $metadataBefore->database_table_name,
                'source_language' => $metadataBefore->source_language,
                'target_language' => $metadataBefore->target_language,
                'color' => $metadataBefore->color,
                'enabled' => $metadataBefore->enabled,
                'created_at' => $metadataBefore->created_at,
                'updated_at' => $metadataBefore->updated_at,
            ]);

        DB::table('dictionaries')
            ->where('database_table_name', $databaseTableName)
            ->where('id', '<>', $metadataBefore->id)
            ->delete();
    }

    private function renameTablesAtomically(array $renames): void
    {
        $clauses = [];

        foreach ($renames as $from => $to) {
            $this->assertSafeInternalTableName($from);
            $this->assertSafeInternalTableName($to);
            $clauses[] = sprintf('`%s` TO `%s`', $from, $to);
        }

        DB::statement('RENAME TABLE '.implode(', ', $clauses));
    }

    private function makeAuxiliaryTableName(string $databaseTableName, string $purpose): string
    {
        do {
            $suffix = '_'.$purpose.'_'.bin2hex(random_bytes(4));
            $candidate = substr($databaseTableName, 0, 64 - strlen($suffix)).$suffix;
        } while (Schema::hasTable($candidate));

        return $candidate;
    }

    private function assertSafeDictionaryTableName(string $databaseTableName): void
    {
        $this->assertSafeNewDictionaryTableName($databaseTableName);

        if (! str_starts_with($databaseTableName, 'dict_')) {
            throw new \Exception('Database name must start with dict_!');
        }
    }

    private function assertSafeNewDictionaryTableName(string $databaseTableName): void
    {
        if (! preg_match('/^[a-z0-9_]+$/', $databaseTableName)) {
            throw new \Exception('Database name can only contain lowercase letters, numbers and underscore!');
        }

        if (mb_strlen($databaseTableName) > 40) {
            throw new \Exception('Database name can only contain up to 40 characters!');
        }
    }

    private function assertSafeInternalTableName(string $tableName): void
    {
        if (! preg_match('/^[a-z0-9_]+$/', $tableName) || strlen($tableName) > 64) {
            throw new \RuntimeException('Unsafe internal dictionary table name.');
        }
    }

    private function rollbackToTransactionLevel(int $targetLevel): void
    {
        while (DB::transactionLevel() > $targetLevel) {
            DB::rollBack();
        }
    }
}