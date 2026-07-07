<?php

namespace App\Http\Controllers;

use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\SenseOccurrencePayloadSerializerService;
use App\Services\WordSenseOccurrenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SenseOccurrenceActionController extends Controller
{
    public function __construct(
        private WordSenseOccurrenceService $occurrenceService,
        private SenseOccurrencePayloadSerializerService $payloadSerializer,
    )
    {
    }

    public function confirm(int $id)
    {
        $occurrence = $this->findOccurrence($id);
        $occurrence = $this->occurrenceService->confirmOccurrence($occurrence);

        return response()->json($this->serializeOccurrence($occurrence->load('wordSense.reviewCard')));
    }

    public function bind(int $id, Request $request)
    {
        $request->validate([
            'sense_id' => ['required', 'integer'],
            'auto_fsrs_allowed' => ['sometimes', 'boolean'],
        ]);

        $occurrence = $this->findOccurrence($id);
        $sense = WordSense::where('id', (int) $request->post('sense_id'))
            ->where('user_id', Auth::user()->id)
            ->where('language_id', Auth::user()->selected_language)
            ->firstOrFail();

        $occurrence = $this->occurrenceService->bindOccurrenceToSense(
            $occurrence,
            $sense,
            (bool) $request->post('auto_fsrs_allowed', false),
        );

        return response()->json($this->serializeOccurrence($occurrence->load('wordSense.reviewCard')));
    }

    public function createSense(int $id, Request $request)
    {
        $request->validate([
            'sense_zh' => ['required', 'string'],
            'sense_en' => ['nullable', 'string'],
            'pos' => ['nullable', 'string'],
            'aliases_zh' => ['nullable'],
            'collocations' => ['nullable'],
            'auto_fsrs_allowed' => ['sometimes', 'boolean'],
        ]);

        $occurrence = $this->findOccurrence($id);
        $occurrence = $this->occurrenceService->createConfirmedSenseFromOccurrence($occurrence, [
            'sense_zh' => $request->post('sense_zh'),
            'sense_en' => $request->post('sense_en'),
            'pos' => $request->post('pos'),
            'aliases_zh' => $this->payloadSerializer->normalizeList($request->post('aliases_zh')),
            'collocations' => $this->payloadSerializer->normalizeList($request->post('collocations')),
        ], (bool) $request->post('auto_fsrs_allowed', false));

        return response()->json($this->serializeOccurrence($occurrence->load('wordSense.reviewCard')));
    }

    public function reject(int $id)
    {
        $occurrence = $this->occurrenceService->rejectOccurrence($this->findOccurrence($id));

        return response()->json($this->serializeOccurrence($occurrence->load('wordSense.reviewCard')));
    }

    public function ignore(int $id)
    {
        $occurrence = $this->occurrenceService->ignoreOccurrence($this->findOccurrence($id));

        return response()->json($this->serializeOccurrence($occurrence->load('wordSense.reviewCard')));
    }

    private function findOccurrence(int $id): WordSenseOccurrence
    {
        return WordSenseOccurrence::where('id', $id)
            ->where('user_id', Auth::user()->id)
            ->where('language_id', Auth::user()->selected_language)
            ->firstOrFail();
    }

    private function serializeOccurrence(WordSenseOccurrence $occurrence): array
    {
        return $this->payloadSerializer->serializeOccurrence($occurrence);
    }
}
