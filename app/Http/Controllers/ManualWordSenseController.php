<?php

namespace App\Http\Controllers;

use App\Models\WordSense;
use App\Services\SenseOccurrencePayloadSerializerService;
use App\Services\WordSenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ManualWordSenseController extends Controller
{
    private const POS_OPTIONS = ['noun', 'verb', 'adjective', 'adverb', 'preposition', 'conjunction', 'phrase', 'other'];

    public function __construct(
        private WordSenseService $wordSenseService,
        private SenseOccurrencePayloadSerializerService $payloadSerializer,
    )
    {
    }

    public function storeManualSense(Request $request)
    {
        $data = $request->validate([
            'lemma' => ['required', 'string'],
            'surface_form' => ['nullable', 'string'],
            'pos' => ['required', Rule::in(self::POS_OPTIONS)],
            'sense_zh' => ['required', 'string'],
            'sense_en' => ['nullable', 'string'],
            'aliases_zh' => ['nullable'],
            'collocations' => ['nullable'],
            'chapter_id' => ['nullable', 'integer'],
            'sentence_id' => ['nullable'],
            'sentence_en' => ['nullable', 'string'],
            'sentence_zh' => ['nullable', 'string'],
            'encountered_word_id' => ['nullable', 'integer'],
            'keep_new' => ['nullable', 'boolean'],
        ]);

        $data['aliases_zh'] = $this->payloadSerializer->normalizeList($request->post('aliases_zh'));
        $data['collocations'] = $this->payloadSerializer->normalizeList($request->post('collocations'));

        $result = $this->wordSenseService->createManualSense(
            Auth::user()->id,
            Auth::user()->selected_language,
            $data,
        );

        $response = $this->payloadSerializer->serializeSense($result['sense']);
        $response['updated_word'] = $result['updated_word'];

        return response()->json($response);
    }

    public function updateManualSense(int $id, Request $request)
    {
        $data = $request->validate([
            'pos' => ['required', Rule::in(self::POS_OPTIONS)],
            'sense_zh' => ['required', 'string'],
            'sense_en' => ['nullable', 'string'],
            'aliases_zh' => ['nullable'],
            'collocations' => ['nullable'],
        ]);

        $data['aliases_zh'] = $this->payloadSerializer->normalizeList($request->post('aliases_zh'));
        $data['collocations'] = $this->payloadSerializer->normalizeList($request->post('collocations'));

        $sense = $this->wordSenseService->updateManualSense(
            Auth::user()->id,
            Auth::user()->selected_language,
            $id,
            $data,
        );

        return response()->json($this->payloadSerializer->serializeSense($sense));
    }

    public function archiveSense(int $id)
    {
        $sense = WordSense::where('id', $id)
            ->where('user_id', Auth::user()->id)
            ->where('language_id', Auth::user()->selected_language)
            ->firstOrFail();

        $sense = $this->wordSenseService->archiveSense($sense);

        return response()->json($this->payloadSerializer->serializeSense($sense->load('reviewCard')));
    }
}
