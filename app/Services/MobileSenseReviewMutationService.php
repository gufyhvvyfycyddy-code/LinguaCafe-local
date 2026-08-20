<?php

namespace App\Services;

use App\Exceptions\MobileQueuedActionDomainException;
use App\Exceptions\MobileReviewCardUnavailableException;
use App\Models\MobileDevice;
use App\Models\Operation;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class MobileSenseReviewMutationService
{
    public function __construct(
        private ReviewCardService $reviewCardService,
        private MobileOperationLedgerService $operationLedger,
        private ReadingSessionService $readingSessionService,
    ) {
    }

    public static function idempotencyPayload(
        int $reviewCardId,
        string $rating,
        ?string $reviewSessionId,
        ?int $reviewDurationMs,
        ?string $readingSessionId = null,
        ?string $occurrenceId = null,
        ?string $questionExampleKey = null,
    ): array {
        $payload = [
            'review_card_id' => $reviewCardId,
            'rating' => $rating,
            'review_session_id' => $reviewSessionId,
            'review_duration_ms' => $reviewDurationMs,
        ];

        if ($readingSessionId !== null) {
            $payload['reading_session_id'] = $readingSessionId;
            $payload['occurrence_id'] = $occurrenceId;
        } elseif ($questionExampleKey !== null) {
            $payload['question_example_key'] = $questionExampleKey;
        }

        return $payload;
    }

    public function apply(
        string $operationId,
        int $userId,
        string $language,
        MobileDevice $device,
        int $reviewCardId,
        string $rating,
        ?string $reviewSessionId,
        ?int $reviewDurationMs,
        Carbon $occurredAt,
        ?int $clientSequence = null,
        ?string $batchId = null,
        ?string $readingSessionId = null,
        ?string $occurrenceId = null,
        ?string $readingActionId = null,
        ?string $questionExampleKey = null,
    ): array {
        return $this->runConcurrencySafeTransaction(function () use (
            $operationId,
            $userId,
            $language,
            $device,
            $reviewCardId,
            $rating,
            $reviewSessionId,
            $reviewDurationMs,
            $occurredAt,
            $clientSequence,
            $batchId,
            $readingSessionId,
            $occurrenceId,
            $readingActionId,
            $questionExampleKey,
        ) {
            $readingContext = null;
            if ($readingSessionId !== null && $occurrenceId !== null && $readingActionId !== null) {
                if (!in_array($rating, ['again', 'good'], true)) {
                    throw new MobileQueuedActionDomainException(
                        ReadingSessionService::ERROR_EXPLICIT_CONTEXT_INVALID,
                        'Reader ratings only accept Again or Good.',
                    );
                }
                $session = $this->readingSessionService->lockOwnedSessionForExplicitAction(
                    $userId,
                    $language,
                    $readingSessionId,
                );
                $readingContext = $this->readingSessionService->lockExplicitRatingContext(
                    $userId,
                    $language,
                    $readingSessionId,
                    $occurrenceId,
                    $reviewCardId,
                );
                // Match Web lock order so two offline Reader actions on one card
                // cannot deadlock through absent settlement-row gap locks.
                $this->readingSessionService->assertNoActiveExplicitRating($session, $reviewCardId);
                $this->readingSessionService->assertExplicitRatingEvidenceAllowed(
                    $readingContext['session'],
                    $readingContext['target'],
                    $rating,
                );
                $card = $readingContext['review_card'];
            } else {
                $card = ReviewCard::query()
                    ->lockForUpdate()
                    ->whereKey($reviewCardId)
                    ->where('user_id', $userId)
                    ->where('language_id', $language)
                    ->where('target_type', ReviewCard::TARGET_SENSE)
                    ->where('fsrs_enabled', true)
                    ->where('lifecycle_state', ReviewCard::LIFECYCLE_ACTIVE)
                    ->where(function ($query) {
                        $query->whereNull('buried_until')
                            ->orWhere('buried_until', '<=', Carbon::now());
                    })
                    ->whereHas('sense', function ($query) use ($userId, $language) {
                        $query->where('user_id', $userId)
                            ->where('language_id', $language)
                            ->where('status', WordSense::STATUS_CONFIRMED);
                    })
                    ->first();
            }

            if (!$card) {
                throw new MobileReviewCardUnavailableException();
            }

            if (
                ($card->fsrs_last_reviewed_at !== null
                    && $card->fsrs_last_reviewed_at->gt($occurredAt))
                || $this->hasLaterRating(
                    $userId,
                    $language,
                    $device,
                    $card->id,
                    $occurredAt,
                    $clientSequence,
                )
            ) {
                throw new MobileQueuedActionDomainException(
                    'OUT_OF_ORDER_ACTION',
                    'A later rating for this card has already been accepted.',
                );
            }

            try {
                $outcome = $this->reviewCardService->recordReviewWithLog(
                    $userId,
                    $language,
                    $card->id,
                    $rating,
                    $readingContext ? ReviewLog::SOURCE_READING_EXPLICIT : ReviewLog::SOURCE_SENSE_REVIEW,
                    $readingContext ? $readingSessionId : $reviewSessionId,
                    $reviewDurationMs,
                    $occurredAt,
                    $readingContext ? null : $questionExampleKey,
                );
            } catch (\InvalidArgumentException $e) {
                if (!$readingContext
                    || $e->getMessage() !== ReviewCardService::ERROR_READING_POSITIVE_SPACING_BLOCKED) {
                    throw $e;
                }

                $response = [
                    'operation_id' => $operationId,
                    'review_log_id' => null,
                    'card' => $this->serializeCard($card->fresh()),
                    'scored' => false,
                    'non_scoring_reason' => ReviewCardService::ERROR_READING_POSITIVE_SPACING_BLOCKED,
                ];
                $this->readingSessionService->recordExplicitNonScoringLocked(
                    $readingContext['session'],
                    $readingContext['target'],
                    (int) $readingContext['review_card']->id,
                    (int) $readingContext['sense']->id,
                    $readingActionId,
                    $response,
                );

                return $response;
            }
            $actionPayload = [
                'review_card_id' => $card->id,
                'rating' => $rating,
                'review_session_id' => $reviewSessionId,
                'review_duration_ms' => $reviewDurationMs,
                'occurred_at' => $occurredAt->toIso8601String(),
                'sequence' => $clientSequence,
                'batch_id' => $batchId,
            ];
            if ($readingContext) {
                $actionPayload['reading_session_id'] = $readingSessionId;
                $actionPayload['occurrence_id'] = $occurrenceId;
            } elseif ($questionExampleKey !== null) {
                $actionPayload['question_example_key'] = $questionExampleKey;
            }

            $response = [
                'operation_id' => $operationId,
                'review_log_id' => $outcome['review_log']->id,
                'card' => $this->serializeCard($outcome['card']),
            ];

            $this->operationLedger->registerRating(
                $operationId,
                $userId,
                $language,
                $device,
                $readingContext ? $readingSessionId : $reviewSessionId,
                $outcome['review_log'],
                $occurredAt,
                $clientSequence,
                $batchId,
                $actionPayload,
            );

            if ($readingContext) {
                $this->readingSessionService->recordReadingSettlementLocked(
                    $readingContext['session'],
                    (int) $readingContext['review_card']->id,
                    (int) $readingContext['sense']->id,
                    (int) $outcome['review_log']->id,
                    $rating,
                );
                $this->readingSessionService->recordExplicitRatingLocked(
                    $readingContext['session'],
                    $readingContext['target'],
                    (int) $readingContext['review_card']->id,
                    (int) $readingContext['sense']->id,
                    (int) $outcome['review_log']->id,
                    $readingActionId,
                    $response,
                );
            }

            return $response;
        });
    }

    private function runConcurrencySafeTransaction(callable $callback): mixed
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return DB::transaction($callback, 3);
            } catch (QueryException $e) {
                if ($attempt === 3 || !str_contains($e->getMessage(), 'Record has changed since last read')) {
                    throw $e;
                }
            }
        }

        throw new \LogicException('Mobile Reader transaction retry loop exhausted unexpectedly.');
    }

    private function hasLaterRating(
        int $userId,
        string $language,
        MobileDevice $device,
        int $reviewCardId,
        Carbon $occurredAt,
        ?int $clientSequence,
    ): bool {
        return Operation::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('review_card_id', $reviewCardId)
            ->where('operation_type', Operation::TYPE_SENSE_REVIEW_RATING)
            ->whereNotNull('client_occurred_at')
            ->where(function ($query) use ($device, $occurredAt, $clientSequence) {
                $query->where('client_occurred_at', '>', $occurredAt);

                if ($clientSequence !== null) {
                    $query->orWhere(function ($sameTimestamp) use (
                        $device,
                        $occurredAt,
                        $clientSequence,
                    ) {
                        $sameTimestamp
                            ->where('client_occurred_at', '=', $occurredAt)
                            ->where('mobile_device_id', $device->id)
                            ->where('client_sequence', '>=', $clientSequence);
                    });
                }
            })
            ->exists();
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
