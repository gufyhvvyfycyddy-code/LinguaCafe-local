<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\MobileApiResponse;
use App\Services\ReadingUnfamiliarTargetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MobileReadingUnfamiliarTargetController extends Controller
{
    public function __construct(
        private ReadingUnfamiliarTargetService $readingUnfamiliarTargetService,
    ) {
    }

    public function store(int $chapter, Request $request)
    {
        $validator = Validator::make($request->only([
            'kind',
            'start_word_index',
            'end_word_index',
            'source_revision',
        ]), [
            'kind' => ['required', 'in:word,phrase'],
            'start_word_index' => ['required', 'integer', 'min:0'],
            'end_word_index' => ['required', 'integer', 'min:0'],
            'source_revision' => ['required', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return MobileApiResponse::error(
                'VALIDATION_ERROR',
                'The reading target request is invalid.',
                422,
                $validator->errors()->toArray(),
            );
        }

        $data = $validator->validated();
        try {
            $target = $this->readingUnfamiliarTargetService->createTarget(
                $request->user()->id,
                $request->user()->selected_language,
                $chapter,
                $data['kind'],
                (int) $data['start_word_index'],
                (int) $data['end_word_index'],
                $data['source_revision'],
            );
        } catch (\InvalidArgumentException $exception) {
            $code = $exception->getMessage();
            $missingChapter = $code === 'Chapter does not exist in the current user and language scope.';
            $staleSource = $code === ReadingUnfamiliarTargetService::ERROR_STALE_SOURCE;

            return MobileApiResponse::error(
                $missingChapter
                    ? 'ARTICLE_PACKAGE_NOT_FOUND'
                    : ($staleSource ? ReadingUnfamiliarTargetService::ERROR_STALE_SOURCE : 'READING_TARGET_INVALID'),
                $missingChapter
                    ? 'The article context was not found.'
                    : ($staleSource
                        ? 'The Reader article has changed. Reload it before marking a target.'
                        : 'The selected Reader target is no longer valid.'),
                $missingChapter ? 404 : ($staleSource ? 409 : 422),
            );
        }

        return MobileApiResponse::success(['target' => $target], 201);
    }
}
