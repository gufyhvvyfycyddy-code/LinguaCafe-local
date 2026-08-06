<?php

namespace App\Http\Controllers;

use App\Exceptions\MobileOperationException;
use App\Exceptions\ReviewCardManualOperationException;
use App\Services\MobileOperationLedgerService;
use App\Services\ReviewCardManualOperationService;
use App\Services\ReviewStudyTimezoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReviewCardManualOperationController extends Controller
{
    public function __construct(
        private ReviewCardManualOperationService $manualOperations,
        private MobileOperationLedgerService $ledger,
        private ReviewStudyTimezoneService $studyTimezone,
    ) {}

    public function preview(Request $request, int $reviewCard): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string'],
            'options' => ['nullable', 'array'],
        ]);

        try {
            return response()->json($this->manualOperations->preview(
                Auth::id(),
                Auth::user()->selected_language,
                $reviewCard,
                $validated['action'],
                $validated['options'] ?? [],
                $this->studyTimezone->getStudyTimezone(),
            ));
        } catch (ReviewCardManualOperationException $exception) {
            return $this->manualError($exception);
        }
    }

    public function apply(Request $request, int $reviewCard): JsonResponse
    {
        $validated = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'action' => ['required', 'string'],
            'options' => ['nullable', 'array'],
            'expected_state_fingerprint' => [
                'required',
                'string',
                'size:64',
                'regex:/^[a-f0-9]{64}$/',
            ],
        ]);

        try {
            return response()->json($this->manualOperations->apply(
                $validated['operation_id'],
                Auth::id(),
                Auth::user()->selected_language,
                $reviewCard,
                $validated['action'],
                $validated['options'] ?? [],
                $validated['expected_state_fingerprint'],
                $this->studyTimezone->getStudyTimezone(),
                'web',
            ));
        } catch (ReviewCardManualOperationException $exception) {
            return $this->manualError($exception);
        }
    }

    public function undo(Request $request, string $operationId): JsonResponse
    {
        return $this->transition('undo', $operationId, $request);
    }

    public function redo(Request $request, string $operationId): JsonResponse
    {
        return $this->transition('redo', $operationId, $request);
    }

    private function transition(
        string $direction,
        string $operationId,
        Request $request,
    ): JsonResponse {
        $validated = $request->validate([
            'client_action_id' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        try {
            return response()->json($this->ledger->{$direction}(
                $operationId,
                Auth::id(),
                Auth::user()->selected_language,
                null,
                $validated['expected_version'],
                $validated['client_action_id'],
                'web',
            ));
        } catch (MobileOperationException $exception) {
            return response()->json([
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        }
    }

    private function manualError(
        ReviewCardManualOperationException $exception,
    ): JsonResponse {
        return response()->json([
            'code' => $exception->errorCode,
            'message' => $exception->getMessage(),
        ], $exception->status);
    }
}
