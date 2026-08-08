<?php

namespace App\Services;

use App\Models\ReadingSession;
use App\Models\ReadingSessionCompletion;
use App\Models\ReadingSessionInteraction;
use App\Models\ReviewCard;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReadingSessionService
{
    public const ERROR_SESSION_NOT_FOUND = 'READING_SESSION_NOT_FOUND';
    public const ERROR_SESSION_NOT_ACTIVE = 'READING_SESSION_NOT_ACTIVE';
    public const ERROR_SESSION_CHAPTER_MISMATCH = 'READING_SESSION_CHAPTER_MISMATCH';
    public const ERROR_SESSION_STALE_SOURCE = 'READING_SESSION_STALE_SOURCE';
    public const ERROR_OCCURRENCE_STALE = 'READING_OCCURRENCE_STALE';
    public const ERROR_EXPLICIT_CONTEXT_INVALID = 'READING_EXPLICIT_CONTEXT_INVALID';

    public function __construct(
        private ReadingChapterTextService $chapterTextService,
        private ReadingTargetCatalogService $targetCatalogService,
    ) {
    }

    public function startSession(
        int $userId,
        string $language,
        int $chapterId,
        ?string $resumeReadingSessionId = null,
    ): array {
        return DB::transaction(function () use ($userId, $language, $chapterId, $resumeReadingSessionId) {
            if ($resumeReadingSessionId) {
                $session = ReadingSession::query()
                    ->lockForUpdate()
                    ->where('uuid', $resumeReadingSessionId)
                    ->where('user_id', $userId)
                    ->where('language_id', $language)
                    ->first();
                if (!$session) {
                    throw new \InvalidArgumentException(self::ERROR_SESSION_NOT_FOUND);
                }
                if ((int) $session->chapter_id !== $chapterId) {
                    throw new \InvalidArgumentException(self::ERROR_SESSION_CHAPTER_MISMATCH);
                }

                if ($session->status === ReadingSession::STATUS_COMPLETED) {
                    $completion = ReadingSessionCompletion::query()
                        ->where('reading_session_id', $session->id)
                        ->first();
                    if (!$completion) {
                        throw new \InvalidArgumentException(self::ERROR_SESSION_NOT_ACTIVE);
                    }

                    return $completion->result;
                }
                if ($session->status !== ReadingSession::STATUS_ACTIVE) {
                    throw new \InvalidArgumentException(self::ERROR_SESSION_NOT_ACTIVE);
                }

                $chapter = $this->chapterTextService->lockChapterForUser($userId, $language, $chapterId);
                $currentRevision = $this->chapterTextService->sourceRevision($chapter);
                if (!hash_equals($session->source_revision, $currentRevision)) {
                    throw new \InvalidArgumentException(self::ERROR_SESSION_STALE_SOURCE);
                }

                $catalog = $this->targetCatalogService->build($userId, $language, $chapterId);

                return $this->serializeSession($session, $catalog) + [
                    'resumed' => true,
                    'completed' => false,
                    'is_current_source' => true,
                ];
            }

            $chapter = $this->chapterTextService->lockChapterForUser($userId, $language, $chapterId);
            $sourceRevision = $this->chapterTextService->sourceRevision($chapter);
            $session = ReadingSession::query()
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->where('chapter_id', $chapterId)
                ->where('source_revision', $sourceRevision)
                ->where('status', ReadingSession::STATUS_ACTIVE)
                ->orderBy('id')
                ->first();

            $resumed = $session !== null;
            if (!$session) {
                $session = ReadingSession::create([
                    'uuid' => (string) Str::uuid(),
                    'user_id' => $userId,
                    'language_id' => $language,
                    'chapter_id' => (int) $chapter->id,
                    'source_revision' => $sourceRevision,
                    'status' => ReadingSession::STATUS_ACTIVE,
                    'started_at' => Carbon::now(),
                ]);
            }

            $catalog = $this->targetCatalogService->build($userId, $language, $chapterId);

            return $this->serializeSession($session, $catalog) + [
                'resumed' => $resumed,
                'completed' => false,
                'is_current_source' => true,
            ];
        });
    }

    public function resolveOwnedSession(int $userId, string $language, string $sessionId): ReadingSession
    {
        $session = ReadingSession::query()
            ->where('uuid', $sessionId)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->first();

        if (!$session) {
            throw new \InvalidArgumentException(self::ERROR_SESSION_NOT_FOUND);
        }

        return $session;
    }

    public function requireActiveSession(int $userId, string $language, string $sessionId): ReadingSession
    {
        $session = $this->resolveOwnedSession($userId, $language, $sessionId);
        if ($session->status !== ReadingSession::STATUS_ACTIVE) {
            throw new \InvalidArgumentException(self::ERROR_SESSION_NOT_ACTIVE);
        }

        return $session;
    }

    public function lockActiveSessionContext(
        int $userId,
        string $language,
        string $sessionId,
        ?int $chapterId = null,
    ): array {
        $session = ReadingSession::query()
            ->lockForUpdate()
            ->where('uuid', $sessionId)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->first();
        if (!$session) {
            throw new \InvalidArgumentException(self::ERROR_SESSION_NOT_FOUND);
        }
        if ($chapterId !== null && (int) $session->chapter_id !== $chapterId) {
            throw new \InvalidArgumentException(self::ERROR_SESSION_CHAPTER_MISMATCH);
        }
        if ($session->status !== ReadingSession::STATUS_ACTIVE) {
            throw new \InvalidArgumentException(self::ERROR_SESSION_NOT_ACTIVE);
        }

        $chapter = $this->chapterTextService->lockChapterForUser(
            $userId,
            $language,
            (int) $session->chapter_id,
        );
        $currentRevision = $this->chapterTextService->sourceRevision($chapter);
        if (!hash_equals($session->source_revision, $currentRevision)) {
            throw new \InvalidArgumentException(self::ERROR_SESSION_STALE_SOURCE);
        }

        $catalog = $this->targetCatalogService->build($userId, $language, (int) $session->chapter_id);
        if (!hash_equals($session->source_revision, $catalog['source_revision'])) {
            throw new \InvalidArgumentException(self::ERROR_SESSION_STALE_SOURCE);
        }

        return ['session' => $session, 'catalog' => $catalog];
    }

    public function validateOccurrenceContext(int $userId, string $language, string $sessionId, string $occurrenceId): array
    {
        $session = $this->requireActiveSession($userId, $language, $sessionId);
        $target = $this->resolveCurrentOccurrenceTarget($userId, $language, $session, $occurrenceId);

        return [
            'session' => $session,
            'target' => $target,
        ];
    }

    public function recordOccurrenceInteraction(
        int $userId,
        string $language,
        string $sessionId,
        string $interactionType,
        string $occurrenceId
    ): array {
        if (!in_array($interactionType, [ReadingSessionInteraction::TYPE_OPENED, ReadingSessionInteraction::TYPE_HELPED], true)) {
            throw new \InvalidArgumentException(self::ERROR_EXPLICIT_CONTEXT_INVALID);
        }

        return DB::transaction(function () use ($userId, $language, $sessionId, $interactionType, $occurrenceId) {
            $context = $this->lockActiveSessionContext($userId, $language, $sessionId);
            $session = $context['session'];
            $target = $context['catalog']['targets_by_id'][$occurrenceId] ?? null;
            if (!$target) {
                throw new \InvalidArgumentException(self::ERROR_OCCURRENCE_STALE);
            }
            $interactionKey = $interactionType . ':' . $occurrenceId;

            $interaction = ReadingSessionInteraction::query()->updateOrCreate(
                [
                    'reading_session_id' => $session->id,
                    'interaction_key' => $interactionKey,
                ],
                [
                    'user_id' => $userId,
                    'language_id' => $language,
                    'occurrence_id' => $occurrenceId,
                    'interaction_type' => $interactionType,
                    'word_sense_id' => null,
                    'review_card_id' => null,
                    'review_log_id' => null,
                    'metadata' => [
                        'chapter_id' => $session->chapter_id,
                        'source_revision' => $session->source_revision,
                        'sentence_index' => $target['sentence_index'],
                    ],
                ]
            );

            return [
                'session_id' => $session->uuid,
                'interaction_type' => $interaction->interaction_type,
                'occurrence_id' => $interaction->occurrence_id,
                'recorded' => true,
            ];
        });
    }

    public function lockExplicitRatingContext(
        int $userId,
        string $language,
        string $sessionId,
        string $occurrenceId,
        int $reviewCardId,
    ): array {
        $context = $this->lockActiveSessionContext($userId, $language, $sessionId);
        $session = $context['session'];
        $target = $context['catalog']['targets_by_id'][$occurrenceId] ?? null;
        if (!$target || ($target['kind'] ?? null) !== 'word') {
            throw new \InvalidArgumentException(self::ERROR_OCCURRENCE_STALE);
        }

        $reviewCard = ReviewCard::query()
            ->lockForUpdate()
            ->where('id', $reviewCardId)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->first();
        if (!$reviewCard
            || !$reviewCard->fsrs_enabled
            || $reviewCard->lifecycle_state !== ReviewCard::LIFECYCLE_ACTIVE
            || ($reviewCard->buried_until && $reviewCard->buried_until->isFuture())) {
            throw new \InvalidArgumentException(self::ERROR_EXPLICIT_CONTEXT_INVALID);
        }

        $sense = WordSense::query()
            ->lockForUpdate()
            ->where('id', $reviewCard->target_id)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', WordSense::STATUS_CONFIRMED)
            ->first();
        if (!$sense) {
            throw new \InvalidArgumentException(self::ERROR_EXPLICIT_CONTEXT_INVALID);
        }

        $candidateIds = array_map(
            fn (array $candidate) => (int) $candidate['word_sense_id'],
            $target['candidate_word_senses'] ?? [],
        );
        if (!in_array((int) $sense->id, $candidateIds, true)) {
            throw new \InvalidArgumentException(self::ERROR_EXPLICIT_CONTEXT_INVALID);
        }

        return $context + [
            'target' => $target,
            'review_card' => $reviewCard,
            'sense' => $sense,
        ];
    }

    public function explicitRatingReplay(ReadingSession $session, int $reviewCardId): ?array
    {
        $interaction = ReadingSessionInteraction::query()
            ->lockForUpdate()
            ->where('reading_session_id', $session->id)
            ->where('interaction_key', ReadingSessionInteraction::TYPE_EXPLICIT_RATED . ':' . $reviewCardId)
            ->first();
        if (!$interaction) {
            return null;
        }
        if (!$interaction->review_log_id) {
            throw new \InvalidArgumentException(self::ERROR_EXPLICIT_CONTEXT_INVALID);
        }

        $metadata = is_array($interaction->metadata) ? $interaction->metadata : [];
        if (!is_array($metadata['response_payload'] ?? null)) {
            throw new \InvalidArgumentException(self::ERROR_EXPLICIT_CONTEXT_INVALID);
        }

        return $metadata['response_payload'];
    }

    public function recordExplicitRatingLocked(
        ReadingSession $session,
        array $target,
        int $reviewCardId,
        int $wordSenseId,
        int $reviewLogId,
        array $responsePayload,
    ): ReadingSessionInteraction {
        $interactionKey = ReadingSessionInteraction::TYPE_EXPLICIT_RATED . ':' . $reviewCardId;
        $interaction = ReadingSessionInteraction::query()
            ->where('reading_session_id', $session->id)
            ->where('interaction_key', $interactionKey)
            ->first();
        if (!$interaction) {
            $interaction = new ReadingSessionInteraction();
            $interaction->reading_session_id = $session->id;
            $interaction->interaction_key = $interactionKey;
        }

        $interaction->fill([
            'user_id' => $session->user_id,
            'language_id' => $session->language_id,
            'occurrence_id' => $target['occurrence_id'],
            'interaction_type' => ReadingSessionInteraction::TYPE_EXPLICIT_RATED,
            'word_sense_id' => $wordSenseId,
            'review_card_id' => $reviewCardId,
            'review_log_id' => $reviewLogId,
            'metadata' => [
                'chapter_id' => $session->chapter_id,
                'source_revision' => $session->source_revision,
                'sentence_index' => $target['sentence_index'],
                'response_payload' => $responsePayload,
            ],
        ]);
        $interaction->save();

        return $interaction;
    }

    public function interactionSummary(ReadingSession $session): array
    {
        $rows = ReadingSessionInteraction::query()
            ->where('reading_session_id', $session->id)
            ->get();

        $summary = [
            'opened_occurrence_ids' => [],
            'helped_occurrence_ids' => [],
            'explicit_review_card_ids' => [],
            'explicit_word_sense_ids' => [],
        ];

        foreach ($rows as $row) {
            if ($row->interaction_type === ReadingSessionInteraction::TYPE_OPENED && $row->occurrence_id) {
                $summary['opened_occurrence_ids'][$row->occurrence_id] = true;
            }
            if ($row->interaction_type === ReadingSessionInteraction::TYPE_HELPED && $row->occurrence_id) {
                $summary['helped_occurrence_ids'][$row->occurrence_id] = true;
            }
            if ($row->interaction_type === ReadingSessionInteraction::TYPE_EXPLICIT_RATED) {
                if ($row->review_card_id) {
                    $summary['explicit_review_card_ids'][(int) $row->review_card_id] = true;
                }
                if ($row->word_sense_id) {
                    $summary['explicit_word_sense_ids'][(int) $row->word_sense_id] = true;
                }
            }
        }

        return $summary;
    }

    public function serializeSession(ReadingSession $session, ?array $catalog = null): array
    {
        return [
            'reading_session_id' => $session->uuid,
            'chapter_id' => (int) $session->chapter_id,
            'source_revision' => $session->source_revision,
            'status' => $session->status,
            'started_at' => $session->started_at?->toIso8601String(),
            'completed_at' => $session->completed_at?->toIso8601String(),
            'reading_targets' => $catalog ? $this->serializeReadingTargets($catalog['targets']) : [],
        ];
    }

    private function serializeReadingTargets(array $targets): array
    {
        return array_values(array_map(function (array $target): array {
            $candidateIds = array_values(array_map(
                fn (array $candidate) => (int) $candidate['word_sense_id'],
                $target['candidate_word_senses'] ?? [],
            ));

            return [
                'occurrence_id' => $target['occurrence_id'],
                'kind' => $target['kind'],
                'purpose' => $target['purpose'],
                'start_word_index' => (int) $target['start_word_index'],
                'end_word_index' => (int) $target['end_word_index'],
                'sentence_index' => (int) $target['sentence_index'],
                'surface' => $target['surface'],
                'lemma' => $target['lemma'],
                'pos' => $target['pos'],
                'candidate_word_sense_ids' => $target['kind'] === 'word' ? $candidateIds : [],
                'candidate_word_senses' => $target['kind'] === 'word' ? ($target['candidate_word_senses'] ?? []) : [],
            ];
        }, $targets));
    }

    private function resolveCurrentOccurrenceTarget(
        int $userId,
        string $language,
        ReadingSession $session,
        string $occurrenceId
    ): array {
        $catalog = $this->targetCatalogService->build($userId, $language, (int) $session->chapter_id);
        if ($catalog['source_revision'] !== $session->source_revision) {
            throw new \InvalidArgumentException(self::ERROR_SESSION_STALE_SOURCE);
        }

        $target = $catalog['targets_by_id'][$occurrenceId] ?? null;
        if (!$target) {
            throw new \InvalidArgumentException(self::ERROR_OCCURRENCE_STALE);
        }

        return $target;
    }
}
