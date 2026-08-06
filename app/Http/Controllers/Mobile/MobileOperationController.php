<?php

namespace App\Http\Controllers\Mobile;

use App\Exceptions\MobileIdempotencyConflictException;
use App\Exceptions\MobileOperationException;
use App\Exceptions\ReviewCardManualOperationException;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileApiResponse;
use App\Models\MobileDevice;
use App\Services\MobileIdempotencyService;
use App\Services\MobileOperationLedgerService;
use App\Services\ReviewCardManualOperationService;
use App\Services\ReviewStudyTimezoneService;
use Illuminate\Http\Request;

class MobileOperationController extends Controller
{
    public function __construct(
        private MobileIdempotencyService $idempotency,
        private MobileOperationLedgerService $ledger,
        private ReviewCardManualOperationService $manualOperations,
        private ReviewStudyTimezoneService $studyTimezone,
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'review_session_id' => ['nullable', 'uuid'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'before_sequence' => ['nullable', 'integer', 'min:1'],
        ]);
        $user = $request->user();

        return MobileApiResponse::success($this->ledger->recent(
            $user->id,
            $user->selected_language,
            $validated['review_session_id'] ?? null,
            $validated['limit'] ?? 20,
            $validated['before_sequence'] ?? null,
        ));
    }

    public function undo(string $operationId, Request $request)
    {
        return $this->transition('undo', $operationId, $request);
    }

    public function redo(string $operationId, Request $request)
    {
        return $this->transition('redo', $operationId, $request);
    }

    public function previewManual(int $reviewCard, Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', 'string'],
            'options' => ['nullable', 'array'],
        ]);
        $user = $request->user();

        try {
            return MobileApiResponse::success($this->manualOperations->preview(
                $user->id,
                $user->selected_language,
                $reviewCard,
                $validated['action'],
                $validated['options'] ?? [],
                $this->studyTimezone->getStudyTimezone(),
            ));
        } catch (ReviewCardManualOperationException $exception) {
            return MobileApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
            );
        }
    }

    public function applyManual(int $reviewCard, Request $request)
    {
        $validated = $request->validate([
            'client_action_id' => ['required', 'uuid'],
            'action' => ['required', 'string'],
            'options' => ['nullable', 'array'],
            'expected_state_fingerprint' => [
                'required',
                'string',
                'size:64',
                'regex:/^[a-f0-9]{64}$/',
            ],
        ]);
        $user = $request->user();
        /** @var MobileDevice $device */
        $device = $request->attributes->get('mobile_device');
        $requestPayload = [
            'review_card_id' => $reviewCard,
            'action' => $validated['action'],
            'options' => $validated['options'] ?? [],
            'expected_state_fingerprint' => $validated['expected_state_fingerprint'],
        ];

        try {
            $result = $this->idempotency->execute(
                $user->id,
                $device,
                'review_control.apply',
                $validated['client_action_id'],
                $requestPayload,
                function (string $operationId) use (
                    $user,
                    $device,
                    $reviewCard,
                    $validated,
                ) {
                    $body = $this->manualOperations->apply(
                        $operationId,
                        $user->id,
                        $user->selected_language,
                        $reviewCard,
                        $validated['action'],
                        $validated['options'] ?? [],
                        $validated['expected_state_fingerprint'],
                        $this->studyTimezone->getStudyTimezone(),
                        'mobile',
                        $device,
                    );

                    return ['status' => 200, 'body' => $body];
                },
            );
        } catch (MobileIdempotencyConflictException) {
            return MobileApiResponse::error(
                'IDEMPOTENCY_KEY_REUSED',
                'The client action id was already used with a different request.',
                409,
            );
        } catch (ReviewCardManualOperationException $exception) {
            return MobileApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
            );
        }

        $body = $result['body'];
        $body['replayed'] = $result['replayed'];

        return MobileApiResponse::success($body, $result['status']);
    }

    private function transition(
        string $direction,
        string $operationId,
        Request $request,
    ) {
        $validated = $request->validate([
            'client_action_id' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);
        $user = $request->user();
        /** @var MobileDevice $device */
        $device = $request->attributes->get('mobile_device');
        $requestPayload = [
            'operation_id' => $operationId,
            'expected_version' => $validated['expected_version'],
        ];

        try {
            $result = $this->idempotency->execute(
                $user->id,
                $device,
                "operation.{$direction}",
                $validated['client_action_id'],
                $requestPayload,
                function (string $_requestOperationId) use (
                    $direction,
                    $operationId,
                    $user,
                    $device,
                    $validated,
                ) {
                    $body = $this->ledger->{$direction}(
                        $operationId,
                        $user->id,
                        $user->selected_language,
                        $device,
                        $validated['expected_version'],
                        $validated['client_action_id'],
                    );

                    return ['status' => 200, 'body' => $body];
                },
            );
        } catch (MobileIdempotencyConflictException) {
            return MobileApiResponse::error(
                'IDEMPOTENCY_KEY_REUSED',
                'The client action id was already used with a different request.',
                409,
            );
        } catch (MobileOperationException $exception) {
            return MobileApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        $body = $result['body'];
        $body['replayed'] = $result['replayed'];

        return MobileApiResponse::success($body, $result['status']);
    }
}
