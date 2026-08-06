<?php

namespace App\Http\Controllers;

use App\Exceptions\SpecialStudyException;
use App\Services\SpecialStudy\SpecialStudyAnswerService;
use App\Services\SpecialStudy\SpecialStudyOptionsService;
use App\Services\SpecialStudy\SpecialStudySessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpecialStudySessionController extends Controller
{
    public function __construct(
        private readonly SpecialStudySessionService $sessionService,
        private readonly SpecialStudyAnswerService $answerService,
        private readonly SpecialStudyOptionsService $optionsService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'sessions' => $this->sessionService->listSaved(
                $user->id,
                $user->selected_language,
            ),
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json($this->optionsService->get(
            $request->user()->id,
            $request->user()->selected_language,
        ));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->sessionService->create(
            $request->all(),
            $request->user()->id,
            $request->user()->selected_language,
        ), 201);
    }

    public function show(Request $request, string $sessionId): JsonResponse
    {
        return $this->respond(fn () => $this->sessionService->show(
            $sessionId,
            $request->user()->id,
            $request->user()->selected_language,
        ));
    }

    public function save(Request $request, string $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        return $this->respond(fn () => $this->sessionService->save(
            $sessionId,
            $request->user()->id,
            $request->user()->selected_language,
            $validated['name'],
            $validated['expected_revision'],
        ));
    }

    public function rebuild(Request $request, string $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        return $this->respond(fn () => $this->sessionService->rebuild(
            $sessionId,
            $request->user()->id,
            $request->user()->selected_language,
            $validated['expected_revision'],
        ));
    }

    public function end(Request $request, string $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        return $this->respond(fn () => $this->sessionService->end(
            $sessionId,
            $request->user()->id,
            $request->user()->selected_language,
            $validated['expected_revision'],
        ));
    }

    public function answer(Request $request, string $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'rating' => ['required', 'string', 'in:again,hard,good,easy'],
            'client_action_id' => ['required', 'uuid'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'review_duration_ms' => [
                'sometimes',
                'nullable',
                'integer',
                'between:0,3600000',
            ],
        ]);

        return $this->respond(fn () => $this->answerService->answer(
            $sessionId,
            $request->user()->id,
            $request->user()->selected_language,
            $validated['rating'],
            $validated['client_action_id'],
            $validated['expected_revision'],
            $validated['review_duration_ms'] ?? null,
        ));
    }

    private function respond(callable $callback, int $successStatus = 200): JsonResponse
    {
        try {
            return response()->json($callback(), $successStatus);
        } catch (SpecialStudyException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error' => [
                    'reason' => $exception->reason,
                    'field' => $exception->field,
                ],
            ], $exception->httpStatus);
        }
    }
}
