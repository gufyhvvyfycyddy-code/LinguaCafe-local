<?php

namespace App\Services;

use App\Models\Chapter;
use App\Models\ReadingOccurrenceSenseEvidence;
use App\Models\ReadingSession;
use App\Models\ReadingSessionCardSettlement;
use App\Models\ReadingUnfamiliarTarget;
use App\Models\ReadingSessionCompletion;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReadingFinishSettlementService
{
    public function __construct(
        private ReadingSessionService $readingSessionService,
        private ReadingTargetCatalogService $targetCatalogService,
        private ReadingOccurrenceSenseEvidenceService $evidenceService,
        private ReviewCardService $reviewCardService,
        private ChapterService $chapterService,
    ) {
    }

    public function finishChapterWithSession(
        int $userId,
        string $language,
        int $chapterId,
        string $readingSessionId,
        bool $autoMoveWordsToKnown,
        array $uniqueWords,
        bool $autoLevelUpWords,
        array $leveledUpWords,
        array $leveledUpPhrases,
        string $settlementMode = 'preflight'
    ): array {
        if (!in_array($settlementMode, ['preflight', 'trust'], true)) {
            throw new \InvalidArgumentException('Unsupported reading settlement mode.');
        }

        $session = $this->readingSessionService->resolveOwnedSession($userId, $language, $readingSessionId);
        if ((int) $session->chapter_id !== $chapterId) {
            throw new \InvalidArgumentException('Reading session does not belong to the requested chapter.');
        }

        $completion = ReadingSessionCompletion::query()->where('reading_session_id', $session->id)->first();
        if ($completion) {
            return $completion->result + [
                'success' => true,
                'already_completed' => true,
            ];
        }
        if ($session->status !== ReadingSession::STATUS_ACTIVE) {
            throw new \InvalidArgumentException('Reading session is not active.');
        }

        $catalog = $this->targetCatalogService->build($userId, $language, $chapterId);
        if ($catalog['source_revision'] !== $session->source_revision) {
            throw new \InvalidArgumentException('Reading session source revision is stale.');
        }

        $plan = $this->buildPlan($userId, $language, $session, $catalog, $settlementMode);
        if ($settlementMode !== 'trust' && $plan['unresolved_count'] > 0) {
            return $this->preflightResult($session, $plan);
        }

        return DB::transaction(function () use (
            $userId,
            $language,
            $chapterId,
            $session,
            $autoMoveWordsToKnown,
            $uniqueWords,
            $autoLevelUpWords,
            $leveledUpWords,
            $leveledUpPhrases,
            $settlementMode
        ) {
            $lockedSession = ReadingSession::query()
                ->lockForUpdate()
                ->where('id', $session->id)
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->where('chapter_id', $chapterId)
                ->first();
            if (!$lockedSession) {
                throw new \InvalidArgumentException('Reading session no longer belongs to the current scope.');
            }

            $completion = ReadingSessionCompletion::query()
                ->lockForUpdate()
                ->where('reading_session_id', $lockedSession->id)
                ->first();
            if ($completion) {
                return $completion->result + [
                    'success' => true,
                    'already_completed' => true,
                ];
            }
            if ($lockedSession->status !== ReadingSession::STATUS_ACTIVE) {
                throw new \InvalidArgumentException('Reading session is not active.');
            }

            $currentCatalog = $this->targetCatalogService->build($userId, $language, $chapterId);
            if ($currentCatalog['source_revision'] !== $lockedSession->source_revision) {
                throw new \InvalidArgumentException('Reading session source revision is stale.');
            }

            $currentPlan = $this->buildPlan($userId, $language, $lockedSession, $currentCatalog, $settlementMode);
            if ($settlementMode !== 'trust' && $currentPlan['unresolved_count'] > 0) {
                return $this->preflightResult($lockedSession, $currentPlan);
            }

            // Serialize legacy Finish mutations for this chapter while keeping the
            // existing ChapterService as the one compatibility implementation.
            $chapter = Chapter::query()
                ->lockForUpdate()
                ->where('id', $chapterId)
                ->where('user_id', $userId)
                ->where('language', $language)
                ->first();
            if (!$chapter) {
                throw new \InvalidArgumentException('Chapter does not exist in the current user and language scope.');
            }

            $appliedCount = 0;
            foreach ($currentPlan['eligible_settlements'] as $candidate) {
                $existing = ReadingSessionCardSettlement::query()
                    ->where('reading_session_id', $lockedSession->id)
                    ->where('review_card_id', $candidate['review_card_id'])
                    ->first();
                if ($existing) {
                    continue;
                }

                $reviewed = $this->reviewCardService->recordReviewWithLog(
                    $userId,
                    $language,
                    $candidate['review_card_id'],
                    'good',
                    ReviewLog::SOURCE_READING_PASSIVE,
                    $lockedSession->uuid,
                    null,
                    Carbon::now(),
                );

                ReadingSessionCardSettlement::create([
                    'reading_session_id' => $lockedSession->id,
                    'user_id' => $userId,
                    'language_id' => $language,
                    'review_card_id' => $candidate['review_card_id'],
                    'word_sense_id' => $candidate['word_sense_id'],
                    'review_log_id' => $reviewed['review_log']->id,
                    'rating' => 'good',
                ]);
                $appliedCount++;
            }

            $this->chapterService->finishChapter(
                $userId,
                $chapterId,
                $autoMoveWordsToKnown,
                $uniqueWords,
                $autoLevelUpWords,
                $leveledUpWords,
                $leveledUpPhrases,
                $language,
            );

            $lockedSession->status = ReadingSession::STATUS_COMPLETED;
            $lockedSession->completed_at = Carbon::now();
            $lockedSession->save();

            $result = [
                'success' => true,
                'completed' => true,
                'preflight_required' => false,
                'already_completed' => false,
                'settlement_mode' => $settlementMode,
                'passive_good_count' => $appliedCount,
                'planned_passive_good_count' => $currentPlan['passive_good_count'],
                'unresolved_count' => $currentPlan['unresolved_count'],
                'excluded_count' => $currentPlan['excluded_count'],
                'passive_occurrence_ids' => $currentPlan['passive_occurrence_ids'],
                'unresolved_occurrence_ids' => $currentPlan['unresolved_occurrence_ids'],
                'excluded_occurrence_ids' => $currentPlan['excluded_occurrence_ids'],
                'chapter_id' => $chapterId,
                'reading_session_id' => $lockedSession->uuid,
                'source_revision' => $lockedSession->source_revision,
            ];

            ReadingSessionCompletion::create([
                'reading_session_id' => $lockedSession->id,
                'user_id' => $userId,
                'language_id' => $language,
                'chapter_id' => $chapterId,
                'source_revision' => $lockedSession->source_revision,
                'result' => $result,
            ]);

            return $result;
        });
    }

    private function buildPlan(
        int $userId,
        string $language,
        ReadingSession $session,
        array $catalog,
        string $settlementMode,
    ): array {
        $evidenceMap = $this->evidenceService->currentEvidenceMap(
            $userId,
            $language,
            (int) $session->chapter_id,
            $session->source_revision,
        );
        $interactionSummary = $this->readingSessionService->interactionSummary($session);

        $eligibleSettlements = [];
        $excludedOccurrenceIds = [];
        $unresolvedOccurrenceIds = [];
        $seenReviewCards = [];
        $sameSessionMarkedIds = ReadingUnfamiliarTarget::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('chapter_id', $session->chapter_id)
            ->where('source_revision', $session->source_revision)
            ->where('created_at', '>=', $session->started_at)
            ->pluck('occurrence_id')
            ->flip()
            ->all();

        foreach ($catalog['targets'] as $target) {
            if (($target['kind'] ?? null) !== 'word') {
                continue;
            }

            $purpose = $target['purpose'] ?? null;
            if (!in_array($purpose, ['passive_disambiguation', 'marked_unknown'], true)) {
                continue;
            }

            $occurrenceId = (string) $target['occurrence_id'];
            $evidence = $evidenceMap[$occurrenceId] ?? null;
            if ($purpose === 'marked_unknown' && isset($sameSessionMarkedIds[$occurrenceId])) {
                $excludedOccurrenceIds[] = $occurrenceId;
                continue;
            }
            if ($purpose === 'marked_unknown'
                && $evidence
                && $evidence->updated_at
                && $session->started_at
                && $evidence->updated_at->greaterThanOrEqualTo($session->started_at)) {
                // A marked occurrence resolved/changed during this reading is
                // active learning, not passive evidence. A later reading
                // session may consume the persisted binding.
                $excludedOccurrenceIds[] = $occurrenceId;
                continue;
            }
            $wasOpened = isset($interactionSummary['opened_occurrence_ids'][$occurrenceId]);
            $wasHelped = isset($interactionSummary['helped_occurrence_ids'][$occurrenceId]);

            if ($wasOpened || $wasHelped) {
                $excludedOccurrenceIds[] = $occurrenceId;
                continue;
            }
            if (!$evidence) {
                $unresolvedOccurrenceIds[] = $occurrenceId;
                continue;
            }
            if (!in_array($evidence->target_origin, ['passive_disambiguation', 'marked_unknown'], true)) {
                $excludedOccurrenceIds[] = $occurrenceId;
                continue;
            }
            if ($evidence->resolution !== ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING) {
                $excludedOccurrenceIds[] = $occurrenceId;
                continue;
            }
            if (!$this->evidenceService->isCurrentConfirmedBinding($evidence, $userId, $language)) {
                $excludedOccurrenceIds[] = $occurrenceId;
                continue;
            }

            $sense = WordSense::query()
                ->where('id', $evidence->word_sense_id)
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->where('status', WordSense::STATUS_CONFIRMED)
                ->first();
            $card = $sense?->reviewCard;
            if (!$sense || !$card || !$this->isQueueEligibleSenseCard($card, $userId, $language, $sense->id)) {
                $excludedOccurrenceIds[] = $occurrenceId;
                continue;
            }
            if (isset($interactionSummary['explicit_review_card_ids'][$card->id])
                || isset($interactionSummary['explicit_word_sense_ids'][$sense->id])) {
                $excludedOccurrenceIds[] = $occurrenceId;
                continue;
            }
            if (isset($seenReviewCards[$card->id])) {
                $excludedOccurrenceIds[] = $occurrenceId;
                continue;
            }

            $seenReviewCards[$card->id] = true;
            $eligibleSettlements[] = [
                'occurrence_id' => $occurrenceId,
                'review_card_id' => (int) $card->id,
                'word_sense_id' => (int) $sense->id,
            ];
        }

        return [
            'passive_good_count' => count($eligibleSettlements),
            'unresolved_count' => count($unresolvedOccurrenceIds),
            'excluded_count' => count($excludedOccurrenceIds),
            'eligible_settlements' => $eligibleSettlements,
            'passive_occurrence_ids' => array_values(array_map(fn (array $row) => $row['occurrence_id'], $eligibleSettlements)),
            'unresolved_occurrence_ids' => array_values(array_unique($unresolvedOccurrenceIds)),
            'excluded_occurrence_ids' => array_values(array_unique($excludedOccurrenceIds)),
        ];
    }

    private function preflightResult(ReadingSession $session, array $plan): array
    {
        return [
            'success' => true,
            'completed' => false,
            'preflight_required' => true,
            'settlement_mode' => 'preflight',
            'chapter_id' => (int) $session->chapter_id,
            'reading_session_id' => $session->uuid,
            'source_revision' => $session->source_revision,
        ] + $plan;
    }

    private function isQueueEligibleSenseCard(ReviewCard $card, int $userId, string $language, int $senseId): bool
    {
        return ReviewCard::query()
            ->where('id', $card->id)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->where('target_id', $senseId)
            ->where('fsrs_enabled', true)
            ->where('lifecycle_state', ReviewCard::LIFECYCLE_ACTIVE)
            ->where(function ($query) {
                $query->whereNull('buried_until')->orWhere('buried_until', '<=', Carbon::now());
            })
            ->exists();
    }
}
