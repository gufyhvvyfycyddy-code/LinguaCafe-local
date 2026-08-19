<?php

namespace App\Services;

use App\Models\ReadingOccurrenceSenseEvidence;
use App\Models\WordSense;
use Illuminate\Support\Facades\DB;

class ReadingOccurrenceSenseEvidenceService
{
    public function __construct(
        private ReadingTargetCatalogService $targetCatalogService,
        private ReadingChapterTextService $chapterTextService,
        private WordSenseOccurrenceService $wordSenseOccurrenceService,
    ) {
    }

    public function storeUserDecision(
        int $userId,
        string $language,
        int $chapterId,
        string $occurrenceId,
        string $resolution,
        ?int $wordSenseId,
    ): ReadingOccurrenceSenseEvidence {
        return DB::transaction(function () use (
            $userId,
            $language,
            $chapterId,
            $occurrenceId,
            $resolution,
            $wordSenseId,
        ) {
            $this->chapterTextService->lockChapterForUser($userId, $language, $chapterId);
            $catalog = $this->targetCatalogService->build($userId, $language, $chapterId);
            $target = $catalog['targets_by_id'][$occurrenceId] ?? null;
            if (!$target) {
                throw new \InvalidArgumentException('READING_OCCURRENCE_STALE');
            }

            $validatedSenseId = $this->validateResolution(
                $userId,
                $language,
                $target,
                $resolution,
                $wordSenseId,
                ReadingOccurrenceSenseEvidence::SOURCE_USER,
                null,
            );
            $evidence = ReadingOccurrenceSenseEvidence::query()
                ->lockForUpdate()
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->where('chapter_id', $chapterId)
                ->where('source_revision', $catalog['source_revision'])
                ->where('occurrence_id', $target['occurrence_id'])
                ->first();
            if (!$evidence) {
                $evidence = new ReadingOccurrenceSenseEvidence();
            }

            $evidence->fill($this->baseEvidenceData(
                $userId,
                $language,
                $chapterId,
                $catalog['source_revision'],
                $target,
            ) + [
                'resolution' => $resolution,
                'word_sense_id' => $validatedSenseId,
                'resolution_source' => ReadingOccurrenceSenseEvidence::SOURCE_USER,
                'ai_confidence' => null,
                'ai_package_id' => null,
                'ai_payload_hash' => null,
                'provenance' => [
                    'source' => 'reading_verification',
                    'authority' => 'user',
                ],
            ]);
            $evidence->save();
            $this->wordSenseOccurrenceService->projectReadingEvidence($evidence, $target);

            return $evidence;
        });
    }

    /**
     * Persist a complete batch of already-validated high-confidence AI matches.
     * The current target catalog is built once for the whole import, and user
     * evidence remains authoritative over every AI write.
     *
     * @param array<int, array{target:array,word_sense_id:int,confidence:string}> $matches
     * @return array<int, ReadingOccurrenceSenseEvidence>
     */
    public function storeTrustedAiMatches(
        int $userId,
        string $language,
        int $chapterId,
        array $matches,
        string $payloadHash,
    ): array {
        if (empty($matches)) {
            return [];
        }

        return DB::transaction(function () use ($userId, $language, $chapterId, $matches, $payloadHash) {
            $this->chapterTextService->lockChapterForUser($userId, $language, $chapterId);
            $catalog = $this->targetCatalogService->build($userId, $language, $chapterId);
            $validated = [];
            foreach ($matches as $match) {
                $confidence = (string) ($match['confidence'] ?? '');
                if ($confidence !== 'high') {
                    throw new \InvalidArgumentException('Trusted AI evidence requires high confidence.');
                }

                $target = is_array($match['target'] ?? null) ? $match['target'] : [];
                $current = $catalog['targets_by_id'][$target['occurrence_id'] ?? ''] ?? null;
                if (!$current || !$this->sameTargetIdentity($current, $target)) {
                    throw new \InvalidArgumentException('READING_TRUST_AI_TARGET_STALE');
                }

                $packageId = trim((string) ($match['package_id'] ?? ''));
                if ($packageId === '') {
                    throw new \InvalidArgumentException('Trusted AI evidence requires the source package_id.');
                }

                $validated[] = [
                    'target' => $current,
                    'package_id' => $packageId,
                    'word_sense_id' => $this->validateResolution(
                        $userId,
                        $language,
                        $current,
                        ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
                        isset($match['word_sense_id']) ? (int) $match['word_sense_id'] : null,
                        ReadingOccurrenceSenseEvidence::SOURCE_TRUST_AI,
                        $confidence,
                    ),
                ];
            }

            $saved = [];
            foreach ($validated as $match) {
                $current = $match['target'];
                $evidence = ReadingOccurrenceSenseEvidence::query()
                    ->lockForUpdate()
                    ->where('user_id', $userId)
                    ->where('language_id', $language)
                    ->where('chapter_id', $chapterId)
                    ->where('source_revision', $catalog['source_revision'])
                    ->where('occurrence_id', $current['occurrence_id'])
                    ->first();

                if ($evidence && $evidence->resolution_source === ReadingOccurrenceSenseEvidence::SOURCE_USER) {
                    $this->wordSenseOccurrenceService->projectReadingEvidence($evidence, $current);
                    $saved[] = $evidence;
                    continue;
                }
                if (!$evidence) {
                    $evidence = new ReadingOccurrenceSenseEvidence();
                }

                $evidence->fill($this->baseEvidenceData(
                    $userId,
                    $language,
                    $chapterId,
                    $catalog['source_revision'],
                    $current,
                ) + [
                    'resolution' => ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
                    'word_sense_id' => $match['word_sense_id'],
                    'resolution_source' => ReadingOccurrenceSenseEvidence::SOURCE_TRUST_AI,
                    'ai_confidence' => 'high',
                    'ai_package_id' => $match['package_id'],
                    'ai_payload_hash' => $payloadHash,
                    'provenance' => [
                        'source' => 'ai_reading_assist_v2',
                        'authority' => 'trust_ai',
                    ],
                ]);
                $evidence->save();
                $this->wordSenseOccurrenceService->projectReadingEvidence($evidence, $current);
                $saved[] = $evidence;
            }

            return $saved;
        });
    }

    /**
     * @return array{source_revision:string,items:array<int,array>}
     */
    public function listForChapter(
        int $userId,
        string $language,
        int $chapterId,
        int $offset = 0,
        int $limit = 200,
    ): array {
        $offset = max(0, $offset);
        $limit = max(1, min(500, $limit));
        $catalog = $this->targetCatalogService->build($userId, $language, $chapterId);
        $total = ReadingOccurrenceSenseEvidence::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('chapter_id', $chapterId)
            ->where('source_revision', $catalog['source_revision'])
            ->count();
        $rows = ReadingOccurrenceSenseEvidence::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('chapter_id', $chapterId)
            ->where('source_revision', $catalog['source_revision'])
            ->orderBy('start_word_index')
            ->orderBy('end_word_index')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $senseIds = $rows->pluck('word_sense_id')->filter()->unique()->values()->all();
        $confirmed = WordSense::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', WordSense::STATUS_CONFIRMED)
            ->whereIn('id', $senseIds)
            ->get(['id'])
            ->keyBy('id');

        $items = [];
        foreach ($rows as $row) {
            $target = $catalog['targets_by_id'][$row->occurrence_id] ?? null;
            $sense = $row->word_sense_id ? $confirmed->get($row->word_sense_id) : null;
            $items[] = [
                'occurrence_id' => $row->occurrence_id,
                'start_word_index' => $row->start_word_index,
                'end_word_index' => $row->end_word_index,
                'sentence_index' => $row->sentence_index,
                'surface' => $row->surface,
                'lemma' => $row->lemma,
                'pos' => $row->pos,
                'resolution' => $row->resolution,
                'word_sense_id' => $row->word_sense_id,
                'resolution_source' => $row->resolution_source,
                'binding_current' => $row->resolution !== ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING || $sense !== null,
                'candidate_word_senses' => $target['candidate_word_senses'] ?? [],
            ];
        }

        $nextOffset = $offset + count($items);

        return [
            'source_revision' => $catalog['source_revision'],
            'items' => $items,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'has_more' => $nextOffset < $total,
            'next_offset' => $nextOffset < $total ? $nextOffset : null,
        ];
    }

    /**
     * @return array<string, ReadingOccurrenceSenseEvidence>
     */
    public function currentEvidenceMap(int $userId, string $language, int $chapterId, string $sourceRevision): array
    {
        return ReadingOccurrenceSenseEvidence::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('chapter_id', $chapterId)
            ->where('source_revision', $sourceRevision)
            ->get()
            ->keyBy('occurrence_id')
            ->all();
    }

    public function isCurrentConfirmedBinding(
        ReadingOccurrenceSenseEvidence $evidence,
        int $userId,
        string $language,
    ): bool {
        if ($evidence->resolution !== ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING || !$evidence->word_sense_id) {
            return false;
        }

        return WordSense::query()
            ->where('id', $evidence->word_sense_id)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', WordSense::STATUS_CONFIRMED)
            ->exists();
    }

    private function validateResolution(
        int $userId,
        string $language,
        array $target,
        string $resolution,
        ?int $wordSenseId,
        string $resolutionSource,
        ?string $confidence,
    ): ?int {
        if (!in_array($resolution, [
            ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
            ReadingOccurrenceSenseEvidence::RESOLUTION_NEW_SENSE,
            ReadingOccurrenceSenseEvidence::RESOLUTION_EXCLUDED,
        ], true)) {
            throw new \InvalidArgumentException('Unsupported occurrence resolution.');
        }

        if ($resolutionSource === ReadingOccurrenceSenseEvidence::SOURCE_TRUST_AI
            && ($resolution !== ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING || $confidence !== 'high')) {
            throw new \InvalidArgumentException('Trusted AI may only persist high-confidence matched-existing evidence.');
        }

        if ($resolution !== ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING) {
            if ($wordSenseId !== null) {
                throw new \InvalidArgumentException('New-sense and excluded evidence cannot bind a WordSense.');
            }
            return null;
        }

        if ($target['kind'] !== 'word' || !$wordSenseId) {
            throw new \InvalidArgumentException('Matched-existing evidence requires a word target and WordSense.');
        }

        $candidateIds = array_map(
            fn (array $candidate) => (int) $candidate['word_sense_id'],
            $target['candidate_word_senses'] ?? [],
        );
        if (!in_array($wordSenseId, $candidateIds, true)) {
            throw new \InvalidArgumentException('WordSense is not an allowed candidate for this occurrence.');
        }

        $exists = WordSense::query()
            ->where('id', $wordSenseId)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', WordSense::STATUS_CONFIRMED)
            ->exists();
        if (!$exists) {
            throw new \InvalidArgumentException('WordSense is not currently confirmed in this user and language scope.');
        }

        return $wordSenseId;
    }

    private function baseEvidenceData(
        int $userId,
        string $language,
        int $chapterId,
        string $sourceRevision,
        array $target,
    ): array {
        return [
            'user_id' => $userId,
            'language_id' => $language,
            'chapter_id' => $chapterId,
            'source_revision' => $sourceRevision,
            'occurrence_id' => $target['occurrence_id'],
            'target_origin' => $target['purpose'],
            'start_word_index' => $target['start_word_index'],
            'end_word_index' => $target['end_word_index'],
            'sentence_index' => $target['sentence_index'],
            'surface' => $target['surface'],
            'lemma' => $target['lemma'],
            'pos' => $target['pos'],
        ];
    }

    private function sameTargetIdentity(array $left, array $right): bool
    {
        foreach (['occurrence_id', 'kind', 'start_word_index', 'end_word_index', 'sentence_index'] as $key) {
            if (($left[$key] ?? null) !== ($right[$key] ?? null)) {
                return false;
            }
        }

        return $this->sameTargetSnapshot($left, $right);
    }

    private function sameTargetSnapshot(array $target, mixed $evidence): bool
    {
        $read = fn (string $key) => is_array($evidence) ? ($evidence[$key] ?? null) : ($evidence->{$key} ?? null);

        return (string) ($target['surface'] ?? '') === (string) $read('surface')
            && mb_strtolower(trim((string) ($target['lemma'] ?? ''))) === mb_strtolower(trim((string) $read('lemma')))
            && (string) ($target['pos'] ?? '') === (string) $read('pos');
    }
}
