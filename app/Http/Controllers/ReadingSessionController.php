<?php

namespace App\Http\Controllers;

use App\Services\ReadingSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReadingSessionController extends Controller
{
    public function __construct(private ReadingSessionService $service)
    {
    }

    public function store(int $chapterId, Request $request)
    {
        $data = $request->validate([
            'resume_reading_session_id' => ['nullable', 'string', 'uuid'],
        ]);

        try {
            $result = $this->service->startSession(
                Auth::user()->id,
                Auth::user()->selected_language,
                $chapterId,
                $data['resume_reading_session_id'] ?? null,
            );

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return $this->contractError($e);
        }
    }

    public function recordInteraction(Request $request)
    {
        $data = $request->validate([
            'reading_session_id' => ['required', 'string', 'uuid'],
            'interaction_type' => ['required', 'in:opened,helped'],
            'occurrence_id' => ['required', 'string'],
        ]);

        try {
            return response()->json($this->service->recordOccurrenceInteraction(
                Auth::user()->id,
                Auth::user()->selected_language,
                $data['reading_session_id'],
                $data['interaction_type'],
                $data['occurrence_id'],
            ));
        } catch (\InvalidArgumentException $e) {
            return $this->contractError($e);
        }
    }

    private function contractError(\InvalidArgumentException $exception)
    {
        $code = $exception->getMessage();
        $statuses = [
            ReadingSessionService::ERROR_SESSION_NOT_FOUND => 404,
            ReadingSessionService::ERROR_SESSION_NOT_ACTIVE => 409,
            ReadingSessionService::ERROR_SESSION_CHAPTER_MISMATCH => 409,
            ReadingSessionService::ERROR_SESSION_STALE_SOURCE => 409,
            ReadingSessionService::ERROR_OCCURRENCE_STALE => 409,
            ReadingSessionService::ERROR_EXPLICIT_CONTEXT_INVALID => 422,
        ];
        if (!isset($statuses[$code])) {
            $code = 'READING_SESSION_CONFLICT';
            $statuses[$code] = 409;
        }

        return response()->json([
            'success' => false,
            'error_code' => $code,
            'message' => 'Reading request conflicts with the current server state.',
        ], $statuses[$code]);
    }
}
