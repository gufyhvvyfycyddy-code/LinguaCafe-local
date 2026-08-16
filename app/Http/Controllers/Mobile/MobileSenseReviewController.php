<?php

namespace App\Http\Controllers\Mobile;

use App\Exceptions\MobileIdempotencyConflictException;
use App\Exceptions\MobileQueuedActionDomainException;
use App\Exceptions\MobileReviewCardUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileApiResponse;
use App\Models\MobileDevice;
use App\Services\MobileIdempotencyService;
use App\Services\MobileSenseReviewMutationService;
use App\Services\ReadingSessionService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MobileSenseReviewController extends Controller
{
    public function __construct(
        private MobileIdempotencyService $idempotency,
        private MobileSenseReviewMutationService $mutationService,
    ) {}

    public function store(int $reviewCard, Request $request)
    {
        $validated = $request->validate([
            'rating' => ['required', 'in:again,hard,good,easy'],
            'client_action_id' => ['required', 'uuid'],
            'review_session_id' => ['nullable', 'uuid'],
            'review_duration_ms' => ['nullable', 'integer', 'min:0', 'max:600000'],
            'reading_session_id' => ['nullable', 'required_with:occurrence_id', 'uuid'],
            'occurrence_id' => ['nullable', 'required_with:reading_session_id', 'string', 'max:255'],
        ]);

        $user = $request->user();
        /** @var MobileDevice $device */
        $device = $request->attributes->get('mobile_device');
        $rating = (string) $validated['rating'];
        $clientActionId = (string) $validated['client_action_id'];
        $reviewSessionId = isset($validated['review_session_id'])
            ? (string) $validated['review_session_id']
            : null;
        $reviewDurationMs = isset($validated['review_duration_ms'])
            ? (int) $validated['review_duration_ms']
            : null;
        $readingSessionId = isset($validated['reading_session_id'])
            ? (string) $validated['reading_session_id']
            : null;
        $occurrenceId = isset($validated['occurrence_id'])
            ? (string) $validated['occurrence_id']
            : null;

        $requestPayload = MobileSenseReviewMutationService::idempotencyPayload(
            $reviewCard,
            $rating,
            $reviewSessionId,
            $reviewDurationMs,
            $readingSessionId,
            $occurrenceId,
        );
        $occurredAt = Carbon::now();

        try {
            $result = $this->idempotency->execute(
                $user->id,
                $device,
                'sense_review.rating',
                $clientActionId,
                $requestPayload,
                function (string $operationId) use (
                    $user,
                    $device,
                    $reviewCard,
                    $rating,
                    $clientActionId,
                    $reviewSessionId,
                    $reviewDurationMs,
                    $occurredAt,
                    $readingSessionId,
                    $occurrenceId,
                ) {
                    $body = $this->mutationService->apply(
                        $operationId,
                        $user->id,
                        $user->selected_language,
                        $device,
                        $reviewCard,
                        $rating,
                        $reviewSessionId,
                        $reviewDurationMs,
                        $occurredAt,
                        null,
                        null,
                        $readingSessionId,
                        $occurrenceId,
                        $readingSessionId ? $clientActionId : null,
                    );
                    $body['client_action_id'] = $clientActionId;

                    return [
                        'status' => 200,
                        'body' => ['data' => $body],
                    ];
                },
            );
        } catch (MobileIdempotencyConflictException) {
            return MobileApiResponse::error(
                'IDEMPOTENCY_KEY_REUSED',
                'The client action id was already used with a different request.',
                409,
            );
        } catch (MobileReviewCardUnavailableException) {
            return MobileApiResponse::error(
                'REVIEW_CARD_NOT_FOUND',
                'The sense review card does not exist or is not reviewable.',
                404,
            );
        } catch (MobileQueuedActionDomainException $exception) {
            return MobileApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
            );
        } catch (\InvalidArgumentException $exception) {
            if ($readingSessionId === null) {
                throw $exception;
            }

            return $this->readingContractError($exception);
        }

        $body = $result['body']['data'];
        $body['client_action_id'] = $clientActionId;
        $body['replayed'] = $result['replayed'];

        return MobileApiResponse::success($body, $result['status']);
    }

    private function readingContractError(\InvalidArgumentException $exception)
    {
        $code = $exception->getMessage();
        $status = match ($code) {
            ReadingSessionService::ERROR_SESSION_NOT_FOUND => 404,
            ReadingSessionService::ERROR_EXPLICIT_CONTEXT_INVALID => 422,
            ReadingSessionService::ERROR_SESSION_NOT_ACTIVE,
            ReadingSessionService::ERROR_SESSION_CHAPTER_MISMATCH,
            ReadingSessionService::ERROR_SESSION_STALE_SOURCE,
            ReadingSessionService::ERROR_OCCURRENCE_STALE,
            ReadingSessionService::ERROR_EXPLICIT_ACTION_UNDONE,
            ReadingSessionService::ERROR_EXPLICIT_ACTION_ACTIVE => 409,
            default => 422,
        };

        return MobileApiResponse::error($code, 'The reading action could not be applied.', $status);
    }

}
