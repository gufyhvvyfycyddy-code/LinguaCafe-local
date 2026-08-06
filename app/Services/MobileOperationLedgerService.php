<?php

namespace App\Services;

use App\Exceptions\MobileOperationException;
use App\Models\MobileDevice;
use App\Models\Operation;
use App\Models\OperationChange;
use App\Models\ReviewCard;
use App\Models\ReviewCardStateEvent;
use App\Models\ReviewLog;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MobileOperationLedgerService
{
    public function __construct(
        private ReviewCardFsrsSnapshotService $snapshotService,
        private ReviewCardOperationSnapshotService $operationSnapshotService,
    ) {}

    public function registerRating(
        string $operationId,
        int $userId,
        string $language,
        MobileDevice $device,
        ?string $reviewSessionId,
        ReviewLog $reviewLog,
        ?Carbon $clientOccurredAt = null,
        ?int $clientSequence = null,
        ?string $batchId = null,
        ?array $actionPayload = null,
    ): Operation {
        return $this->registerRatingForChannel(
            $operationId,
            $userId,
            $language,
            $device,
            'mobile',
            $reviewSessionId,
            $reviewLog,
            $actionPayload,
            null,
            $clientOccurredAt,
            $clientSequence,
            $batchId,
        );
    }

    public function registerWebRating(
        string $operationId,
        int $userId,
        string $language,
        string $reviewSessionId,
        ReviewLog $reviewLog,
        array $actionPayload,
        string $requestFingerprint,
    ): Operation {
        return $this->registerRatingForChannel(
            $operationId,
            $userId,
            $language,
            null,
            'web',
            $reviewSessionId,
            $reviewLog,
            $actionPayload,
            $requestFingerprint,
        );
    }

    private function registerRatingForChannel(
        string $operationId,
        int $userId,
        string $language,
        ?MobileDevice $device,
        string $sourceChannel,
        ?string $reviewSessionId,
        ReviewLog $reviewLog,
        ?array $actionPayload = null,
        ?string $requestFingerprint = null,
        ?Carbon $clientOccurredAt = null,
        ?int $clientSequence = null,
        ?string $batchId = null,
    ): Operation {
        if ($device === null && $reviewSessionId === null) {
            throw new \InvalidArgumentException(
                'A rating operation requires a device or review session scope.',
            );
        }

        return DB::transaction(function () use (
            $operationId,
            $userId,
            $language,
            $device,
            $sourceChannel,
            $reviewSessionId,
            $reviewLog,
            $actionPayload,
            $requestFingerprint,
            $clientOccurredAt,
            $clientSequence,
            $batchId,
        ) {
            [$scopeType, $scopeId] = $this->scope($device, $reviewSessionId);

            $this->supersedeRedoBranch(
                $userId,
                $language,
                $scopeType,
                $scopeId,
                $device,
                $sourceChannel,
            );

            $operation = Operation::create([
                'operation_id' => $operationId,
                'user_id' => $userId,
                'language_id' => $language,
                'mobile_device_id' => $device?->id,
                'source_channel' => $sourceChannel,
                'operation_type' => Operation::TYPE_SENSE_REVIEW_RATING,
                'action_payload' => $actionPayload,
                'request_fingerprint' => $requestFingerprint,
                'client_occurred_at' => $clientOccurredAt,
                'client_sequence' => $clientSequence,
                'batch_id' => $batchId,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'status' => Operation::STATUS_APPLIED,
                'version' => 1,
                'review_card_id' => $reviewLog->review_card_id,
                'review_log_id' => $reviewLog->id,
                'review_session_id' => $reviewSessionId,
            ]);

            $this->appendChange(
                $operation,
                OperationChange::TRANSITION_APPLY,
                null,
                Operation::STATUS_APPLIED,
                $device,
                $sourceChannel,
                null,
                $reviewLog->before_card_snapshot,
                $reviewLog->after_card_snapshot,
            );

            return $operation->fresh();
        });
    }

    public function registerManual(
        string $operationId,
        int $userId,
        string $language,
        ?MobileDevice $device,
        string $sourceChannel,
        string $operationType,
        array $actionPayload,
        string $requestFingerprint,
        ReviewCard $card,
        ?ReviewLog $reviewLog,
        array $beforeState,
        array $afterState,
    ): Operation {
        $scopeType = Operation::SCOPE_REVIEW_CONTROL;
        $scopeId = 'review_control';

        $this->supersedeRedoBranch(
            $userId,
            $language,
            $scopeType,
            $scopeId,
            $device,
            $sourceChannel,
        );

        $operation = Operation::create([
            'operation_id' => $operationId,
            'user_id' => $userId,
            'language_id' => $language,
            'mobile_device_id' => $device?->id,
            'source_channel' => $sourceChannel,
            'operation_type' => $operationType,
            'action_payload' => $actionPayload,
            'request_fingerprint' => $requestFingerprint,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'status' => Operation::STATUS_APPLIED,
            'version' => 1,
            'review_card_id' => $card->id,
            'review_log_id' => $reviewLog?->id,
        ]);

        $this->appendChange(
            $operation,
            OperationChange::TRANSITION_APPLY,
            null,
            Operation::STATUS_APPLIED,
            $device,
            $sourceChannel,
            $operationId,
            $beforeState,
            $afterState,
        );

        return $operation->fresh('device');
    }

    public function present(Operation $operation): array
    {
        $operation->loadMissing('device');
        $candidates = $this->candidateMap(
            (int) $operation->user_id,
            (string) $operation->language_id,
            collect([$operation]),
        );
        $scopeCandidates = $candidates[$this->scopeKey($operation)] ?? [];

        return $this->serialize(
            $operation,
            ($scopeCandidates['undo'] ?? null) === $operation->id,
            ($scopeCandidates['redo'] ?? null) === $operation->id,
        );
    }

    public function presentMany(Collection $operations): array
    {
        if ($operations->isEmpty()) {
            return [];
        }
        $operations->each->loadMissing('device');
        $first = $operations->first();
        $candidates = $this->candidateMap(
            (int) $first->user_id,
            (string) $first->language_id,
            $operations,
        );

        return $operations->map(function (Operation $operation) use ($candidates) {
            $scopeCandidates = $candidates[$this->scopeKey($operation)] ?? [];

            return $this->serialize(
                $operation,
                ($scopeCandidates['undo'] ?? null) === $operation->id,
                ($scopeCandidates['redo'] ?? null) === $operation->id,
            );
        })->values()->all();
    }

    public function presentCard(ReviewCard $card): array
    {
        return $this->serializeCard($card);
    }

    /**
     * @return array{operations: array<int, array<string, mixed>>}
     */
    public function recent(
        int $userId,
        string $language,
        ?string $reviewSessionId,
        int $limit,
        ?int $beforeSequence = null,
    ): array {
        $operations = Operation::query()
            ->with([
                'device',
                'changes' => fn ($query) => $query->orderBy('version'),
            ])
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->when(
                $reviewSessionId !== null,
                fn ($query) => $query->where('review_session_id', $reviewSessionId),
            )
            ->when(
                $beforeSequence !== null,
                fn ($query) => $query->where('last_transition_sequence', '<', $beforeSequence),
            )
            ->orderByDesc('last_transition_sequence')
            ->limit($limit + 1)
            ->get();
        $hasMore = $operations->count() > $limit;
        $operations = $operations->take($limit)->values();

        $candidates = $this->candidateMap($userId, $language, $operations);

        return [
            'operations' => $operations
                ->map(fn (Operation $operation) => $this->serialize(
                    $operation,
                    ($candidates[$this->scopeKey($operation)]['undo'] ?? null) === $operation->id,
                    ($candidates[$this->scopeKey($operation)]['redo'] ?? null) === $operation->id,
                ))
                ->values()
                ->all(),
            'next_before_sequence' => $hasMore
                ? $operations->last()?->last_transition_sequence
                : null,
        ];
    }

    /**
     * @return array{operation: array<string, mixed>, card: array<string, mixed>}
     */
    public function undo(
        string $operationId,
        int $userId,
        string $language,
        ?MobileDevice $device,
        int $expectedVersion,
        string $clientActionId,
        string $actorSourceChannel = 'mobile',
    ): array {
        return $this->transition(
            OperationChange::TRANSITION_UNDO,
            $operationId,
            $userId,
            $language,
            $device,
            $expectedVersion,
            $clientActionId,
            $actorSourceChannel,
        );
    }

    /**
     * @return array{operation: array<string, mixed>, card: array<string, mixed>}
     */
    public function redo(
        string $operationId,
        int $userId,
        string $language,
        ?MobileDevice $device,
        int $expectedVersion,
        string $clientActionId,
        string $actorSourceChannel = 'mobile',
    ): array {
        return $this->transition(
            OperationChange::TRANSITION_REDO,
            $operationId,
            $userId,
            $language,
            $device,
            $expectedVersion,
            $clientActionId,
            $actorSourceChannel,
        );
    }

    private function transition(
        string $transition,
        string $operationId,
        int $userId,
        string $language,
        ?MobileDevice $device,
        int $expectedVersion,
        string $clientActionId,
        string $actorSourceChannel,
    ): array {
        return DB::transaction(function () use (
            $transition,
            $operationId,
            $userId,
            $language,
            $device,
            $expectedVersion,
            $clientActionId,
            $actorSourceChannel,
        ) {
            $operation = Operation::query()
                ->where('operation_id', $operationId)
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->lockForUpdate()
                ->first();

            if (! $operation) {
                throw new MobileOperationException(
                    'OPERATION_NOT_FOUND',
                    'The operation does not exist.',
                    404,
                );
            }

            $existingTransition = OperationChange::query()
                ->where('client_action_id', $clientActionId)
                ->first();
            if ($existingTransition) {
                if ((int) $existingTransition->operation_record_id !== (int) $operation->id
                    || $existingTransition->transition !== $transition) {
                    throw new MobileOperationException(
                        'OPERATION_REQUEST_CONFLICT',
                        'The transition request identifier is already in use.',
                        409,
                    );
                }

                $card = ReviewCard::query()
                    ->whereKey($operation->review_card_id)
                    ->where('user_id', $userId)
                    ->where('language_id', $language)
                    ->first();
                $operation = $operation->fresh('device');

                return [
                    'operation' => $this->present($operation),
                    'card' => $card ? $this->serializeCard($card) : null,
                    'replayed' => true,
                ];
            }

            if ($operation->version !== $expectedVersion) {
                throw new MobileOperationException(
                    'OPERATION_VERSION_CONFLICT',
                    'The operation version has changed.',
                    409,
                );
            }

            $isUndo = $transition === OperationChange::TRANSITION_UNDO;
            $requiredStatus = $isUndo
                ? Operation::STATUS_APPLIED
                : Operation::STATUS_UNDONE;
            $statusError = $isUndo
                ? 'OPERATION_NOT_UNDOABLE'
                : 'OPERATION_NOT_REDOABLE';

            if ($operation->status !== $requiredStatus) {
                throw new MobileOperationException(
                    $statusError,
                    $isUndo
                        ? 'The operation cannot be undone.'
                        : 'The operation cannot be redone.',
                    409,
                );
            }

            $candidate = $this->latestCandidate(
                $operation,
                $requiredStatus,
                true,
            );

            if (! $candidate || $candidate->id !== $operation->id) {
                throw new MobileOperationException(
                    'OPERATION_NOT_LATEST',
                    'A newer operation must be processed first.',
                    409,
                );
            }

            $reviewLog = $operation->review_log_id
                ? ReviewLog::query()
                    ->whereKey($operation->review_log_id)
                    ->where('user_id', $userId)
                    ->where('language_id', $language)
                    ->lockForUpdate()
                    ->first()
                : null;

            $card = ReviewCard::query()
                ->whereKey($operation->review_card_id)
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->lockForUpdate()
                ->first();

            $manualOperation = $operation->isManualReviewControl();
            $targetAvailable = $card && ($manualOperation
                ? $this->manualTargetAvailable($card, $userId, $language)
                : $this->targetAvailable($card, $userId, $language));

            if (! $card
                || (! $manualOperation && ! $reviewLog)
                || ! $targetAvailable) {
                throw new MobileOperationException(
                    'OPERATION_TARGET_UNAVAILABLE',
                    'The operation target is no longer available.',
                    409,
                );
            }

            if ($manualOperation) {
                $applyChange = $operation->changes()
                    ->where('transition', OperationChange::TRANSITION_APPLY)
                    ->lockForUpdate()
                    ->first();
                $expectedSnapshot = $isUndo
                    ? $applyChange?->after_state
                    : $applyChange?->before_state;
                $restoredSnapshot = $isUndo
                    ? $applyChange?->before_state
                    : $applyChange?->after_state;
                $stateMatches = is_array($expectedSnapshot)
                    && is_array($restoredSnapshot)
                    && $this->operationSnapshotService->matches($card, $expectedSnapshot);
            } else {
                $expectedSnapshot = $isUndo
                    ? $reviewLog->after_card_snapshot
                    : $reviewLog->before_card_snapshot;
                $restoredSnapshot = $isUndo
                    ? $reviewLog->before_card_snapshot
                    : $reviewLog->after_card_snapshot;
                $stateMatches = is_array($expectedSnapshot)
                    && is_array($restoredSnapshot)
                    && $this->snapshotService->matches($card, $expectedSnapshot);
            }

            if (! $stateMatches) {
                throw new MobileOperationException(
                    'OPERATION_STATE_CHANGED',
                    'The review card state has changed.',
                    409,
                );
            }

            $lifecycleBefore = $manualOperation
                ? $this->operationSnapshotService->capture($card)['lifecycle']
                : null;
            if ($manualOperation) {
                $this->operationSnapshotService->restore($card, $restoredSnapshot);
            } else {
                $this->snapshotService->restore($card, $restoredSnapshot);
            }
            $card->save();

            if ($reviewLog && $isUndo) {
                $reviewLog->forceFill([
                    'undone_at' => now(),
                    'undo_request_id' => $clientActionId,
                    'undo_source' => $manualOperation
                        ? $actorSourceChannel . '_operation_ledger'
                        : 'mobile_operation_ledger',
                ])->save();
                $nextStatus = Operation::STATUS_UNDONE;
            } elseif ($reviewLog) {
                $reviewLog->forceFill([
                    'undone_at' => null,
                    'undo_request_id' => null,
                    'undo_source' => null,
                ])->save();
            }
            $nextStatus = $isUndo
                ? Operation::STATUS_UNDONE
                : Operation::STATUS_APPLIED;

            if ($manualOperation) {
                $lifecycleAfter = $restoredSnapshot['lifecycle'];
                if ($lifecycleBefore !== $lifecycleAfter) {
                    ReviewCardStateEvent::create([
                        'user_id' => $userId,
                        'language_id' => $language,
                        'review_card_id' => $card->id,
                        'action' => $isUndo ? 'operation_undo' : 'operation_redo',
                        'previous_state' => $lifecycleBefore,
                        'new_state' => $lifecycleAfter,
                        'request_id' => $clientActionId,
                        'source' => $actorSourceChannel . '_operation_ledger',
                        'metadata' => ['operation_id' => $operation->operation_id],
                        'created_at' => now(),
                    ]);
                }
            }

            $fromStatus = $operation->status;
            $operation->forceFill([
                'status' => $nextStatus,
                'version' => $operation->version + 1,
            ])->save();

            $this->appendChange(
                $operation,
                $transition,
                $fromStatus,
                $nextStatus,
                $device,
                $actorSourceChannel,
                $clientActionId,
                $expectedSnapshot,
                $restoredSnapshot,
            );

            $operation = $operation->fresh('device');
            $candidates = $this->candidateMap(
                $userId,
                $language,
                collect([$operation]),
            );
            $scopeCandidates = $candidates[$this->scopeKey($operation)] ?? [];

            return [
                'operation' => $this->serialize(
                    $operation,
                    ($scopeCandidates['undo'] ?? null) === $operation->id,
                    ($scopeCandidates['redo'] ?? null) === $operation->id,
                ),
                'card' => $this->serializeCard($card->fresh()),
                'replayed' => false,
            ];
        });
    }

    private function supersedeRedoBranch(
        int $userId,
        string $language,
        string $scopeType,
        string $scopeId,
        ?MobileDevice $device,
        string $actorSourceChannel = 'mobile',
    ): void {
        $undone = Operation::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where('status', Operation::STATUS_UNDONE)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($undone as $operation) {
            $fromStatus = $operation->status;
            $operation->forceFill([
                'status' => Operation::STATUS_SUPERSEDED,
                'version' => $operation->version + 1,
            ])->save();

            $this->appendChange(
                $operation,
                OperationChange::TRANSITION_SUPERSEDE,
                $fromStatus,
                Operation::STATUS_SUPERSEDED,
                $device,
                $actorSourceChannel,
            );
        }
    }

    private function appendChange(
        Operation $operation,
        string $transition,
        ?string $fromStatus,
        string $toStatus,
        ?MobileDevice $device,
        string $actorSourceChannel,
        ?string $clientActionId = null,
        ?array $beforeState = null,
        ?array $afterState = null,
    ): void {
        $change = OperationChange::create([
            'operation_record_id' => $operation->id,
            'transition' => $transition,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'version' => $operation->version,
            'actor_mobile_device_id' => $device?->id,
            'actor_source_channel' => $actorSourceChannel,
            'client_action_id' => $clientActionId,
            'before_state' => $beforeState,
            'after_state' => $afterState,
        ]);

        $operation->forceFill([
            'last_transition_sequence' => $change->id,
        ])->save();
    }

    private function targetAvailable(
        ReviewCard $card,
        int $userId,
        string $language,
    ): bool {
        if ($card->target_type !== ReviewCard::TARGET_SENSE
            || ! $card->fsrs_enabled
            || $card->lifecycle_state !== ReviewCard::LIFECYCLE_ACTIVE) {
            return false;
        }

        return WordSense::query()
            ->whereKey($card->target_id)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', WordSense::STATUS_CONFIRMED)
            ->exists();
    }

    private function manualTargetAvailable(
        ReviewCard $card,
        int $userId,
        string $language,
    ): bool {
        if ($card->target_type !== ReviewCard::TARGET_SENSE) {
            return false;
        }

        return WordSense::query()
            ->whereKey($card->target_id)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', WordSense::STATUS_CONFIRMED)
            ->exists();
    }

    private function latestCandidate(
        Operation $operation,
        string $status,
        bool $lock = false,
    ): ?Operation {
        $query = Operation::query()
            ->where('user_id', $operation->user_id)
            ->where('language_id', $operation->language_id)
            ->where('scope_type', $operation->scope_type)
            ->where('scope_id', $operation->scope_id)
            ->where('status', $status)
            ->orderByDesc('last_transition_sequence');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @param  Collection<int, Operation>  $operations
     * @return array<string, array{undo: int|null, redo: int|null}>
     */
    private function candidateMap(
        int $userId,
        string $language,
        Collection $operations,
    ): array {
        $scopes = $operations
            ->unique(fn (Operation $operation) => $this->scopeKey($operation))
            ->values();

        if ($scopes->isEmpty()) {
            return [];
        }

        $sequences = Operation::query()
            ->selectRaw(
                'scope_type, scope_id, status, MAX(last_transition_sequence) AS transition_sequence'
            )
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->whereIn('status', [
                Operation::STATUS_APPLIED,
                Operation::STATUS_UNDONE,
            ])
            ->where(function ($query) use ($scopes) {
                foreach ($scopes as $scope) {
                    $query->orWhere(function ($scopeQuery) use ($scope) {
                        $scopeQuery
                            ->where('scope_type', $scope->scope_type)
                            ->where('scope_id', $scope->scope_id);
                    });
                }
            })
            ->groupBy('scope_type', 'scope_id', 'status')
            ->get();

        $candidateRows = Operation::query()
            ->whereIn(
                'last_transition_sequence',
                $sequences->pluck('transition_sequence')->filter()->all(),
            )
            ->get(['id', 'scope_type', 'scope_id', 'status']);
        $map = [];

        foreach ($candidateRows as $candidate) {
            $map[$this->scopeKey($candidate)][
                $candidate->status === Operation::STATUS_APPLIED ? 'undo' : 'redo'
            ] = $candidate->id;
        }

        return $map;
    }

    private function scope(?MobileDevice $device, ?string $reviewSessionId): array
    {
        if ($reviewSessionId !== null) {
            return [Operation::SCOPE_SESSION, $reviewSessionId];
        }

        if ($device === null) {
            throw new \InvalidArgumentException(
                'A device-scoped operation requires a mobile device.',
            );
        }

        return [Operation::SCOPE_DEVICE, $device->device_uuid];
    }

    private function scopeKey(Operation $operation): string
    {
        return $operation->scope_type . ':' . $operation->scope_id;
    }

    private function serialize(
        Operation $operation,
        bool $canUndo,
        bool $canRedo,
    ): array {
        $applyChange = $operation->relationLoaded('changes')
            ? $operation->changes->firstWhere(
                'transition',
                OperationChange::TRANSITION_APPLY,
            )
            : null;

        return [
            'operation_id' => $operation->operation_id,
            'operation_type' => $operation->operation_type,
            'source_channel' => $operation->source_channel
                ?? ($operation->mobile_device_id ? 'mobile' : null),
            'action_payload' => $operation->action_payload,
            'status' => $operation->status,
            'version' => $operation->version,
            'review_session_id' => $operation->review_session_id,
            'client_occurred_at' => $operation->client_occurred_at?->toIso8601String(),
            'client_sequence' => $operation->client_sequence,
            'batch_id' => $operation->batch_id,
            'review_card_id' => $operation->review_card_id,
            'review_log_id' => $operation->review_log_id,
            'source_device_uuid' => $operation->device?->device_uuid,
            'before_state' => $applyChange?->before_state,
            'after_state' => $applyChange?->after_state,
            'can_undo' => $canUndo,
            'can_redo' => $canRedo,
            'created_at' => $operation->created_at?->toIso8601String(),
            'updated_at' => $operation->updated_at?->toIso8601String(),
        ];
    }

    private function serializeCard(ReviewCard $card): array
    {
        return [
            'id' => $card->id,
            'target_type' => $card->target_type,
            'target_id' => $card->target_id,
            'fsrs_state' => $card->fsrs_state,
            'fsrs_due_at' => $card->fsrs_due_at?->toIso8601String(),
            'fsrs_stability' => $card->fsrs_stability,
            'fsrs_difficulty' => $card->fsrs_difficulty,
            'fsrs_reps' => $card->fsrs_reps,
            'fsrs_lapses' => $card->fsrs_lapses,
            'fsrs_last_reviewed_at' => $card->fsrs_last_reviewed_at?->toIso8601String(),
            'lifecycle_state' => $card->lifecycle_state,
            'lifecycle_version' => $card->lifecycle_version,
        ];
    }
}
