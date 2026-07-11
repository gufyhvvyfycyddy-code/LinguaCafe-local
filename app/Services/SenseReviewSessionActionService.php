<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\WordSense;
use Illuminate\Support\Facades\DB;

/**
 * SenseReviewSessionActionService
 *
 * Read-only service that returns the session action timeline for
 * the current user, language, and review session. Includes undone
 * actions (for audit) with undoable/blocked_reason metadata.
 *
 * ADR-0009: only the latest active action in the session is
 * undoable; all others are blocked with a specific reason.
 */
class SenseReviewSessionActionService
{
    public function __construct(
        private SenseReviewUndoPolicy $undoPolicy,
        private SenseReviewRatingContract $ratingContract,
    ) {
    }

    /**
     * Return the most recent 20 actions for the session, newest first.
     *
     * @return array<int, array>
     */
    public function timeline(int $userId, string $language, string $reviewSessionId): array
    {
        // Fetch the 20 most recent logs for this session (including undone).
        $logs = ReviewLog::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('review_session_id', $reviewSessionId)
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get();

        if ($logs->isEmpty()) {
            return [];
        }

        // Find the latest active (non-undone) log — it's the only
        // one that can be undoable. All others are blocked.
        $latestActiveLog = $logs->firstWhere('undone_at', null);

        // Eager-load cards and senses for the timeline.
        $cardIds = $logs->pluck('review_card_id')->unique()->values()->all();
        $cards = ReviewCard::whereIn('id', $cardIds)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->get()
            ->keyBy('id');

        $senseIds = $cards->where('target_type', ReviewCard::TARGET_SENSE)
            ->pluck('target_id')
            ->unique()
            ->values()
            ->all();
        $senses = WordSense::whereIn('id', $senseIds)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->get()
            ->keyBy('id');

        $actions = [];
        foreach ($logs as $log) {
            $card = $cards->get($log->review_card_id);
            $sense = $card && $card->target_type === ReviewCard::TARGET_SENSE
                ? $senses->get($card->target_id)
                : null;

            $undoable = false;
            $blockedReason = null;

            if ($log->undone_at !== null) {
                // Already undone — no action needed.
                $blockedReason = null;
            } elseif ($card && $log->before_card_snapshot !== null && $log->review_session_id === $reviewSessionId) {
                // Evaluate policy for this log.
                $policyResult = $this->undoPolicy->evaluate(
                    $log,
                    $latestActiveLog,
                    $card,
                    $reviewSessionId,
                    [
                        'sense_confirmed' => $sense ? $sense->status === WordSense::STATUS_CONFIRMED : false,
                    ],
                );
                $undoable = $policyResult['undoable'];
                $blockedReason = $policyResult['blocked_reason'];
            } else {
                // Legacy log without snapshot — not undoable.
                $blockedReason = $log->before_card_snapshot === null
                    ? SenseReviewUndoPolicy::REASON_MISSING_SNAPSHOT
                    : null;
            }

            $actions[] = [
                'review_log_id' => $log->id,
                'review_card_id' => $log->review_card_id,
                'word_sense_id' => $sense?->id,
                'lemma' => $sense?->lemma,
                'sense_zh' => $sense?->sense_zh,
                'rating' => $log->rating,
                'rating_label' => $this->ratingContract->labelFor($log->rating),
                'reviewed_at' => $log->reviewed_at?->toIso8601String(),
                'previous_due_at' => $log->previous_due_at?->toIso8601String(),
                'new_due_at' => $log->new_due_at?->toIso8601String(),
                'undone' => $log->undone_at !== null,
                'undone_at' => $log->undone_at?->toIso8601String(),
                'undo_source' => $log->undo_source,
                'undoable' => $undoable,
                'blocked_reason' => $blockedReason,
            ];
        }

        return $actions;
    }
}
