<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Characterization tests for DictionaryImportService.
 * Locks current behavior without changing implementation.
 * Uses minimal fixture files — no real large dictionary files.
 * Each test that creates a dynamic DB table must clean it up.
 */
class DictionaryImportTest extends TestCase
{
    use RefreshDatabase;

    private \App\Services\DictionaryImportService $service;

    /** @var string[] Paths of temp files created by this test class */
    private array $createdTempFiles = [];

    /** @var string[] Database table names created by this test class */
    private array $createdTempTables = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new \App\Services\DictionaryImportService();
    }

    protected function tearDown(): void
    {
        // Only delete files this test class created
        foreach ($this->createdTempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        // Only drop tables this test class created
        foreach ($this->createdTempTables as $table) {
            Schema::dropIfExists($table);
        }
        // Clean up Dictionary records this test class created (they're transient test records)
        \App\Models\Dictionary::where('name', 'csvtest')->orWhere('name', 'dictcsv')->delete();

        parent::tearDown();
    }

    /**
     * Create a temp file in storage_path('app/temp') and register it for cleanup.
     */
    private function createTestTempFile(string $subpath, string $content): string
    {
        $fullPath = storage_path('app/temp/' . $subpath);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($fullPath, $content);
        $this->createdTempFiles[] = $fullPath;
        return $fullPath;
    }

    /**
     * Register a database table name for cleanup in tearDown.
     */
    private function registerTempTable(string $tableName): void
    {
        $this->createdTempTables[] = $tableName;
    }

    // ==================== A. CEDICT file detection ====================

    public function test_cedict_file_detection(): void
    {
        $content = "#! entries=2\nhello 你好 [ni3 hao3] /hello/\ngoodbye 再见 [zai4 jian4] /goodbye/\n";
        $sourcePath = $this->createTestTempFile('cedict_test_upload.u8', $content);
        $file = new UploadedFile($sourcePath, 'cedict_ts.u8', 'application/octet-stream', null, true);

        $result = $this->service->getDictionaryFileInformation($file, [], [], []);

        $this->assertNotNull($result);
        $this->assertSame('cc-cedict', $result->name);
        $this->assertSame('dict_zh_cedict', $result->databaseName);
        $this->assertSame('chinese', $result->source_language);
        $this->assertSame('english', $result->target_language);
        $this->assertSame(2, $result->expectedRecordCount);
        $this->assertSame('cedict_ts.u8', $result->fileName);
    }

    // ==================== B. HanDeDict file detection ====================

    public function test_handedict_file_detection(): void
    {
        $content = "line one\nline two\n";
        $sourcePath = $this->createTestTempFile('handedict_test_upload.u8', $content);
        $file = new UploadedFile($sourcePath, 'handedict.u8', 'application/octet-stream', null, true);

        $result = $this->service->getDictionaryFileInformation($file, [], [], []);

        $this->assertNotNull($result);
        $this->assertSame('HanDeDict', $result->name);
        $this->assertSame('dict_zh_handedict', $result->databaseName);
        $this->assertSame('chinese', $result->source_language);
        $this->assertSame('german', $result->target_language);
        $this->assertSame(2, $result->expectedRecordCount);
        $this->assertSame('handedict.u8', $result->fileName);
    }

    // ==================== C. dict.cc txt detection ====================

    public function test_dict_cc_file_detection(): void
    {
        // Line 137 checks for: ' vocabulary database\tcompiled by dict.cc'
        // Second space-separated word must be the language code (e.g. "FI-EN")
        $tab = chr(9);
        $content = "x FI-EN vocabulary database{$tab}compiled by dict.cc\nkoira{$tab}hund\n";
        $sourcePath = $this->createTestTempFile('fi_en_test.txt', $content);
        $file = new UploadedFile($sourcePath, 'fi-en-dict.txt', 'application/octet-stream', null, true);

        $result = $this->service->getDictionaryFileInformation($file, ['Finnish', 'English'], ['FI' => 'finnish', 'EN' => 'english'], ['finnish' => 'fi', 'english' => 'en']);

        $this->assertNotNull($result);
        $this->assertStringContainsString('dictcc', $result->name);
        $this->assertSame('finnish', $result->source_language);
        $this->assertSame('english', $result->target_language);
        $this->assertSame('dict_fi_en_dict_cc', $result->databaseName);
        $this->assertSame(2, $result->expectedRecordCount);
    }

    public function test_unsupported_txt_returns_null(): void
    {
        $content = "This is not a dict.cc file.\nsome data\n";
        $sourcePath = $this->createTestTempFile('random_test.txt', $content);
        $file = new UploadedFile($sourcePath, 'random.txt', 'application/octet-stream', null, true);

        $result = $this->service->getDictionaryFileInformation($file, [], [], []);

        $this->assertNull($result);
    }

    // ==================== D. Wiktionary tsv detection ====================

    public function test_wiktionary_tsv_detection(): void
    {
        $content = "word\tdefinition\ncat\tkissa\n";
        $sourcePath = $this->createTestTempFile('finnish_wiktionary_test.tsv', $content);
        $file = new UploadedFile($sourcePath, 'finnish.wiktionary.tsv', 'application/octet-stream', null, true);

        $result = $this->service->getDictionaryFileInformation($file, [], [], ['finnish' => 'fi']);

        $this->assertNotNull($result);
        $this->assertStringContainsString('wiktionary', $result->name);
        $this->assertSame('finnish', $result->source_language);
        $this->assertSame('english', $result->target_language);
        $this->assertSame('dict_fi_wiktionary', $result->databaseName);
        $this->assertSame(2, $result->expectedRecordCount);
    }

    public function test_unsupported_tsv_returns_null(): void
    {
        // Use a filename that passes the count>=2 check but fails wiktionary check
        $content = "a\tb\n";
        $sourcePath = $this->createTestTempFile('custom_tsv_test.tsv', $content);
        $file = new UploadedFile($sourcePath, 'finnish.notwiktionary.tsv', 'application/octet-stream', null, true);

        $result = $this->service->getDictionaryFileInformation($file, [], [], []);

        $this->assertNull($result);
    }

    // ==================== E. testDictionaryCsvFile — success ====================

    public function test_csv_sample_success_with_skip_header(): void
    {
        $content = "Word,Translation\nApple,Omena\nPear,Paaryna\nCat,Kissa\nDog,Koira\n";
        $csvPath = $this->createTestTempFile('test_csv_skip.csv', $content);
        $file = new UploadedFile($csvPath, 'test_csv_skip.csv', 'text/csv', null, true);

        $result = $this->service->testDictionaryCsvFile($file, ',', true);
        $this->assertSame('success', $result->status);
        $this->assertSame(4, $result->recordCount);
        $this->assertCount(3, $result->sample);
        $this->assertSame('apple', $result->sample[0]->word); // lowercased
        $this->assertSame('Omena', $result->sample[0]->translation);
    }

    public function test_csv_sample_success_without_skip_header(): void
    {
        $content = "Word,Translation\nApple,Omena\nPear,Paaryna\n";
        $csvPath = $this->createTestTempFile('test_csv_no_skip.csv', $content);
        $file = new UploadedFile($csvPath, 'test_csv_no_skip.csv', 'text/csv', null, true);

        $result = $this->service->testDictionaryCsvFile($file, ',', false);
        $this->assertSame('success', $result->status);
        $this->assertSame(3, $result->recordCount); // header counted
        $this->assertCount(3, $result->sample);
    }

    // ==================== F. testDictionaryCsvFile — error ====================

    public function test_csv_sample_error_on_missing_column(): void
    {
        $content = "Word,Translation\nApple\nPear,Paaryna\n";
        $csvPath = $this->createTestTempFile('test_csv_error.csv', $content);
        $file = new UploadedFile($csvPath, 'test_csv_error.csv', 'text/csv', null, true);

        $result = $this->service->testDictionaryCsvFile($file, ',', true);
        $this->assertSame('error', $result->status);
        $this->assertSame([], $result->sample);
    }

    // ==================== G. importDictionaryCsvFile — validation ====================

    public function test_import_csv_rejects_invalid_table_name(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database name can only contain lowercase letters');

        $content = "Word,Translation\nApple,Omena\n";
        $csvPath = $this->createTestTempFile('test_invalid_table.csv', $content);
        $file = new UploadedFile($csvPath, 'test_invalid_table.csv', 'text/csv', null, true);

        $this->service->importDictionaryCsvFile($file, true, ',', 'mydict', 'UPPERCASE_TABLE', 'finnish', 'english', '#FF0000');
    }

    public function test_import_csv_rejects_long_dictionary_name(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Dictionary name can only contain up to 16 characters');

        $content = "Word,Translation\nApple,Omena\n";
        $csvPath = $this->createTestTempFile('test_long_name.csv', $content);
        $file = new UploadedFile($csvPath, 'test_long_name.csv', 'text/csv', null, true);

        $this->service->importDictionaryCsvFile($file, true, ',', 'very_long_dictionary_name', 'dict_ok', 'finnish', 'english', '#FF0000');
    }

    public function test_import_csv_rejects_long_table_name(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database name can only contain up to 40 characters');

        $content = "Word,Translation\nApple,Omena\n";
        $csvPath = $this->createTestTempFile('test_long_table.csv', $content);
        $file = new UploadedFile($csvPath, 'test_long_table.csv', 'text/csv', null, true);

        $this->service->importDictionaryCsvFile($file, true, ',', 'mydict', 'dict_this_table_name_is_way_too_long_for_the_limit', 'finnish', 'english', '#FF0000');
    }

    // ==================== H. importDictionaryCsvFile — success ====================

    public function test_import_csv_success_path(): void
    {
        $content = "Word,Translation\nApple,Omena\nPear,Paaryna\n";
        $csvPath = $this->createTestTempFile('test_import_success.csv', $content);
        $file = new UploadedFile($csvPath, 'test_import_success.csv', 'text/csv', null, true);

        $tableName = 'dict_test_csv_' . bin2hex(random_bytes(4));
        $this->registerTempTable($tableName);
        $dictName = 'csvtest';

        $result = $this->service->importDictionaryCsvFile(
            $file, true, ',', $dictName, $tableName, 'finnish', 'english', '#FF0000'
        );

        $this->assertTrue($result);

        // Table was created and has rows
        $this->assertTrue(Schema::hasTable($tableName));
        $rows = DB::table($tableName)->get();
        $this->assertCount(2, $rows);
        $this->assertSame('apple', $rows[0]->word); // lowercased
        $this->assertSame('Omena', $rows[0]->definitions);

        // Dictionary record was created (cleaned up in tearDown)
        $dictionary = \App\Models\Dictionary::where('name', $dictName)->first();
        $this->assertNotNull($dictionary);
        $this->assertSame('custom_csv', $dictionary->type);
        $this->assertSame($tableName, $dictionary->database_table_name);
        $this->assertSame('finnish', $dictionary->source_language);
        $this->assertSame('english', $dictionary->target_language);
        $this->assertSame('#FF0000', $dictionary->color);
        $this->assertTrue((bool) $dictionary->enabled);
    }

    // ==================== I. importDictionaryCsvFile — existing table ====================

    public function test_import_csv_rejects_existing_table_name(): void
    {
        $tableName = 'dict_existing_csv_' . bin2hex(random_bytes(4));
        $this->registerTempTable($tableName);

        // Pre-create the table so it "already exists"
        Schema::create($tableName, function ($table) {
            $table->id();
            $table->string('word', 256);
            $table->string('definitions', 2048);
            $table->timestamps();
        });

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database table name already exists');

        $content = "Word,Translation\nApple,Omena\n";
        $csvPath = $this->createTestTempFile('test_existing_table.csv', $content);
        $file = new UploadedFile($csvPath, 'test_existing_table.csv', 'text/csv', null, true);

        $this->service->importDictionaryCsvFile($file, true, ',', 'dictcsv', $tableName, 'finnish', 'english', '#FF0000');
    }
}
