<?php

namespace App\Http\Controllers;

use App\Services\ReadingUnfamiliarTargetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReadingUnfamiliarTargetController extends Controller
{
    public function __construct(private ReadingUnfamiliarTargetService $service)
    {
    }

    public function index(int $chapterId)
    {
        return response()->json($this->service->listCurrentTargets(Auth::user()->id, Auth::user()->selected_language, $chapterId));
    }

    public function store(int $chapterId, Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', 'in:word,phrase'],
            'start_word_index' => ['required', 'integer', 'min:0'],
            'end_word_index' => ['required', 'integer', 'min:0'],
        ]);

        $this->service->createTarget(
            Auth::user()->id,
            Auth::user()->selected_language,
            $chapterId,
            $data['kind'],
            (int) $data['start_word_index'],
            (int) $data['end_word_index'],
        );

        return response()->json(['success' => true]);
    }

    public function destroy(int $chapterId, string $occurrenceId)
    {
        $this->service->deleteCurrentTarget(
            Auth::user()->id,
            Auth::user()->selected_language,
            $chapterId,
            $occurrenceId,
        );

        return response()->json(['success' => true]);
    }
}
