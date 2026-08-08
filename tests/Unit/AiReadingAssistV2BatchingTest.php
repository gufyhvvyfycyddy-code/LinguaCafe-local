<?php

namespace Tests\Unit;

use App\Services\AiReadingAssistV2Service;
use PHPUnit\Framework\TestCase;
use Tests\Support\PabR3AiReadingAssistV2Harness as V2Harness;

class AiReadingAssistV2BatchingTest extends TestCase
{
    private mixed $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFacadeApplication = V2Harness::installPureCryptoFacade();
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        V2Harness::restoreFacadeApplication($this->previousFacadeApplication);
        parent::tearDown();
    }

    private function serviceForCount(int $count, ?array &$catalog = null): AiReadingAssistV2Service
    {
        $catalog = V2Harness::catalog($count);
        return V2Harness::service(fn () => $catalog);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('packageCountProvider')]
    public function test_targets_are_split_into_exact_fifty_target_parts(int $targets, int $expectedParts): void
    {
        $service = $this->serviceForCount($targets, $catalog);
        $result = $service->buildSourcePackages(V2Harness::USER_ID, V2Harness::LANGUAGE, V2Harness::CHAPTER_ID);

        $this->assertTrue($result['success']);
        $this->assertSame($expectedParts, $result['package_count']);
        $this->assertSame($expectedParts, $result['part_count']);
        $this->assertSame($targets, $result['target_count']);
        foreach ($result['packages'] as $package) {
            $this->assertLessThanOrEqual(50, $package['target_count']);
        }
    }

    public static function packageCountProvider(): array
    {
        return [
            '20 => 1' => [20, 1],
            '49 => 1' => [49, 1],
            '50 => 1' => [50, 1],
            '51 => 2' => [51, 2],
            '100 => 2' => [100, 2],
            '101 => 3' => [101, 3],
        ];
    }

    public function test_missing_part_rejects_whole_preview(): void
    {
        $service = $this->serviceForCount(51, $catalog);
        $packages = V2Harness::packages($service);
        $result = $service->previewImport(
            V2Harness::USER_ID,
            V2Harness::LANGUAGE,
            V2Harness::CHAPTER_ID,
            [V2Harness::importPart($packages[0])],
        );

        $this->assertFalse($result['success']);
        $this->assertSame(AiReadingAssistV2Service::ERROR_PART_SET_INCOMPLETE, $result['error_code']);
    }

    public function test_duplicate_part_rejects_whole_preview(): void
    {
        $service = $this->serviceForCount(51, $catalog);
        $packages = V2Harness::packages($service);
        $part = V2Harness::importPart($packages[0]);
        $result = $service->previewImport(
            V2Harness::USER_ID,
            V2Harness::LANGUAGE,
            V2Harness::CHAPTER_ID,
            [$part, $part],
        );

        $this->assertFalse($result['success']);
        $this->assertSame(AiReadingAssistV2Service::ERROR_PART_SET_INCOMPLETE, $result['error_code']);
    }

    public function test_duplicate_target_across_parts_is_rejected(): void
    {
        $service = $this->serviceForCount(51, $catalog);
        $packages = V2Harness::packages($service);
        $firstManifest = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($packages[0]['manifest_token']), true, 512, JSON_THROW_ON_ERROR);
        $duplicateId = $firstManifest['target_ids'][0];
        $duplicateCandidates = $firstManifest['candidate_sets'][$duplicateId] ?? [];
        $packages[1]['manifest_token'] = V2Harness::mutateManifestToken(
            $packages[1]['manifest_token'],
            function (array $manifest) use ($duplicateId, $duplicateCandidates): array {
                $manifest['target_ids'][0] = $duplicateId;
                $manifest['candidate_sets'][$duplicateId] = $duplicateCandidates;
                return $manifest;
            },
        );

        $result = $service->previewImport(
            V2Harness::USER_ID,
            V2Harness::LANGUAGE,
            V2Harness::CHAPTER_ID,
            array_map(fn (array $package) => V2Harness::importPart($package), $packages),
        );

        $this->assertFalse($result['success']);
        $this->assertSame(AiReadingAssistV2Service::ERROR_DUPLICATE_OCCURRENCE_ID, $result['error_code']);
    }

    public function test_part_two_and_later_reject_sentence_translations(): void
    {
        $service = $this->serviceForCount(51, $catalog);
        $packages = V2Harness::packages($service);
        $parts = [];
        foreach ($packages as $index => $package) {
            $payload = V2Harness::aiPayload($package);
            if ($index === 1) {
                $payload['sentence_translations'] = [[
                    'sentence_index' => 0,
                    'source_text' => 'Harness sentence.',
                    'translation_zh' => '不应由第二部分携带',
                ]];
            }
            $parts[] = V2Harness::importPart($package, $payload);
        }

        $result = $service->previewImport(V2Harness::USER_ID, V2Harness::LANGUAGE, V2Harness::CHAPTER_ID, $parts);
        $this->assertFalse($result['success']);
        $this->assertSame(AiReadingAssistV2Service::ERROR_TRANSLATION_SET_MISMATCH, $result['error_code']);
    }

    public function test_stale_source_revision_in_manifest_is_rejected(): void
    {
        $service = $this->serviceForCount(51, $catalog);
        $packages = V2Harness::packages($service);
        $packages[1]['manifest_token'] = V2Harness::mutateManifestToken(
            $packages[1]['manifest_token'],
            function (array $manifest): array {
                $manifest['source_revision'] = 'sha256:stale';
                return $manifest;
            },
        );

        $parts = array_map(fn (array $package) => V2Harness::importPart($package), $packages);
        $result = $service->previewImport(V2Harness::USER_ID, V2Harness::LANGUAGE, V2Harness::CHAPTER_ID, $parts);
        $this->assertFalse($result['success']);
        $this->assertSame(AiReadingAssistV2Service::ERROR_STALE_SOURCE, $result['error_code']);
    }

    public function test_candidate_scope_change_invalidates_stale_manifest(): void
    {
        $catalog = V2Harness::catalog(1, [0 => [111]]);
        $service = V2Harness::service(function () use (&$catalog) {
            return $catalog;
        });
        $package = V2Harness::packages($service)[0];

        $catalog = V2Harness::catalog(1, [0 => [222]]);
        $result = $service->previewImport(
            V2Harness::USER_ID,
            V2Harness::LANGUAGE,
            V2Harness::CHAPTER_ID,
            [V2Harness::importPart($package)],
        );

        $this->assertFalse($result['success']);
        $this->assertSame(AiReadingAssistV2Service::ERROR_CANDIDATE_MISMATCH, $result['error_code']);
    }

    public function test_complete_part_set_merges_every_target_exactly_once_in_memory(): void
    {
        $service = $this->serviceForCount(101, $catalog);
        $packages = V2Harness::packages($service);
        $parts = array_map(fn (array $package) => V2Harness::importPart($package), $packages);

        $result = $service->previewImport(V2Harness::USER_ID, V2Harness::LANGUAGE, V2Harness::CHAPTER_ID, $parts);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['items']['part_count']);
        $this->assertCount(3, $result['items']['package_ids']);
        $this->assertCount(101, $result['items']['word_results']);
        $this->assertSame(101, count(array_unique(array_column($result['items']['word_results'], 'occurrence_id'))));
        $this->assertSame(101, $result['summary']['word_target_count']);
    }
}
