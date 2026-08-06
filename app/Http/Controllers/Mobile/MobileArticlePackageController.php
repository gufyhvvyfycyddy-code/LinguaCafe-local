<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\MobileApiResponse;
use App\Services\InvalidMobilePackageCursorException;
use App\Services\InvalidMobilePackageSourceException;
use App\Services\MobileArticlePackageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MobileArticlePackageController extends Controller
{
    public function __construct(private MobileArticlePackageService $service)
    {
    }

    public function index(Request $request)
    {
        $validator = Validator::make($request->only(['page', 'per_page']), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);
        if ($validator->fails()) {
            return MobileApiResponse::error(
                'VALIDATION_ERROR',
                'The article package request is invalid.',
                422,
                $validator->errors()->toArray(),
            );
        }

        $user = $request->user();

        try {
            return MobileApiResponse::success($this->service->listForUser(
                $user->id,
                $user->selected_language,
                (int) $request->input('page', 1),
                (int) $request->input('per_page', 20),
            ));
        } catch (InvalidMobilePackageSourceException $exception) {
            return $this->sourceError($exception);
        }
    }

    public function show(Request $request, int $book)
    {
        $validator = Validator::make(
            $request->only(['chapter_page', 'chapters_per_page']),
            [
                'chapter_page' => ['sometimes', 'integer', 'min:1'],
                'chapters_per_page' => [
                    'sometimes',
                    'integer',
                    'min:1',
                    'max:' . MobileArticlePackageService::MAX_CHAPTERS_PER_PAGE,
                ],
            ],
        );
        if ($validator->fails()) {
            return MobileApiResponse::error(
                'VALIDATION_ERROR',
                'The article manifest request is invalid.',
                422,
                $validator->errors()->toArray(),
            );
        }

        $user = $request->user();

        try {
            $manifest = $this->service->manifestForUser(
                $book,
                $user->id,
                $user->selected_language,
                (int) $request->input('chapter_page', 1),
                (int) $request->input(
                    'chapters_per_page',
                    MobileArticlePackageService::DEFAULT_CHAPTERS_PER_PAGE,
                ),
            );
        } catch (InvalidMobilePackageSourceException $exception) {
            return $this->sourceError($exception);
        }

        return $manifest
            ? MobileApiResponse::success($manifest)
            : MobileApiResponse::error(
                'ARTICLE_PACKAGE_NOT_FOUND',
                'The article package was not found.',
                404,
            );
    }

    public function chapter(Request $request, int $book, int $chapter)
    {
        $validator = Validator::make($request->only(['cursor', 'token_limit']), [
            'cursor' => ['sometimes', 'nullable', 'string', 'max:8192'],
            'token_limit' => [
                'sometimes',
                'integer',
                'min:1',
                'max:' . MobileArticlePackageService::MAX_TOKEN_LIMIT,
            ],
        ]);
        if ($validator->fails()) {
            return MobileApiResponse::error(
                'VALIDATION_ERROR',
                'The article chapter package request is invalid.',
                422,
                $validator->errors()->toArray(),
            );
        }

        $user = $request->user();

        try {
            $payload = $this->service->chapterShardForUser(
                $book,
                $chapter,
                $user->id,
                $user->selected_language,
                $request->input('cursor'),
                (int) $request->input(
                    'token_limit',
                    MobileArticlePackageService::DEFAULT_TOKEN_LIMIT,
                ),
            );
        } catch (InvalidMobilePackageCursorException $exception) {
            return MobileApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
            );
        } catch (InvalidMobilePackageSourceException $exception) {
            return $this->sourceError($exception);
        }

        return $payload
            ? MobileApiResponse::success($payload)
            : MobileApiResponse::error(
                'ARTICLE_PACKAGE_NOT_FOUND',
                'The article package was not found.',
                404,
            );
    }

    private function sourceError(InvalidMobilePackageSourceException $exception)
    {
        return MobileApiResponse::error(
            $exception->errorCode,
            $exception->getMessage(),
            $exception->status,
        );
    }
}
