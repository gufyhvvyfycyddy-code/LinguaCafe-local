<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\ReviewLog;

/**
 * SenseReviewUndoPolicy
 *
 * Pure policy service: determines whether a ReviewLog can be undone
 * within the stack-based undo model (ADR-0009). Does NOT query the
 * database, does NOT write to the database, does NOT modify models.
 *
 * The caller is responsible for providing:
 *   - The target ReviewLog (the one the user wants to undo)
 *   - The latest active ReviewLog in the same session (or null)
 *   - The current ReviewCard (freshly loaded, within the transaction)
 *
 * The policy returns:
 *   [
 *     'undoable' => bool,
 *     'blocked_reason' => string|null,
 *   ]
 *
 * Blocked reasons:
 *   wrong_session        — target log's session ID doesn't match request
 *   not_latest_action    — a newer active action exists in the session
 *   already_undone       — target log's undone_at is not null
 *   missing_snapshot     — before_card_snapshot is null (legacy log)
 *   card_state_changed   — current card doesn't match after_card_snapshot
 *   legacy_target        — card is not a sense card
 *   sense_not_confirmed  — WordSense status is not confirmed
 *   card_suspended      — card lifecycle_state is suspended (ADR-0010)
 *   card_archived       — card lifecycle_state is archived (ADR-0010)
 *   unsupported_rating   — rating is not again/hard/good/easy
 *   unsupported_source   — source is not a review source
 */
class SenseReviewUndoPolicy
{
    public const SUPPORTED_RATINGS = ['again', 'hard', 'good', 'easy'];
    public const SUPPORTED_SOURCES = ['sense_review', 'review'];

    public const REASON_WRONG_SESSION = 'wrong_session';
    public const REASON_NOT_LATEST = 'not_latest_action';
    public const REASON_ALREADY_UNDONE = 'already_undone';
    public const REASON_MISSING_SNAPSHOT = 'missing_snapshot';
    public const REASON_CARD_STATE_CHANGED = 'card_state_changed';
    public const REASON_LEGACY_TARGET = 'legacy_target';
    public const REASON_SENSE_NOT_CONFIRMED = 'sense_not_confirmed';
    public const REASON_CARD_SUSPENDED = 'card_suspended';
    public const REASON_CARD_ARCHIVED = 'card_archived';
    public const REASON_UNSUPPORTED_RATING = 'unsupported_rating';
    public const REASON_UNSUPPORTED_SOURCE = 'unsupported_source';

    /**
     * Evaluate whether the target ReviewLog can be undone.
     *
     * @param  ReviewLog  $targetLog     The log the user wants to undo.
     * @param  ReviewLog|null  $latestActiveLog  The latest non-undone log
     *         in the same session, or null if none exists.
     * @param  ReviewCard  $currentCard   The current card state (freshly loaded).
     * @param  string  $requestSessionId  The session ID from the undo request.
     * @param  array  $additionalContext  Optional: ['sense_confirmed' => bool]
     * @return array{undoable: bool, blocked_reason: string|null}
     */
    public function evaluate(
        ReviewLog $targetLog,
        ?ReviewLog $latestActiveLog,
        ReviewCard $currentCard,
        string $requestSessionId,
        array $additionalContext = [],
    ): array {
        // 1. Session match
        if ($targetLog->review_session_id !== $requestSessionId) {
            return ['undoable' => false, 'blocked_reason' => self::REASON_WRONG_SESSION];
        }

        // 2. Already undone
        if ($targetLog->undone_at !== null) {
            return ['undoable' => false, 'blocked_reason' => self::REASON_ALREADY_UNDONE];
        }

        // 3. Missing snapshot (legacy log)
        if ($targetLog->before_card_snapshot === null) {
            return ['undoable' => false, 'blocked_reason' => self::REASON_MISSING_SNAPSHOT];
        }

        // 4. Unsupported rating
        if (!in_array($targetLog->rating, self::SUPPORTED_RATINGS, true)) {
            return ['undoable' => false, 'blocked_reason' => self::REASON_UNSUPPORTED_RATING];
        }

        // 5. Unsupported source
        if (!in_array($targetLog->source, self::SUPPORTED_SOURCES, true)) {
            return ['undoable' => false, 'blocked_reason' => self::REASON_UNSUPPORTED_SOURCE];
        }

        // 6. Not the latest active action in the session
        if ($latestActiveLog !== null && $latestActiveLog->id !== $targetLog->id) {
            return ['undoable' => false, 'blocked_reason' => self::REASON_NOT_LATEST];
        }

        // 7. Legacy target (not a sense card)
        if ($currentCard->target_type !== ReviewCard::TARGET_SENSE) {
            return ['undoable' => false, 'blocked_reason' => self::REASON_LEGACY_TARGET];
        }

        // 8. Card lifecycle state check (ADR-0010)
        // Suspended and Archived cards block undo. Buried cards do NOT
        // block undo because bury is temporary and doesn't change FSRS.
        $lifecycleState = $currentCard->lifecycle_state ?? ReviewCard::LIFECYCLE_ACTIVE;
        if ($lifecycleState === ReviewCard::LIFECYCLE_SUSPENDED) {
            return ['undoable' => false, 'blocked_reason' => self::REASON_CARD_SUSPENDED];
        }
        if ($lifecycleState === ReviewCard::LIFECYCLE_ARCHIVED) {
            return ['undoable' => false, 'blocked_reason' => self::REASON_CARD_ARCHIVED];
        }

        // 9. Sense not confirmed (if context provided)
        if (isset($additionalContext['sense_confirmed']) && !$additionalContext['sense_confirmed']) {
            return ['undoable' => false, 'blocked_reason' => self::REASON_SENSE_NOT_CONFIRMED];
        }

        // 10. Card state changed (current state doesn't match after snapshot)
        if ($targetLog->after_card_snapshot !== null) {
            $snapshotService = app(ReviewCardFsrsSnapshotService::class);
            if (!$snapshotService->matches($currentCard, $targetLog->after_card_snapshot)) {
                return ['undoable' => false, 'blocked_reason' => self::REASON_CARD_STATE_CHANGED];
            }
        }

        return ['undoable' => true, 'blocked_reason' => null];
    }
}
