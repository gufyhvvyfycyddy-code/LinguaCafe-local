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

    public function store(int $chapterId)
    {
        return response()->json($this->service->startSession(Auth::user()->id, Auth::user()->selected_language, $chapterId));
    }

    public function recordInteraction(Request $request)
    {
        $data = $request->validate([
            'reading_session_id' => ['required', 'string', 'uuid'],
            'interaction_type' => ['required', 'in:opened,helped'],
            'occurrence_id' => ['required', 'string'],
        ]);

        return response()->json($this->service->recordOccurrenceInteraction(
            Auth::user()->id,
            Auth::user()->selected_language,
            $data['reading_session_id'],
            $data['interaction_type'],
            $data['occurrence_id'],
        ));
    }
}
