<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\MobileApiResponse;
use App\Services\ReadingFinishSettlementService;
use App\Services\ReadingContinuityService;
use App\Services\ReadingSessionService;
use Illuminate\Http\Request;

class MobileReadingSessionController extends Controller
{
    public function __construct(
        private ReadingSessionService $readingSessionService,
        private ReadingFinishSettlementService $finishSettlementService,
        private ReadingContinuityService $readingContinuityService,
    ) {
    }

    public function store(int $chapter, Request $request)
    {
        $validated = $request->validate([
            'resume_reading_session_id' => ['nullable', 'uuid'],
        ]);

        try {
            $data = $this->readingSessionService->startSession(
                $request->user()->id,
                $request->user()->selected_language,
                $chapter,
                $validated['resume_reading_session_id'] ?? null,
            );
            $data['continuity'] = $this->readingContinuityService->current(
                $request->user()->id,
                $request->user()->selected_language,
                $chapter,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->contractError($exception);
        }

        return MobileApiResponse::success($data);
    }

    public function finish(int $chapter, string $readingSession, Request $request)
    {
        $validated = $request->validate([
            'settlement_mode' => ['required', 'in:preflight,commit'],
        ]);

        try {
            $data = $this->finishSettlementService->finishChapterWithSession(
                $request->user()->id,
                $request->user()->selected_language,
                $chapter,
                $readingSession,
                false,
                [],
                false,
                [],
                [],
                $validated['settlement_mode'],
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->contractError($exception);
        }

        return MobileApiResponse::success($data);
    }

    private function contractError(\InvalidArgumentException $exception)
    {
        $code = $exception->getMessage();
        $status = match ($code) {
            ReadingSessionService::ERROR_SESSION_NOT_FOUND => 404,
            ReadingSessionService::ERROR_EXPLICIT_CONTEXT_INVALID,
            'READING_FINISH_MODE_INVALID' => 422,
            default => 409,
        };

        return MobileApiResponse::error($code, 'The reading session request could not be applied.', $status);
    }
}
