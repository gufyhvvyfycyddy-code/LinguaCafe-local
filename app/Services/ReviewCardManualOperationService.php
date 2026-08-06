<?php

namespace App\Services;

use App\Exceptions\ReviewCardManualOperationException;
use App\Models\MobileDevice;
use App\Models\Operation;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReviewCardManualOperationService
{
    public const ACTION_BURY_NEXT_DAY = 'bury_next_day';
    public const ACTION_SUSPEND = 'suspend';
    public const ACTION_RESUME = 'resume';
    public const ACTION_SET_DUE = 'set_due';
    public const ACTION_DUE_NOW = 'due_now';
    public const ACTION_RESET_NEW = 'reset_new';

    private const ACTION_TYPES = [
        self::ACTION_BURY_NEXT_DAY => Operation::TYPE_MANUAL_BURY_NEXT_DAY,
        self::ACTION_SUSPEND => Operation::TYPE_MANUAL_SUSPEND,
        self::ACTION_RESUME => Operation::TYPE_MANUAL_RESUME,
        self::ACTION_SET_DUE => Operation::TYPE_MANUAL_SET_DUE,
        self::ACTION_DUE_NOW => Operation::TYPE_MANUAL_DUE_NOW,
        self::ACTION_RESET_NEW => Operation::TYPE_MANUAL_RESET_NEW,
    ];

    public function __construct(
        private ReviewCardOperationSnapshotService $snapshots,
        private ReviewCardLifecyclePolicy $lifecyclePolicy,
        private ReviewCardBuryTimeService $buryTimeService,
        private ReviewCardLifecycleCommandService $lifecycleCommands,
        private ReviewCardService $reviewCards,
        private MobileOperationLedgerService $ledger,
    ) {}

    public static function actions(): array
    {
        return array_keys(self::ACTION_TYPES);
    }

    public function preview(
        int $userId,
        string $language,
        int $reviewCardId,
        string $action,
        array $options,
        string $timezone,
    ): array {
        $card = $this->findCard($userId, $language, $reviewCardId);
        $payload = $this->normalizePayload($action, $options, $timezone);
        $before = $this->snapshots->capture($card);
        $projected = $this->project($card, $action, $payload, $timezone);

        return [
            'review_card_id' => $card->id,
            'action' => $action,
            'action_payload' => $payload,
            'expected_state_fingerprint' => $this->snapshots->fingerprint($before),
            'before_state' => $before,
            'projected_after_state' => $this->snapshots->capture($projected),
        ];
    }

    public function apply(
        string $operationId,
        int $userId,
        string $language,
        int $reviewCardId,
        string $action,
        array $options,
        string $expectedStateFingerprint,
        string $timezone,
        string $sourceChannel,
        ?MobileDevice $device = null,
    ): array {
        $payload = $this->normalizePayload($action, $options, $timezone);
        $requestFingerprint = $this->requestFingerprint(
            $reviewCardId,
            $action,
            $payload,
        );

        return DB::transaction(function () use (
            $operationId,
            $userId,
            $language,
            $reviewCardId,
            $action,
            $payload,
            $requestFingerprint,
            $expectedStateFingerprint,
            $timezone,
            $sourceChannel,
            $device,
        ) {
            $existing = Operation::query()
                ->where('operation_id', $operationId)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if ((int) $existing->user_id !== $userId
                    || (string) $existing->language_id !== $language
                    || ! $existing->isManualReviewControl()
                    || ! hash_equals(
                        (string) $existing->request_fingerprint,
                        $requestFingerprint,
                    )) {
                    throw new ReviewCardManualOperationException(
                        'MANUAL_OPERATION_REQUEST_CONFLICT',
                        'The operation request identifier is already in use.',
                        409,
                    );
                }

                $card = $this->findCard($userId, $language, $reviewCardId, true);

                return [
                    'already_applied' => true,
                    'operation' => $this->ledger->present($existing),
                    'card' => $this->ledger->presentCard($card),
                ];
            }

            $card = $this->findCard($userId, $language, $reviewCardId, true);
            $before = $this->snapshots->capture($card);
            if (! hash_equals(
                $this->snapshots->fingerprint($before),
                $expectedStateFingerprint,
            )) {
                throw new ReviewCardManualOperationException(
                    'MANUAL_OPERATION_STATE_CHANGED',
                    'The review card changed after the preview.',
                    409,
                );
            }

            $reviewLog = $this->applyForward(
                $card,
                $action,
                $payload,
                $operationId,
                $userId,
                $language,
                $timezone,
                $sourceChannel,
            );
            $card = $this->findCard($userId, $language, $reviewCardId, true);
            $after = $this->snapshots->capture($card);

            $operation = $this->ledger->registerManual(
                $operationId,
                $userId,
                $language,
                $device,
                $sourceChannel,
                self::ACTION_TYPES[$action],
                $payload,
                $requestFingerprint,
                $card,
                $reviewLog,
                $before,
                $after,
            );

            return [
                'already_applied' => false,
                'operation' => $this->ledger->present($operation),
                'card' => $this->ledger->presentCard($card->fresh()),
            ];
        });
    }

    private function applyForward(
        ReviewCard $card,
        string $action,
        array $payload,
        string $operationId,
        int $userId,
        string $language,
        string $timezone,
        string $sourceChannel,
    ): ?ReviewLog {
        $lifecycleAction = match ($action) {
            self::ACTION_BURY_NEXT_DAY => ReviewCardLifecyclePolicy::ACTION_BURY,
            self::ACTION_SUSPEND => ReviewCardLifecyclePolicy::ACTION_SUSPEND,
            self::ACTION_RESUME => ReviewCardLifecyclePolicy::ACTION_RESUME,
            default => null,
        };

        if ($lifecycleAction) {
            try {
                $this->lifecycleCommands->act(
                    $card,
                    $lifecycleAction,
                    $operationId,
                    (int) $card->lifecycle_version,
                    $sourceChannel . '_manual_operation',
                    $userId,
                    $language,
                    $timezone,
                );
            } catch (LifecycleConflictException $exception) {
                throw new ReviewCardManualOperationException(
                    'MANUAL_OPERATION_LIFECYCLE_CONFLICT',
                    $exception->getMessage(),
                    409,
                );
            }

            return null;
        }

        if ($action === self::ACTION_DUE_NOW || $action === self::ACTION_SET_DUE) {
            $card->fsrs_due_at = $action === self::ACTION_DUE_NOW
                ? Carbon::now()
                : Carbon::parse($payload['due_at']);
            $card->save();

            return null;
        }

        $result = $this->reviewCards->resetCardWithLog(
            $userId,
            $language,
            $card->id,
            $payload['reset_counts'],
        );

        return $result['review_log'];
    }

    private function project(
        ReviewCard $card,
        string $action,
        array $payload,
        string $timezone,
    ): ReviewCard {
        $projected = clone $card;
        $now = Carbon::now();

        if (in_array($action, [
            self::ACTION_BURY_NEXT_DAY,
            self::ACTION_SUSPEND,
            self::ACTION_RESUME,
        ], true)) {
            $lifecycleAction = match ($action) {
                self::ACTION_BURY_NEXT_DAY => ReviewCardLifecyclePolicy::ACTION_BURY,
                self::ACTION_SUSPEND => ReviewCardLifecyclePolicy::ACTION_SUSPEND,
                self::ACTION_RESUME => ReviewCardLifecyclePolicy::ACTION_RESUME,
            };
            $effective = $this->lifecyclePolicy->effectiveState($projected, $now);
            if (! $this->lifecyclePolicy->canTransition($effective, $lifecycleAction)) {
                throw new ReviewCardManualOperationException(
                    'MANUAL_OPERATION_NOT_AVAILABLE',
                    "The {$action} action is not available for this card.",
                    409,
                );
            }
            $projected->lifecycle_state = $this->lifecyclePolicy->transitionTo(
                $effective,
                $lifecycleAction,
            );
            $projected->lifecycle_version = (int) $projected->lifecycle_version + 1;
            $projected->lifecycle_changed_at = $now;
            $projected->buried_until = $action === self::ACTION_BURY_NEXT_DAY
                ? $this->buryTimeService->buryUntil($timezone, $now)
                : null;
            $projected->fsrs_enabled = in_array($projected->lifecycle_state, [
                ReviewCard::LIFECYCLE_ACTIVE,
                ReviewCard::LIFECYCLE_BURIED,
            ], true);

            return $projected;
        }

        if ($action === self::ACTION_DUE_NOW || $action === self::ACTION_SET_DUE) {
            $projected->fsrs_due_at = $action === self::ACTION_DUE_NOW
                ? $now
                : Carbon::parse($payload['due_at']);

            return $projected;
        }

        $projected->fsrs_state = 'new';
        $projected->fsrs_due_at = $now;
        $projected->fsrs_stability = null;
        $projected->fsrs_difficulty = null;
        $projected->fsrs_last_reviewed_at = null;
        if ($payload['reset_counts']) {
            $projected->fsrs_reps = 0;
            $projected->fsrs_lapses = 0;
        }

        return $projected;
    }

    private function normalizePayload(
        string $action,
        array $options,
        string $timezone,
    ): array {
        if (! isset(self::ACTION_TYPES[$action])) {
            throw new ReviewCardManualOperationException(
                'MANUAL_OPERATION_INVALID_ACTION',
                'The manual operation action is not supported.',
                422,
            );
        }
        if (! $this->buryTimeService->isValidTimezone($timezone)) {
            throw new ReviewCardManualOperationException(
                'MANUAL_OPERATION_INVALID_TIMEZONE',
                'The timezone is invalid.',
                422,
            );
        }

        if ($action === self::ACTION_SET_DUE) {
            $dueDate = $options['due_date'] ?? null;
            if (! is_string($dueDate)
                || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
                throw new ReviewCardManualOperationException(
                    'MANUAL_OPERATION_INVALID_DUE_DATE',
                    'A valid due_date is required.',
                    422,
                );
            }
            try {
                $dueAt = Carbon::createFromFormat('!Y-m-d', $dueDate, $timezone);
            } catch (\Throwable) {
                $dueAt = null;
            }
            if (! $dueAt || $dueAt->format('Y-m-d') !== $dueDate) {
                throw new ReviewCardManualOperationException(
                    'MANUAL_OPERATION_INVALID_DUE_DATE',
                    'A valid due_date is required.',
                    422,
                );
            }

            return [
                'due_date' => $dueDate,
                'due_at' => $dueAt->setTimezone('UTC')
                    ->toIso8601String(),
            ];
        }

        if ($action === self::ACTION_RESET_NEW) {
            return [
                'reset_counts' => (bool) ($options['reset_counts'] ?? false),
            ];
        }

        return [];
    }

    private function requestFingerprint(
        int $reviewCardId,
        string $action,
        array $payload,
    ): string {
        return hash('sha256', json_encode([
            'review_card_id' => $reviewCardId,
            'action' => $action,
            'action_payload' => $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function findCard(
        int $userId,
        string $language,
        int $reviewCardId,
        bool $lock = false,
    ): ReviewCard {
        $query = ReviewCard::query()
            ->whereKey($reviewCardId)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('target_type', ReviewCard::TARGET_SENSE);
        if ($lock) {
            $query->lockForUpdate();
        }
        $card = $query->first();
        if (! $card || ! WordSense::query()
            ->whereKey($card->target_id)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', WordSense::STATUS_CONFIRMED)
            ->exists()) {
            throw new ReviewCardManualOperationException(
                'MANUAL_OPERATION_TARGET_NOT_FOUND',
                'The review card does not exist.',
                404,
            );
        }

        return $card;
    }
}
