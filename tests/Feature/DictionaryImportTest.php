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

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new \App\Services\DictionaryImportService();
    }

    protected function tearDown(): void
    {
        // Clean up any temp files created by tests
        $tempFiles = Storage::allFiles('temp');
        Storage::delete($tempFiles);

        parent::tearDown();
    }

    // ==================== A. CEDICT file detection ====================

    public function test_cedict_file_detection(): void
    {
        $content = "#! entries=2\nhello 你好 [ni3 hao3] /hello/\ngoodbye 再见 [zai4 jian4] /goodbye/\n";
        $sourceDir = storage_path('app/temp');
        $destDir = storage_path('app/temp/dictionaries');
        if (!is_dir($sourceDir)) {
            mkdir($sourceDir, 0777, true);
        }
        if (!is_dir($destDir)) {
            mkdir($destDir, 0777, true);
        }
        // Write to source dir, then UploadedFile with $fromLocal=true moves it
        $sourcePath = $sourceDir . '/cedict_test_upload.u8';
        file_put_contents($sourcePath, $content);

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
        $sourceDir = storage_path('app/temp');
        $destDir = storage_path('app/temp/dictionaries');
        if (!is_dir($sourceDir)) {
            mkdir($sourceDir, 0777, true);
        }
        if (!is_dir($destDir)) {
            mkdir($destDir, 0777, true);
        }
        $sourcePath = $sourceDir . '/handedict_test_upload.u8';
        file_put_contents($sourcePath, $content);

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
        $sourceDir = storage_path('app/temp');
        $destDir = storage_path('app/temp/dictionaries');
        if (!is_dir($sourceDir)) {
            mkdir($sourceDir, 0777, true);
        }
        if (!is_dir($destDir)) {
            mkdir($destDir, 0777, true);
        }
        $sourcePath = $sourceDir . '/fi_en_test.txt';
        file_put_contents($sourcePath, $content);

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
        $sourceDir = storage_path('app/temp');
        $destDir = storage_path('app/temp/dictionaries');
        if (!is_dir($sourceDir)) {
            mkdir($sourceDir, 0777, true);
        }
        if (!is_dir($destDir)) {
            mkdir($destDir, 0777, true);
        }
        $sourcePath = $sourceDir . '/random_test.txt';
        file_put_contents($sourcePath, $content);

        $file = new UploadedFile($sourcePath, 'random.txt', 'application/octet-stream', null, true);

        $result = $this->service->getDictionaryFileInformation($file, [], [], []);

        $this->assertNull($result);
    }

    // ==================== D. Wiktionary tsv detection ====================

    public function test_wiktionary_tsv_detection(): void
    {
        $content = "word\tdefinition\ncat\tkissa\n";
        $sourceDir = storage_path('app/temp');
        $destDir = storage_path('app/temp/dictionaries');
        if (!is_dir($sourceDir)) {
            mkdir($sourceDir, 0777, true);
        }
        if (!is_dir($destDir)) {
            mkdir($destDir, 0777, true);
        }
        $sourcePath = $sourceDir . '/finnish_wiktionary_test.tsv';
        file_put_contents($sourcePath, $content);

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
        $sourceDir = storage_path('app/temp');
        $destDir = storage_path('app/temp/dictionaries');
        if (!is_dir($sourceDir)) {
            mkdir($sourceDir, 0777, true);
        }
        if (!is_dir($destDir)) {
            mkdir($destDir, 0777, true);
        }
        $sourcePath = $sourceDir . '/custom_tsv_test.tsv';
        file_put_contents($sourcePath, $content);

        $file = new UploadedFile($sourcePath, 'finnish.notwiktionary.tsv', 'application/octet-stream', null, true);

        $result = $this->service->getDictionaryFileInformation($file, [], [], []);

        $this->assertNull($result);
    }

    // ==================== E. testDictionaryCsvFile — success ====================

    public function test_csv_sample_success_with_skip_header(): void
    {
        $content = "Word,Translation\nApple,Omena\nPear,Paaryna\nCat,Kissa\nDog,Koira\n";
        $path = storage_path('app/temp');
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $csvPath = $path . '/test_csv_skip.csv';
        file_put_contents($csvPath, $content);

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
        $path = storage_path('app/temp');
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $csvPath = $path . '/test_csv_no_skip.csv';
        file_put_contents($csvPath, $content);

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
        $path = storage_path('app/temp');
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $csvPath = $path . '/test_csv_error.csv';
        file_put_contents($csvPath, $content);

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
        $path = storage_path('app/temp');
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $csvPath = $path . '/test_invalid_table.csv';
        file_put_contents($csvPath, $content);
        $file = new UploadedFile($csvPath, 'test_invalid_table.csv', 'text/csv', null, true);

        $this->service->importDictionaryCsvFile($file, true, ',', 'mydict', 'UPPERCASE_TABLE', 'finnish', 'english', '#FF0000');
    }

    public function test_import_csv_rejects_long_dictionary_name(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Dictionary name can only contain up to 16 characters');

        $content = "Word,Translation\nApple,Omena\n";
        $path = storage_path('app/temp');
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $csvPath = $path . '/test_long_name.csv';
        file_put_contents($csvPath, $content);
        $file = new UploadedFile($csvPath, 'test_long_name.csv', 'text/csv', null, true);

        $this->service->importDictionaryCsvFile($file, true, ',', 'very_long_dictionary_name', 'dict_ok', 'finnish', 'english', '#FF0000');
    }

    public function test_import_csv_rejects_long_table_name(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database name can only contain up to 40 characters');

        $content = "Word,Translation\nApple,Omena\n";
        $path = storage_path('app/temp');
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $csvPath = $path . '/test_long_table.csv';
        file_put_contents($csvPath, $content);
        $file = new UploadedFile($csvPath, 'test_long_table.csv', 'text/csv', null, true);

        $this->service->importDictionaryCsvFile($file, true, ',', 'mydict', 'dict_this_table_name_is_way_too_long_for_the_limit', 'finnish', 'english', '#FF0000');
    }

    // ==================== H. importDictionaryCsvFile — success ====================

    public function test_import_csv_success_path(): void
    {
        $content = "Word,Translation\nApple,Omena\nPear,Paaryna\n";
        $path = storage_path('app/temp');
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $csvPath = $path . '/test_import_success.csv';
        file_put_contents($csvPath, $content);
        $file = new UploadedFile($csvPath, 'test_import_success.csv', 'text/csv', null, true);

        $tableName = 'dict_test_csv_' . bin2hex(random_bytes(4));
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

        // Dictionary record was created
        $dictionary = \App\Models\Dictionary::where('name', $dictName)->first();
        $this->assertNotNull($dictionary);
        $this->assertSame('custom_csv', $dictionary->type);
        $this->assertSame($tableName, $dictionary->database_table_name);
        $this->assertSame('finnish', $dictionary->source_language);
        $this->assertSame('english', $dictionary->target_language);
        $this->assertSame('#FF0000', $dictionary->color);
        $this->assertTrue((bool) $dictionary->enabled);

        // Cleanup
        Schema::dropIfExists($tableName);
        $dictionary->delete();
    }
}
