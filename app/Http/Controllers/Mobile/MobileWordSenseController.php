<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\MobileApiResponse;
use App\Models\Chapter;
use App\Services\SenseOccurrencePayloadSerializerService;
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
        private SenseOccurrencePayloadSerializerService $payloadSerializer,
    ) {
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
        ]), [
            'lemma' => ['required', 'string', 'max:100'],
            'surface_form' => ['nullable', 'string', 'max:100'],
            'pos' => ['required', Rule::in(self::POS_OPTIONS)],
            'sense_zh' => ['required', 'string', 'max:1000'],
            'sense_en' => ['nullable', 'string', 'max:1000'],
            'chapter_id' => ['nullable', 'integer'],
            'sentence_id' => ['nullable'],
            'sentence_en' => ['nullable', 'string', 'max:5000'],
            'sentence_zh' => ['nullable', 'string', 'max:5000'],
            'keep_new' => ['nullable', 'boolean'],
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
        $result = $this->wordSenseService->createManualSense(
            $user->id,
            $user->selected_language,
            $data,
        );

        $sense = $this->payloadSerializer->serializeSense($result['sense']);
        $sense['updated_word'] = $result['updated_word'];

        return MobileApiResponse::success(['word_sense' => $sense], 201);
    }
}
