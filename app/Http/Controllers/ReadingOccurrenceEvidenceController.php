<?php

namespace App\Http\Controllers;

use App\Services\ReadingOccurrenceSenseEvidenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReadingOccurrenceEvidenceController extends Controller
{
    public function __construct(private ReadingOccurrenceSenseEvidenceService $service)
    {
    }

    public function index(int $chapterId)
    {
        return response()->json($this->service->listForChapter(Auth::user()->id, Auth::user()->selected_language, $chapterId));
    }

    public function store(int $chapterId, Request $request)
    {
        $data = $request->validate([
            'occurrence_id' => ['required', 'string'],
            'resolution' => ['required', 'in:matched_existing,new_sense,excluded'],
            'word_sense_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $evidence = $this->service->storeUserDecision(
            Auth::user()->id,
            Auth::user()->selected_language,
            $chapterId,
            $data['occurrence_id'],
            $data['resolution'],
            isset($data['word_sense_id']) ? (int) $data['word_sense_id'] : null,
        );

        return response()->json(['success' => true, 'evidence_id' => $evidence->id]);
    }
}
