<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Services\ReviewLimitSummaryService;

class ReviewService {
    
    public function __construct(
        private SenseReviewService $senseReviewService,
        private ReviewLimitSummaryService $reviewLimitSummaryService,
        private SenseReviewCardSerializerService $senseReviewCardSerializerService,
    ) {
    }

    public function getReviewItems($userId, $language, $bookId, $chapterId, $practiceMode, $languagesWithoutSpaces, $ignoreDailyLimits = false) {
        // 前端可能传字符串 "-1"，统一转为 int
        $bookId = (int) $bookId;
        $chapterId = (int) $chapterId;

        // 书/章节限定模式第一版不支持 sense 过滤，返回空队列
        if ($bookId !== -1 || $chapterId !== -1) {
            return [
                'reviews' => [],
                'summary' => $this->reviewLimitSummaryService->emptyScoped(),
            ];
        }

        // 日常复习只保留 sense card，孤立 word card 不再进入复习
        $result = $this->senseReviewService->dueCardsWithLimits($userId, $language, $ignoreDailyLimits);
        $senseCards = $result['cards'];

        $reviews = [];
        foreach ($senseCards as $card) {
            $serialized = $this->senseReviewCardSerializerService->serialize($card);
            $serialized['type'] = 'sense';
            $reviews[] = (object) $serialized;
        }

        // 随机顺序
        shuffle($reviews);

        return [
            'reviews' => $reviews,
            'summary' => $result['summary'],
        ];
    }

}
