<?php

namespace App\Services\SpecialStudy;

use App\Exceptions\SpecialStudyException;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\SpecialStudySession;
use App\Models\SpecialStudySessionAction;
use App\Models\WordSense;
use App\Services\MobileOperationLedgerService;
use App\Services\ReviewCardService;
use App\Services\ReviewStudyTimezoneService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SpecialStudyAnswerService
{
    private const RATINGS = ['again', 'hard', 'good', 'easy'];

    public function __construct(
        private readonly ReviewCardService $reviewCardService,
        private readonly MobileOperationLedgerService $operationLedgerService,
        private readonly SpecialStudySessionService $sessionService,
        private readonly ReviewStudyTimezoneService $timezoneService,
    ) {
    }

    public function answer(
        string $sessionId,
        int $userId,
        string $language,
        string $rating,
        string $clientActionId,
        int $expectedRevision,
        ?int $reviewDurationMs = null,
        ?Carbon $now = null,
        ?string $questionExampleKey = null,
    ): array {
        $now ??= Carbon::now();
        if (! in_array($rating, self::RATINGS, true)) {
            throw new SpecialStudyException(
                'invalid_rating',
                'Special Study rating is invalid.',
                422,
                'rating',
            );
        }
        if (! Str::isUuid($clientActionId)) {
            throw new SpecialStudyException(
                'invalid_client_action_id',
                'Special Study client_action_id must be a UUID.',
                422,
                'client_action_id',
            );
        }
        if ($reviewDurationMs !== null
            && ($reviewDurationMs < 0 || $reviewDurationMs > 3600000)) {
            throw new SpecialStudyException(
                'invalid_review_duration',
                'Special Study review_duration_ms is invalid.',
                422,
                'review_duration_ms',
            );
        }

        $payload = [
            'expected_revision' => $expectedRevision,
            'rating' => $rating,
            'review_duration_ms' => $reviewDurationMs,
        ];
        if ($questionExampleKey !== null) {
            $payload['question_example_key'] = $questionExampleKey;
        }
        $requestHash = $this->requestHash($payload);

        return DB::transaction(function () use (
            $sessionId,
            $userId,
            $language,
            $rating,
            $clientActionId,
            $expectedRevision,
            $reviewDurationMs,
            $questionExampleKey,
            $now,
            $payload,
            $requestHash,
        ) {
            $session = SpecialStudySession::query()
                ->whereKey($sessionId)
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->lockForUpdate()
                ->first();
            if (! $session) {
                throw new SpecialStudyException(
                    'session_not_found',
                    'The Special Study session does not exist.',
                    404,
                );
            }

            $existing = SpecialStudySessionAction::query()
                ->where('special_study_session_id', $session->id)
                ->where('client_action_id', $clientActionId)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new SpecialStudyException(
                        'action_request_conflict',
                        'The Special Study action identifier is already in use.',
                        409,
                        'client_action_id',
                    );
                }
                if ($existing->status !== SpecialStudySessionAction::STATUS_COMPLETED
                    || ! is_array($existing->response_body)) {
                    throw new SpecialStudyException(
                        'action_in_progress',
                        'The Special Study action is still processing.',
                        409,
                    );
                }

                return array_merge(
                    $existing->response_body,
                    ['replayed' => true],
                );
            }

            if ((int) $session->revision !== $expectedRevision) {
                throw new SpecialStudyException(
                    'revision_conflict',
                    'The Special Study session has changed.',
                    409,
                    'expected_revision',
                );
            }
            if ($session->status !== SpecialStudySession::STATUS_ACTIVE) {
                throw new SpecialStudyException(
                    'session_not_active',
                    'The Special Study session is not active.',
                    409,
                );
            }

            $action = SpecialStudySessionAction::create([
                'special_study_session_id' => $session->id,
                'client_action_id' => $clientActionId,
                'request_hash' => $requestHash,
                'status' => SpecialStudySessionAction::STATUS_PROCESSING,
            ]);

            [$session, $card] = $this->resolveCurrentEligible(
                $session,
                $userId,
                $language,
                $now,
            );
            $operationId = null;

            if ($card !== null) {
                if ($session->execution_mode !== SpecialStudyCriteria::MODE_PREVIEW) {
                    $operationId = (string) Str::uuid();
                    $outcome = $this->reviewCardService->recordReviewWithLog(
                        $userId,
                        $language,
                        $card->id,
                        $rating,
                        ReviewLog::SOURCE_SPECIAL_STUDY,
                        $session->id,
                        $reviewDurationMs,
                        null,
                        $questionExampleKey,
                    );
                    $this->operationLedgerService->registerWebRating(
                        $operationId,
                        $userId,
                        $language,
                        $session->id,
                        $outcome['review_log'],
                        [
                            'special_study_session_id' => $session->id,
                            'rating' => $rating,
                            'expected_revision' => $expectedRevision,
                        ],
                        $requestHash,
                    );
                }

                $remaining = array_values($session->remaining_card_ids ?? []);
                array_shift($remaining);
                $completedIds = array_values($session->completed_card_ids ?? []);
                $completedIds[] = (int) $card->id;
                $session->remaining_card_ids = $remaining;
                $session->completed_card_ids = array_values(array_unique(
                    $completedIds,
                ));
            }

            $remaining = array_values($session->remaining_card_ids ?? []);
            $completed = $remaining === [];
            $session->revision = $session->revision + 1;
            $session->status = $completed
                ? SpecialStudySession::STATUS_COMPLETED
                : SpecialStudySession::STATUS_ACTIVE;
            $session->completed_at = $completed ? $now : null;
            $session->save();

            $response = array_merge($this->sessionService->present(
                $session->fresh(),
            ), [
                'operation_id' => $operationId,
                'replayed' => false,
            ]);
            $action->forceFill([
                'status' => SpecialStudySessionAction::STATUS_COMPLETED,
                'operation_id' => $operationId,
                'response_status' => 200,
                'response_body' => $response,
            ])->save();

            return $response;
        }, 3);
    }

    /**
     * @return array{SpecialStudySession, ReviewCard|null}
     */
    private function resolveCurrentEligible(
        SpecialStudySession $session,
        int $userId,
        string $language,
        Carbon $now,
    ): array {
        $remaining = array_values($session->remaining_card_ids ?? []);
        $skipped = array_values($session->skipped_card_ids ?? []);
        $allowedLifecycles = $session->definition['filters']['lifecycle_states']
            ?? [ReviewCard::LIFECYCLE_ACTIVE];

        while ($remaining !== []) {
            $cardId = (int) $remaining[0];
            $query = ReviewCard::query()
                ->whereKey($cardId)
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->where('target_type', ReviewCard::TARGET_SENSE)
                ->whereHas('sense', fn ($senseQuery) =>
                    $senseQuery->where('user_id', $userId)
                        ->where('language_id', $language)
                        ->where('status', WordSense::STATUS_CONFIRMED)
                );

            if ($session->execution_mode === SpecialStudyCriteria::MODE_PREVIEW) {
                $query->whereIn('lifecycle_state', $allowedLifecycles);
            } else {
                $query->where('fsrs_enabled', true)
                    ->where('lifecycle_state', ReviewCard::LIFECYCLE_ACTIVE)
                    ->where(function ($builder) use ($now) {
                        $builder->whereNull('buried_until')
                            ->orWhere('buried_until', '<=', $now);
                    });

                if ($session->execution_mode === SpecialStudyCriteria::MODE_EARLY_REVIEW) {
                    $windowEnd = $this->timezoneService
                        ->dayStart($now)
                        ->addDays(((int) ($session->definition['days'] ?? 7)) + 1);
                    $query->whereIn('fsrs_state', ['review', 'relearning'])
                        ->where('fsrs_due_at', '>', $now)
                        ->where('fsrs_due_at', '<', $windowEnd);
                }
            }

            $card = $query->lockForUpdate()->first();
            if ($card) {
                $session->remaining_card_ids = $remaining;
                $session->skipped_card_ids = $skipped;

                return [$session, $card];
            }

            array_shift($remaining);
            $skipped[] = $cardId;
        }

        $session->remaining_card_ids = [];
        $session->skipped_card_ids = array_values(array_unique($skipped));

        return [$session, null];
    }

    private function requestHash(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->canonicalize($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
