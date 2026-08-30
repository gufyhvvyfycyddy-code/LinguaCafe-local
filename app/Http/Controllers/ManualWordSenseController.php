<?php

namespace App\Http\Controllers;

use App\Models\WordSense;
use App\Services\ReadingManualSenseCreationService;
use App\Services\ReadingSessionService;
use App\Services\SenseOccurrencePayloadSerializerService;
use App\Services\WordSenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ManualWordSenseController extends Controller
{
    private const POS_OPTIONS = ['noun', 'verb', 'adjective', 'adverb', 'preposition', 'conjunction', 'phrase', 'other'];
    private const POS_ALIASES = [
        'n' => 'noun',
        'v' => 'verb',
        'adj' => 'adjective',
        'adv' => 'adverb',
        'prep' => 'preposition',
        'conj' => 'conjunction',
    ];

    public function __construct(
        private WordSenseService $wordSenseService,
        private SenseOccurrencePayloadSerializerService $payloadSerializer,
        private ReadingManualSenseCreationService $readingManualSenseCreationService,
    )
    {
    }

    public function storeManualSense(Request $request)
    {
        $request->merge(['pos' => $this->normalizePos($request->input('pos'))]);

        $data = $request->validate([
            'lemma' => ['required', 'string'],
            'surface_form' => ['nullable', 'string'],
            'pos' => ['required', Rule::in(self::POS_OPTIONS)],
            'sense_zh' => ['required', 'string'],
            'sense_en' => ['nullable', 'string'],
            'aliases_zh' => ['nullable'],
            'collocations' => ['nullable'],
            'chapter_id' => ['nullable', 'required_with:reading_session_id', 'integer'],
            'sentence_id' => ['nullable'],
            'sentence_en' => ['nullable', 'string'],
            'sentence_zh' => ['nullable', 'string'],
            'encountered_word_id' => ['nullable', 'integer'],
            'keep_new' => ['nullable', 'boolean'],
            'reading_session_id' => ['nullable', 'required_with:source_revision,occurrence_id', 'string', 'uuid'],
            'source_revision' => ['nullable', 'required_with:reading_session_id,occurrence_id', 'string'],
            'occurrence_id' => ['nullable', 'required_with:reading_session_id,source_revision', 'string'],
        ]);

        $data['aliases_zh'] = $this->payloadSerializer->normalizeList($request->post('aliases_zh'));
        $data['collocations'] = $this->payloadSerializer->normalizeList($request->post('collocations'));

        $user = Auth::user();
        try {
            if (!empty($data['reading_session_id'])) {
                $result = $this->readingManualSenseCreationService->create(
                    $user->id,
                    $user->selected_language,
                    $data,
                );
            } else {
                $result = $this->wordSenseService->createManualSense(
                    $user->id,
                    $user->selected_language,
                    $data,
                );
            }
        } catch (\InvalidArgumentException $e) {
            return $this->readingContractError($e);
        }

        $response = $this->payloadSerializer->serializeSense($result['sense']);
        $response['updated_word'] = $result['updated_word'];

        return response()->json($response);
    }

    public function updateManualSense(int $id, Request $request)
    {
        $request->merge(['pos' => $this->normalizePos($request->input('pos'))]);

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

    private function normalizePos(mixed $pos): mixed
    {
        if (!is_string($pos)) {
            return $pos;
        }

        $normalized = strtolower(trim($pos));

        return self::POS_ALIASES[$normalized] ?? $normalized;
    }

    private function readingContractError(\InvalidArgumentException $exception)
    {
        $code = $exception->getMessage();
        $statuses = [
            ReadingSessionService::ERROR_SESSION_NOT_FOUND => 404,
            ReadingSessionService::ERROR_SESSION_NOT_ACTIVE => 409,
            ReadingSessionService::ERROR_SESSION_CHAPTER_MISMATCH => 409,
            ReadingSessionService::ERROR_SESSION_STALE_SOURCE => 409,
            ReadingSessionService::ERROR_OCCURRENCE_STALE => 409,
            ReadingSessionService::ERROR_EXPLICIT_CONTEXT_INVALID => 422,
        ];
        if (!isset($statuses[$code])) {
            throw $exception;
        }

        return response()->json([
            'success' => false,
            'error_code' => $code,
            'message' => 'Reading request conflicts with the current server state.',
        ], $statuses[$code]);
    }
}
