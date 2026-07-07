<?php

namespace App\Http\Controllers;

use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\SenseOccurrenceExampleService;
use App\Services\SenseOccurrencePayloadSerializerService;
use App\Services\WordSenseKnownSenseService;
use App\Services\WordSenseOccurrenceService;
use App\Services\WordSenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class SenseOccurrenceController extends Controller
{
    private const POS_OPTIONS = ['noun', 'verb', 'adjective', 'adverb', 'preposition', 'conjunction', 'phrase', 'other'];

    public function __construct(
        private WordSenseOccurrenceService $occurrenceService,
        private WordSenseService $wordSenseService,
        private WordSenseKnownSenseService $knownSenseService,
        private SenseOccurrencePayloadSerializerService $payloadSerializer,
        private SenseOccurrenceExampleService $exampleService,
    )
    {
    }

    public function index(Request $request)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        if ($request->query('language') && $request->query('language') !== $language) {
            abort(403, 'Language does not match the selected language.');
        }

        $occurrences = $this->occurrenceService->listOccurrences($userId, $language, [
            'status' => $request->query('status'),
            'lemma' => $request->query('lemma'),
            'decision' => $request->query('decision'),
            'confidence_min' => $request->query('confidence_min'),
            'auto_fsrs_allowed' => $request->query('auto_fsrs_allowed'),
            'per_page' => $request->query('per_page', 20),
        ]);

        return response()->json([
            'data' => $occurrences->getCollection()->map(fn (WordSenseOccurrence $occurrence) => $this->payloadSerializer->serializeOccurrence($occurrence))->values(),
            'summary' => $this->occurrenceService->statusSummary($userId, $language),
            'pagination' => [
                'current_page' => $occurrences->currentPage(),
                'per_page' => $occurrences->perPage(),
                'total' => $occurrences->total(),
                'last_page' => $occurrences->lastPage(),
            ],
        ]);
    }

    public function candidates(Request $request)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        if ($request->query('language') && $request->query('language') !== $language) {
            abort(403, 'Language does not match the selected language.');
        }

        $lemma = (string) $request->query('lemma');
        if ($lemma === '') {
            abort(422, 'The lemma parameter is required.');
        }

        $senses = $this->occurrenceService->candidates($userId, $language, $lemma, $request->query('pos'));

        return response()->json($senses->map(fn (WordSense $sense) => $this->serializeSense($sense))->values());
    }

    /**
     * Return confirmed WordSense candidates for a lemma (Trae-LemmaKnownSenseBridge-1).
     *
     * Read-only payload used by the vocabulary box to render an
     * "已学词义候选" panel and a "熟词僻义" hint. Does not write anything.
     */
    public function knownSenseLookup(Request $request)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        if ($request->query('language') && $request->query('language') !== $language) {
            abort(403, 'Language does not match the selected language.');
        }

        $lemma = (string) $request->query('lemma');
        if ($lemma === '') {
            abort(422, 'The lemma parameter is required.');
        }

        return response()->json(
            $this->knownSenseService->knownSenseLookupPayload($userId, $language, $lemma)
        );
    }

    /**
     * Return a READ-ONLY inline preview payload for the reading page
     * (GLM-ReadingInlinePreview-First-1).
     *
     * This endpoint powers the "InlineSensePreviewPanel" shown after the user
     * clicks a token in the reading page. It returns:
     *  - the lemma / surface / sentence passed through for display;
     *  - confirmed WordSense candidates for this lemma (with read-only FSRS
     *    status summary per candidate);
     *  - a hard safety_flags contract proving nothing is written.
     *
     * The "是这个意思 / 不是这个意思" buttons on the frontend are FRONT-END
     * ONLY this round — they do not call any POST endpoint, do not record
     * the user's choice, do not write ReviewLog / FSRS / WordSense /
     * ReviewCard. This GET endpoint is the only backend call involved.
     */
    public function inlinePreview(Request $request)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        if ($request->query('language') && $request->query('language') !== $language) {
            abort(403, 'Language does not match the selected language.');
        }

        $lemma = (string) $request->query('lemma');
        if ($lemma === '') {
            abort(422, 'The lemma parameter is required.');
        }

        $surface = (string) $request->query('surface', '');
        $sentence = (string) $request->query('sentence', '');
        $chapterId = $request->query('chapter_id') !== null ? (int) $request->query('chapter_id') : null;
        $sentenceIndex = $request->query('sentence_index') !== null ? (int) $request->query('sentence_index') : null;

        return response()->json(
            $this->knownSenseService->previewInlineSenseCandidates(
                $userId,
                $language,
                $lemma,
                $surface,
                $sentence,
                $chapterId,
                $sentenceIndex
            )
        );
    }

    public function possibleDuplicates(Request $request)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        if ($request->query('language') && $request->query('language') !== $language) {
            abort(403, 'Language does not match the selected language.');
        }

        return response()->json($this->occurrenceService->possibleDuplicates($userId, $language, $request->query('lemma')));
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

        $data['aliases_zh'] = $this->normalizeList($request->post('aliases_zh'));
        $data['collocations'] = $this->normalizeList($request->post('collocations'));

        $result = $this->wordSenseService->createManualSense(
            Auth::user()->id,
            Auth::user()->selected_language,
            $data,
        );

        $response = $this->serializeSense($result['sense']);
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

        $data['aliases_zh'] = $this->normalizeList($request->post('aliases_zh'));
        $data['collocations'] = $this->normalizeList($request->post('collocations'));

        $sense = $this->wordSenseService->updateManualSense(
            Auth::user()->id,
            Auth::user()->selected_language,
            $id,
            $data,
        );

        return response()->json($this->serializeSense($sense));
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

    public function examples(int $id)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        return response()->json(
            $this->exampleService->getExamples($userId, $language, $id)
        );
    }

    public function archiveSense(int $id)
    {
        $sense = WordSense::where('id', $id)
            ->where('user_id', Auth::user()->id)
            ->where('language_id', Auth::user()->selected_language)
            ->firstOrFail();

        $sense = $this->wordSenseService->archiveSense($sense);

        return response()->json($this->serializeSense($sense->load('reviewCard')));
    }

    private function serializeOccurrence(WordSenseOccurrence $occurrence): array
    {
        return $this->payloadSerializer->serializeOccurrence($occurrence);
    }

    private function serializeSense(WordSense $sense): array
    {
        return $this->payloadSerializer->serializeSense($sense);
    }

    private function normalizeList(mixed $value): array
    {
        return $this->payloadSerializer->normalizeList($value);
    }
}
