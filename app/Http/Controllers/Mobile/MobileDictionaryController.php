<?php

namespace App\Http\Controllers\Mobile;

use App\Exceptions\DictionaryReadException;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileApiResponse;
use App\Services\Dictionaries\DictionaryLookupRequestPolicy;
use App\Services\DictionaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MobileDictionaryController extends Controller
{
    public function __construct(
        private DictionaryService $dictionaryService,
        private DictionaryLookupRequestPolicy $requestPolicy,
    ) {
    }

    public function show(Request $request)
    {
        $validator = Validator::make($request->only('term'), [
            'term' => $this->requestPolicy->validationRules(),
        ]);
        if ($validator->fails()) {
            return MobileApiResponse::error(
                'VALIDATION_ERROR',
                'The dictionary lookup request is invalid.',
                422,
                $validator->errors()->toArray(),
            );
        }

        $term = $this->requestPolicy->normalize((string) $request->input('term'));

        try {
            $result = $this->dictionaryService->searchDefinitionsForHoverVocabulary(
                $request->user()->selected_language,
                $term,
            );
        } catch (DictionaryReadException $exception) {
            return MobileApiResponse::error(
                $exception->errorCode,
                $exception->publicMessage,
                $exception->httpStatus,
            );
        } catch (\Throwable $exception) {
            report($exception);

            return MobileApiResponse::error(
                'DICTIONARY_LOOKUP_FAILED',
                'Dictionary lookup failed.',
                500,
            );
        }

        return MobileApiResponse::success([
            'term' => $result['term'],
            'definitions' => $result['definitions'],
            'warnings' => $result['warnings'],
            'configured' => $result['configured'],
            'local_only' => true,
            'read_only' => true,
        ]);
    }
}
