<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\MobileApiResponse;
use App\Models\Chapter;
use App\Services\ReadingManualSenseCreationService;
use App\Services\ReadingSessionService;
use App\Services\SenseOccurrencePayloadSerializerService;
use App\Services\WordSenseLibraryQueryService;
use App\Services\WordSenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MobileWordSenseController extends Controller
{
    private const POS_OPTIONS = [
        'noun',
        'verb',
        'adjective',
        'adverb',
        'preposition',
        'conjunction',
        'phrase',
        'other',
    ];

    public function __construct(
        private WordSenseService $wordSenseService,
        private ReadingManualSenseCreationService $readingManualSenseCreationService,
        private SenseOccurrencePayloadSerializerService $payloadSerializer,
        private WordSenseLibraryQueryService $queryService,
    ) {
    }

    public function index(Request $request)
    {
        $validator = Validator::make($request->only(['q', 'page', 'per_page']), [
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        if ($validator->fails()) {
            return MobileApiResponse::error(
                'VALIDATION_ERROR',
                'The WordSense library request is invalid.',
                422,
                $validator->errors()->toArray(),
            );
        }

        $data = $validator->validated();
        $user = $request->user();
        $page = $this->queryService->page(
            $user->id,
            $user->selected_language,
            $data['q'] ?? null,
            $data['page'] ?? 1,
            $data['per_page'] ?? 20,
        );

        return MobileApiResponse::success([
            'items' => $page['data'],
            'pagination' => $page['pagination'],
            'read_only' => true,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->only([
            'lemma',
            'surface_form',
            'pos',
            'sense_zh',
            'sense_en',
            'chapter_id',
            'sentence_id',
            'sentence_en',
            'sentence_zh',
            'keep_new',
            'reading_session_id',
            'source_revision',
            'occurrence_id',
        ]), [
            'lemma' => ['required', 'string', 'max:100'],
            'surface_form' => ['nullable', 'string', 'max:100'],
            'pos' => ['required', Rule::in(self::POS_OPTIONS)],
            'sense_zh' => ['required', 'string', 'max:1000'],
            'sense_en' => ['nullable', 'string', 'max:1000'],
            'chapter_id' => ['nullable', 'required_with:reading_session_id', 'integer'],
            'sentence_id' => ['nullable'],
            'sentence_en' => ['nullable', 'string', 'max:5000'],
            'sentence_zh' => ['nullable', 'string', 'max:5000'],
            'keep_new' => ['nullable', 'boolean'],
            'reading_session_id' => ['nullable', 'required_with:source_revision,occurrence_id', 'uuid'],
            'source_revision' => ['nullable', 'required_with:reading_session_id,occurrence_id', 'string'],
            'occurrence_id' => ['nullable', 'required_with:reading_session_id,source_revision', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return MobileApiResponse::error(
                'VALIDATION_ERROR',
                'The WordSense request is invalid.',
                422,
                $validator->errors()->toArray(),
            );
        }

        $data = $validator->validated();
        if (isset($data['sentence_id']) && !isset($data['chapter_id'])) {
            return MobileApiResponse::error(
                'VALIDATION_ERROR',
                'Sentence context requires a chapter.',
                422,
                ['chapter_id' => ['The chapter field is required with sentence context.']],
            );
        }

        $user = $request->user();
        if (isset($data['chapter_id']) && !Chapter::query()
            ->where('id', $data['chapter_id'])
            ->where('user_id', $user->id)
            ->where('language', $user->selected_language)
            ->exists()) {
            return MobileApiResponse::error(
                'ARTICLE_PACKAGE_NOT_FOUND',
                'The article context was not found.',
                404,
            );
        }

        $data['aliases_zh'] = [];
        $data['collocations'] = [];
        try {
            $result = !empty($data['reading_session_id'])
                ? $this->readingManualSenseCreationService->create(
                    $user->id,
                    $user->selected_language,
                    $data,
                )
                : $this->wordSenseService->createManualSense(
                    $user->id,
                    $user->selected_language,
                    $data,
                );
        } catch (\InvalidArgumentException $exception) {
            return $this->readingContractError($exception);
        }

        $sense = $this->payloadSerializer->serializeSense($result['sense']);
        $sense['updated_word'] = $result['updated_word'];

        return MobileApiResponse::success(['word_sense' => $sense], 201);
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

        return MobileApiResponse::error(
            $code,
            'The reading request conflicts with the current server state.',
            $statuses[$code],
        );
    }
}
