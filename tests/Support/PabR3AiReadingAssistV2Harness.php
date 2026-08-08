<?php

namespace Tests\Support;

use App\Services\AiReadingAssistV2Service;
use App\Services\ReadingOccurrenceSenseEvidenceService;
use App\Services\ReadingTargetCatalogService;
use App\Services\ReadingUnfamiliarTargetService;
use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Facade;
use Mockery;

final class PabR3AiReadingAssistV2Harness
{
    public static function installPureCryptoFacade(): mixed
    {
        $previous = Facade::getFacadeApplication();
        $container = new Container();
        $container->instance('encrypter', new Encrypter(random_bytes(32), 'AES-256-CBC'));
        Facade::setFacadeApplication($container);
        Crypt::clearResolvedInstance('encrypter');

        return $previous;
    }

    public static function restoreFacadeApplication(mixed $previous): void
    {
        Crypt::clearResolvedInstance('encrypter');
        Facade::setFacadeApplication($previous);
    }

    public const USER_ID = 71001;
    public const LANGUAGE = 'english';
    public const CHAPTER_ID = 72001;
    public const SOURCE_REVISION = 'sha256:pab-r3-harness-source-revision';

    public static function catalog(int $targetCount = 1, array $candidateIdsByIndex = [], bool $includePhrase = false): array
    {
        $targets = [];
        for ($index = 0; $index < $targetCount; $index++) {
            $occurrenceId = sprintf('occ2_pab_r3_word_%04d', $index);
            $candidateIds = $candidateIdsByIndex[$index] ?? [];
            $targets[] = [
                'occurrence_id' => $occurrenceId,
                'kind' => 'word',
                'purpose' => 'passive_disambiguation',
                'start_word_index' => $index,
                'end_word_index' => $index,
                'sentence_index' => 0,
                'surface' => 'word'.$index,
                'lemma' => 'lemma'.$index,
                'pos' => 'NOUN',
                'source_sentence' => 'Harness sentence.',
                'candidate_word_senses' => array_map(
                    fn (int $id) => [
                        'word_sense_id' => $id,
                        'lemma' => 'lemma'.$index,
                        'sense_zh' => '测试义项',
                        'sense_en' => 'test sense',
                        'pos' => 'NOUN',
                    ],
                    $candidateIds,
                ),
            ];
        }

        if ($includePhrase) {
            $targets[] = [
                'occurrence_id' => 'occ2_pab_r3_phrase_0001',
                'kind' => 'phrase',
                'purpose' => 'marked_unknown',
                'start_word_index' => $targetCount,
                'end_word_index' => $targetCount + 1,
                'sentence_index' => 0,
                'surface' => 'in light',
                'lemma' => 'in light',
                'pos' => null,
                'source_sentence' => 'Harness sentence.',
                'candidate_word_senses' => [],
            ];
        }

        $targetsById = [];
        foreach ($targets as $target) {
            $targetsById[$target['occurrence_id']] = $target;
        }

        return [
            'chapter' => (object) ['id' => self::CHAPTER_ID, 'name' => 'PAB R3 Harness'],
            'source_revision' => self::SOURCE_REVISION,
            'sentences' => [0 => 'Harness sentence.'],
            'targets' => $targets,
            'targets_by_id' => $targetsById,
        ];
    }

    public static function service(callable $catalogProvider, ?ReadingOccurrenceSenseEvidenceService $evidenceService = null): AiReadingAssistV2Service
    {
        $catalogService = Mockery::mock(ReadingTargetCatalogService::class);
        $catalogService->shouldReceive('build')->andReturnUsing(fn () => $catalogProvider());

        $unfamiliar = Mockery::mock(ReadingUnfamiliarTargetService::class);
        $evidenceService ??= Mockery::mock(ReadingOccurrenceSenseEvidenceService::class);

        return new AiReadingAssistV2Service($catalogService, $unfamiliar, $evidenceService);
    }

    public static function packages(AiReadingAssistV2Service $service): array
    {
        $result = $service->buildSourcePackages(
            self::USER_ID,
            self::LANGUAGE,
            self::CHAPTER_ID,
        );

        if (($result['success'] ?? false) !== true) {
            throw new \RuntimeException('Harness could not build V2 source packages: '.json_encode($result));
        }

        return $result['packages'];
    }

    public static function aiPayload(array $package, string $wordResult = 'ambiguous'): array
    {
        $source = $package['source_payload'];
        $wordResults = [];
        foreach ($source['word_targets'] as $target) {
            $matchedId = null;
            $newSense = null;
            if ($wordResult === 'matched_existing') {
                $matchedId = (int) ($target['candidate_word_senses'][0]['word_sense_id'] ?? 0);
            } elseif ($wordResult === 'new_sense') {
                $newSense = ['sense_zh' => '新义', 'sense_en' => 'new sense', 'pos' => $target['pos'] ?? 'NOUN'];
            }

            $wordResults[] = [
                'occurrence_id' => $target['occurrence_id'],
                'surface' => $target['surface'],
                'lemma' => $target['lemma'],
                'pos' => $target['pos'],
                'sentence_index' => $target['sentence_index'],
                'source_sentence' => $target['source_sentence'],
                'result' => $wordResult,
                'matched_word_sense_id' => $matchedId,
                'new_sense' => $newSense,
                'confidence' => 'high',
                'reason' => 'PAB R3 harness decision.',
            ];
        }

        $phraseResults = [];
        foreach ($source['phrase_targets'] as $target) {
            $phraseResults[] = [
                'occurrence_id' => $target['occurrence_id'],
                'phrase' => $target['phrase'],
                'sentence_index' => $target['sentence_index'],
                'source_sentence' => $target['source_sentence'],
                'sense_zh' => '固定短语',
                'sense_en' => 'fixed phrase',
                'confidence' => 'medium',
                'reason' => 'PAB R3 harness phrase.',
            ];
        }

        $translations = [];
        if ($source['part']['translation_owner']) {
            foreach ($source['sentences'] as $sentence) {
                $translations[] = [
                    'sentence_index' => $sentence['sentence_index'],
                    'source_text' => $sentence['source_text'],
                    'translation_zh' => '测试翻译',
                ];
            }
        }

        return [
            'schema_version' => AiReadingAssistV2Service::SCHEMA_VERSION,
            'package_id' => $source['package_id'],
            'part_index' => $source['part']['part_index'],
            'part_count' => $source['part']['part_count'],
            'source_revision' => $source['source']['source_revision'],
            'sentence_translations' => $translations,
            'word_results' => $wordResults,
            'phrase_results' => $phraseResults,
            'warnings' => [],
        ];
    }

    public static function importPart(array $package, array|string|null $payload = null): array
    {
        $payload ??= self::aiPayload($package);

        return [
            'manifest_token' => $package['manifest_token'],
            'ai_text' => is_array($payload)
                ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : $payload,
        ];
    }

    public static function mutateManifestToken(string $token, callable $mutator): string
    {
        $manifest = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        $manifest = $mutator($manifest);

        return Crypt::encryptString(json_encode(
            $manifest,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }
}
