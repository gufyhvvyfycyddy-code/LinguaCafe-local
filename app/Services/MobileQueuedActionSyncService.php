<?php

namespace App\Services;

use App\Exceptions\MobileIdempotencyConflictException;
use App\Exceptions\MobileQueuedActionDomainException;
use App\Exceptions\MobileReviewCardUnavailableException;
use App\Models\MobileDevice;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class MobileQueuedActionSyncService
{
    public const TYPE_RATING = 'sense_review.rating';
    public const TYPE_SENSE_UPDATE = 'word_sense.update';
    public const TYPE_SENSE_DELETE = 'word_sense.delete';
    public const TYPE_READING_INTERACTION = 'reading_session.interaction';

    public const MAX_ACTIONS = 100;
    public const MAX_REQUEST_BYTES = 1048576;
    public const RETRY_BASE_MS = 1000;
    public const RETRY_MAX_MS = 30000;

    public function __construct(
        private MobileIdempotencyService $idempotency,
        private MobileSenseReviewMutationService $ratingMutation,
        private WordSenseContentVersionService $wordSenseVersion,
        private ReviewCardManageMutationService $senseMutation,
        private WordSenseService $wordSenseService,
        private ReadingSessionService $readingSessionService,
    ) {
    }

    public function sync(
        int $userId,
        string $language,
        MobileDevice $device,
        string $batchId,
        array $actions,
    ): array {
        $ordered = collect($actions)
            ->map(fn (array $action, int $index) => [
                'action' => $action,
                'original_index' => $index,
                'sort_time' => $this->sortableTime($action['occurred_at'] ?? null),
                'sort_sequence' => is_int($action['sequence'] ?? null)
                    ? $action['sequence']
                    : PHP_INT_MAX,
            ])
            ->sortBy([
                ['sort_time', 'asc'],
                ['sort_sequence', 'asc'],
                ['original_index', 'asc'],
            ])
            ->values();

        $results = [];
        foreach ($ordered as $processedOrder => $entry) {
            $results[$entry['original_index']] = $this->processAction(
                $userId,
                $language,
                $device,
                $batchId,
                $entry['action'],
                $entry['original_index'],
                $processedOrder,
            );
        }
        ksort($results);
        $results = array_values($results);

        $succeeded = count(array_filter(
            $results,
            fn (array $result) => in_array($result['outcome'], ['applied', 'replayed'], true),
        ));
        $replayed = count(array_filter(
            $results,
            fn (array $result) => ($result['replayed'] ?? false) === true,
        ));
        $failed = count($results) - $succeeded;

        return [
            'batch_id' => $batchId,
            'status' => $failed === 0
                ? 'completed'
                : ($succeeded === 0 ? 'failed' : 'partial'),
            'counts' => [
                'total' => count($results),
                'succeeded' => $succeeded,
                'failed' => $failed,
                'replayed' => $replayed,
            ],
            'results' => $results,
            'retry_policy' => [
                'strategy' => 'exponential_backoff',
                'base_delay_ms' => self::RETRY_BASE_MS,
                'max_delay_ms' => self::RETRY_MAX_MS,
                'retryable_codes' => ['INTERNAL_ERROR'],
            ],
        ];
    }

    private function processAction(
        int $userId,
        string $language,
        MobileDevice $device,
        string $batchId,
        array $action,
        int $originalIndex,
        int $processedOrder,
    ): array {
        try {
            $normalized = $this->validateAction($action);
        } catch (ValidationException $exception) {
            return $this->failureResult(
                $action,
                $originalIndex,
                $processedOrder,
                422,
                'VALIDATION_ERROR',
                'The queued action is invalid.',
                false,
                null,
                $exception->errors(),
            );
        }

        $idempotencyPayload = $normalized['type'] === self::TYPE_RATING
            ? MobileSenseReviewMutationService::idempotencyPayload(
                $normalized['payload']['review_card_id'],
                $normalized['payload']['rating'],
                $normalized['payload']['review_session_id'] ?? null,
                $normalized['payload']['review_duration_ms'] ?? null,
                $normalized['payload']['reading_session_id'] ?? null,
                $normalized['payload']['occurrence_id'] ?? null,
                $normalized['payload']['question_example_key'] ?? null,
            )
            : [
                'occurred_at' => $normalized['occurred_at']->toIso8601String(),
                'sequence' => $normalized['sequence'],
                'payload' => $normalized['payload'],
            ];

        try {
            $result = $this->idempotency->execute(
                $userId,
                $device,
                $normalized['type'],
                $normalized['client_action_id'],
                $idempotencyPayload,
                function (string $operationId) use (
                    $userId,
                    $language,
                    $device,
                    $batchId,
                    $normalized,
                ) {
                    return $this->applyAction(
                        $operationId,
                        $userId,
                        $language,
                        $device,
                        $batchId,
                        $normalized,
                    );
                },
            );
        } catch (MobileIdempotencyConflictException) {
            return $this->failureResult(
                $normalized,
                $originalIndex,
                $processedOrder,
                409,
                'IDEMPOTENCY_KEY_REUSED',
                'The client action id was already used with a different request.',
            );
        } catch (Throwable $exception) {
            Log::error('Queued mobile action failed unexpectedly.', [
                'user_id' => $userId,
                'mobile_device_id' => $device->id,
                'batch_id' => $batchId,
                'client_action_id' => $normalized['client_action_id'],
                'action_type' => $normalized['type'],
                'exception' => get_class($exception),
            ]);

            return $this->failureResult(
                $normalized,
                $originalIndex,
                $processedOrder,
                500,
                'INTERNAL_ERROR',
                'The action could not be processed. Retry it later.',
                true,
                self::RETRY_BASE_MS,
            );
        }

        $body = $result['body'];
        if ($result['status'] >= 400) {
            return $this->failureResult(
                $normalized,
                $originalIndex,
                $processedOrder,
                $result['status'],
                $body['error']['code'],
                $body['error']['message'],
                $body['error']['retryable'] ?? false,
                $body['error']['retry_after_ms'] ?? null,
                $body['error']['details'] ?? null,
                $result['replayed'],
                $result['operation_id'],
            );
        }

        return [
            'original_index' => $originalIndex,
            'processed_order' => $processedOrder,
            'client_action_id' => $normalized['client_action_id'],
            'type' => $normalized['type'],
            'outcome' => $result['replayed'] ? 'replayed' : 'applied',
            'http_status' => $result['status'],
            'replayed' => $result['replayed'],
            'operation_id' => $result['operation_id'],
            'data' => $body['data'],
            'error' => null,
        ];
    }

    private function applyAction(
        string $operationId,
        int $userId,
        string $language,
        MobileDevice $device,
        string $batchId,
        array $action,
    ): array {
        $now = Carbon::now('UTC');
        if ($action['occurred_at']->gt($now->copy()->addMinutes(5))
            || $action['occurred_at']->lt($now->copy()->subDays(30))) {
            return $this->domainFailure(
                422,
                'ACTION_TIME_OUT_OF_RANGE',
                'The action time is outside the supported offline window.',
            );
        }

        try {
            return match ($action['type']) {
                self::TYPE_RATING => [
                    'status' => 200,
                    'body' => [
                        'data' => $this->ratingMutation->apply(
                            $operationId,
                            $userId,
                            $language,
                            $device,
                            $action['payload']['review_card_id'],
                            $action['payload']['rating'],
                            $action['payload']['review_session_id'] ?? null,
                            $action['payload']['review_duration_ms'] ?? null,
                            $action['occurred_at'],
                            $action['sequence'],
                            $batchId,
                            $action['payload']['reading_session_id'] ?? null,
                            $action['payload']['occurrence_id'] ?? null,
                            isset($action['payload']['reading_session_id'])
                                ? $action['client_action_id']
                                : null,
                            $action['payload']['question_example_key'] ?? null,
                        ),
                    ],
                ],
                self::TYPE_SENSE_UPDATE => $this->updateSense(
                    $userId,
                    $language,
                    $action['payload'],
                ),
                self::TYPE_SENSE_DELETE => $this->deleteSense(
                    $userId,
                    $language,
                    $action['payload'],
                ),
                self::TYPE_READING_INTERACTION => [
                    'status' => 200,
                    'body' => [
                        'data' => $this->readingSessionService->recordOccurrenceInteraction(
                            $userId,
                            $language,
                            $action['payload']['reading_session_id'],
                            $action['payload']['interaction_type'],
                            $action['payload']['occurrence_id'],
                        ),
                    ],
                ],
            };
        } catch (MobileReviewCardUnavailableException) {
            return $this->domainFailure(
                404,
                'REVIEW_CARD_NOT_FOUND',
                'The sense review card does not exist or is not reviewable.',
            );
        } catch (MobileQueuedActionDomainException $exception) {
            return $this->domainFailure(
                $exception->status,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->retryable,
                $exception->retryAfterMs,
            );
        } catch (\InvalidArgumentException $exception) {
            $hasReadingContext = $action['type'] === self::TYPE_READING_INTERACTION
                || ($action['type'] === self::TYPE_RATING
                    && isset($action['payload']['reading_session_id']));
            if (!$hasReadingContext) {
                throw $exception;
            }

            return $this->readingContractFailure($exception);
        }
    }

    private function updateSense(int $userId, string $language, array $payload): array
    {
        $sense = $this->lockedSense($payload['word_sense_id'], $userId, $language);
        if (!$sense) {
            return $this->domainFailure(
                404,
                'WORD_SENSE_NOT_FOUND',
                'The WordSense was not found.',
            );
        }
        if ($sense->status !== WordSense::STATUS_CONFIRMED) {
            return $this->domainFailure(
                409,
                'WORD_SENSE_DELETED',
                'The WordSense is no longer editable.',
            );
        }

        $currentVersion = $this->wordSenseVersion->version($sense);
        if (!hash_equals($currentVersion, $payload['expected_word_sense_version'])) {
            return $this->domainFailure(
                409,
                'STALE_WORD_SENSE',
                'The WordSense changed after the queued edit was created.',
                false,
                null,
                ['current_word_sense_version' => $currentVersion],
            );
        }

        $sense = $this->senseMutation->updateSenseTextFieldsFromArray(
            $sense,
            $payload['changes'],
        );

        return [
            'status' => 200,
            'body' => [
                'data' => [
                    'word_sense_id' => $sense->id,
                    'word_sense_version' => $this->wordSenseVersion->version($sense),
                    'updated' => true,
                ],
            ],
        ];
    }

    private function deleteSense(int $userId, string $language, array $payload): array
    {
        $sense = $this->lockedSense($payload['word_sense_id'], $userId, $language);
        if (!$sense) {
            return $this->domainFailure(
                404,
                'WORD_SENSE_NOT_FOUND',
                'The WordSense was not found.',
            );
        }
        if ($sense->status !== WordSense::STATUS_CONFIRMED) {
            return $this->domainFailure(
                409,
                'WORD_SENSE_DELETED',
                'The WordSense was already removed.',
            );
        }

        $currentVersion = $this->wordSenseVersion->version($sense);
        if (!hash_equals($currentVersion, $payload['expected_word_sense_version'])) {
            return $this->domainFailure(
                409,
                'STALE_WORD_SENSE',
                'The WordSense changed after the queued delete was created.',
                false,
                null,
                ['current_word_sense_version' => $currentVersion],
            );
        }

        $cardId = $sense->reviewCard()->value('id');
        $this->wordSenseService->removeSenseFromReviewSystem($sense, true);

        return [
            'status' => 200,
            'body' => [
                'data' => [
                    'word_sense_id' => $sense->id,
                    'review_card_id' => $cardId,
                    'deleted' => true,
                ],
            ],
        ];
    }

    private function lockedSense(int $senseId, int $userId, string $language): ?WordSense
    {
        return WordSense::query()
            ->lockForUpdate()
            ->whereKey($senseId)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->first();
    }

    private function validateAction(array $action): array
    {
        $validator = Validator::make($action, [
            'client_action_id' => ['required', 'uuid'],
            'type' => ['required', 'in:' . implode(',', [
                self::TYPE_RATING,
                self::TYPE_SENSE_UPDATE,
                self::TYPE_SENSE_DELETE,
                self::TYPE_READING_INTERACTION,
            ])],
            'occurred_at' => ['required', 'string', 'max:40'],
            'sequence' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'payload' => ['required', 'array'],
        ]);
        $validated = $validator->validate();

        $occurredAtValue = $validated['occurred_at'];
        $isIso8601 = preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/',
            $occurredAtValue,
        ) === 1;
        $parsedOccurredAt = $isIso8601 ? date_create_immutable($occurredAtValue) : false;
        $dateErrors = $parsedOccurredAt === false ? false : date_get_last_errors();
        if (
            $parsedOccurredAt === false
            || ($dateErrors !== false
                && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        ) {
            throw ValidationException::withMessages([
                'occurred_at' => ['The occurred at value must be a valid ISO-8601 timestamp.'],
            ]);
        }
        $occurredAt = Carbon::instance($parsedOccurredAt)->utc();
        $payload = match ($validated['type']) {
            self::TYPE_RATING => $this->validateRatingPayload($validated['payload']),
            self::TYPE_SENSE_UPDATE => $this->validateSenseUpdatePayload($validated['payload']),
            self::TYPE_SENSE_DELETE => $this->validateSenseDeletePayload($validated['payload']),
            self::TYPE_READING_INTERACTION => $this->validateReadingInteractionPayload($validated['payload']),
        };

        return [
            'client_action_id' => $validated['client_action_id'],
            'type' => $validated['type'],
            'occurred_at' => $occurredAt,
            'sequence' => (int) $validated['sequence'],
            'payload' => $payload,
        ];
    }

    private function validateRatingPayload(array $payload): array
    {
        $validated = Validator::make($payload, [
            'review_card_id' => ['required', 'integer', 'min:1'],
            'rating' => ['required', 'in:again,hard,good,easy'],
            'review_session_id' => ['nullable', 'uuid'],
            'review_duration_ms' => ['nullable', 'integer', 'min:0', 'max:600000'],
            'question_example_key' => ['nullable', 'regex:/^[a-f0-9]{64}$/'],
            'reading_session_id' => ['nullable', 'required_with:occurrence_id', 'uuid'],
            'occurrence_id' => ['nullable', 'required_with:reading_session_id', 'string', 'max:255'],
        ])->validate();

        return [
            'review_card_id' => (int) $validated['review_card_id'],
            'rating' => (string) $validated['rating'],
            'review_session_id' => isset($validated['review_session_id'])
                ? (string) $validated['review_session_id']
                : null,
            'review_duration_ms' => isset($validated['review_duration_ms'])
                ? (int) $validated['review_duration_ms']
                : null,
            'reading_session_id' => isset($validated['reading_session_id'])
                ? (string) $validated['reading_session_id']
                : null,
            'occurrence_id' => isset($validated['occurrence_id'])
                ? (string) $validated['occurrence_id']
                : null,
            'question_example_key' => !isset($validated['reading_session_id']) && isset($validated['question_example_key'])
                ? (string) $validated['question_example_key']
                : null,
        ];
    }

    private function validateReadingInteractionPayload(array $payload): array
    {
        return Validator::make($payload, [
            'reading_session_id' => ['required', 'uuid'],
            'interaction_type' => ['required', 'in:opened,helped,marked_unknown'],
            'occurrence_id' => ['required', 'string', 'max:255'],
        ])->validate();
    }

    private function readingContractFailure(\InvalidArgumentException $exception): array
    {
        $code = $exception->getMessage();
        $status = match ($code) {
            ReadingSessionService::ERROR_SESSION_NOT_FOUND => 404,
            ReadingSessionService::ERROR_EXPLICIT_CONTEXT_INVALID => 422,
            default => 409,
        };

        return $this->domainFailure($status, $code, 'The reading action could not be applied.');
    }

    private function validateSenseUpdatePayload(array $payload): array
    {
        $allowedChanges = ReviewCardManageMutationService::EDITABLE_FIELDS;
        $extra = array_diff(
            array_keys(is_array($payload['changes'] ?? null) ? $payload['changes'] : []),
            $allowedChanges,
        );
        if ($extra !== []) {
            throw ValidationException::withMessages([
                'payload.changes' => ['The update contains unsupported WordSense fields.'],
            ]);
        }

        return Validator::make($payload, [
            'word_sense_id' => ['required', 'integer', 'min:1'],
            'expected_word_sense_version' => ['required', 'regex:/^sha256:[a-f0-9]{64}$/'],
            'changes' => ['required', 'array', 'min:1'],
            'changes.pos' => ['sometimes', 'nullable', 'string', 'max:50'],
            'changes.sense_zh' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'changes.sense_en' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'changes.example_sentence_en' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'changes.example_sentence_zh' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'changes.aliases_zh' => ['sometimes', 'array', 'max:50'],
            'changes.aliases_zh.*' => ['string', 'max:500'],
            'changes.collocations' => ['sometimes', 'array', 'max:50'],
            'changes.collocations.*' => ['string', 'max:500'],
        ])->validate();
    }

    private function validateSenseDeletePayload(array $payload): array
    {
        return Validator::make($payload, [
            'word_sense_id' => ['required', 'integer', 'min:1'],
            'expected_word_sense_version' => ['required', 'regex:/^sha256:[a-f0-9]{64}$/'],
        ])->validate();
    }

    private function domainFailure(
        int $status,
        string $code,
        string $message,
        bool $retryable = false,
        ?int $retryAfterMs = null,
        ?array $details = null,
    ): array {
        return [
            'status' => $status,
            'body' => [
                'error' => array_filter([
                    'code' => $code,
                    'message' => $message,
                    'retryable' => $retryable,
                    'retry_after_ms' => $retryAfterMs,
                    'details' => $details,
                ], fn ($value) => $value !== null),
            ],
        ];
    }

    private function failureResult(
        array $action,
        int $originalIndex,
        int $processedOrder,
        int $status,
        string $code,
        string $message,
        bool $retryable = false,
        ?int $retryAfterMs = null,
        ?array $details = null,
        bool $replayed = false,
        ?string $operationId = null,
    ): array {
        return [
            'original_index' => $originalIndex,
            'processed_order' => $processedOrder,
            'client_action_id' => $action['client_action_id'] ?? null,
            'type' => $action['type'] ?? null,
            'outcome' => $retryable ? 'retryable' : (
                $status === 409 ? 'conflict' : 'rejected'
            ),
            'http_status' => $status,
            'replayed' => $replayed,
            'operation_id' => $operationId,
            'data' => null,
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'retryable' => $retryable,
                'retry_after_ms' => $retryAfterMs,
                'details' => $details,
            ], fn ($value) => $value !== null),
        ];
    }

    private function sortableTime(mixed $occurredAt): string
    {
        if (!is_string($occurredAt)) {
            return '9999-12-31T23:59:59.999999Z';
        }

        try {
            return Carbon::parse($occurredAt)->utc()->format('Y-m-d\TH:i:s.u\Z');
        } catch (Throwable) {
            return '9999-12-31T23:59:59.999999Z';
        }
    }
}
