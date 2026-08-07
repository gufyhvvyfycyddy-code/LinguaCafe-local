<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

// services
use App\Services\ImportService;
use App\Services\TempFileService;

// request classes
use App\Http\Requests\Import\GetWebsiteTextRequest;
use App\Http\Requests\Import\ImportRequest;
use App\Http\Requests\Import\GetYoutubeSubtitlesRequest;
use App\Http\Requests\Import\GetSubtitleFileContentRequest;

class ImportController extends Controller {
    private $importMethods = [
        'e-book' => 'e-book',
        'jellyfin-subtitle' => 'subtitle',
        'subtitle-file' => 'subtitle',
        'plain-text' => 'text',
        'text-file' => 'text',
        'youtube' => 'text',
        'website' => 'text',
    ];

    private $importService;
    private $tempFileService;

    public function __construct(ImportService $importService, TempFileService $tempFileService) {
        $this->importService = $importService;
        $this->tempFileService = $tempFileService;
    }

    public function import(ImportRequest $request) {
        $userId = Auth::user()->id;
        $userUuid = Auth::user()->uuid;
        $importType = $request->post('importType');
        $textProcessingMethod = $request->post('textProcessingMethod');
        $eBookChapterSortMethod = $request->post('eBookChapterSortMethod');
        $bookId = $request->post('bookId');
        $bookName = $request->post('bookName');
        $chapterName = $request->post('chapterName');
        $chunkSize = intval($request->post('maximumCharactersPerChapter'));
        $importMethod = $this->importMethods[$importType];
        $fileName = null;

        if ($importMethod == 'e-book') {
            $importFile = $request->file('importFile');
        } else if ($importMethod == 'text') {
            $importText = $request->post('importText');
        } else if ($importMethod == 'subtitle') {
            $importSubtitles = $request->post('importSubtitles');
        }
        
        if (isset($importFile)) {
            try {
                $fileName = $this->tempFileService->moveFileToTempFolder($userId, $importFile);
            } catch (\Exception $exception) {
                Log::warning('content_import_failed', [
                    'stage' => 'store',
                    'exception' => $exception::class,
                ]);

                return $this->contentImportFailureResponse();
            }
        }

        // import
        try {
            if ($importMethod === 'e-book') {
                // e-book
                $processingMode = $this->importService->importBook($userId, $userUuid, $chunkSize, $eBookChapterSortMethod, $textProcessingMethod, storage_path('app/temp') . '/' . $fileName, $bookId, $bookName, $chapterName);
            } else if ($importMethod === 'text') {
                // text
                $processingMode = $this->importService->importText($userId, $userUuid, $chunkSize, $textProcessingMethod, $importText, $bookId, $bookName, $chapterName);
            } else if ($importMethod === 'subtitle') {
                // text
                $processingMode = $this->importService->importSubtitles($userId, $userUuid, $chunkSize, $textProcessingMethod, $importSubtitles, $bookId, $bookName, $chapterName);
            }
        } catch (\Exception $exception) {
            $this->deleteContentImportTempFileQuietly($fileName);

            Log::warning('content_import_failed', [
                'stage' => 'process',
                'exception' => $exception::class,
            ]);

            return $this->contentImportFailureResponse();
        }

        $this->deleteContentImportTempFileQuietly($fileName);

        return response()->json([
            'message' => '导入成功。',
            'processing_mode' => $processingMode ?? 'tokenizer',
        ], 200);
    }

    private function contentImportFailureResponse(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => 'CONTENT_IMPORT_FAILED',
                'message' => '导入失败，请稍后重试。',
            ],
        ], 500);
    }

    private function deleteContentImportTempFileQuietly(?string $fileName): void
    {
        if ($fileName === null) {
            return;
        }

        try {
            $this->tempFileService->deleteTempFile($fileName);
        } catch (\Throwable $cleanupException) {
            Log::warning('content_import_temp_cleanup_failed', [
                'exception' => $cleanupException::class,
            ]);
        }
    }

    public function getYoutubeSubtitles(GetYoutubeSubtitlesRequest $request) {
        $url = $request->post('url');

        try {
            $subtitleList = $this->importService->getYoutubeSubtitles($url);
        } catch (\Exception $exception) {
            Log::warning('youtube_subtitle_lookup_failed', [
                'exception' => $exception::class,
            ]);

            return new JsonResponse([
                'error' => [
                    'code' => 'YOUTUBE_SUBTITLE_SERVICE_UNAVAILABLE',
                    'message' => '暂时无法获取 YouTube 字幕，请稍后重试。',
                ],
            ], 503);
        }

        return new JsonResponse($subtitleList, 200);
    }

    public function getSubtitleFileContent(GetSubtitleFileContentRequest $request) {
        $subtitleFile = $request->file('subtitleFile');
        $userId = Auth::user()->id;
        $fileName = null;

        try {
            $fileName = $this->tempFileService->moveFileToTempFolder($userId, $subtitleFile);
            $subtitleContent = $this->importService->getSubtitleFileContent(
                storage_path('app/temp') . '/' . $fileName,
            );
        } catch (\Exception $exception) {
            if (is_string($fileName)) {
                try {
                    $this->tempFileService->deleteTempFile($fileName);
                } catch (\Throwable $cleanupException) {
                    Log::warning('subtitle_temp_cleanup_failed', [
                        'exception' => $cleanupException::class,
                    ]);
                }
            }

            Log::warning('subtitle_file_read_failed', [
                'stage' => $fileName === null ? 'store' : 'parse',
                'exception' => $exception::class,
            ]);

            return new JsonResponse([
                'error' => [
                    'code' => 'SUBTITLE_FILE_READ_FAILED',
                    'message' => '暂时无法读取字幕文件，请重试。',
                ],
            ], 500);
        }

        try {
            $this->tempFileService->deleteTempFile($fileName);
        } catch (\Throwable $cleanupException) {
            Log::warning('subtitle_temp_cleanup_failed', [
                'exception' => $cleanupException::class,
            ]);
        }

        return new JsonResponse($subtitleContent, 200);
    }

    public function getWebsiteText(GetWebsiteTextRequest $request) {
        $url = $request->post('url');

        try {
            $websiteText = $this->importService->getWebsiteText($url);
        } catch (\Exception $exception) {
            Log::warning('website_text_lookup_failed', [
                'exception' => $exception::class,
            ]);

            return new JsonResponse([
                'error' => [
                    'code' => 'WEBSITE_TEXT_SERVICE_UNAVAILABLE',
                    'message' => '暂时无法获取网页内容，请稍后重试。',
                ],
            ], 503);
        }

        return new JsonResponse($websiteText, 200);
    }
}
