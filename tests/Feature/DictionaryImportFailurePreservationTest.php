<?php

namespace Tests\Feature;

use App\Http\Requests\Dictionaries\ImportSupportedDictionaryRequest;
use App\Services\DictionaryImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * RED harness for dictionary-import failure preservation.
 *
 * These tests intentionally describe the safe user-visible contract rather than
 * the current destructive behavior. Every fixture uses a unique table and
 * metadata row in the dedicated testing database and is cleaned before the
 * assertions run, so a RED result cannot leave shared test state behind.
 */
class DictionaryImportFailurePreservationTest extends TestCase
{
    private DictionaryImportService $service;

    /** @var string[] */
    private array $createdTables = [];

    /** @var string[] */
    private array $createdDictionaryTableNames = [];

    /** @var string[] */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DictionaryImportService();
    }

    protected function tearDown(): void
    {
        $this->rollbackOpenTransactions(0);

        foreach (array_unique($this->createdTables) as $tableName) {
            Schema::dropIfExists($tableName);
        }

        if ($this->createdDictionaryTableNames !== []) {
            DB::table('dictionaries')
                ->whereIn('database_table_name', array_unique($this->createdDictionaryTableNames))
                ->delete();
        }

        foreach (array_unique($this->createdFiles) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_unreadable_supported_replacement_preserves_existing_live_dictionary(): void
    {
        Storage::fake('local');

        $suffix = bin2hex(random_bytes(4));
        $tableName = 'dict_zh_cedict';
        $originalDictionaryName = 'r11old_'.$suffix;

        $this->createdTables[] = $tableName;
        $this->createdDictionaryTableNames[] = $tableName;

        Schema::create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->string('word', 256)->collation('utf8mb4_bin')->index();
            $table->string('definitions', 2048)->collation('utf8mb4_bin');
            $table->timestamps();
        });

        DB::table($tableName)->insert([
            'word' => 'legacy',
            'definitions' => 'old definition',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $originalMetadataId = DB::table('dictionaries')->insertGetId([
            'name' => $originalDictionaryName,
            'type' => 'supported',
            'api_host' => null,
            'database_table_name' => $tableName,
            'source_language' => 'chinese',
            'target_language' => 'english',
            'color' => '#111111',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $baselineTransactionLevel = DB::transactionLevel();
        $missingFile = 'cedict_ts.u8';
        $result = null;
        $thrown = null;

        try {
            $result = $this->service->importSupportedDictionary(
                'r11-test-user',
                'cc-cedict',
                $missingFile,
                'chinese',
                'english',
                $tableName,
            );
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        $rowsAfterFailure = DB::table($tableName)
            ->orderBy('id')
            ->get(['word', 'definitions'])
            ->map(fn ($row): array => [
                'word' => $row->word,
                'definitions' => $row->definitions,
            ])
            ->all();

        $metadataAfterFailure = DB::table('dictionaries')
            ->where('database_table_name', $tableName)
            ->orderBy('id')
            ->get();

        $transactionLevelAfterFailure = DB::transactionLevel();

        $this->rollbackOpenTransactions($baselineTransactionLevel);
        $this->cleanupScenario($tableName);

        $expectedState = [
            'rows' => [['word' => 'legacy', 'definitions' => 'old definition']],
            'metadata_count' => 1,
            'metadata_ids' => [$originalMetadataId],
            'metadata_names' => [$originalDictionaryName],
            'transaction_level' => $baselineTransactionLevel,
            'failure_reported' => true,
        ];
        $actualState = [
            'rows' => $rowsAfterFailure,
            'metadata_count' => $metadataAfterFailure->count(),
            'metadata_ids' => $metadataAfterFailure->pluck('id')->all(),
            'metadata_names' => $metadataAfterFailure->pluck('name')->all(),
            'transaction_level' => $transactionLevelAfterFailure,
            'failure_reported' => $result !== true || $thrown instanceof \Throwable,
        ];

        $this->assertSame(
            $expectedState,
            $actualState,
            'An unreadable replacement must preserve the complete live dictionary state.',
        );
    }

    public function test_successful_supported_replacement_atomically_publishes_new_rows_and_reuses_metadata(): void
    {
        Event::fake();
        Storage::fake('local');

        $suffix = bin2hex(random_bytes(4));
        $tableName = 'dict_zh_cedict';
        $fileName = 'cedict_ts.u8';
        $sourcePath = Storage::path('temp/dictionaries/'.$fileName);

        $this->createdTables[] = $tableName;
        $this->createdDictionaryTableNames[] = $tableName;
        $this->createdFiles[] = $sourcePath;

        if (! is_dir(dirname($sourcePath))) {
            mkdir(dirname($sourcePath), 0775, true);
        }

        Schema::create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->string('word', 256)->collation('utf8mb4_bin')->index();
            $table->string('definitions', 2048)->collation('utf8mb4_bin');
            $table->timestamps();
        });
        DB::table($tableName)->insert([
            'word' => 'legacy',
            'definitions' => 'old definition',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $metadataId = DB::table('dictionaries')->insertGetId([
            'name' => 'old-'.$suffix,
            'type' => 'supported',
            'api_host' => null,
            'database_table_name' => $tableName,
            'source_language' => 'chinese',
            'target_language' => 'english',
            'color' => '#111111',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        file_put_contents(
            $sourcePath,
            "traditional simplified [pin1] /new definition/\n",
        );

        $baselineTransactionLevel = DB::transactionLevel();
        $result = $this->service->importSupportedDictionary(
            'r11-test-user',
            'cc-cedict',
            $fileName,
            'chinese',
            'english',
            $tableName,
        );

        $rows = DB::table($tableName)
            ->orderBy('id')
            ->get(['word', 'definitions'])
            ->map(fn ($row): array => [
                'word' => $row->word,
                'definitions' => $row->definitions,
            ])
            ->all();
        $metadata = DB::table('dictionaries')
            ->where('database_table_name', $tableName)
            ->get();
        $auxiliaryTableCount = DB::table('information_schema.tables')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('table_name', 'like', $tableName.'\_%')
            ->count();
        $transactionLevel = DB::transactionLevel();

        $this->cleanupScenario($tableName);

        $this->assertSame([
            'result' => true,
            'rows' => [['word' => 'simplified', 'definitions' => 'new definition']],
            'metadata_count' => 1,
            'metadata_id' => $metadataId,
            'metadata_name' => 'cc-cedict',
            'metadata_color' => '#EF4556',
            'auxiliary_table_count' => 0,
            'transaction_level' => $baselineTransactionLevel,
        ], [
            'result' => $result,
            'rows' => $rows,
            'metadata_count' => $metadata->count(),
            'metadata_id' => $metadata->first()?->id,
            'metadata_name' => $metadata->first()?->name,
            'metadata_color' => $metadata->first()?->color,
            'auxiliary_table_count' => $auxiliaryTableCount,
            'transaction_level' => $transactionLevel,
        ]);
    }

    public function test_supported_replacement_failure_after_a_committed_batch_preserves_old_dictionary(): void
    {
        Event::fake();
        Storage::fake('local');

        $suffix = bin2hex(random_bytes(4));
        $tableName = 'dict_zh_cedict';
        $dictionaryName = 'r11batch_'.$suffix;
        $fileName = 'cedict_ts.u8';
        $sourcePath = Storage::path('temp/dictionaries/'.$fileName);

        $this->createdTables[] = $tableName;
        $this->createdDictionaryTableNames[] = $tableName;
        $this->createdFiles[] = $sourcePath;

        if (! is_dir(dirname($sourcePath))) {
            mkdir(dirname($sourcePath), 0775, true);
        }

        Schema::create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->string('word', 256)->collation('utf8mb4_bin')->index();
            $table->string('definitions', 2048)->collation('utf8mb4_bin');
            $table->timestamps();
        });
        DB::table($tableName)->insert([
            'word' => 'legacy',
            'definitions' => 'old definition',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $metadataId = DB::table('dictionaries')->insertGetId([
            'name' => $dictionaryName,
            'type' => 'supported',
            'api_host' => null,
            'database_table_name' => $tableName,
            'source_language' => 'chinese',
            'target_language' => 'english',
            'color' => '#222222',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        file_put_contents(
            $sourcePath,
            "old new [pin1] /first definition/\n"
            .'old oversized [pin2] /'.str_repeat('x', 3000)."/\n",
        );

        $baselineTransactionLevel = DB::transactionLevel();
        $thrown = null;

        try {
            $this->service->importSupportedDictionary(
                'r11-test-user',
                'cc-cedict',
                $fileName,
                'chinese',
                'english',
                $tableName,
            );
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        $rowsAfterFailure = DB::table($tableName)
            ->orderBy('id')
            ->get(['word', 'definitions'])
            ->map(fn ($row): array => [
                'word' => $row->word,
                'definitions' => $row->definitions,
            ])
            ->all();
        $metadataAfterFailure = DB::table('dictionaries')
            ->where('database_table_name', $tableName)
            ->get();
        $auxiliaryTableCount = DB::table('information_schema.tables')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('table_name', 'like', $tableName.'\_%')
            ->count();
        $transactionLevelAfterFailure = DB::transactionLevel();

        $this->rollbackOpenTransactions($baselineTransactionLevel);
        $this->cleanupScenario($tableName);

        $this->assertSame([
            'failure_reported' => true,
            'rows' => [['word' => 'legacy', 'definitions' => 'old definition']],
            'metadata_count' => 1,
            'metadata_ids' => [$metadataId],
            'auxiliary_table_count' => 0,
            'transaction_level' => $baselineTransactionLevel,
        ], [
            'failure_reported' => $thrown instanceof \Throwable,
            'rows' => $rowsAfterFailure,
            'metadata_count' => $metadataAfterFailure->count(),
            'metadata_ids' => $metadataAfterFailure->pluck('id')->all(),
            'auxiliary_table_count' => $auxiliaryTableCount,
            'transaction_level' => $transactionLevelAfterFailure,
        ]);
    }

    public function test_invalid_jmdict_package_preserves_all_existing_japanese_dictionary_tables(): void
    {
        Storage::fake('local');

        $suffix = bin2hex(random_bytes(4));
        $baselineTransactionLevel = DB::transactionLevel();

        $vocabularyId = DB::table('dict_jp_jmdict')->insertGetId([
            'translations' => 'legacy-'.$suffix,
            'conjugations' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $wordId = DB::table('dict_jp_jmdict_words')->insertGetId([
            'dict_jp_jmdict_id' => $vocabularyId,
            'word' => 'legacy-'.$suffix,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $readingId = DB::table('dict_jp_jmdict_readings')->insertGetId([
            'dict_jp_jmdict_id' => $vocabularyId,
            'reading' => 'legacy-'.$suffix,
            'word_restrictions' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $kanjiId = DB::table('dict_jp_kanji')->insertGetId([
            'kanji' => '旧'.$suffix,
            'meanings' => json_encode(['legacy']),
            'readings_on' => json_encode([]),
            'readings_kun' => json_encode([]),
            'grade' => 0,
            'strokes' => 0,
            'frequency' => 0,
            'jlpt' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $radicalId = DB::table('dict_jp_kanji_radicals')->insertGetId([
            'kanji' => '旧'.$suffix,
            'radicals' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $metadataId = DB::table('dictionaries')->insertGetId([
            'name' => 'JMDict',
            'type' => 'supported',
            'api_host' => null,
            'database_table_name' => 'dict_jp_jmdict',
            'source_language' => 'japanese',
            'target_language' => 'english',
            'color' => '#74E39A',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $thrown = null;

        try {
            $this->service->importSupportedDictionary(
                'r11-test-user',
                'JMDict',
                'jmdict.zip',
                'japanese',
                'english',
                'dict_jp_jmdict',
            );
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        $actualState = [
            'failure_reported' => $thrown instanceof \Throwable,
            'main' => DB::table('dict_jp_jmdict')->where('id', $vocabularyId)->value('translations'),
            'word' => DB::table('dict_jp_jmdict_words')->where('id', $wordId)->value('word'),
            'reading' => DB::table('dict_jp_jmdict_readings')->where('id', $readingId)->value('reading'),
            'kanji' => DB::table('dict_jp_kanji')->where('id', $kanjiId)->value('kanji'),
            'radical' => DB::table('dict_jp_kanji_radicals')->where('id', $radicalId)->value('kanji'),
            'metadata_id' => DB::table('dictionaries')
                ->where('database_table_name', 'dict_jp_jmdict')
                ->value('id'),
            'transaction_level' => DB::transactionLevel(),
            'auxiliary_tables' => DB::table('information_schema.tables')
                ->where('table_schema', DB::connection()->getDatabaseName())
                ->where(function ($query): void {
                    $query->where('table_name', 'like', 'dict_jp_jmdict\_%')
                        ->orWhere('table_name', 'like', 'dict_jp_kanji\_%');
                })
                ->where(function ($query): void {
                    $query->where('table_name', 'like', '%\_stage\_%')
                        ->orWhere('table_name', 'like', '%\_backup\_%')
                        ->orWhere('table_name', 'like', '%\_failed\_%');
                })
                ->count(),
        ];

        DB::table('dict_jp_jmdict_readings')->where('id', $readingId)->delete();
        DB::table('dict_jp_jmdict_words')->where('id', $wordId)->delete();
        DB::table('dict_jp_jmdict')->where('id', $vocabularyId)->delete();
        DB::table('dict_jp_kanji')->where('id', $kanjiId)->delete();
        DB::table('dict_jp_kanji_radicals')->where('id', $radicalId)->delete();
        DB::table('dictionaries')->where('id', $metadataId)->delete();
        $this->rollbackOpenTransactions($baselineTransactionLevel);

        $this->assertSame([
            'failure_reported' => true,
            'main' => 'legacy-'.$suffix,
            'word' => 'legacy-'.$suffix,
            'reading' => 'legacy-'.$suffix,
            'kanji' => '旧'.$suffix,
            'radical' => '旧'.$suffix,
            'metadata_id' => $metadataId,
            'transaction_level' => $baselineTransactionLevel,
            'auxiliary_tables' => 0,
        ], $actualState);
    }

    public function test_japanese_dictionary_set_rolls_back_when_kanji_import_fails_after_jmdict_load(): void
    {
        Storage::fake('local');

        $suffix = bin2hex(random_bytes(4));
        $baselineTransactionLevel = DB::transactionLevel();
        Storage::makeDirectory('temp/dictionaries');

        $zip = new \ZipArchive();
        $zipPath = Storage::path('temp/dictionaries/jmdict.zip');
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString(
            'jmdict_processed.txt',
            'newword_'.$suffix.'|newreading_'.$suffix.'|new translation '.$suffix.'|noun'."\n",
        );
        $zip->addFromString(
            'kanjidic2.xml',
            '<kanjidic2><character><literal>'.str_repeat('x', 300).'</literal><misc /></character></kanjidic2>',
        );
        $zip->addFromString('radicals.txt', "# not reached\n");
        $zip->addFromString('radical-strokes.txt', "x 1\n");
        $zip->close();

        $vocabularyId = DB::table('dict_jp_jmdict')->insertGetId([
            'translations' => 'legacy-'.$suffix,
            'conjugations' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $wordId = DB::table('dict_jp_jmdict_words')->insertGetId([
            'dict_jp_jmdict_id' => $vocabularyId,
            'word' => 'legacy-'.$suffix,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $readingId = DB::table('dict_jp_jmdict_readings')->insertGetId([
            'dict_jp_jmdict_id' => $vocabularyId,
            'reading' => 'legacy-'.$suffix,
            'word_restrictions' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $kanjiId = DB::table('dict_jp_kanji')->insertGetId([
            'kanji' => '旧'.$suffix,
            'meanings' => json_encode(['legacy']),
            'readings_on' => json_encode([]),
            'readings_kun' => json_encode([]),
            'grade' => 0,
            'strokes' => 0,
            'frequency' => 0,
            'jlpt' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $radicalId = DB::table('dict_jp_kanji_radicals')->insertGetId([
            'kanji' => '旧'.$suffix,
            'radicals' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $metadataId = DB::table('dictionaries')->insertGetId([
            'name' => 'JMDict',
            'type' => 'supported',
            'api_host' => null,
            'database_table_name' => 'dict_jp_jmdict',
            'source_language' => 'japanese',
            'target_language' => 'english',
            'color' => '#74E39A',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $thrown = null;

        try {
            $this->service->importSupportedDictionary(
                'r11-test-user',
                'JMDict',
                'jmdict.zip',
                'japanese',
                'english',
                'dict_jp_jmdict',
            );
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        $auxiliaryTables = DB::table('information_schema.tables')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where(function ($query): void {
                $query->where('table_name', 'like', 'dict_jp_jmdict\_%')
                    ->orWhere('table_name', 'like', 'dict_jp_kanji\_%');
            })
            ->where(function ($query): void {
                $query->where('table_name', 'like', '%\_stage\_%')
                    ->orWhere('table_name', 'like', '%\_backup\_%')
                    ->orWhere('table_name', 'like', '%\_failed\_%')
                    ->orWhere('table_name', 'like', '%\_set\_backup\_%')
                    ->orWhere('table_name', 'like', '%\_set\_failed\_%');
            })
            ->pluck('table_name')
            ->all();
        $actualState = [
            'failure_reported' => $thrown instanceof \Throwable,
            'main' => DB::table('dict_jp_jmdict')->where('id', $vocabularyId)->value('translations'),
            'word' => DB::table('dict_jp_jmdict_words')->where('id', $wordId)->value('word'),
            'reading' => DB::table('dict_jp_jmdict_readings')->where('id', $readingId)->value('reading'),
            'kanji' => DB::table('dict_jp_kanji')->where('id', $kanjiId)->value('kanji'),
            'radical' => DB::table('dict_jp_kanji_radicals')->where('id', $radicalId)->value('kanji'),
            'metadata_id' => DB::table('dictionaries')
                ->where('database_table_name', 'dict_jp_jmdict')
                ->value('id'),
            'transaction_level' => DB::transactionLevel(),
            'auxiliary_tables' => $auxiliaryTables,
        ];

        DB::table('dict_jp_jmdict_readings')
            ->whereIn('reading', ['legacy-'.$suffix, 'newreading_'.$suffix])
            ->delete();
        DB::table('dict_jp_jmdict_words')
            ->whereIn('word', ['legacy-'.$suffix, 'newword_'.$suffix])
            ->delete();
        DB::table('dict_jp_jmdict')
            ->whereIn('translations', ['legacy-'.$suffix, 'new translation '.$suffix])
            ->delete();
        DB::table('dict_jp_kanji')->where('kanji', '旧'.$suffix)->delete();
        DB::table('dict_jp_kanji_radicals')->where('kanji', '旧'.$suffix)->delete();
        DB::table('dictionaries')->where('id', $metadataId)->delete();
        foreach ($auxiliaryTables as $auxiliaryTable) {
            Schema::dropIfExists($auxiliaryTable);
        }
        $this->rollbackOpenTransactions($baselineTransactionLevel);

        $this->assertSame([
            'failure_reported' => true,
            'main' => 'legacy-'.$suffix,
            'word' => 'legacy-'.$suffix,
            'reading' => 'legacy-'.$suffix,
            'kanji' => '旧'.$suffix,
            'radical' => '旧'.$suffix,
            'metadata_id' => $metadataId,
            'transaction_level' => $baselineTransactionLevel,
            'auxiliary_tables' => [],
        ], $actualState);
    }

    public function test_kengdic_import_parses_real_tab_separated_rows(): void
    {
        Event::fake();
        Storage::fake('local');
        Storage::makeDirectory('temp/dictionaries');
        Storage::put(
            'temp/dictionaries/kengdic.tsv',
            "id\tsurface\tunused\tdefinition\n1\tHanguk\tunused\tKorea\n",
        );

        $tableName = 'dict_ko_kengdic';
        $this->createdTables[] = $tableName;
        $this->createdDictionaryTableNames[] = $tableName;

        $result = $this->service->importSupportedDictionary(
            'r11-test-user',
            'kengdic',
            'kengdic.tsv',
            'korean',
            'english',
            $tableName,
        );
        $rows = DB::table($tableName)->pluck('definitions', 'word')->all();

        $this->cleanupScenario($tableName);

        $this->assertSame([
            'result' => true,
            'rows' => ['hanguk' => 'korea'],
        ], [
            'result' => $result,
            'rows' => $rows,
        ]);
    }

    public function test_dict_cc_import_parses_real_tab_separated_rows(): void
    {
        Event::fake();
        Storage::fake('local');
        Storage::makeDirectory('temp/dictionaries');
        Storage::put(
            'temp/dictionaries/icelandic-english.txt',
            "# IS-EN vocabulary database\tcompiled by dict.cc\nHundur\tDog\n",
        );

        $tableName = 'dict_is_en_dict_cc';
        $this->createdTables[] = $tableName;
        $this->createdDictionaryTableNames[] = $tableName;

        $result = $this->service->importSupportedDictionary(
            'r11-test-user',
            'dictcc is-en',
            'icelandic-english.txt',
            'icelandic',
            'english',
            $tableName,
        );
        $rows = DB::table($tableName)->pluck('definitions', 'word')->all();

        $this->cleanupScenario($tableName);

        $this->assertSame([
            'result' => true,
            'rows' => ['hundur' => 'dog'],
        ], [
            'result' => $result,
            'rows' => $rows,
        ]);
    }

    public function test_wiktionary_import_parses_real_tab_separated_rows(): void
    {
        Event::fake();
        Storage::fake('local');
        Storage::makeDirectory('temp/dictionaries');
        Storage::put(
            'temp/dictionaries/icelandic.wiktionary.tsv',
            "Hundur|noun\t<li>Dog</li>\n",
        );

        $tableName = 'dict_is_wiktionary';
        $this->createdTables[] = $tableName;
        $this->createdDictionaryTableNames[] = $tableName;

        $result = $this->service->importSupportedDictionary(
            'r11-test-user',
            'wiktionary is',
            'icelandic.wiktionary.tsv',
            'icelandic',
            'english',
            $tableName,
        );
        $rows = DB::table($tableName)->pluck('definitions', 'word')->all();

        $this->cleanupScenario($tableName);

        $this->assertSame([
            'result' => true,
            'rows' => ['hundur' => 'dog'],
        ], [
            'result' => $result,
            'rows' => $rows,
        ]);
    }

    public function test_fixed_supported_dictionary_descriptor_cannot_target_another_dictionary_table(): void
    {
        Storage::fake('local');
        Storage::makeDirectory('temp/dictionaries');
        Storage::put(
            'temp/dictionaries/cedict_ts.u8',
            "traditional simplified [pin1] /definition/\n",
        );

        $suffix = bin2hex(random_bytes(4));
        $wrongTableName = 'dict_r11_wrong_'.$suffix;
        $this->createdTables[] = $wrongTableName;
        $this->createdDictionaryTableNames[] = $wrongTableName;
        $thrown = null;

        try {
            $this->service->importSupportedDictionary(
                'r11-test-user',
                'cc-cedict',
                'cedict_ts.u8',
                'chinese',
                'english',
                $wrongTableName,
            );
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        $actualState = [
            'failure_reported' => $thrown instanceof \Throwable,
            'message' => $thrown?->getMessage(),
            'table_exists' => Schema::hasTable($wrongTableName),
            'metadata_count' => DB::table('dictionaries')
                ->where('database_table_name', $wrongTableName)
                ->count(),
            'transaction_level' => DB::transactionLevel(),
        ];

        $this->cleanupScenario($wrongTableName);

        $this->assertSame([
            'failure_reported' => true,
            'message' => 'Supported dictionary import details do not match the detected dictionary.',
            'table_exists' => false,
            'metadata_count' => 0,
            'transaction_level' => 0,
        ], $actualState);
    }

    public function test_dict_cc_descriptor_must_match_the_language_pair_inside_the_file(): void
    {
        Storage::fake('local');
        Storage::makeDirectory('temp/dictionaries');
        Storage::put(
            'temp/dictionaries/icelandic-english.txt',
            "x FI-EN vocabulary database\tcompiled by dict.cc\nword\tdefinition\n",
        );

        $tableName = 'dict_is_en_dict_cc';
        $this->createdTables[] = $tableName;
        $this->createdDictionaryTableNames[] = $tableName;
        $thrown = null;

        try {
            $this->service->importSupportedDictionary(
                'r11-test-user',
                'dictcc is-en',
                'icelandic-english.txt',
                'icelandic',
                'english',
                $tableName,
            );
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        $actualState = [
            'failure_reported' => $thrown instanceof \Throwable,
            'message' => $thrown?->getMessage(),
            'table_exists' => Schema::hasTable($tableName),
            'metadata_count' => DB::table('dictionaries')
                ->where('database_table_name', $tableName)
                ->count(),
            'transaction_level' => DB::transactionLevel(),
        ];

        $this->cleanupScenario($tableName);

        $this->assertSame([
            'failure_reported' => true,
            'message' => 'Supported dictionary import details do not match the detected dictionary.',
            'table_exists' => false,
            'metadata_count' => 0,
            'transaction_level' => 0,
        ], $actualState);
    }

    public function test_custom_csv_keeps_legacy_non_prefixed_new_table_name_compatibility(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $tableName = 'customcsv_'.$suffix;
        $dictionaryName = 'csv'.$suffix;
        $sourcePath = storage_path('app/temp/r11-custom-'.$suffix.'.csv');

        $this->createdTables[] = $tableName;
        $this->createdDictionaryTableNames[] = $tableName;
        $this->createdFiles[] = $sourcePath;

        if (! is_dir(dirname($sourcePath))) {
            mkdir(dirname($sourcePath), 0775, true);
        }

        file_put_contents($sourcePath, "Word,Translation\nApple,Omena\n");

        $result = $this->service->importDictionaryCsvFile(
            new UploadedFile($sourcePath, 'custom.csv', 'text/csv', null, true),
            true,
            ',',
            $dictionaryName,
            $tableName,
            'finnish',
            'english',
            '#FF0000',
        );

        $actualState = [
            'result' => $result,
            'table_exists' => Schema::hasTable($tableName),
            'rows' => DB::table($tableName)->pluck('definitions', 'word')->all(),
            'metadata_count' => DB::table('dictionaries')
                ->where('database_table_name', $tableName)
                ->count(),
        ];

        $this->cleanupScenario($tableName);

        $this->assertSame([
            'result' => true,
            'table_exists' => true,
            'rows' => ['apple' => 'Omena'],
            'metadata_count' => 1,
        ], $actualState);
    }

    public function test_complete_japanese_dictionary_package_replaces_all_five_tables_without_residue(): void
    {
        Event::fake();
        Storage::fake('local');
        Storage::makeDirectory('temp/dictionaries');

        $suffix = bin2hex(random_bytes(4));
        $literal = '新'.$suffix;
        $word = 'newword'.$suffix;
        $reading = 'newreading'.$suffix;
        $translation = 'new translation '.$suffix;
        $baselineTransactionLevel = DB::transactionLevel();

        $oldVocabularyId = DB::table('dict_jp_jmdict')->insertGetId([
            'translations' => 'legacy-'.$suffix,
            'conjugations' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('dict_jp_jmdict_words')->insert([
            'dict_jp_jmdict_id' => $oldVocabularyId,
            'word' => 'legacy-'.$suffix,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('dict_jp_jmdict_readings')->insert([
            'dict_jp_jmdict_id' => $oldVocabularyId,
            'reading' => 'legacy-'.$suffix,
            'word_restrictions' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('dict_jp_kanji')->insert([
            'kanji' => '旧'.$suffix,
            'meanings' => json_encode(['legacy']),
            'readings_on' => json_encode([]),
            'readings_kun' => json_encode([]),
            'grade' => 0,
            'strokes' => 0,
            'frequency' => 0,
            'jlpt' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('dict_jp_kanji_radicals')->insert([
            'kanji' => '旧'.$suffix,
            'radicals' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $metadataId = DB::table('dictionaries')->insertGetId([
            'name' => 'JMDict',
            'type' => 'supported',
            'api_host' => null,
            'database_table_name' => 'dict_jp_jmdict',
            'source_language' => 'japanese',
            'target_language' => 'english',
            'color' => '#74E39A',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $zipPath = Storage::path('temp/dictionaries/jmdict.zip');
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString(
            'jmdict_processed.txt',
            $word.'|'.$reading.'|'.$translation."|xx\n",
        );
        $zip->addFromString(
            'kanjidic2.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<kanjidic2><character><literal>'.$literal.'</literal>'
            .'<misc><grade>1</grade><stroke_count>13</stroke_count><freq>100</freq><jlpt>2</jlpt></misc>'
            .'<reading_meaning><rmgroup><reading r_type="ja_on">シン</reading>'
            .'<reading r_type="ja_kun">あたら.しい</reading><meaning>new</meaning>'
            .'</rmgroup></reading_meaning></character></kanjidic2>',
        );
        $zip->addFromString('radicals.txt', $literal." : 立 木\n");
        $zip->addFromString('radical-strokes.txt', "立 5\n木 4\n");
        $zip->close();

        $result = $this->service->importSupportedDictionary(
            'r11-test-user',
            'JMDict',
            'jmdict.zip',
            'japanese',
            'english',
            'dict_jp_jmdict',
        );

        $newVocabularyId = DB::table('dict_jp_jmdict_words')
            ->where('word', $word)
            ->value('dict_jp_jmdict_id');
        $actualState = [
            'result' => $result,
            'main_translation' => DB::table('dict_jp_jmdict')
                ->where('id', $newVocabularyId)
                ->value('translations'),
            'word' => DB::table('dict_jp_jmdict_words')
                ->where('dict_jp_jmdict_id', $newVocabularyId)
                ->value('word'),
            'reading' => DB::table('dict_jp_jmdict_readings')
                ->where('dict_jp_jmdict_id', $newVocabularyId)
                ->value('reading'),
            'kanji' => DB::table('dict_jp_kanji')->value('kanji'),
            'radical' => DB::table('dict_jp_kanji_radicals')->value('kanji'),
            'old_main_exists' => DB::table('dict_jp_jmdict')->where('id', $oldVocabularyId)->exists(),
            'metadata_id' => DB::table('dictionaries')
                ->where('database_table_name', 'dict_jp_jmdict')
                ->value('id'),
            'transaction_level' => DB::transactionLevel(),
            'auxiliary_tables' => DB::table('information_schema.tables')
                ->where('table_schema', DB::connection()->getDatabaseName())
                ->where(function ($query): void {
                    $query->where('table_name', 'like', '%\_stage\_%')
                        ->orWhere('table_name', 'like', '%\_backup\_%')
                        ->orWhere('table_name', 'like', '%\_failed\_%');
                })
                ->where(function ($query): void {
                    $query->where('table_name', 'like', 'dict_jp_jmdict%')
                        ->orWhere('table_name', 'like', 'dict_jp_kanji%');
                })
                ->count(),
        ];

        DB::table('dict_jp_jmdict_readings')->where('dict_jp_jmdict_id', $newVocabularyId)->delete();
        DB::table('dict_jp_jmdict_words')->where('dict_jp_jmdict_id', $newVocabularyId)->delete();
        DB::table('dict_jp_jmdict')->where('id', $newVocabularyId)->delete();
        DB::table('dict_jp_kanji')->where('kanji', $literal)->delete();
        DB::table('dict_jp_kanji_radicals')->where('kanji', $literal)->delete();
        DB::table('dictionaries')->where('id', $metadataId)->delete();

        $this->assertSame([
            'result' => true,
            'main_translation' => $translation,
            'word' => $word,
            'reading' => $reading,
            'kanji' => $literal,
            'radical' => $literal,
            'old_main_exists' => false,
            'metadata_id' => $metadataId,
            'transaction_level' => $baselineTransactionLevel,
            'auxiliary_tables' => 0,
        ], $actualState);
    }

    public function test_malformed_custom_csv_does_not_publish_partial_dictionary_or_leak_temp_file(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $tableName = 'dict_r11_csv_'.$suffix;
        $dictionaryName = 'r11csv'.$suffix;
        $sourcePath = storage_path('app/temp/r11-source-'.$suffix.'.csv');

        $this->createdTables[] = $tableName;
        $this->createdDictionaryTableNames[] = $tableName;
        $this->createdFiles[] = $sourcePath;

        if (! is_dir(dirname($sourcePath))) {
            mkdir(dirname($sourcePath), 0775, true);
        }

        file_put_contents(
            $sourcePath,
            "Word,Translation\nApple,Omena\nMalformedOnlyOneColumn\n",
        );

        $beforeFiles = $this->listTempFiles();
        $baselineTransactionLevel = DB::transactionLevel();
        $thrown = null;

        try {
            $this->service->importDictionaryCsvFile(
                new UploadedFile($sourcePath, 'malformed.csv', 'text/csv', null, true),
                true,
                ',',
                $dictionaryName,
                $tableName,
                'finnish',
                'english',
                '#FF0000',
            );
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        $tableExistsAfterFailure = Schema::hasTable($tableName);
        $rowCountAfterFailure = $tableExistsAfterFailure ? DB::table($tableName)->count() : 0;
        $metadataAfterFailure = DB::table('dictionaries')
            ->where('database_table_name', $tableName)
            ->get();
        $transactionLevelAfterFailure = DB::transactionLevel();
        $newTempFiles = array_values(array_diff($this->listTempFiles(), $beforeFiles));

        foreach ($newTempFiles as $path) {
            $this->createdFiles[] = $path;
        }

        $this->rollbackOpenTransactions($baselineTransactionLevel);
        $this->cleanupScenario($tableName);
        foreach ($newTempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $expectedState = [
            'exception_type' => \Exception::class,
            'missing_data_reported' => true,
            'table_exists' => false,
            'row_count' => 0,
            'metadata_count' => 0,
            'transaction_level' => $baselineTransactionLevel,
            'leaked_temp_files' => [],
        ];
        $actualState = [
            'exception_type' => $thrown ? $thrown::class : null,
            'missing_data_reported' => str_contains($thrown?->getMessage() ?? '', 'Missing data'),
            'table_exists' => $tableExistsAfterFailure,
            'row_count' => $rowCountAfterFailure,
            'metadata_count' => $metadataAfterFailure->count(),
            'transaction_level' => $transactionLevelAfterFailure,
            'leaked_temp_files' => array_map('basename', $newTempFiles),
        ];

        $this->assertSame(
            $expectedState,
            $actualState,
            'A malformed CSV must leave no published or temporary import residue.',
        );
    }

    public function test_supported_import_request_rejects_non_dictionary_table_name(): void
    {
        $request = new ImportSupportedDictionaryRequest();
        $validator = Validator::make([
            'dictionaryName' => 'cc-cedict',
            'dictionaryFileName' => 'cedict_ts.u8',
            'dictionarySourceLanguage' => 'chinese',
            'dictionaryTargetLanguage' => 'english',
            'dictionaryDatabaseName' => 'users',
        ], $request->rules());

        $this->assertTrue(
            $validator->fails(),
            'The import request must reject a core application table name before it reaches destructive schema code.',
        );
    }

    private function cleanupScenario(string $tableName): void
    {
        Schema::dropIfExists($tableName);
        DB::table('dictionaries')->where('database_table_name', $tableName)->delete();
    }

    private function rollbackOpenTransactions(int $targetLevel): void
    {
        while (DB::transactionLevel() > $targetLevel) {
            DB::rollBack();
        }
    }

    /** @return string[] */
    private function listTempFiles(): array
    {
        $files = glob(storage_path('app/temp/*')) ?: [];

        return array_values(array_filter($files, 'is_file'));
    }
}
