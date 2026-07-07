<?php

namespace App\Http\Controllers;

use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\ReadingInlineSenseConfirmationService;
use App\Services\SenseOccurrenceExampleService;
use App\Services\SenseOccurrencePayloadSerializerService;
use App\Services\SenseSourceContextService;
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
        private SenseSourceContextService $senseSourceContextService,
        private WordSenseKnownSenseService $knownSenseService,
        private ReadingInlineSenseConfirmationService $inlineConfirmationService,
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

    /**
     * Persist (or update) the user's match / not_match choice for a
     * reading-inline sense candidate (ADR-0003).
     *
     * This endpoint is the ONLY write entrypoint for the
     * `reading_inline_sense_confirmations` table. It does NOT write
     * ReviewLog, does NOT change FSRS, does NOT create WordSense /
     * ReviewCard, does NOT call AI. The choice is NOT a review rating.
     */
    public function storeInlineConfirmation(Request $request)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $data = $request->validate([
            'language' => ['sometimes', 'string'],
            'lemma' => ['required', 'string'],
            'surface' => ['required', 'string'],
            'chapter_id' => ['nullable', 'integer'],
            'sentence_index' => ['nullable', 'integer'],
            'sentence_hash' => ['nullable', 'string'],
            'sentence_text' => ['nullable', 'string'],
            'word_sense_id' => ['required', 'integer'],
            'choice' => ['required', Rule::in(['match', 'not_match'])],
        ]);

        if (isset($data['language']) && $data['language'] !== $language) {
            abort(403, 'Language does not match the selected language.');
        }

        $result = $this->inlineConfirmationService->storeConfirmation([
            'user_id' => $userId,
            'language' => $language,
            'chapter_id' => $data['chapter_id'] ?? null,
            'sentence_index' => $data['sentence_index'] ?? null,
            'sentence_hash' => $data['sentence_hash'] ?? null,
            'sentence_text' => $data['sentence_text'] ?? null,
            'surface' => $data['surface'],
            'lemma' => $data['lemma'],
            'word_sense_id' => $data['word_sense_id'],
            'choice' => $data['choice'],
        ]);

        // Also return the updated preview payload so the frontend can refresh
        // the echoed persisted_choice without a second round-trip.
        $previewPayload = $this->knownSenseService->previewInlineSenseCandidates(
            $userId,
            $language,
            $data['lemma'],
            $data['surface'],
            $data['sentence_text'] ?? '',
            $data['chapter_id'] ?? null,
            $data['sentence_index'] ?? null
        );

        return response()->json([
            'confirmation_id' => $result['confirmation_id'],
            'choice' => $result['choice'],
            'persisted' => $result['persisted'],
            'updated_at' => $result['updated_at'],
            'safety_flags' => $result['safety_flags'],
            'updated_preview' => $previewPayload,
            'undo_token' => $result['undo_token'],
            'undo_expires_at' => $result['undo_expires_at'],
            'undo_hint' => $result['undo_hint'],
        ]);
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

    /**
     * Read-only management list: return the current user's reading-inline
     * confirmations with filters + WordSense / Chapter summary
     * (ADR-0003 Management Surface Layer).
     *
     * This endpoint is READ-ONLY. It does NOT write any table. It does NOT
     * call ReviewLog / FSRS / AI. It is isolated by user + language.
     */
    public function listInlineConfirmations(Request $request)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        if ($request->query('language') && $request->query('language') !== $language) {
            abort(403, 'Language does not match the selected language.');
        }

        $filters = [
            'choice' => $request->query('choice', 'all'),
            'lemma' => $request->query('lemma'),
            'surface' => $request->query('surface'),
            'word_sense_id' => $request->query('word_sense_id') !== null ? (int) $request->query('word_sense_id') : null,
            'chapter_id' => $request->query('chapter_id') !== null ? (int) $request->query('chapter_id') : null,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'per_page' => (int) $request->query('per_page', 20),
        ];

        return response()->json(
            $this->inlineConfirmationService->listConfirmationsForManagement($userId, $language, $filters)
        );
    }

    /**
     * Revoke (delete) a single reading-inline confirmation owned by the
     * current user + current language (ADR-0003 Management Surface Layer).
     *
     * This endpoint ONLY deletes a row in `reading_inline_sense_confirmations`.
     * It does NOT delete WordSense / ReviewCard / ReviewLog / EncounteredWord.
     * It does NOT call ReviewLog::create / FSRS / AI. It is NOT a review rating.
     *
     * Returns a backend-signed `undo_token` so the user can press Ctrl+Z
     * to restore the revoked row (OpenCode-ReadingInlineConfirmationUndoHotkey-800-1).
     */
    public function revokeInlineConfirmation(int $id)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $result = $this->inlineConfirmationService->revokeConfirmation($userId, $language, $id);

        return response()->json($result);
    }

    /**
     * Undo the most recent reading-inline-confirmation action described
     * by a backend-signed undo token
     * (OpenCode-ReadingInlineConfirmationUndoHotkey-800-1, ADR-0003 Undo Hotkey Layer).
     *
     * The token is returned by `POST /senses/inline-confirmation` (store)
     * or `DELETE /senses/inline-confirmations/{id}` (revoke) and is short-lived.
     *
     * This endpoint ONLY performs INSERT / UPDATE / DELETE on the
     * `reading_inline_sense_confirmations` table. It does NOT write
     * ReviewLog, does NOT change FSRS, does NOT create / delete
     * WordSense / ReviewCard, does NOT call AI. It is NOT a review rating.
     */
    public function undoInlineConfirmation(Request $request)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $data = $request->validate([
            'undo_token' => ['required', 'string'],
            // Optional: lemma / surface / chapter_id / sentence_index allow
            // the backend to also return an updated preview payload for the
            // reading page so the frontend can refresh without a second
            // round-trip.
            'lemma' => ['sometimes', 'string'],
            'surface' => ['sometimes', 'string'],
            'sentence' => ['sometimes', 'string'],
            'chapter_id' => ['sometimes', 'nullable', 'integer'],
            'sentence_index' => ['sometimes', 'nullable', 'integer'],
        ]);

        $result = $this->inlineConfirmationService->undoLastInlineConfirmationAction(
            $userId,
            $language,
            (string) $data['undo_token']
        );

        // If the caller passed lemma/surface/sentence/chapter/sentence_index,
        // also return an updated preview payload so the reading page can
        // refresh the echoed persisted_choice without a second round-trip.
        if (isset($data['lemma']) && $data['lemma'] !== '') {
            $previewPayload = $this->knownSenseService->previewInlineSenseCandidates(
                $userId,
                $language,
                (string) $data['lemma'],
                (string) ($data['surface'] ?? ''),
                (string) ($data['sentence'] ?? ''),
                isset($data['chapter_id']) ? (int) $data['chapter_id'] : null,
                isset($data['sentence_index']) ? (int) $data['sentence_index'] : null
            );
            $result['updated_preview'] = $previewPayload;
        }

        return response()->json($result);
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

        return response()->json(
            $this->exampleService->getExamples($userId, $language, $id)
        );
    }

    public function sourceContext(int $id)
    {
        return response()->json($this->senseSourceContextService->sourceContext(
            Auth::user()->id,
            Auth::user()->selected_language,
            $id,
        ));
    }

    /**
     * Multi-source variant of sourceContext: returns a list of distinct
     * chapter-based source contexts (up to 3) for the review page source
     * dialog carousel. Falls back to a single-entry list when no
     * chapter-based sources are available.
     *
     * SenseSourceContextFollowDisplayedOccurrence-1000-7:
     * Accepts an optional ?preferred_occurrence_id= query parameter. When
     * supplied, the service attempts to place that occurrence's source
     * context at sources[0] so the source dialog opens on the example the
     * user is currently looking at on the review card. The id is strictly
     * validated server-side (owner / language / sense / status=bound);
     * on any failure the call silently falls back to the original
     * multi-source list and reports the outcome via
     * preferred_occurrence_status in the JSON payload.
     */
    public function sourceContextList(int $id)
    {
        $preferred = request()->query('preferred_occurrence_id');
        $preferredId = $preferred !== null ? (int) $preferred : null;

        return response()->json($this->senseSourceContextService->sourceContextList(
            Auth::user()->id,
            Auth::user()->selected_language,
            $id,
            $preferredId,
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
