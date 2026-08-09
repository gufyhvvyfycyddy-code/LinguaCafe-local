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

    public function index(int $chapterId, Request $request)
    {
        $data = $request->validate([
            'offset' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        return response()->json($this->service->listForChapter(
            Auth::user()->id,
            Auth::user()->selected_language,
            $chapterId,
            (int) ($data['offset'] ?? 0),
            (int) ($data['limit'] ?? 200),
        ));
    }

    public function store(int $chapterId, Request $request)
    {
        $data = $request->validate([
            'occurrence_id' => ['required', 'string'],
            'resolution' => ['required', 'in:matched_existing,new_sense,excluded'],
            'word_sense_id' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $this->service->storeUserDecision(
                Auth::user()->id,
                Auth::user()->selected_language,
                $chapterId,
                $data['occurrence_id'],
                $data['resolution'],
                isset($data['word_sense_id']) ? (int) $data['word_sense_id'] : null,
            );

            return response()->json(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            $isStale = $e->getMessage() === 'READING_OCCURRENCE_STALE';

            return response()->json([
                'success' => false,
                'error_code' => $isStale ? 'READING_OCCURRENCE_STALE' : 'READING_EVIDENCE_INVALID',
                'message' => $isStale
                    ? 'Reading occurrence is stale.'
                    : 'Reading evidence is invalid for the current server state.',
            ], $isStale ? 409 : 422);
        }
    }
}
