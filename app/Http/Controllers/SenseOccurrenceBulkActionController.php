<?php

namespace App\Http\Controllers;

use App\Services\WordSenseOccurrenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SenseOccurrenceBulkActionController extends Controller
{
    public function __construct(
        private WordSenseOccurrenceService $occurrenceService,
    )
    {
    }

    public function bulkConfirm(Request $request)
    {
        $request->validate([
            'occurrence_ids' => ['required', 'array'],
            'occurrence_ids.*' => ['integer'],
            'auto_fsrs_allowed' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->occurrenceService->bulkConfirm(
            Auth::user()->id,
            Auth::user()->selected_language,
            $request->post('occurrence_ids', []),
            (bool) $request->post('auto_fsrs_allowed', false),
        ));
    }

    public function bulkIgnore(Request $request)
    {
        $request->validate([
            'occurrence_ids' => ['required', 'array'],
            'occurrence_ids.*' => ['integer'],
        ]);

        return response()->json($this->occurrenceService->bulkIgnore(
            Auth::user()->id,
            Auth::user()->selected_language,
            $request->post('occurrence_ids', []),
        ));
    }

    public function bulkReject(Request $request)
    {
        $request->validate([
            'occurrence_ids' => ['required', 'array'],
            'occurrence_ids.*' => ['integer'],
        ]);

        return response()->json($this->occurrenceService->bulkReject(
            Auth::user()->id,
            Auth::user()->selected_language,
            $request->post('occurrence_ids', []),
        ));
    }

    public function bulkConfirmHighConfidence(Request $request)
    {
        $request->validate([
            'confidence_min' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'decision' => ['nullable', 'string'],
            'lemma' => ['nullable', 'string'],
            'only_auto_fsrs_allowed' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->occurrenceService->bulkConfirmHighConfidence(
            Auth::user()->id,
            Auth::user()->selected_language,
            [
                'confidence_min' => $request->post('confidence_min', 0.90),
                'decision' => $request->post('decision', 'match_existing_sense'),
                'lemma' => $request->post('lemma'),
                'only_auto_fsrs_allowed' => (bool) $request->post('only_auto_fsrs_allowed', false),
            ],
        ));
    }
}
