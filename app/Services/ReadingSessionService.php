<?php

namespace App\Services;

use App\Models\ReadingSession;
use App\Models\ReadingSessionInteraction;
use App\Models\ReviewCard;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ReadingSessionService
{
    public function __construct(
        private ReadingChapterTextService $chapterTextService,
        private ReadingTargetCatalogService $targetCatalogService,
    ) {
    }

    public function startSession(int $userId, string $language, int $chapterId): array
    {
        $chapter = $this->chapterTextService->chapterForUser($userId, $language, $chapterId);
        $sourceRevision = $this->chapterTextService->sourceRevision($chapter);

        $session = ReadingSession::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $userId,
            'language_id' => $language,
            'chapter_id' => (int) $chapter->id,
            'source_revision' => $sourceRevision,
            'status' => ReadingSession::STATUS_ACTIVE,
            'started_at' => Carbon::now(),
        ]);

        return $this->serializeSession($session);
    }

    public function resolveOwnedSession(int $userId, string $language, string $sessionId): ReadingSession
    {
        $session = ReadingSession::query()
            ->where('uuid', $sessionId)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->first();

        if (!$session) {
            throw new \InvalidArgumentException('Reading session does not exist in the current user and language scope.');
        }

        return $session;
    }

    public function requireActiveSession(int $userId, string $language, string $sessionId): ReadingSession
    {
        $session = $this->resolveOwnedSession($userId, $language, $sessionId);
        if ($session->status !== ReadingSession::STATUS_ACTIVE) {
            throw new \InvalidArgumentException('Reading session is not active.');
        }

        return $session;
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
            throw new \InvalidArgumentException('Unsupported reading interaction type.');
        }

        $session = $this->requireActiveSession($userId, $language, $sessionId);
        $target = $this->resolveCurrentOccurrenceTarget($userId, $language, $session, $occurrenceId);
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
    }

    public function validateExplicitRatingContext(
        int $userId,
        string $language,
        string $sessionId,
        string $occurrenceId,
        ReviewCard $reviewCard,
    ): array {
        $session = $this->requireActiveSession($userId, $language, $sessionId);
        $target = $this->resolveCurrentOccurrenceTarget($userId, $language, $session, $occurrenceId);
        if (($target['kind'] ?? null) !== 'word') {
            throw new \InvalidArgumentException('Reading explicit ratings require a word occurrence.');
        }
        if ($reviewCard->user_id !== $userId
            || $reviewCard->language_id !== $language
            || $reviewCard->target_type !== ReviewCard::TARGET_SENSE) {
            throw new \InvalidArgumentException('Reading explicit rating card is outside the current user and language scope.');
        }

        $sense = WordSense::query()
            ->where('id', $reviewCard->target_id)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', WordSense::STATUS_CONFIRMED)
            ->first();
        if (!$sense) {
            throw new \InvalidArgumentException('Reading explicit rating requires a current confirmed sense.');
        }

        $candidateIds = array_map(
            fn (array $candidate) => (int) $candidate['word_sense_id'],
            $target['candidate_word_senses'] ?? [],
        );
        if (!in_array((int) $sense->id, $candidateIds, true)) {
            throw new \InvalidArgumentException('Reading explicit rating sense is not a current candidate for this occurrence.');
        }

        return [
            'session' => $session,
            'target' => $target,
            'sense' => $sense,
        ];
    }

    public function recordExplicitRating(
        int $userId,
        string $language,
        string $sessionId,
        string $occurrenceId,
        int $reviewCardId,
        ?int $wordSenseId = null
    ): array {
        $session = $this->requireActiveSession($userId, $language, $sessionId);
        $target = $this->resolveCurrentOccurrenceTarget($userId, $language, $session, $occurrenceId);
        $interactionKey = ReadingSessionInteraction::TYPE_EXPLICIT_RATED . ':' . $reviewCardId;

        ReadingSessionInteraction::query()->updateOrCreate(
            [
                'reading_session_id' => $session->id,
                'interaction_key' => $interactionKey,
            ],
            [
                'user_id' => $userId,
                'language_id' => $language,
                'occurrence_id' => $occurrenceId,
                'interaction_type' => ReadingSessionInteraction::TYPE_EXPLICIT_RATED,
                'word_sense_id' => $wordSenseId,
                'review_card_id' => $reviewCardId,
                'metadata' => [
                    'chapter_id' => $session->chapter_id,
                    'source_revision' => $session->source_revision,
                    'sentence_index' => $target['sentence_index'],
                ],
            ]
        );

        return [
            'session_id' => $session->uuid,
            'occurrence_id' => $occurrenceId,
            'review_card_id' => $reviewCardId,
            'word_sense_id' => $wordSenseId,
            'recorded' => true,
        ];
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

    public function serializeSession(ReadingSession $session): array
    {
        return [
            'reading_session_id' => $session->uuid,
            'chapter_id' => (int) $session->chapter_id,
            'source_revision' => $session->source_revision,
            'status' => $session->status,
            'started_at' => $session->started_at?->toIso8601String(),
            'completed_at' => $session->completed_at?->toIso8601String(),
        ];
    }

    private function resolveCurrentOccurrenceTarget(
        int $userId,
        string $language,
        ReadingSession $session,
        string $occurrenceId
    ): array {
        $catalog = $this->targetCatalogService->build($userId, $language, (int) $session->chapter_id);
        if ($catalog['source_revision'] !== $session->source_revision) {
            throw new \InvalidArgumentException('Reading session source revision is stale.');
        }

        $target = $catalog['targets_by_id'][$occurrenceId] ?? null;
        if (!$target) {
            throw new \InvalidArgumentException('Occurrence does not belong to this reading session chapter revision.');
        }

        return $target;
    }
}
