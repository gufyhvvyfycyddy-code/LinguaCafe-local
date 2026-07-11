<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SenseReviewUndoService
 *
 * Transactional undo service for the review action ledger (ADR-0009).
 *
 * Restores a ReviewCard's FSRS state to the before-snapshot stored in
 * the ReviewLog, then marks the ReviewLog as undone. The ReviewLog is
 * NEVER deleted — it is retained for audit.
 *
 * Stack semantics: only the latest active action in the session can
 * be undone. The caller (via SenseReviewUndoPolicy) enforces this.
 *
 * Idempotency: the same undo_request_id returns 200 with
 * already_applied=true. A different undo_request_id on an
 * already-undone log returns 409.
 *
 * Conflict handling: if the current card state doesn't match the
 * after_card_snapshot (e.g., another tab rated the same card), the
 * undo is rejected with 409 and card_state_changed.
 */
class SenseReviewUndoService
{
    public function __construct(
        private ReviewCardFsrsSnapshotService $snapshotService,
        private SenseReviewUndoPolicy $undoPolicy,
        private SenseReviewSessionActionService $sessionActionService,
        private SenseReviewRatingContract $ratingContract,
    ) {
    }

    /**
     * Undo a review action.
     *
     * @param  int  $reviewLogId
     * @param  int  $userId
     * @param  string  $language
     * @param  string  $reviewSessionId  UUID from the browser tab session.
     * @param  string  $undoRequestId    UUID for idempotency.
     * @param  string  $source           UI entry: snackbar/history/hotkey.
     * @return array{
     *     success: bool,
     *     status?: int,
     *     blocked_reason?: string|null,
     *     message?: string,
     *     already_applied?: bool,
     *     restored_card?: array,
     *     action?: array,
     *     timeline?: array,
     * }
     */
    public function undo(
        int $reviewLogId,
        int $userId,
        string $language,
        string $reviewSessionId,
        string $undoRequestId,
        string $source,
    ): array {
        return DB::transaction(function () use ($reviewLogId, $userId, $language, $reviewSessionId, $undoRequestId, $source) {
            // Lock the target ReviewLog.
            $targetLog = ReviewLog::lockForUpdate()
                ->where('id', $reviewLogId)
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->first();

            if (!$targetLog) {
                return [
                    'success' => false,
                    'status' => 404,
                    'message' => 'Review log does not exist or does not belong to the current user.',
                ];
            }

            // Idempotency: same undo_request_id already applied.
            if ($targetLog->undo_request_id === $undoRequestId) {
                return [
                    'success' => true,
                    'already_applied' => true,
                    'message' => 'Undo already applied.',
                    'restored_card' => $this->buildRestoredCardPayload($targetLog->review_card_id, $userId, $language),
                    'timeline' => $this->sessionActionService->timeline($userId, $language, $reviewSessionId),
                ];
            }

            // Different undo_request_id on already-undone log → conflict.
            if ($targetLog->undone_at !== null) {
                return [
                    'success' => false,
                    'status' => 409,
                    'blocked_reason' => SenseReviewUndoPolicy::REASON_ALREADY_UNDONE,
                    'message' => 'This action has already been undone by a different request.',
                ];
            }

            // Lock the ReviewCard.
            $card = ReviewCard::lockForUpdate()
                ->where('id', $targetLog->review_card_id)
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->first();

            if (!$card) {
                return [
                    'success' => false,
                    'status' => 404,
                    'message' => 'Review card no longer exists.',
                ];
            }

            // Find the latest active log in this session.
            $latestActiveLog = ReviewLog::query()
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->where('review_session_id', $reviewSessionId)
                ->whereNull('undone_at')
                ->orderBy('id', 'desc')
                ->first();

            // Check sense status if applicable.
            $senseConfirmed = true;
            if ($card->target_type === ReviewCard::TARGET_SENSE) {
                $sense = WordSense::where('id', $card->target_id)
                    ->where('user_id', $userId)
                    ->where('language_id', $language)
                    ->first();
                $senseConfirmed = $sense && $sense->status === WordSense::STATUS_CONFIRMED;
            }

            // Evaluate undo policy.
            $policyResult = $this->undoPolicy->evaluate(
                $targetLog,
                $latestActiveLog,
                $card,
                $reviewSessionId,
                ['sense_confirmed' => $senseConfirmed],
            );

            if (!$policyResult['undoable']) {
                $status = $policyResult['blocked_reason'] === SenseReviewUndoPolicy::REASON_WRONG_SESSION
                    ? 404
                    : 409;

                return [
                    'success' => false,
                    'status' => $status,
                    'blocked_reason' => $policyResult['blocked_reason'],
                    'message' => $this->messageForReason($policyResult['blocked_reason']),
                ];
            }

            // Restore the before snapshot onto the card.
            $this->snapshotService->restore($card, $targetLog->before_card_snapshot);
            $card->save();

            // Mark the ReviewLog as undone (NEVER delete it).
            $targetLog->undone_at = Carbon::now();
            $targetLog->undo_request_id = $undoRequestId;
            $targetLog->undo_source = $source;
            $targetLog->save();

            // Build the response.
            $card->refresh();
            $sense = $card->target_type === ReviewCard::TARGET_SENSE
                ? WordSense::find($card->target_id)
                : null;

            return [
                'success' => true,
                'already_applied' => false,
                'restored_card' => [
                    'review_card_id' => $card->id,
                    'word_sense_id' => $sense?->id,
                    'lemma' => $sense?->lemma,
                    'sense_zh' => $sense?->sense_zh,
                    'fsrs_state' => $card->fsrs_state,
                    'fsrs_due_at' => $card->fsrs_due_at?->toIso8601String(),
                    'fsrs_stability' => $card->fsrs_stability,
                    'fsrs_difficulty' => $card->fsrs_difficulty,
                    'fsrs_reps' => $card->fsrs_reps,
                    'fsrs_lapses' => $card->fsrs_lapses,
                    'fsrs_last_reviewed_at' => $card->fsrs_last_reviewed_at?->toIso8601String(),
                ],
                'action' => [
                    'review_log_id' => $targetLog->id,
                    'rating' => $targetLog->rating,
                    'rating_label' => $this->ratingContract->labelFor($targetLog->rating),
                    'undone' => true,
                    'undone_at' => $targetLog->undone_at->toIso8601String(),
                    'undo_source' => $targetLog->undo_source,
                ],
                'timeline' => $this->sessionActionService->timeline($userId, $language, $reviewSessionId),
            ];
        });
    }

    private function buildRestoredCardPayload(int $cardId, int $userId, string $language): array
    {
        $card = ReviewCard::where('id', $cardId)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->first();

        if (!$card) {
            return ['review_card_id' => $cardId];
        }

        $sense = $card->target_type === ReviewCard::TARGET_SENSE
            ? WordSense::find($card->target_id)
            : null;

        return [
            'review_card_id' => $card->id,
            'word_sense_id' => $sense?->id,
            'lemma' => $sense?->lemma,
            'sense_zh' => $sense?->sense_zh,
            'fsrs_state' => $card->fsrs_state,
            'fsrs_due_at' => $card->fsrs_due_at?->toIso8601String(),
            'fsrs_stability' => $card->fsrs_stability,
            'fsrs_difficulty' => $card->fsrs_difficulty,
            'fsrs_reps' => $card->fsrs_reps,
            'fsrs_lapses' => $card->fsrs_lapses,
            'fsrs_last_reviewed_at' => $card->fsrs_last_reviewed_at?->toIso8601String(),
        ];
    }

    private function messageForReason(?string $reason): string
    {
        return match ($reason) {
            SenseReviewUndoPolicy::REASON_WRONG_SESSION => 'This action does not belong to the current review session.',
            SenseReviewUndoPolicy::REASON_NOT_LATEST => 'A newer action must be undone first.',
            SenseReviewUndoPolicy::REASON_ALREADY_UNDONE => 'This action has already been undone.',
            SenseReviewUndoPolicy::REASON_MISSING_SNAPSHOT => 'This legacy action does not support undo.',
            SenseReviewUndoPolicy::REASON_CARD_STATE_CHANGED => 'Card state has changed since this action.',
            SenseReviewUndoPolicy::REASON_LEGACY_TARGET => 'This action is on a legacy word card, not a sense card.',
            SenseReviewUndoPolicy::REASON_SENSE_NOT_CONFIRMED => 'The sense is no longer confirmed.',
            SenseReviewUndoPolicy::REASON_CARD_ARCHIVED => 'The review card is archived.',
            SenseReviewUndoPolicy::REASON_UNSUPPORTED_RATING => 'This action type does not support undo.',
            SenseReviewUndoPolicy::REASON_UNSUPPORTED_SOURCE => 'This action source does not support undo.',
            default => 'Undo failed.',
        };
    }
}
