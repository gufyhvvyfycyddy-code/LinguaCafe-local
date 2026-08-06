<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\ReviewCardStateEvent;
use App\Models\ReviewLog;
use App\Models\Operation;
use App\Models\WordSense;
use Illuminate\Support\Carbon;

/**
 * ReviewCardInfoQueryService
 *
 * ADR-0014 — Review Card Info Read Model.
 *
 * Read-only orchestrator that aggregates the three sub-sections of the Card
 * Info panel (recent review logs, recent lifecycle events, leech descriptor)
 * into a single `card_info` payload. Called by ReviewCardManageController::detail()
 * AFTER access control has been performed by ReviewCardManageAccessService.
 *
 * Responsibilities:
 *  - Query ReviewLog (card + user + language, reviewed_at DESC, limit 20).
 *  - Query ReviewCardStateEvent (card + user, created_at DESC, limit 20).
 *  - Delegate leech classification to SenseReviewLeechQueryService (no Policy duplication).
 *  - Return an additive `card_info` array; never touches top-level serializer fields.
 *
 * Safety invariants:
 *  - Does NOT write ReviewLog.
 *  - Does NOT modify FSRS fields.
 *  - Does NOT modify lifecycle state.
 *  - Does NOT call AI providers.
 *  - Does NOT duplicate Leech Policy or lifecycle state machine logic.
 *  - Does NOT re-implement access control (caller must pre-validate).
 *  - No per-log / per-event DB query (single batched query per section).
 */
class ReviewCardInfoQueryService
{
    private const LOG_LIMIT = 20;
    private const EVENT_LIMIT = 20;

    public function __construct(
        private SenseReviewLeechQueryService $leechQueryService,
        private MobileOperationLedgerService $operationLedger,
    ) {
    }

    /**
     * Build the additive `card_info` payload for a manageable sense card.
     *
     * @param ReviewCard $card   Already access-checked by ReviewCardManageAccessService.
     * @param WordSense  $sense  Already access-checked by ReviewCardManageAccessService.
     * @param int        $userId Current user id (defensive scope on logs/events).
     * @param string     $language Current language id (defensive scope on logs).
     * @return array{
     *     review_logs: array{items: array<int, array>, limit: int},
     *     lifecycle_events: array{items: array<int, array>, limit: int},
     *     leech: array|null
     * }
     */
    public function build(
        ReviewCard $card,
        WordSense $sense,
        int $userId,
        string $language
    ): array {
        return [
            'review_logs' => [
                'items' => $this->queryReviewLogs($card, $userId, $language),
                'limit' => self::LOG_LIMIT,
            ],
            'lifecycle_events' => [
                'items' => $this->queryLifecycleEvents($card, $userId),
                'limit' => self::EVENT_LIMIT,
            ],
            'manual_operations' => [
                'items' => $this->queryManualOperations(
                    $card,
                    $userId,
                    $language,
                ),
                'limit' => self::EVENT_LIMIT,
            ],
            'statistics' => $this->queryStatistics($card, $userId, $language),
            'leech' => $this->resolveLeechDescriptor($card),
        ];
    }

    private function queryStatistics(ReviewCard $card, int $userId, string $language): array
    {
        $logs = ReviewLog::query()
            ->where('review_card_id', $card->id)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->whereIn('source', ReviewLog::FORMAL_RATING_SOURCES)
            ->notUndone()
            ->get(['rating', 'review_duration_ms', 'reviewed_at', 'new_due_at']);
        $durations = $logs->pluck('review_duration_ms')
            ->filter(fn ($value) => $value !== null)
            ->map(fn ($value) => min(60000, max(0, (int) $value)));
        $retrievability = null;
        if ($card->fsrs_stability !== null && $card->fsrs_last_reviewed_at) {
            $elapsedDays = max(0.0, Carbon::parse($card->fsrs_last_reviewed_at)
                ->diffInSeconds(Carbon::now(), false) / 86400);
            $retrievability = round(pow(
                1.0 + ($elapsedDays / (9.0 * max(0.01, (float) $card->fsrs_stability))),
                -1,
            ), 4);
        }

        return [
            'current' => [
                'state' => $card->fsrs_state,
                'due_at' => optional($card->fsrs_due_at)->toISOString(),
                'stability' => $card->fsrs_stability,
                'difficulty' => $card->fsrs_difficulty,
                'retrievability' => $retrievability,
                'reps' => (int) $card->fsrs_reps,
                'lapses' => (int) $card->fsrs_lapses,
            ],
            'ratings' => [
                'again' => $logs->where('rating', 'again')->count(),
                'hard' => $logs->where('rating', 'hard')->count(),
                'good' => $logs->where('rating', 'good')->count(),
                'easy' => $logs->where('rating', 'easy')->count(),
            ],
            'review_time' => [
                'total_seconds' => round($durations->sum() / 1000, 1),
                'average_seconds' => $durations->isEmpty() ? null : round($durations->avg() / 1000, 1),
                'timed_reviews' => $durations->count(),
            ],
        ];
    }

    private function queryManualOperations(
        ReviewCard $card,
        int $userId,
        string $language,
    ): array {
        $operations = Operation::query()
            ->with([
                'device',
                'changes' => fn ($query) => $query->orderBy('version'),
            ])
            ->where('review_card_id', $card->id)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('scope_type', Operation::SCOPE_REVIEW_CONTROL)
            ->orderByDesc('last_transition_sequence')
            ->limit(self::EVENT_LIMIT)
            ->get();

        return $this->operationLedger->presentMany($operations);
    }

    /**
     * Query the most recent ReviewLog rows for this card.
     *
     * Contract is byte-identical to ReviewCardManageController::logs() — all
     * sources and undone rows are retained (ADR-0009 audit trail). Sorted by
     * reviewed_at DESC, capped at LOG_LIMIT.
     *
     * @return array<int, array>
     */
    private function queryReviewLogs(ReviewCard $card, int $userId, string $language): array
    {
        return ReviewLog::query()
            ->where('review_card_id', $card->id)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->orderBy('reviewed_at', 'desc')
            ->limit(self::LOG_LIMIT)
            ->get()
            ->map(fn (ReviewLog $log) => [
                'id' => $log->id,
                'rating' => $log->rating,
                'source' => $log->source,
                'reviewed_at' => optional($log->reviewed_at)->toISOString(),
                'previous_state' => $log->previous_state,
                'new_state' => $log->new_state,
                'previous_due_at' => optional($log->previous_due_at)->toISOString(),
                'new_due_at' => optional($log->new_due_at)->toISOString(),
                'previous_stability' => $log->previous_stability,
                'new_stability' => $log->new_stability,
                'previous_difficulty' => $log->previous_difficulty,
                'new_difficulty' => $log->new_difficulty,
                'undone' => $log->undone_at !== null,
                'undone_at' => optional($log->undone_at)->toISOString(),
                'undo_source' => $log->undo_source,
            ])
            ->all();
    }

    /**
     * Query the most recent ReviewCardStateEvent rows for this card.
     *
     * Contract is byte-identical to ReviewCardLifecycleController::events() —
     * sorted by created_at DESC, capped at EVENT_LIMIT.
     *
     * @return array<int, array>
     */
    private function queryLifecycleEvents(ReviewCard $card, int $userId): array
    {
        return ReviewCardStateEvent::query()
            ->where('review_card_id', $card->id)
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit(self::EVENT_LIMIT)
            ->get()
            ->map(fn (ReviewCardStateEvent $e) => [
                'id' => $e->id,
                'action' => $e->action,
                'previous_state' => $e->previous_state,
                'new_state' => $e->new_state,
                'source' => $e->source,
                'created_at' => optional($e->created_at)->toISOString(),
                'request_id_prefix' => $e->request_id ? substr($e->request_id, 0, 8) : null,
            ])
            ->all();
    }

    /**
     * Delegate leech classification to SenseReviewLeechQueryService.
     *
     * No Leech Policy duplication — the Policy class is the single source of
     * truth. No AI provider call.
     */
    private function resolveLeechDescriptor(ReviewCard $card): ?array
    {
        $timezone = auth()->user()->timezone ?? 'UTC';

        return $this->leechQueryService->describeForCard(
            $card,
            Carbon::now(),
            $timezone
        );
    }
}
