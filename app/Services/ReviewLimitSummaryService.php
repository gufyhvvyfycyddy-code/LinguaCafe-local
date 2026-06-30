<?php

namespace App\Services;

class ReviewLimitSummaryService
{
    /**
     * Build a standard review-limit summary array.
     *
     * All parameters have sensible defaults so callers only need to
     * pass what they actually compute.
     */
    public function build(
        int $totalDueCount = 0,
        int $visibleCount = 0,
        int $reviewedTodayCount = 0,
        int $remainingReviewSlots = 0,
        int $reviewLimit = 200,
        bool $reviewLimitEnabled = true,
        int $newLimit = 20,
        bool $newLimitEnabled = true,
        bool $newIgnoreReviewLimit = false,
        bool $ignoreDailyLimits = false,
        bool $limitReached = false,
        int $hiddenDueCount = 0,
        int $hiddenByReviewLimit = 0,
        int $hiddenByNewLimit = 0,
        bool $isQueueEnforced = true,
        bool $canContinueOverLimit = false
    ): array {
        $limitMessage = null;

        if ($reviewLimitEnabled && $limitReached && !$ignoreDailyLimits) {
            if ($hiddenDueCount > 0) {
                $limitMessage = "今天已完成 {$reviewedTodayCount} 张复习，达到每日复习上限。还有 {$hiddenDueCount} 张到期卡暂时未显示。";
            }
        }

        return [
            'due_count' => $visibleCount,
            'visible_count' => $visibleCount,
            'total_due_count' => $totalDueCount,
            'hidden_due_count' => $hiddenDueCount,
            'hidden_by_review_limit' => $hiddenByReviewLimit,
            'hidden_by_new_limit' => $hiddenByNewLimit,
            'daily_review_limit_enabled' => $reviewLimitEnabled,
            'daily_review_limit' => $reviewLimit,
            'daily_new_limit_enabled' => $newLimitEnabled,
            'daily_new_limit' => $newLimit,
            'new_cards_ignore_review_limit' => $newIgnoreReviewLimit,
            'reviewed_today_count' => $reviewedTodayCount,
            'remaining_review_slots' => $remainingReviewSlots,
            'is_queue_enforced' => $isQueueEnforced,
            'ignore_daily_limits' => $ignoreDailyLimits,
            'limit_reached' => $limitReached,
            'can_continue_over_limit' => $canContinueOverLimit,
            'limit_message' => $limitMessage,
        ];
    }

    /**
     * Empty summary for scoped (book/chapter) mode.
     *
     * Returns the same 18-fields shape as build() but with all
     * limits disabled and zero values, matching the semantics of
     * "no sense cards returned for this scope".
     */
    public function emptyScoped(): array
    {
        return $this->build(
            totalDueCount: 0,
            visibleCount: 0,
            reviewedTodayCount: 0,
            remainingReviewSlots: 0,
            reviewLimit: 0,
            reviewLimitEnabled: false,
            newLimit: 0,
            newLimitEnabled: false,
            newIgnoreReviewLimit: false,
            ignoreDailyLimits: false,
            limitReached: false,
            hiddenDueCount: 0,
            hiddenByReviewLimit: 0,
            hiddenByNewLimit: 0,
            isQueueEnforced: true,
            canContinueOverLimit: false,
        );
    }
}
