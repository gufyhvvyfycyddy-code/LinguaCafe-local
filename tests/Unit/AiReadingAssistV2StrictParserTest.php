<?php

namespace Tests\Unit;

use App\Services\AiReadingAssistV2Service;
use PHPUnit\Framework\TestCase;
use Tests\Support\PabR3AiReadingAssistV2Harness as V2Harness;

class AiReadingAssistV2StrictParserTest extends TestCase
{
    private mixed $previousFacadeApplication;
    private array $catalog;
    private AiReadingAssistV2Service $service;
    private array $package;
    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFacadeApplication = V2Harness::installPureCryptoFacade();
        $this->catalog = V2Harness::catalog();
        $this->service = V2Harness::service(fn () => $this->catalog);
        $this->package = V2Harness::packages($this->service)[0];
        $this->payload = V2Harness::aiPayload($this->package);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        V2Harness::restoreFacadeApplication($this->previousFacadeApplication);
        parent::tearDown();
    }

    private function preview(array|string $payload, ?array $package = null): array
    {
        return $this->service->previewImport(
            V2Harness::USER_ID,
            V2Harness::LANGUAGE,
            V2Harness::CHAPTER_ID,
            [V2Harness::importPart($package ?? $this->package, $payload)],
        );
    }

    private function assertRejected(array|string $payload, string $errorCode): void
    {
        $result = $this->preview($payload);
        $this->assertFalse($result['success']);
        $this->assertSame($errorCode, $result['error_code']);
    }

    public function test_v2_accepts_direct_valid_json_and_exact_server_echoes(): void
    {
        $result = $this->preview($this->payload);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['parsed']);
        $this->assertSame(V2Harness::SOURCE_REVISION, $result['source_revision']);
        $this->assertSame(1, $result['summary']['word_target_count']);
        $this->assertSame($this->payload['word_results'][0]['occurrence_id'], $result['items']['word_results'][0]['occurrence_id']);
    }

    public function test_v2_rejects_code_fence_surrounding_prose_and_trailing_comma(): void
    {
        $json = json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach ([
            "```json\n{$json}\n```",
            "Here is the result:\n{$json}\nDone.",
            substr($json, 0, -1).',}',
        ] as $invalid) {
            $this->assertRejected($invalid, AiReadingAssistV2Service::ERROR_INVALID_JSON);
        }
    }

    public function test_v2_rejects_missing_extra_and_wrong_typed_top_level_fields(): void
    {
        $missing = $this->payload;
        unset($missing['word_results']);
        $this->assertRejected($missing, AiReadingAssistV2Service::ERROR_SCHEMA_MISMATCH);

        $extra = $this->payload;
        $extra['unexpected'] = true;
        $this->assertRejected($extra, AiReadingAssistV2Service::ERROR_SCHEMA_MISMATCH);

        $numericString = $this->payload;
        $numericString['part_index'] = '1';
        $this->assertRejected($numericString, AiReadingAssistV2Service::ERROR_SCHEMA_MISMATCH);

        foreach (['sentence_translations', 'word_results', 'phrase_results', 'warnings'] as $listField) {
            $jsonObject = $this->payload;
            $jsonObject[$listField] = new \stdClass();
            $this->assertRejected($jsonObject, AiReadingAssistV2Service::ERROR_SCHEMA_MISMATCH);
        }
    }

    public function test_v2_rejects_invalid_result_and_confidence_enums(): void
    {
        $invalidResult = $this->payload;
        $invalidResult['word_results'][0]['result'] = 'ignore';
        $this->assertRejected($invalidResult, AiReadingAssistV2Service::ERROR_SCHEMA_MISMATCH);

        $invalidConfidence = $this->payload;
        $invalidConfidence['word_results'][0]['confidence'] = 'very_high';
        $this->assertRejected($invalidConfidence, AiReadingAssistV2Service::ERROR_SCHEMA_MISMATCH);
    }

    public function test_v2_rejects_duplicate_missing_and_extra_occurrences(): void
    {
        $duplicate = $this->payload;
        $duplicate['word_results'][] = $duplicate['word_results'][0];
        $this->assertRejected($duplicate, AiReadingAssistV2Service::ERROR_DUPLICATE_OCCURRENCE_ID);

        $missing = $this->payload;
        $missing['word_results'] = [];
        $this->assertRejected($missing, AiReadingAssistV2Service::ERROR_TARGET_SET_MISMATCH);

        $extra = $this->payload;
        $extra['word_results'][] = array_merge($extra['word_results'][0], ['occurrence_id' => 'occ2_ai_fabricated']);
        $this->assertRejected($extra, AiReadingAssistV2Service::ERROR_TARGET_SET_MISMATCH);
    }

    public function test_v2_rejects_identity_echo_mismatches(): void
    {
        foreach (['surface', 'lemma', 'pos', 'source_sentence'] as $field) {
            $invalid = $this->payload;
            $invalid['word_results'][0][$field] = 'mismatch';
            $this->assertRejected($invalid, AiReadingAssistV2Service::ERROR_IDENTITY_ECHO_MISMATCH);
        }
    }

    public function test_v2_rejects_package_part_and_source_echo_mismatches(): void
    {
        $package = $this->payload;
        $package['package_id'] = 'wrong-package';
        $this->assertRejected($package, AiReadingAssistV2Service::ERROR_PACKAGE_MISMATCH);

        $part = $this->payload;
        $part['part_index'] = 2;
        $this->assertRejected($part, AiReadingAssistV2Service::ERROR_PACKAGE_MISMATCH);

        $source = $this->payload;
        $source['source_revision'] = 'sha256:stale';
        $this->assertRejected($source, AiReadingAssistV2Service::ERROR_STALE_SOURCE);
    }

    public function test_matched_new_and_ambiguous_result_shapes_fail_closed_before_database_lookup(): void
    {
        $matchedNull = $this->payload;
        $matchedNull['word_results'][0]['result'] = 'matched_existing';
        $this->assertRejected($matchedNull, AiReadingAssistV2Service::ERROR_SCHEMA_MISMATCH);

        $matchedWithNew = $this->payload;
        $matchedWithNew['word_results'][0]['result'] = 'matched_existing';
        $matchedWithNew['word_results'][0]['matched_word_sense_id'] = 123;
        $matchedWithNew['word_results'][0]['new_sense'] = ['sense_zh' => 'x', 'sense_en' => 'x', 'pos' => 'NOUN'];
        $this->assertRejected($matchedWithNew, AiReadingAssistV2Service::ERROR_SCHEMA_MISMATCH);

        $newWithMatched = $this->payload;
        $newWithMatched['word_results'][0]['result'] = 'new_sense';
        $newWithMatched['word_results'][0]['matched_word_sense_id'] = 123;
        $newWithMatched['word_results'][0]['new_sense'] = ['sense_zh' => 'x', 'sense_en' => 'x', 'pos' => 'NOUN'];
        $this->assertRejected($newWithMatched, AiReadingAssistV2Service::ERROR_SCHEMA_MISMATCH);

        $ambiguousWithMatched = $this->payload;
        $ambiguousWithMatched['word_results'][0]['matched_word_sense_id'] = 123;
        $this->assertRejected($ambiguousWithMatched, AiReadingAssistV2Service::ERROR_SCHEMA_MISMATCH);
    }

    public function test_phrase_schema_cannot_acquire_word_sense_fields(): void
    {
        $this->catalog = V2Harness::catalog(0, [], true);
        $this->service = V2Harness::service(fn () => $this->catalog);
        $this->package = V2Harness::packages($this->service)[0];
        $payload = V2Harness::aiPayload($this->package);
        $payload['phrase_results'][0]['matched_word_sense_id'] = 123;

        $this->assertRejected($payload, AiReadingAssistV2Service::ERROR_SCHEMA_MISMATCH);
    }
}
