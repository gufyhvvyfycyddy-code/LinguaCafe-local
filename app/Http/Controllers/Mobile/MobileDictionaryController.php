<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\MobileApiResponse;
use App\Services\DictionaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MobileDictionaryController extends Controller
{
    public function __construct(private DictionaryService $dictionaryService)
    {
    }

    public function show(Request $request)
    {
        $validator = Validator::make($request->only('term'), [
            'term' => ['required', 'string', 'max:100'],
        ]);
        if ($validator->fails()) {
            return MobileApiResponse::error(
                'VALIDATION_ERROR',
                'The dictionary lookup request is invalid.',
                422,
                $validator->errors()->toArray(),
            );
        }

        $term = trim((string) $request->input('term'));
        if ($term === '') {
            return MobileApiResponse::error(
                'VALIDATION_ERROR',
                'The dictionary lookup term is required.',
                422,
                ['term' => ['The term field is required.']],
            );
        }

        $result = $this->dictionaryService->searchDefinitionsForHoverVocabulary(
            $request->user()->selected_language,
            $term,
        );

        return MobileApiResponse::success([
            'term' => $result->term,
            'definitions' => array_slice($result->definitions, 0, 10),
            'local_only' => true,
            'read_only' => true,
        ]);
    }
}
