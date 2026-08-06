<?php

namespace Tests\Feature;

use App\Http\Requests\Dictionaries\ImportSupportedDictionaryRequest;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ImportSupportedDictionaryRequestValidationTest extends TestCase
{
    public function test_valid_supported_dictionary_request_passes(): void
    {
        $validator = $this->validatorFor($this->validPayload());

        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    public function test_core_application_table_names_are_rejected(): void
    {
        foreach (['users', 'dictionaries', 'review_cards', 'review_logs'] as $tableName) {
            $validator = $this->validatorFor($this->validPayload([
                'dictionaryDatabaseName' => $tableName,
            ]));

            $this->assertTrue(
                $validator->fails(),
                "The core application table [{$tableName}] must be rejected.",
            );
            $this->assertTrue($validator->errors()->has('dictionaryDatabaseName'));
        }
    }

    public function test_uppercase_and_invalid_dictionary_table_names_are_rejected(): void
    {
        foreach (['dict_ZH_cedict', 'dict-zh-cedict', 'dict_zh.cedict', 'dict_'] as $tableName) {
            $validator = $this->validatorFor($this->validPayload([
                'dictionaryDatabaseName' => $tableName,
            ]));

            $this->assertTrue(
                $validator->fails(),
                "The invalid dictionary table [{$tableName}] must be rejected.",
            );
            $this->assertTrue($validator->errors()->has('dictionaryDatabaseName'));
        }
    }

    public function test_parent_directory_filename_is_rejected(): void
    {
        $validator = $this->validatorFor($this->validPayload([
            'dictionaryFileName' => '../cedict.u8',
        ]));

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('dictionaryFileName'));
    }

    public function test_path_separator_filenames_are_rejected(): void
    {
        foreach (['folder/cedict.u8', 'folder\\cedict.u8'] as $fileName) {
            $validator = $this->validatorFor($this->validPayload([
                'dictionaryFileName' => $fileName,
            ]));

            $this->assertTrue($validator->fails());
            $this->assertTrue($validator->errors()->has('dictionaryFileName'));
        }
    }

    public function test_nul_and_double_dot_filenames_are_rejected(): void
    {
        foreach (["cedict\0.u8", 'cedict..u8'] as $fileName) {
            $validator = $this->validatorFor($this->validPayload([
                'dictionaryFileName' => $fileName,
            ]));

            $this->assertTrue($validator->fails());
            $this->assertTrue($validator->errors()->has('dictionaryFileName'));
        }
    }

    public function test_plain_filename_with_multiple_extensions_passes(): void
    {
        $validator = $this->validatorFor($this->validPayload([
            'dictionaryFileName' => 'finnish.wiktionary.tsv',
        ]));

        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    public function test_overlong_string_fields_are_rejected(): void
    {
        $cases = [
            'dictionaryName' => str_repeat('a', 256),
            'dictionaryFileName' => str_repeat('a', 256),
            'dictionarySourceLanguage' => str_repeat('a', 65),
            'dictionaryTargetLanguage' => str_repeat('a', 65),
            'dictionaryDatabaseName' => 'dict_'.str_repeat('a', 36),
        ];

        foreach ($cases as $field => $value) {
            $validator = $this->validatorFor($this->validPayload([$field => $value]));

            $this->assertTrue($validator->fails(), "The overlong field [{$field}] must be rejected.");
            $this->assertTrue($validator->errors()->has($field));
        }
    }

    /** @param array<string, string> $overrides */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'dictionaryName' => 'cc-cedict',
            'dictionaryFileName' => 'cedict_ts.u8',
            'dictionarySourceLanguage' => 'chinese',
            'dictionaryTargetLanguage' => 'english',
            'dictionaryDatabaseName' => 'dict_zh_cedict',
        ], $overrides);
    }

    /** @param array<string, string> $payload */
    private function validatorFor(array $payload): ValidatorContract
    {
        $request = new ImportSupportedDictionaryRequest();

        return Validator::make($payload, $request->rules());
    }
}
