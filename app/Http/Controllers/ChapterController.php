<?php

namespace App\Http\Controllers;

use App\Services\ChapterService;

// request classes
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\Chapters\CreateChapterRequest;
use App\Http\Requests\Chapters\DeleteChapterRequest;
use App\Http\Requests\Chapters\FinishChapterRequest;
use App\Http\Requests\Chapters\UpdateChapterRequest;
use App\Http\Requests\Chapters\GetChaptersForBookRequest;
use App\Http\Requests\Chapters\GetChapterForEditorRequest;
use App\Http\Requests\Chapters\GetChapterForReaderRequest;
use App\Http\Requests\Chapters\RetryFailedChaptersRequest;
use App\Http\Requests\Chapters\GetChaptersWordCountRequest;


class ChapterController extends Controller {
    private $chapterService;
    private $readingFinishSettlementService;

    public function __construct(ChapterService $chapterService, \App\Services\ReadingFinishSettlementService $readingFinishSettlementService) {
        $this->chapterService = $chapterService;
        $this->readingFinishSettlementService = $readingFinishSettlementService;
    }

    public function getChaptersForBook(GetChaptersForBookRequest $request) {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $bookId = intval($request->bookId);
        
        try {
            $chapters = $this->chapterService->getChaptersForBook($userId, $language, $bookId);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json($chapters, 200);
    }

    public function getChaptersBookCount($bookId, GetChaptersWordCountRequest $request) {
        $userId = Auth::user()->id;
        $userUuid = Auth::user()->uuid;
        $language = Auth::user()->selected_language;
        $bookId = intval($request->bookId);
        
        try {
            $chapterWordCounts = $this->chapterService->getChaptersBookCount($userId, $userUuid, $language, $bookId);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json($chapterWordCounts, 200);
    }

    public function getChapterForEditor(GetChapterForEditorRequest $request) {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $chapterId = $request->chapterId;

        try {
            $chapter = $this->chapterService->getChapterForEditor($userId, $language, $chapterId);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json($chapter, 200);
    }

    public function getChapterForReader(GetChapterForReaderRequest $request) {        
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $chapterId = $request->chapterId;
        $languagesWithoutSpaces = config('linguacafe.languages.languages_without_spaces');
        
        try {
            $chapter = $this->chapterService->getChapterForReader($userId, $language, $languagesWithoutSpaces, $chapterId);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json($chapter, 200);
    }

    public function finishChapter(FinishChapterRequest $request) {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $uniqueWords = json_decode($request->post('uniqueWords')) ?: [];
        $autoLevelUpWords = $request->boolean('autoLevelUpWords');
        $leveledUpWords = json_decode($request->post('leveledUpWords')) ?: [];
        $leveledUpPhrases = json_decode($request->post('leveledUpPhrases')) ?: [];
        $autoMoveWordsToKnown = boolval($request->post('autoMoveWordsToKnown'));
        $chapterId = (int) $request->chapterId;
        $readingSessionId = $request->post('reading_session_id');

        try {
            if ($readingSessionId) {
                try {
                    $result = $this->readingFinishSettlementService->finishChapterWithSession(
                        $userId,
                        $language,
                        $chapterId,
                        $readingSessionId,
                        $autoMoveWordsToKnown,
                        is_array($uniqueWords) ? $uniqueWords : [],
                        $autoLevelUpWords,
                        is_array($leveledUpWords) ? $leveledUpWords : [],
                        is_array($leveledUpPhrases) ? $leveledUpPhrases : [],
                        (string) ($request->post('settlement_mode') ?: 'preflight')
                    );

                    return response()->json($result, 200);
                } catch (\InvalidArgumentException $e) {
                    $knownCodes = [
                        \App\Services\ReadingSessionService::ERROR_SESSION_NOT_FOUND,
                        \App\Services\ReadingSessionService::ERROR_SESSION_NOT_ACTIVE,
                        \App\Services\ReadingSessionService::ERROR_SESSION_CHAPTER_MISMATCH,
                        \App\Services\ReadingSessionService::ERROR_SESSION_STALE_SOURCE,
                        'READING_FINISH_MODE_INVALID',
                    ];
                    $code = in_array($e->getMessage(), $knownCodes, true)
                        ? $e->getMessage()
                        : 'READING_FINISH_CONFLICT';

                    return response()->json([
                        'success' => false,
                        'error_code' => $code,
                        'message' => 'Reading finish conflicts with the current server state.',
                    ], $code === \App\Services\ReadingSessionService::ERROR_SESSION_NOT_FOUND ? 404 : 409);
                } catch (\Throwable $e) {
                    return response()->json([
                        'success' => false,
                        'error_code' => 'READING_FINISH_INTERNAL_ERROR',
                        'message' => 'Reading finish could not be completed.',
                    ], 500);
                }
            }

            $this->chapterService->finishChapter($userId, $chapterId, $autoMoveWordsToKnown, $uniqueWords, $autoLevelUpWords, $leveledUpWords, $leveledUpPhrases, $language);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json('Tasks have been completed successfully.', 200);
    }

    public function createChapter(CreateChapterRequest $request) {
        $userId = Auth::user()->id;
        $userUuid = Auth::user()->uuid;
        $language = Auth::user()->selected_language;
        $chapterName = $request->chapterName;
        $bookId = $request->bookId;
        $chapterText = is_null($request->chapterText) ? '' : $request->chapterText;
        $metadata = $request->safe()->only(['questionType']);

        try {
            $this->chapterService->createChapter($userId, $userUuid, $language, $bookId, $chapterName, $chapterText, $metadata);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json('Chapter has been created successfully.', 200);
    }

    public function updateChapter(UpdateChapterRequest $request) {
        $userId = Auth::user()->id;
        $userUuid = Auth::user()->uuid;
        $language = Auth::user()->selected_language;
        $chapterName = $request->chapterName;
        $chapterId = $request->chapterId;
        $chapterText = $request->chapterText;
        $sourceRevision = $request->sourceRevision;
        $metadata = $request->safe()->only(['questionType']);

        try {
            $this->chapterService->updateChapter($userId, $userUuid, $language, $chapterId, $chapterName, $chapterText, $sourceRevision, $metadata);
        } catch (\InvalidArgumentException $e) {
            if ($e->getMessage() === ChapterService::ERROR_SOURCE_REVISION_CONFLICT) {
                return response()->json([
                    'error' => [
                        'code' => ChapterService::ERROR_SOURCE_REVISION_CONFLICT,
                        'message' => '章节已在其他位置更新，请重新打开后再编辑。',
                    ],
                ], 409);
            }

            abort(500, 'Chapter update failed.');
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json('Chapter has been updated successfully.', 200);
    }

    public function deleteChapter(DeleteChapterRequest $request) {
        $chapterId = $request->post('chapterId');
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        try {
            $this->chapterService->deleteChapter($userId, $language, $chapterId);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json('Chapter has been deleted successfully.', 200);
    }

    public function retryFailedChapters($bookId, RetryFailedChaptersRequest $request) {
        $userId = Auth::user()->id;
        $userUuid = Auth::user()->uuid;
        $language = Auth::user()->selected_language;

        try {
            $this->chapterService->retryFailedChapters($userId, $userUuid, $language, $bookId);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json('Failed chapters has been added to the queue successfully.', 200);
    }
}
