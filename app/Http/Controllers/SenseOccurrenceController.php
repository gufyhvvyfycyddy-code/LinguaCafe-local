<?php

namespace App\Http\Controllers;

use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\SenseReviewService;
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
        private SenseReviewService $senseReviewService,
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
            'data' => $occurrences->getCollection()->map(fn (WordSenseOccurrence $occurrence) => $this->serializeOccurrence($occurrence))->values(),
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
            'aliases_zh' => $this->normalizeList($request->post('aliases_zh')),
            'collocations' => $this->normalizeList($request->post('collocations')),
        ], (bool) $request->post('auto_fsrs_allowed', false));

        return response()->json($this->serializeOccurrence($occurrence->load('wordSense.reviewCard')));
    }

    public function reject(int $id)
    {
        $occurrence = $this->occurrenceService->rejectOccurrence($this->findOccurrence($id));

        return response()->json($this->serializeOccurrence($occurrence->load('wordSense.reviewCard')));
    }

    public function examples(int $id)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $sense = WordSense::where('id', $id)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->firstOrFail();

        $occurrences = WordSenseOccurrence::where('user_id', $userId)
            ->where('language_id', $language)
            ->where('word_sense_id', $sense->id)
            ->whereNotNull('sentence_en')
            ->where('sentence_en', '<>', '')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        return response()->json([
            'sense_id' => $sense->id,
            'lemma' => $sense->lemma,
            'occurrences' => $occurrences->map(fn (WordSenseOccurrence $o) => [
                'occurrence_id' => $o->id,
                'sentence_en' => $o->sentence_en,
                'sentence_zh' => $o->sentence_zh,
                'surface' => $o->surface,
                'chapter_id' => $o->chapter_id,
                'status' => $o->status,
                'created_at' => $o->created_at?->toISOString(),
            ])->values(),
        ]);
    }

    public function sourceContext(int $id)
    {
        return response()->json($this->senseReviewService->sourceContext(
            Auth::user()->id,
            Auth::user()->selected_language,
            $id,
        ));
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
        return [
            'occurrence_id' => $occurrence->id,
            'sentence_en' => $occurrence->sentence_en,
            'sentence_zh' => $occurrence->sentence_zh,
            'surface' => $occurrence->surface,
            'lemma' => $occurrence->lemma,
            'pos' => $occurrence->pos,
            'decision' => $occurrence->decision,
            'confidence' => $occurrence->confidence,
            'evidence' => $occurrence->evidence,
            'status' => $occurrence->status,
            'auto_fsrs_allowed' => $occurrence->auto_fsrs_allowed,
            'sense' => $occurrence->wordSense ? $this->serializeSense($occurrence->wordSense) : null,
            'raw_payload' => $occurrence->raw_payload,
        ];
    }

    private function serializeSense(WordSense $sense): array
    {
        return [
            'sense_id' => $sense->id,
            'lemma' => $sense->lemma,
            'pos' => $sense->pos,
            'sense_key' => $sense->sense_key,
            'sense_zh' => $sense->sense_zh,
            'sense_en' => $sense->sense_en,
            'aliases_zh' => $sense->aliases_zh ?: [],
            'collocations' => $sense->collocations ?: [],
            'status' => $sense->status,
            'fsrs_state' => $sense->reviewCard?->fsrs_state,
            'review_card_id' => $sense->reviewCard?->id,
            'fsrs_enabled' => $sense->reviewCard?->fsrs_enabled,
            'fsrs_due_at' => $sense->reviewCard?->fsrs_due_at,
            'fsrs_stability' => $sense->reviewCard?->fsrs_stability,
            'fsrs_difficulty' => $sense->reviewCard?->fsrs_difficulty,
            'fsrs_reps' => $sense->reviewCard?->fsrs_reps,
            'fsrs_lapses' => $sense->reviewCard?->fsrs_lapses,
        ];
    }

    private function normalizeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value), fn ($item) => $item !== ''));
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($item) => $item !== ''));
    }
}
