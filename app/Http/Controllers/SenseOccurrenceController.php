<?php

namespace App\Http\Controllers;

use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\SenseOccurrenceExampleService;
use App\Services\SenseOccurrencePayloadSerializerService;
use App\Services\WordSenseKnownSenseService;
use App\Services\WordSenseOccurrenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SenseOccurrenceController extends Controller
{
    public function __construct(
        private WordSenseOccurrenceService $occurrenceService,
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

    public function examples(int $id)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        return response()->json(
            $this->exampleService->getExamples($userId, $language, $id)
        );
    }

    private function serializeOccurrence(WordSenseOccurrence $occurrence): array
    {
        return $this->payloadSerializer->serializeOccurrence($occurrence);
    }

    private function serializeSense(WordSense $sense): array
    {
        return $this->payloadSerializer->serializeSense($sense);
    }
}
