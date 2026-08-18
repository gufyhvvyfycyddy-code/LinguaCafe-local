<?php

namespace App\Services;

use App\Exceptions\ReadingAssistV2ContractException;
use App\Models\ChapterAiReadingAssist;
use App\Models\WordSense;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiReadingAssistV2Service
{
    public const SCHEMA_VERSION = 'linguacafe_ai_reading_assist_v2';

    public const ERROR_INVALID_JSON = 'V2_INVALID_JSON';
    public const ERROR_SCHEMA_MISMATCH = 'V2_SCHEMA_MISMATCH';
    public const ERROR_STALE_SOURCE = 'V2_STALE_SOURCE';
    public const ERROR_PACKAGE_MISMATCH = 'V2_PACKAGE_MISMATCH';
    public const ERROR_PART_SET_INCOMPLETE = 'V2_PART_SET_INCOMPLETE';
    public const ERROR_TARGET_SET_MISMATCH = 'V2_TARGET_SET_MISMATCH';
    public const ERROR_DUPLICATE_OCCURRENCE_ID = 'V2_DUPLICATE_OCCURRENCE_ID';
    public const ERROR_IDENTITY_ECHO_MISMATCH = 'V2_IDENTITY_ECHO_MISMATCH';
    public const ERROR_CANDIDATE_MISMATCH = 'V2_CANDIDATE_MISMATCH';
    public const ERROR_WORD_SENSE_OWNERSHIP_MISMATCH = 'V2_WORD_SENSE_OWNERSHIP_MISMATCH';
    public const ERROR_TRANSLATION_SET_MISMATCH = 'V2_TRANSLATION_SET_MISMATCH';
    public const ERROR_INTERNAL = 'V2_INTERNAL_ERROR';

    public function __construct(
        private ReadingTargetCatalogService $targetCatalogService,
        private ReadingUnfamiliarTargetService $unfamiliarTargetService,
        private ReadingOccurrenceSenseEvidenceService $evidenceService,
        private ReadingChapterTextService $chapterTextService,
    ) {
    }

    public function buildSourcePackages(
        int $userId,
        string $language,
        int $chapterId,
        ?array $expectedMarkedTargets = null,
        ?string $expectedMarkedTargetsSnapshotVersion = null,
    ): array {
        try {
            if ($expectedMarkedTargets !== null) {
                try {
                    $snapshot = $this->unfamiliarTargetService->listCurrentTargets(
                        $userId,
                        $language,
                        $chapterId,
                    );
                } catch (\InvalidArgumentException $e) {
                    $this->reject(self::ERROR_TARGET_SET_MISMATCH, 'V2 marked target snapshot is stale or invalid.');
                }
                $currentSnapshotVersion = (string) ($snapshot['snapshot_version'] ?? '');
                if ($currentSnapshotVersion === ''
                    || !$expectedMarkedTargetsSnapshotVersion
                    || !hash_equals($currentSnapshotVersion, $expectedMarkedTargetsSnapshotVersion)) {
                    $this->reject(self::ERROR_TARGET_SET_MISMATCH, 'V2 marked target snapshot is stale or invalid.');
                }
            }
            $catalog = $this->targetCatalogService->build($userId, $language, $chapterId);
            if ($expectedMarkedTargets !== null) {
                $this->assertMarkedTargetSnapshot($catalog['targets'], $expectedMarkedTargets);
            }
            $targets = $catalog['targets'];
            $partCount = max(1, (int) ceil(count($targets) / 50));
            $basePartSize = intdiv(count($targets), $partCount);
            $largerPartCount = count($targets) % $partCount;
            $chunks = [];
            $offset = 0;
            for ($index = 0; $index < $partCount; $index++) {
                $partSize = $basePartSize + ($index < $largerPartCount ? 1 : 0);
                $chunks[] = array_slice($targets, $offset, $partSize);
                $offset += $partSize;
            }
            $scopeHash = $this->targetScopeHash($targets);
            $sentences = $this->sourceSentences($catalog['sentences']);
            $packages = [];

            foreach ($chunks as $index => $chunk) {
                $partIndex = $index + 1;
                $packageId = (string) Str::uuid();
                $wordTargets = array_values(array_map(
                    fn (array $target) => $this->sourceWordTarget($target),
                    array_filter($chunk, fn (array $target) => $target['kind'] === 'word'),
                ));
                $phraseTargets = array_values(array_map(
                    fn (array $target) => $this->sourcePhraseTarget($target),
                    array_filter($chunk, fn (array $target) => $target['kind'] === 'phrase'),
                ));
                $manifest = [
                    'user_id' => $userId,
                    'language' => $language,
                    'chapter_id' => $chapterId,
                    'source_revision' => $catalog['source_revision'],
                    'package_id' => $packageId,
                    'scope_hash' => $scopeHash,
                    'part_index' => $partIndex,
                    'part_count' => count($chunks),
                    'target_ids' => array_values(array_map(fn (array $target) => $target['occurrence_id'], $chunk)),
                    'candidate_sets' => $this->candidateSets($chunk),
                ];
                $sourcePayload = [
                    'schema_version' => self::SCHEMA_VERSION,
                    'package_id' => $packageId,
                    'source' => [
                        'chapter_id' => $chapterId,
                        'language' => $language,
                        'chapter_title' => (string) ($catalog['chapter']->name ?? ''),
                        'source_revision' => $catalog['source_revision'],
                    ],
                    'part' => [
                        'part_index' => $partIndex,
                        'part_count' => count($chunks),
                        'translation_owner' => $partIndex === 1,
                    ],
                    'sentences' => $sentences,
                    'word_targets' => $wordTargets,
                    'phrase_targets' => $phraseTargets,
                ];
                $packages[] = [
                    'package_id' => $packageId,
                    'part_index' => $partIndex,
                    'part_count' => count($chunks),
                    'source_revision' => $catalog['source_revision'],
                    'manifest_token' => Crypt::encryptString(json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                    'target_count' => count($chunk),
                    'source_payload' => $sourcePayload,
                    'prompt' => $this->buildPrompt($sourcePayload),
                ];
            }

            return [
                'success' => true,
                'schema_version' => self::SCHEMA_VERSION,
                'chapter_id' => $chapterId,
                'source_revision' => $catalog['source_revision'],
                'package_count' => count($packages),
                'part_count' => count($packages),
                'target_count' => count($targets),
                'prompt' => count($packages) === 1 ? $packages[0]['prompt'] : '',
                'packages' => $packages,
                'target_summary' => [
                    'total_targets' => count($targets),
                    'marked_unknown_count' => count(array_filter($targets, fn (array $target) => $target['purpose'] === 'marked_unknown')),
                    'passive_disambiguation_count' => count(array_filter($targets, fn (array $target) => $target['purpose'] === 'passive_disambiguation')),
                ],
            ];
        } catch (ReadingAssistV2ContractException $e) {
            return [
                'success' => false,
                'error_code' => $e->errorCode,
                'message' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error_code' => self::ERROR_INTERNAL,
                'message' => 'V2 reading-assist source request could not be processed.',
            ];
        }
    }

    public function currentVerificationItems(
        int $userId,
        string $language,
        int $chapterId,
        ChapterAiReadingAssist $record,
    ): array {
        $catalog = $this->targetCatalogService->build($userId, $language, $chapterId);
        $currentScopeHash = $this->targetScopeHash($catalog['targets']);
        if (!$record->source_revision
            || $record->source_revision !== $catalog['source_revision']
            || !$record->target_scope_hash
            || !hash_equals($record->target_scope_hash, $currentScopeHash)) {
            return [
                'assist_stale' => true,
                'verification_items' => [],
            ];
        }

        $payload = is_array($record->validated_payload) ? $record->validated_payload : [];
        $wordResults = is_array($payload['word_results'] ?? null) ? $payload['word_results'] : [];
        $phraseResults = is_array($payload['phrase_results'] ?? null) ? $payload['phrase_results'] : [];
        $resultByOccurrence = [];
        foreach (array_merge($wordResults, $phraseResults) as $result) {
            if (is_array($result) && is_string($result['occurrence_id'] ?? null)) {
                $resultByOccurrence[$result['occurrence_id']] = $result;
            }
        }

        $evidenceMap = $this->evidenceService->currentEvidenceMap(
            $userId,
            $language,
            $chapterId,
            $catalog['source_revision'],
        );

        $items = [];
        foreach ($catalog['targets'] as $target) {
            $occurrenceId = $target['occurrence_id'];
            $result = $resultByOccurrence[$occurrenceId] ?? null;
            if (!$result) {
                continue;
            }
            $evidence = $evidenceMap[$occurrenceId] ?? null;
            $items[] = array_merge($target, $result, [
                'target_type' => $target['kind'],
                'candidate_word_senses' => $target['candidate_word_senses'] ?? [],
                'evidence' => $evidence ? [
                    'resolution' => $evidence->resolution,
                    'word_sense_id' => $evidence->word_sense_id,
                    'resolution_source' => $evidence->resolution_source,
                ] : null,
            ]);
        }

        return [
            'assist_stale' => false,
            'verification_items' => $items,
        ];
    }

    public function isRecordCurrent(
        int $userId,
        string $language,
        int $chapterId,
        ChapterAiReadingAssist $record,
    ): bool {
        $catalog = $this->targetCatalogService->build($userId, $language, $chapterId);
        if (!$record->source_revision || $record->source_revision !== $catalog['source_revision']) {
            return false;
        }
        if (!$record->target_scope_hash) {
            return false;
        }

        return hash_equals($record->target_scope_hash, $this->targetScopeHash($catalog['targets']));
    }

    public function previewImport(int $userId, string $language, int $chapterId, array $parts): array
    {
        try {
            $merged = $this->validateAndMergeParts($userId, $language, $chapterId, $parts);

            return [
                'success' => true,
                'parsed' => true,
                'schema_version' => self::SCHEMA_VERSION,
                'source_revision' => $merged['source_revision'],
                'summary' => $merged['summary'],
                'items' => $merged['validated_payload'],
            ];
        } catch (ReadingAssistV2ContractException $e) {
            return $this->contractFailure($e);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'parsed' => false,
                'error_code' => self::ERROR_INTERNAL,
                'message' => 'V2 reading-assist preview could not be processed.',
            ];
        }
    }

    public function confirmImport(int $userId, string $language, int $chapterId, array $parts, bool $applyTrustAi = false): array
    {
        try {
            $saved = DB::transaction(function () use ($userId, $language, $chapterId, $parts, $applyTrustAi) {
                $this->chapterTextService->lockChapterForUser($userId, $language, $chapterId);
                $merged = $this->validateAndMergeParts($userId, $language, $chapterId, $parts);
                $projection = $this->legacyProjection($merged);
                $payloadHash = 'sha256:' . hash('sha256', json_encode(
                    $merged['validated_payload'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ));

                $record = ChapterAiReadingAssist::query()->updateOrCreate(
                    [
                        'user_id' => $userId,
                        'language' => $language,
                        'chapter_id' => $chapterId,
                    ],
                    [
                        'schema_version' => self::SCHEMA_VERSION,
                        'source_revision' => $merged['source_revision'],
                        'payload_hash' => $payloadHash,
                        'target_scope_hash' => $merged['target_scope_hash'],
                        'sentence_translations' => $merged['validated_payload']['sentence_translations'],
                        'vocabulary_items' => $projection['vocabulary_items'],
                        'phrase_items' => $projection['phrase_items'],
                        'warnings' => $merged['validated_payload']['warnings'],
                        'summary' => $merged['summary'],
                        'validated_payload' => $merged['validated_payload'],
                    ]
                );

                if ($applyTrustAi && !empty($merged['trust_ai_matches'])) {
                    $this->evidenceService->storeTrustedAiMatches(
                        $userId,
                        $language,
                        $chapterId,
                        $merged['trust_ai_matches'],
                        $payloadHash,
                    );
                }

                return $record;
            });

            return [
                'success' => true,
                'chapter_id' => $chapterId,
                'schema_version' => self::SCHEMA_VERSION,
                'source_revision' => $saved->source_revision,
                'target_scope_hash' => $saved->target_scope_hash,
                'summary' => $saved->summary,
                'message' => '本章 AI 辅助内容已保存。',
            ];
        } catch (ReadingAssistV2ContractException $e) {
            return $this->contractFailure($e);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error_code' => self::ERROR_INTERNAL,
                'message' => 'V2 import could not be saved.',
            ];
        }
    }

    private function validateAndMergeParts(int $userId, string $language, int $chapterId, array $parts): array
    {
        if (empty($parts)) {
            $this->reject(self::ERROR_PART_SET_INCOMPLETE, 'V2 import requires at least one package part.');
        }

        $catalog = $this->targetCatalogService->build($userId, $language, $chapterId);
        $mergedWordResults = [];
        $mergedPhraseResults = [];
        $mergedWarnings = [];
        $sentenceTranslations = null;
        $seenPartIndexes = [];
        $seenTargetIds = [];
        $trustAiMatches = [];
        $partCount = null;
        $packageIdsByPart = [];
        $seenPackageIds = [];
        $scopeHash = null;

        foreach ($parts as $inputPart) {
            if (!is_array($inputPart)
                || empty($inputPart['manifest_token'])
                || !is_string($inputPart['manifest_token'])
                || !array_key_exists('ai_text', $inputPart)
                || !is_string($inputPart['ai_text'])) {
                $this->reject(self::ERROR_SCHEMA_MISMATCH, 'Each V2 package part must contain string manifest_token and ai_text values.');
            }

            $manifest = $this->decryptManifest($inputPart['manifest_token']);
            $this->validateManifestScope($manifest, $userId, $language, $chapterId, $catalog);

            $partIndex = (int) $manifest['part_index'];
            if (isset($seenPartIndexes[$partIndex])) {
                $this->reject(self::ERROR_PART_SET_INCOMPLETE, 'V2 package set contains a duplicate part_index.');
            }
            $seenPartIndexes[$partIndex] = true;

            $partCount = $partCount ?? (int) $manifest['part_count'];
            $scopeHash = $scopeHash ?? (string) $manifest['scope_hash'];
            if ($partCount !== (int) $manifest['part_count']
                || $scopeHash !== (string) $manifest['scope_hash']) {
                $this->reject(self::ERROR_PACKAGE_MISMATCH, 'V2 package parts must belong to the same source/target scope.');
            }

            $packageId = (string) $manifest['package_id'];
            if (isset($seenPackageIds[$packageId])) {
                $this->reject(self::ERROR_PACKAGE_MISMATCH, 'Each V2 part must have its own package_id.');
            }
            $seenPackageIds[$packageId] = true;
            $packageIdsByPart[$partIndex] = $packageId;

            $targetIds = $manifest['target_ids'];
            if (count($targetIds) !== count(array_unique($targetIds))) {
                $this->reject(self::ERROR_DUPLICATE_OCCURRENCE_ID, 'V2 manifest contains a duplicate occurrence_id.');
            }
            foreach ($targetIds as $targetId) {
                if (isset($seenTargetIds[$targetId])) {
                    $this->reject(self::ERROR_DUPLICATE_OCCURRENCE_ID, 'V2 occurrence_id appears in more than one package part.');
                }
                $seenTargetIds[$targetId] = true;
            }

            $payload = $this->parseStrictJson($inputPart['ai_text']);
            $normalized = $this->validatePayloadAgainstManifest($userId, $language, $payload, $manifest, $catalog);

            if ($partIndex === 1) {
                $sentenceTranslations = $this->normalizeSentenceTranslations(
                    $normalized['sentence_translations'],
                    $this->sourceSentences($catalog['sentences']),
                );
            } elseif (!empty($normalized['sentence_translations'])) {
                $this->reject(self::ERROR_TRANSLATION_SET_MISMATCH, 'Only part 1 may contain sentence_translations in V2.');
            }

            foreach ($normalized['word_results'] as $row) {
                $mergedWordResults[] = $row;
                if ($row['result'] === 'matched_existing' && $row['confidence'] === 'high') {
                    $trustAiMatches[] = [
                        'target' => $catalog['targets_by_id'][$row['occurrence_id']],
                        'word_sense_id' => $row['matched_word_sense_id'],
                        'confidence' => $row['confidence'],
                        'package_id' => $packageId,
                    ];
                }
            }
            foreach ($normalized['phrase_results'] as $row) {
                $mergedPhraseResults[] = $row;
            }
            foreach ($normalized['warnings'] as $warning) {
                $mergedWarnings[] = $warning;
            }
        }

        if ($partCount === null || count($seenPartIndexes) !== $partCount) {
            $this->reject(self::ERROR_PART_SET_INCOMPLETE, 'V2 package set is incomplete.');
        }
        for ($index = 1; $index <= $partCount; $index++) {
            if (!isset($seenPartIndexes[$index])) {
                $this->reject(self::ERROR_PART_SET_INCOMPLETE, 'V2 package set is missing a required part.');
            }
        }
        if ($sentenceTranslations === null) {
            $this->reject(self::ERROR_TRANSLATION_SET_MISMATCH, 'V2 package set is missing part 1 sentence translations.');
        }

        $currentTargetIds = array_keys($catalog['targets_by_id']);
        $manifestTargetIds = array_keys($seenTargetIds);
        sort($currentTargetIds);
        sort($manifestTargetIds);
        if ($currentTargetIds !== $manifestTargetIds || $scopeHash !== $this->targetScopeHash($catalog['targets'])) {
            $this->reject(self::ERROR_TARGET_SET_MISMATCH, 'V2 package target scope is stale or no longer complete.');
        }

        usort($mergedWordResults, fn (array $left, array $right) => $left['start_word_index'] <=> $right['start_word_index']);
        usort($mergedPhraseResults, fn (array $left, array $right) => $left['start_word_index'] <=> $right['start_word_index']);

        ksort($packageIdsByPart, SORT_NUMERIC);
        $validatedPayload = [
            'schema_version' => self::SCHEMA_VERSION,
            'package_ids' => array_values($packageIdsByPart),
            'part_count' => $partCount,
            'source_revision' => $catalog['source_revision'],
            'sentence_translations' => $sentenceTranslations,
            'word_results' => $mergedWordResults,
            'phrase_results' => $mergedPhraseResults,
            'warnings' => $mergedWarnings,
        ];

        return [
            'package_ids' => array_values($packageIdsByPart),
            'source_revision' => $catalog['source_revision'],
            'target_scope_hash' => $scopeHash,
            'validated_payload' => $validatedPayload,
            'summary' => [
                'sentence_translation_count' => count($sentenceTranslations),
                'vocabulary_item_count' => count(array_filter($mergedWordResults, fn (array $row) => $row['result'] !== 'ambiguous')),
                'phrase_item_count' => count($mergedPhraseResults),
                'warning_count' => count($mergedWarnings),
                'word_target_count' => count($mergedWordResults),
                'phrase_target_count' => count($mergedPhraseResults),
            ],
            'trust_ai_matches' => $trustAiMatches,
        ];
    }

    private function validateManifestScope(array $manifest, int $userId, string $language, int $chapterId, array $catalog): void
    {
        $required = [
            'user_id', 'language', 'chapter_id', 'source_revision', 'package_id', 'scope_hash',
            'part_index', 'part_count', 'target_ids', 'candidate_sets',
        ];
        $this->assertExactKeys($manifest, $required, self::ERROR_PACKAGE_MISMATCH, 'V2 manifest shape is invalid.');

        if ((int) $manifest['user_id'] !== $userId
            || (string) $manifest['language'] !== $language
            || (int) $manifest['chapter_id'] !== $chapterId) {
            $this->reject(self::ERROR_PACKAGE_MISMATCH, 'V2 manifest does not belong to the current user, language, or chapter.');
        }
        if ((string) $manifest['source_revision'] !== $catalog['source_revision']) {
            $this->reject(self::ERROR_STALE_SOURCE, 'V2 manifest source revision is stale.');
        }
        if (!is_array($manifest['target_ids']) || !is_array($manifest['candidate_sets'])) {
            $this->reject(self::ERROR_PACKAGE_MISMATCH, 'V2 manifest target metadata is invalid.');
        }
        if ((int) $manifest['part_count'] < 1
            || (int) $manifest['part_index'] < 1
            || (int) $manifest['part_index'] > (int) $manifest['part_count']) {
            $this->reject(self::ERROR_PACKAGE_MISMATCH, 'V2 manifest part identity is invalid.');
        }

        foreach ($manifest['target_ids'] as $targetId) {
            if (!is_string($targetId) || !isset($catalog['targets_by_id'][$targetId])) {
                $this->reject(self::ERROR_TARGET_SET_MISMATCH, 'V2 manifest references an unknown current occurrence.');
            }
            $currentCandidateIds = array_map(
                fn (array $candidate) => (int) $candidate['word_sense_id'],
                $catalog['targets_by_id'][$targetId]['candidate_word_senses'] ?? [],
            );
            $manifestCandidateIds = array_map('intval', $manifest['candidate_sets'][$targetId] ?? []);
            sort($currentCandidateIds);
            sort($manifestCandidateIds);
            if ($currentCandidateIds !== $manifestCandidateIds) {
                $this->reject(self::ERROR_CANDIDATE_MISMATCH, 'V2 manifest candidate set is stale for an occurrence.');
            }
        }
    }

    private function validatePayloadAgainstManifest(int $userId, string $language, array $payload, array $manifest, array $catalog): array
    {
        $this->assertExactKeys($payload, [
            'schema_version',
            'package_id',
            'part_index',
            'part_count',
            'source_revision',
            'sentence_translations',
            'word_results',
            'phrase_results',
            'warnings',
        ], self::ERROR_SCHEMA_MISMATCH, 'V2 payload top-level shape is invalid.');

        if (!is_string($payload['schema_version'])
            || !is_string($payload['package_id'])
            || !is_string($payload['source_revision'])
            || !is_int($payload['part_index'])
            || !is_int($payload['part_count'])
            || !is_array($payload['sentence_translations'])
            || !is_array($payload['word_results'])
            || !is_array($payload['phrase_results'])
            || !is_array($payload['warnings'])) {
            $this->reject(self::ERROR_SCHEMA_MISMATCH, 'V2 payload field types are invalid.');
        }
        if ($payload['schema_version'] !== self::SCHEMA_VERSION) {
            $this->reject(self::ERROR_SCHEMA_MISMATCH, 'V2 payload schema_version is invalid.');
        }
        if ($payload['package_id'] !== $manifest['package_id']) {
            $this->reject(self::ERROR_PACKAGE_MISMATCH, 'V2 payload package_id does not match manifest.');
        }
        if ($payload['part_index'] !== (int) $manifest['part_index']
            || $payload['part_count'] !== (int) $manifest['part_count']) {
            $this->reject(self::ERROR_PACKAGE_MISMATCH, 'V2 payload part identity does not match manifest.');
        }
        if ($payload['source_revision'] !== $manifest['source_revision']) {
            $this->reject(self::ERROR_STALE_SOURCE, 'V2 payload source_revision does not match manifest.');
        }

        $expectedTargets = [];
        foreach ($manifest['target_ids'] as $occurrenceId) {
            $expectedTargets[$occurrenceId] = $catalog['targets_by_id'][$occurrenceId];
        }

        return [
            'sentence_translations' => $payload['sentence_translations'],
            'word_results' => $this->normalizeWordResults(
                $userId,
                $language,
                $payload['word_results'],
                array_filter($expectedTargets, fn (array $target) => $target['kind'] === 'word'),
                $manifest['candidate_sets'],
            ),
            'phrase_results' => $this->normalizePhraseResults(
                $payload['phrase_results'],
                array_filter($expectedTargets, fn (array $target) => $target['kind'] === 'phrase'),
            ),
            'warnings' => $this->normalizeWarnings($payload['warnings']),
        ];
    }

    private function normalizeWordResults(int $userId, string $language, mixed $results, array $expectedTargets, array $candidateSets): array
    {
        if (!is_array($results)) {
            $this->reject(self::ERROR_SCHEMA_MISMATCH, 'V2 word_results must be an array.');
        }

        $this->assertExactOccurrenceSet($results, $expectedTargets, 'word');
        $normalized = [];

        foreach ($results as $row) {
            if (!is_array($row)) {
                $this->reject(self::ERROR_SCHEMA_MISMATCH, 'Every V2 word result must be an object.');
            }
            $this->assertExactKeys($row, [
                'occurrence_id', 'surface', 'lemma', 'pos', 'sentence_index', 'source_sentence',
                'result', 'matched_word_sense_id', 'new_sense', 'confidence', 'reason',
            ], self::ERROR_SCHEMA_MISMATCH, 'V2 word result shape is invalid.');

            if (!is_string($row['occurrence_id'])
                || !is_string($row['surface'])
                || !is_string($row['lemma'])
                || !($row['pos'] === null || is_string($row['pos']))
                || !is_int($row['sentence_index'])
                || !is_string($row['source_sentence'])
                || !is_string($row['result'])
                || !($row['matched_word_sense_id'] === null || is_int($row['matched_word_sense_id']))
                || !($row['new_sense'] === null || is_array($row['new_sense']))
                || !is_string($row['confidence'])
                || !is_string($row['reason'])) {
                $this->reject(self::ERROR_SCHEMA_MISMATCH, 'V2 word result field types are invalid.');
            }

            $occurrenceId = $row['occurrence_id'];
            $target = $expectedTargets[$occurrenceId];
            $this->assertWordIdentityEcho($row, $target);

            $result = $row['result'];
            $confidence = $row['confidence'];
            $reason = trim($row['reason']);
            if (!in_array($result, ['matched_existing', 'new_sense', 'ambiguous'], true)) {
                $this->reject(self::ERROR_SCHEMA_MISMATCH, 'V2 word result enum is invalid.');
            }
            if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
                $this->reject(self::ERROR_SCHEMA_MISMATCH, 'V2 word confidence enum is invalid.');
            }
            if ($reason === '') {
                $this->reject(self::ERROR_SCHEMA_MISMATCH, 'V2 word result reason must be non-empty.');
            }

            $matchedWordSenseId = $row['matched_word_sense_id'];
            $newSense = $row['new_sense'];
            $resolvedSenseZh = null;
            $resolvedSenseEn = null;
            $resolvedPos = $target['pos'];

            if ($result === 'matched_existing') {
                if (!$matchedWordSenseId || $newSense !== null) {
                    $this->reject(self::ERROR_SCHEMA_MISMATCH, 'V2 matched_existing result requires matched_word_sense_id and null new_sense.');
                }
                $allowedCandidates = array_map('intval', $candidateSets[$occurrenceId] ?? []);
                if (!in_array($matchedWordSenseId, $allowedCandidates, true)) {
                    $this->reject(self::ERROR_CANDIDATE_MISMATCH, 'V2 matched_existing result references a WordSense outside the manifest candidate set.');
                }
                $sense = WordSense::query()
                    ->where('id', $matchedWordSenseId)
                    ->where('user_id', $userId)
                    ->where('language_id', $language)
                    ->where('status', WordSense::STATUS_CONFIRMED)
                    ->first();
                if (!$sense) {
                    $this->reject(self::ERROR_WORD_SENSE_OWNERSHIP_MISMATCH, 'V2 matched WordSense is not currently confirmed in this user and language scope.');
                }
                $resolvedSenseZh = $sense->sense_zh;
                $resolvedSenseEn = $sense->sense_en;
                $resolvedPos = $sense->pos;
            } elseif ($result === 'new_sense') {
                if ($matchedWordSenseId !== null || !is_array($newSense)) {
                    $this->reject(self::ERROR_SCHEMA_MISMATCH, 'V2 new_sense result requires null matched_word_sense_id and a new_sense object.');
                }
                $this->assertExactKeys($newSense, ['sense_zh', 'sense_en', 'pos'], self::ERROR_SCHEMA_MISMATCH, 'V2 new_sense object shape is invalid.');
                if (!is_string($newSense['sense_zh']) || !is_string($newSense['sense_en']) || !is_string($newSense['pos'])) {
                    $this->reject(self::ERROR_SCHEMA_MISMATCH, 'V2 new_sense field types are invalid.');
                }
                $resolvedSenseZh = trim($newSense['sense_zh']);
                $resolvedSenseEn = trim($newSense['sense_en']);
                $resolvedPos = trim($newSense['pos']);
                if ($resolvedSenseZh === '' || $resolvedSenseEn === '' || $resolvedPos === '') {
                    $this->reject(self::ERROR_SCHEMA_MISMATCH, 'V2 new_sense requires non-empty sense_zh, sense_en, and pos.');
                }
            } elseif ($matchedWordSenseId !== null || $newSense !== null) {
                $this->reject(self::ERROR_SCHEMA_MISMATCH, 'V2 ambiguous result requires null matched_word_sense_id and null new_sense.');
            }

            $normalized[] = [
                'occurrence_id' => $occurrenceId,
                'start_word_index' => $target['start_word_index'],
                'end_word_index' => $target['end_word_index'],
                'surface' => $target['surface'],
                'lemma' => $target['lemma'],
                'pos' => $resolvedPos,
                'sentence_index' => $target['sentence_index'],
                'source_sentence' => $target['source_sentence'],
                'purpose' => $target['purpose'],
                'result' => $result,
                'confidence' => $confidence,
                'matched_word_sense_id' => $matchedWordSenseId,
                'new_sense' => $result === 'new_sense' ? [
                    'sense_zh' => $resolvedSenseZh,
                    'sense_en' => $resolvedSenseEn,
                    'pos' => $resolvedPos,
                ] : null,
                'sense_zh' => $resolvedSenseZh,
                'sense_en' => $resolvedSenseEn,
                'reason' => $reason,
            ];
        }

        return $normalized;
    }

    private function normalizePhraseResults(mixed $results, array $expectedTargets): array
    {
        if (!is_array($results)) {
            $this->reject(self::ERROR_SCHEMA_MISMATCH, 'V2 phrase_results must be an array.');
        }

        $this->assertExactOccurrenceSet($results, $expectedTargets, 'phrase');
        $normalized = [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                $this->reject(self::ERROR_SCHEMA_MISMATCH, 'Every V2 phrase result must be an object.');
            }
            $this->assertExactKeys($row, [
                'occurrence_id', 'phrase', 'sentence_index', 'source_sentence',
                'sense_zh', 'sense_en', 'confidence', 'reason',
            ], self::ERROR_SCHEMA_MISMATCH, 'V2 phrase result shape is invalid.');

            if (!is_string($row['occurrence_id'])
                || !is_string($row['phrase'])
                || !is_int($row['sentence_index'])
                || !is_string($row['source_sentence'])
                || !is_string($row['sense_zh'])
                || !is_string($row['sense_en'])
                || !is_string($row['confidence'])
                || !is_string($row['reason'])) {
                $this->reject(self::ERROR_SCHEMA_MISMATCH, 'V2 phrase result field types are invalid.');
            }

            $occurrenceId = $row['occurrence_id'];
            $target = $expectedTargets[$occurrenceId];
            if ($row['phrase'] !== (string) $target['surface']
                || $row['sentence_index'] !== (int) $target['sentence_index']
                || $row['source_sentence'] !== (string) $target['source_sentence']) {
                $this->reject(self::ERROR_IDENTITY_ECHO_MISMATCH, 'V2 phrase identity echo does not match the server manifest.');
            }

            $confidence = $row['confidence'];
            if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
                $this->reject(self::ERROR_SCHEMA_MISMATCH, 'V2 phrase confidence enum is invalid.');
            }
            $senseZh = trim($row['sense_zh']);
            $senseEn = trim($row['sense_en']);
            $reason = trim($row['reason']);
            if ($senseZh === '' || $senseEn === '' || $reason === '') {
                $this->reject(self::ERROR_SCHEMA_MISMATCH, 'V2 phrase results require non-empty sense_zh, sense_en, and reason.');
            }

            $normalized[] = [
                'occurrence_id' => $occurrenceId,
                'start_word_index' => $target['start_word_index'],
                'end_word_index' => $target['end_word_index'],
                'phrase' => $target['surface'],
                'sentence_index' => $target['sentence_index'],
                'source_sentence' => $target['source_sentence'],
                'confidence' => $confidence,
                'sense_zh' => $senseZh,
                'sense_en' => $senseEn,
                'reason' => $reason,
            ];
        }

        return $normalized;
    }

    private function normalizeSentenceTranslations(mixed $actual, array $expected): array
    {
        if (!is_array($actual) || count($actual) !== count($expected)) {
            $this->reject(self::ERROR_TRANSLATION_SET_MISMATCH, 'V2 sentence_translations must contain every server sentence exactly once.');
        }

        $byIndex = [];
        foreach ($actual as $row) {
            if (!is_array($row)) {
                $this->reject(self::ERROR_TRANSLATION_SET_MISMATCH, 'Every V2 sentence translation must be an object.');
            }
            $this->assertExactKeys($row, ['sentence_index', 'source_text', 'translation_zh'], self::ERROR_TRANSLATION_SET_MISMATCH, 'V2 sentence translation shape is invalid.');
            if (!is_int($row['sentence_index']) || !is_string($row['source_text']) || !is_string($row['translation_zh'])) {
                $this->reject(self::ERROR_TRANSLATION_SET_MISMATCH, 'V2 sentence translation field types are invalid.');
            }
            $sentenceIndex = $row['sentence_index'];
            if (isset($byIndex[$sentenceIndex])) {
                $this->reject(self::ERROR_TRANSLATION_SET_MISMATCH, 'V2 sentence_translations contains a duplicate sentence_index.');
            }
            $byIndex[$sentenceIndex] = $row;
        }

        $normalized = [];
        foreach ($expected as $sentence) {
            $sentenceIndex = (int) $sentence['sentence_index'];
            $actualSentence = $byIndex[$sentenceIndex] ?? null;
            if (!$actualSentence
                || (string) $actualSentence['source_text'] !== (string) $sentence['source_text']
                || trim((string) $actualSentence['translation_zh']) === '') {
                $this->reject(self::ERROR_TRANSLATION_SET_MISMATCH, 'V2 sentence_translations must preserve exact sentence identity with non-empty translation_zh values.');
            }
            $normalized[] = [
                'sentence_index' => $sentenceIndex,
                'source_text' => $sentence['source_text'],
                'translation_zh' => trim((string) $actualSentence['translation_zh']),
            ];
        }

        return $normalized;
    }

    private function assertWordIdentityEcho(array $row, array $target): void
    {
        $rowPos = $row['pos'] === null ? null : (string) $row['pos'];
        $targetPos = $target['pos'] === null ? null : (string) $target['pos'];
        if ((string) $row['surface'] !== (string) $target['surface']
            || mb_strtolower(trim((string) $row['lemma'])) !== mb_strtolower(trim((string) $target['lemma']))
            || $rowPos !== $targetPos
            || (int) $row['sentence_index'] !== (int) $target['sentence_index']
            || (string) $row['source_sentence'] !== (string) $target['source_sentence']) {
            $this->reject(self::ERROR_IDENTITY_ECHO_MISMATCH, 'V2 word identity echo does not match the server manifest.');
        }
    }

    private function assertExactOccurrenceSet(array $results, array $expectedTargets, string $kind): void
    {
        $actualIds = [];
        foreach ($results as $row) {
            if (!is_array($row) || !isset($row['occurrence_id']) || !is_string($row['occurrence_id'])) {
                $this->reject(self::ERROR_SCHEMA_MISMATCH, "Every V2 {$kind} result requires a string occurrence_id.");
            }
            $occurrenceId = $row['occurrence_id'];
            if (isset($actualIds[$occurrenceId])) {
                $this->reject(self::ERROR_DUPLICATE_OCCURRENCE_ID, "V2 {$kind}_results contains a duplicate occurrence_id.");
            }
            $actualIds[$occurrenceId] = true;
        }

        $expectedIds = array_keys($expectedTargets);
        $actual = array_keys($actualIds);
        sort($expectedIds);
        sort($actual);
        if ($expectedIds !== $actual) {
            $this->reject(self::ERROR_TARGET_SET_MISMATCH, "V2 {$kind}_results occurrence_id set does not exactly match the expected targets.");
        }
    }

    private function normalizeWarnings(mixed $warnings): array
    {
        if (!is_array($warnings)) {
            $this->reject(self::ERROR_SCHEMA_MISMATCH, 'V2 warnings must be an array.');
        }

        $normalized = [];
        foreach ($warnings as $warning) {
            if (!is_string($warning)) {
                $this->reject(self::ERROR_SCHEMA_MISMATCH, 'Every V2 warning must be a string.');
            }
            $warning = trim($warning);
            if ($warning !== '') {
                $normalized[] = $warning;
            }
        }

        return $normalized;
    }

    private function legacyProjection(array $merged): array
    {
        $vocabularyItems = [];
        foreach ($merged['validated_payload']['word_results'] as $row) {
            if ($row['result'] === 'ambiguous') {
                continue;
            }
            $vocabularyItems[] = [
                'occurrence_id' => $row['occurrence_id'],
                'surface' => $row['surface'],
                'suggested_lemma' => $row['lemma'],
                'pos' => $row['pos'],
                'sentence_index' => $row['sentence_index'],
                'source_sentence' => $row['source_sentence'],
                'meaning_zh' => $row['sense_zh'],
                'meaning_en' => $row['sense_en'],
                'sense_en' => $row['sense_en'],
                'confidence' => $row['confidence'],
                'purpose' => $row['purpose'],
                'result' => $row['result'],
                'matched_word_sense_id' => $row['matched_word_sense_id'],
                'new_sense' => $row['new_sense'],
                'reason' => $row['reason'],
            ];
        }

        $phraseItems = [];
        foreach ($merged['validated_payload']['phrase_results'] as $row) {
            $phraseItems[] = [
                'occurrence_id' => $row['occurrence_id'],
                'phrase' => $row['phrase'],
                'sentence_index' => $row['sentence_index'],
                'source_sentence' => $row['source_sentence'],
                'meaning_zh' => $row['sense_zh'],
                'meaning_en' => $row['sense_en'],
                'sense_en' => $row['sense_en'],
                'confidence' => $row['confidence'],
                'reason' => $row['reason'],
                'trigger_words' => array_values(array_filter(explode(' ', mb_strtolower($row['phrase'])))),
            ];
        }

        return ['vocabulary_items' => $vocabularyItems, 'phrase_items' => $phraseItems];
    }

    private function assertMarkedTargetSnapshot(array $catalogTargets, array $expectedMarkedTargets): void
    {
        $server = [];
        foreach ($catalogTargets as $target) {
            if (($target['purpose'] ?? null) !== 'marked_unknown') {
                continue;
            }
            $server[] = implode(':', [
                $target['kind'],
                (int) $target['start_word_index'],
                (int) $target['end_word_index'],
            ]);
        }

        $client = [];
        foreach ($expectedMarkedTargets as $target) {
            if (!is_array($target)
                || !in_array($target['kind'] ?? null, ['word', 'phrase'], true)
                || !isset($target['start_word_index'], $target['end_word_index'])) {
                $this->reject(self::ERROR_TARGET_SET_MISMATCH, 'V2 marked target snapshot is invalid.');
            }
            $start = filter_var($target['start_word_index'], FILTER_VALIDATE_INT);
            $end = filter_var($target['end_word_index'], FILTER_VALIDATE_INT);
            if ($start === false || $end === false || $start < 0 || $end < $start) {
                $this->reject(self::ERROR_TARGET_SET_MISMATCH, 'V2 marked target snapshot contains an invalid span.');
            }
            $client[] = implode(':', [$target['kind'], $start, $end]);
        }

        sort($server);
        sort($client);
        if ($server !== $client) {
            $this->reject(self::ERROR_TARGET_SET_MISMATCH, 'V2 marked targets are not synchronized with the server.');
        }
    }

    private function sourceWordTarget(array $target): array
    {
        return [
            'occurrence_id' => $target['occurrence_id'],
            'purpose' => $target['purpose'],
            'start_word_index' => $target['start_word_index'],
            'end_word_index' => $target['end_word_index'],
            'sentence_index' => $target['sentence_index'],
            'surface' => $target['surface'],
            'lemma' => $target['lemma'],
            'pos' => $target['pos'],
            'source_sentence' => $target['source_sentence'],
            'candidate_word_senses' => $target['candidate_word_senses'] ?? [],
        ];
    }

    private function sourcePhraseTarget(array $target): array
    {
        return [
            'occurrence_id' => $target['occurrence_id'],
            'start_word_index' => $target['start_word_index'],
            'end_word_index' => $target['end_word_index'],
            'sentence_index' => $target['sentence_index'],
            'phrase' => $target['surface'],
            'source_sentence' => $target['source_sentence'],
        ];
    }

    private function candidateSets(array $targets): array
    {
        $sets = [];
        foreach ($targets as $target) {
            $sets[$target['occurrence_id']] = array_values(array_map(
                fn (array $candidate) => (int) $candidate['word_sense_id'],
                $target['candidate_word_senses'] ?? [],
            ));
        }

        return $sets;
    }

    public function targetScopeHash(array $targets): string
    {
        $scope = [];
        foreach ($targets as $target) {
            $candidateIds = array_map(
                fn (array $candidate) => (int) $candidate['word_sense_id'],
                $target['candidate_word_senses'] ?? [],
            );
            sort($candidateIds);
            $scope[] = [
                'occurrence_id' => $target['occurrence_id'],
                'purpose' => $target['purpose'],
                'candidate_ids' => $candidateIds,
            ];
        }

        return 'sha256:' . hash('sha256', json_encode($scope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function sourceSentences(array $sentences): array
    {
        $result = [];
        foreach ($sentences as $sentenceIndex => $text) {
            $result[] = [
                'sentence_index' => (int) $sentenceIndex,
                'source_text' => (string) $text,
            ];
        }

        return $result;
    }

    private function decryptManifest(string $token): array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->reject(self::ERROR_PACKAGE_MISMATCH, 'V2 manifest token is invalid or has been tampered with.');
        }

        if (!is_array($decoded)) {
            $this->reject(self::ERROR_PACKAGE_MISMATCH, 'V2 manifest payload is invalid.');
        }

        return $decoded;
    }

    private function parseStrictJson(string $rawText): array
    {
        $trimmed = trim($rawText);
        if ($trimmed === '' || !str_starts_with($trimmed, '{') || !str_ends_with($trimmed, '}')) {
            $this->reject(self::ERROR_INVALID_JSON, 'V2 AI text must be a direct JSON object with no code fences or surrounding prose.');
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            $typed = json_decode($trimmed, false, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->reject(self::ERROR_INVALID_JSON, 'V2 AI text is not valid JSON.');
        }
        if (!is_array($decoded) || !$typed instanceof \stdClass) {
            $this->reject(self::ERROR_INVALID_JSON, 'V2 AI text must decode to a JSON object.');
        }
        foreach (['sentence_translations', 'word_results', 'phrase_results', 'warnings'] as $listField) {
            if (property_exists($typed, $listField) && !is_array($typed->{$listField})) {
                $this->reject(self::ERROR_SCHEMA_MISMATCH, "V2 {$listField} must be a JSON array.");
            }
        }

        return $decoded;
    }

    private function assertExactKeys(array $value, array $expectedKeys, string $errorCode, string $message): void
    {
        $actualKeys = array_keys($value);
        sort($actualKeys);
        sort($expectedKeys);
        if ($actualKeys !== $expectedKeys) {
            $this->reject($errorCode, $message);
        }
    }

    private function buildPrompt(array $sourcePayload): string
    {
        return "Return one direct JSON object only. No markdown fences and no surrounding prose.\n"
            . "Use schema_version " . self::SCHEMA_VERSION . ". Echo package_id, part_index, part_count, and source_revision exactly.\n"
            . "Return exactly these top-level keys: schema_version, package_id, part_index, part_count, source_revision, sentence_translations, word_results, phrase_results, warnings.\n"
            . "For part 1, sentence_translations must contain every supplied sentence exactly once as {sentence_index, source_text, translation_zh}; later parts must return [].\n"
            . "For every word target return exactly {occurrence_id, surface, lemma, pos, sentence_index, source_sentence, result, matched_word_sense_id, new_sense, confidence, reason}.\n"
            . "result is matched_existing, new_sense, or ambiguous. confidence is high, medium, or low.\n"
            . "Before choosing result for every word target, compare the current contextual learnable meaning against all supplied candidate_word_senses.\n"
            . "If any supplied candidate expresses the same or substantially the same learnable meaning, return matched_existing even when the wording differs.\n"
            . "Wording, paraphrase, Chinese translation phrasing, English definition wording, or example style differences alone never justify new_sense.\n"
            . "Return new_sense only when the contextual meaning is genuinely distinct from every supplied candidate. If you cannot determine the comparison reliably, return ambiguous.\n"
            . "matched_existing: matched_word_sense_id must be one supplied candidate and new_sense must be null.\n"
            . "new_sense: matched_word_sense_id must be null and new_sense must be exactly {sense_zh, sense_en, pos} with non-empty values.\n"
            . "ambiguous: matched_word_sense_id and new_sense must both be null.\n"
            . "For every phrase target return exactly {occurrence_id, phrase, sentence_index, source_sentence, sense_zh, sense_en, confidence, reason}. Never return WordSense or FSRS fields for phrases.\n"
            . "Do not add, remove, merge, split, or select targets. Echo all server identity fields exactly.\n\n"
            . "SOURCE_PAYLOAD_START\n"
            . json_encode($sourcePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            . "\nSOURCE_PAYLOAD_END";
    }

    private function contractFailure(ReadingAssistV2ContractException $e): array
    {
        return [
            'success' => false,
            'parsed' => false,
            'error_code' => $e->errorCode,
            'message' => $e->getMessage(),
        ];
    }

    private function reject(string $errorCode, string $message): never
    {
        throw new ReadingAssistV2ContractException($errorCode, $message);
    }
}
