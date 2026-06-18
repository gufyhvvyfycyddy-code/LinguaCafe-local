<?php

namespace App\Jobs;

use Exception;
use Carbon\Carbon;
use App\Models\Phrase;
use App\Models\Chapter;
use Illuminate\Bus\Queueable;
use App\Models\EncounteredWord;

// services
use App\Services\ChapterService;
use App\Enums\ChapterProcessingStatusEnum;

// models
use App\Services\QueueStatsService;
use App\Services\VocabularyService;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class ProcessChapter implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    

    private $userId;
    private $userUuid;
    private $chapterId;
    private $language;
    private $dispatchedAt, $startedAt;

    public function __construct(
        $userId, 
        $userUuid, 
        $chapterId, 
        $language
    ) 
    {
        $this->userId = $userId;
        $this->userUuid = $userUuid;
        $this->chapterId = $chapterId;
        $this->language = $language;
        $this->dispatchedAt = Carbon::now();
    }

    public function handle(
        VocabularyService $vocabularyService,
        ChapterService $chapterService,
        QueueStatsService $queueStatsService
    ) {
        try {
            $this->startedAt = Carbon::now();

            $chapter = Chapter::query()
                ->where('id', $this->chapterId)
                ->where('user_id', $this->userId)
                ->first();

            // process chapter text
            $chapterService->processChapterText($this->userId, $this->chapterId);
            
            // index phrases that were created while the job was running
            $phrases = Phrase
                ::where('user_id', $this->userId)
                ->where('language', $this->language)
                ->where('created_at', '>=', $this->startedAt)
                ->where('created_at', '<=', Carbon::now())
                ->get();

            foreach ($phrases as $phrase) {
                $vocabularyService->indexPhraseInChapter($chapter->id, $this->userId, $this->language, $phrase);
            }

            $chapter->refresh();
            $queueStatsService->insertChapterProcessedStat($chapter, 'finished', $this->dispatchedAt, $this->startedAt);
            $this->broadcastChapterStatusEvent($chapter);
        } catch (\Throwable $e) {
            Log::error('Chapter processing failed.', [
                'user_id' => $this->userId,
                'chapter_id' => $this->chapterId,
                'language' => $this->language,
                'error' => $e->getMessage(),
            ]);
            $this->jobFailed($queueStatsService);
            throw $e;
        }
    }

    // Laravel does not pass context to it's own failed() method.
    public function jobFailed(?QueueStatsService $queueStatsService = null) 
    {
        $queueStatsService = $queueStatsService ?: app(QueueStatsService::class);
        $chapter = Chapter
            ::where('id', $this->chapterId)
            ->where('user_id', $this->userId)
            ->first();

        if (!$chapter) {
            Log::error('Chapter processing failed, but chapter could not be found.', [
                'user_id' => $this->userId,
                'chapter_id' => $this->chapterId,
            ]);

            return;
        }
        
        // set chapter processing status to failed
        $chapter->processing_status = ChapterProcessingStatusEnum::FAILED->value;
        $chapter->save();

        $queueStatsService->insertChapterProcessedStat($chapter, 'failed', $this->dispatchedAt, $this->startedAt);
        $this->broadcastChapterStatusEvent($chapter);
    }

    private function broadcastChapterStatusEvent(Chapter $chapter): void
    {
        $words = EncounteredWord
            ::select(['id', 'word', 'stage'])
            ->where('user_id', $this->userId)
            ->where('language', $this->language)
            ->get()
            ->keyBy('id')
            ->toArray();

        if ($chapter->processing_status === ChapterProcessingStatusEnum::PROCESSED->value) {
            $chapter->wordCount = $chapter->getWordCounts($words);
        }
        
        try {
            event(new \App\Events\ChapterStateUpdatedEvent($this->userUuid, [
                $chapter->id => [
                    'processing_status' => $chapter->processing_status,
                    'wordCount' => $chapter->wordCount ?? null,
                ]
            ]));
        } catch (\Throwable $e) {
            Log::warning('Chapter status broadcast failed.', [
                'user_id' => $this->userId,
                'chapter_id' => $chapter->id,
                'language' => $this->language,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
