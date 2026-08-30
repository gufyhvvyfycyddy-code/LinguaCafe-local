<?php

namespace App\Services;

use App\Models\ReadingOccurrenceSenseEvidence;
use App\Models\WordSense;
use Illuminate\Support\Facades\DB;

class ReadingManualSenseCreationService
{
    public function __construct(
        private ReadingSessionService $readingSessionService,
        private ReadingOccurrenceSenseEvidenceService $readingEvidenceService,
        private WordSenseOccurrenceService $wordSenseOccurrenceService,
        private WordSenseService $wordSenseService,
    ) {
    }

    /**
     * Create a confirmed WordSense from the current authoritative Reader target.
     *
     * Client-provided sentence text is never trusted for a Reader learning-entry
     * source. The active session, current source revision and occurrence id are
     * revalidated, then the canonical reading occurrence is reused for learning
     * provenance and source-example binding.
     */
    public function create(
        int $userId,
        string $language,
        array $data,
    ): array {
        return DB::transaction(function () use ($userId, $language, $data) {
            $target = $this->readingSessionService->lockManualSenseCreationContext(
                $userId,
                $language,
                (string) $data['reading_session_id'],
                (int) $data['chapter_id'],
                (string) $data['source_revision'],
                (string) $data['occurrence_id'],
            );
            if (!$this->sameText($data['lemma'] ?? '', $target['lemma'] ?? '')
                || !$this->sameText($data['surface_form'] ?? $data['lemma'] ?? '', $target['surface'] ?? '')) {
                throw new \InvalidArgumentException(ReadingSessionService::ERROR_EXPLICIT_CONTEXT_INVALID);
            }

            $data['sentence_id'] = (string) $target['sentence_index'];
            $data['sentence_en'] = $target['source_sentence'];
            $data['sentence_zh'] = null;

            $evidence = $this->readingEvidenceService->storeUserDecision(
                $userId,
                $language,
                (int) $data['chapter_id'],
                (string) $data['occurrence_id'],
                ReadingOccurrenceSenseEvidence::RESOLUTION_NEW_SENSE,
                null,
            );
            $sourceOccurrence = $this->wordSenseOccurrenceService->readingOccurrenceForEvidence($evidence);
            if (!$sourceOccurrence) {
                throw new \InvalidArgumentException('Reading source occurrence does not exist.');
            }

            $result = $this->wordSenseService->createManualSense(
                $userId,
                $language,
                $data,
                false,
                WordSense::LEARNING_ORIGIN_READING,
                $sourceOccurrence,
            );
            $this->wordSenseOccurrenceService->bindReadingEvidenceToSense($evidence, $result['sense']);

            return $result;
        });
    }

    private function sameText(mixed $left, mixed $right): bool
    {
        return mb_strtolower(trim((string) $left)) === mb_strtolower(trim((string) $right));
    }
}
