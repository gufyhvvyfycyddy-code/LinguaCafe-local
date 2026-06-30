<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\EncounteredWord;
use App\Models\ReviewCard;

class ReviewService {
    
    public function __construct(
        private SenseReviewService $senseReviewService,
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
                'summary' => $this->emptyReviewSummary(),
            ];
        }

        // 日常复习只保留 sense card，孤立 word card 不再进入复习
        $result = $this->senseReviewService->dueCardsWithLimits($userId, $language, $ignoreDailyLimits);
        $senseCards = $result['cards'];

        $reviews = [];
        foreach ($senseCards as $card) {
            $serialized = $this->senseReviewService->serializeCard($card);
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

    /**
     * Return an empty review summary for scoped (book/chapter) mode,
     * matching the shape of SenseReviewService::buildLimitSummary().
     */
    private function emptyReviewSummary(): array
    {
        return [
            'due_count' => 0,
            'visible_count' => 0,
            'total_due_count' => 0,
            'hidden_due_count' => 0,
            'hidden_by_review_limit' => 0,
            'hidden_by_new_limit' => 0,
            'daily_review_limit_enabled' => false,
            'daily_review_limit' => 0,
            'daily_new_limit_enabled' => false,
            'daily_new_limit' => 0,
            'new_cards_ignore_review_limit' => false,
            'reviewed_today_count' => 0,
            'remaining_review_slots' => 0,
            'is_queue_enforced' => true,
            'ignore_daily_limits' => false,
            'limit_reached' => false,
            'can_continue_over_limit' => false,
            'limit_message' => null,
        ];
    }
}
