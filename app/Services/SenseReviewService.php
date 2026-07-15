<?php

namespace App\Services;

use App\Services\SettingsService;
use Carbon\Carbon;

class SenseReviewService
{
    public function __construct(
        private SettingsService $settingsService,
        private SenseReviewQueryService $senseReviewQueryService,
        private ReviewLimitSummaryService $reviewLimitSummaryService,
        private SenseReviewCardSerializerService $senseReviewCardSerializerService,
        private ReviewQueueOrderService $reviewQueueOrderService,
        private ReviewStudyTimezoneService $studyTimezoneService,
        private EffectiveReviewLimitsService $effectiveReviewLimitsService,
    ) {
    }
    /**
     * Base query builder for due sense review cards.
     *
     * Shared between dueCards() and dueCount() so that the filtering logic
     * stays in one place and the two methods cannot drift apart.
     *
     * Uses ReviewCard::scopeSenseReviewEligible() (ADR-0010) as the unified
     * queue-eligibility scope, which enforces:
     *   - user_id / language_id / target_type=sense
     *   - lifecycle_state='active'
     *   - buried_until IS NULL OR buried_until <= now
     *   - fsrs_enabled=true (compatibility mirror)
     *
     * The due filter (fsrs_due_at <= now) is added here because the scope
     * is "queue eligible" not "due now".
     *
     * Callers must add their own terminal methods:
     *   - dueCards(): select, with('sense'), orderBy, get()
     *   - dueCount(): count()
     */
    private function dueSenseReviewCardQuery(int $userId, string $language): \Illuminate\Database\Eloquent\Builder
    {
        return $this->senseReviewQueryService
            ->confirmedSenseCardQuery($userId, $language)
            ->senseReviewEligible($userId, $language, Carbon::now())
            ->where('review_cards.fsrs_due_at', '<=', Carbon::now());
    }

    public function dueCards(int $userId, string $language)
    {
        return $this->dueSenseReviewCardQuery($userId, $language)
            ->select('review_cards.*')
            ->with('sense')
            ->orderBy('review_cards.fsrs_due_at')
            ->orderBy('review_cards.id')
            ->get();
    }

    public function nextDueCard(int $userId, string $language): ?array
    {
        $cards = $this->dueCards($userId, $language);
        if ($cards->isEmpty()) {
            return null;
        }

        // Apply Queue Order to get the proper first card
        $options = $this->settingsService->getFsrsQueueOrder($userId, $language);
        $queueOptions = \App\Services\ReviewQueueOrderOptions::fromArray($options);
        $timezone = $this->studyTimezoneService->getStudyTimezone();
        $ordered = $this->reviewQueueOrderService->order(
            $cards,
            $userId,
            $language,
            $timezone,
            Carbon::now(),
            $queueOptions
        );

        $card = $ordered->first();
        return $card ? $this->senseReviewCardSerializerService->serialize($card) : null;
    }

    /**
     * SQL-level COUNT of due sense review cards.
     *
     * Uses the same filter conditions as dueCards() but runs a single
     * SQL COUNT query instead of hydrating the full card collection.
     */
    public function dueCount(int $userId, string $language): int
    {
        return $this->dueSenseReviewCardQuery($userId, $language)->count();
    }

    public function summary(int $userId, string $language): array
    {
        return [
            'due_count' => $this->dueCount($userId, $language),
        ];
    }

    /**
     * Count how many sense review cards the user has reviewed today.
     *
     * DEV-QO-8: Uses the unified study timezone boundary service so that
     * the "today" boundary matches the Queue Order local date. This avoids
     * a scenario where daily hash rolls over at midnight UTC but
     * reviewedTodayCount rolls over at a different local midnight.
     */
    public function reviewedTodayCount(int $userId, string $language): int
    {
        return $this->effectiveReviewLimitsService
            ->resolve($userId, $language)['reviewed_today_count'];
    }

    /**
     * Get due sense review cards with daily limits and Queue Order applied.
     *
     * ADR-0015 V1: The Queue Order is applied via ReviewQueueOrderService,
     * which is the single canonical entry point for queue ordering.
     * Both /reviews/senses and /reviews share this code path, ensuring
     * identical card id order for the same user/language/time/settings.
     *
     * @return array{cards: \Illuminate\Support\Collection, summary: array}
     */
    public function dueCardsWithLimits(int $userId, string $language, bool $ignoreDailyLimits = false): array
    {
        $limits = $this->effectiveReviewLimitsService->resolve($userId, $language);

        $reviewLimitEnabled = $limits['effective_review_limit_enabled'];
        $reviewLimit = $limits['effective_review_limit'];
        $newLimitEnabled = $limits['effective_new_limit_enabled'];
        $newLimit = $limits['effective_new_limit'];
        $remainingNewSlots = $limits['remaining_new'];
        $newIgnoreReviewLimit = $limits['new_cards_ignore_review_limit'] ?? false;

        // Base due cards query
        $allCards = $this->dueCards($userId, $language);

        $totalDueCount = $allCards->count();

        // Count today's reviewed sense cards
        $reviewedTodayCount = $limits['reviewed_today_count'];
        $remainingReviewSlots = $limits['remaining_review'];

        // Apply Queue Order (ADR-0015 V1) — single canonical ordering entry point
        $queueOptionsArray = $this->settingsService->getFsrsQueueOrder($userId, $language);
        $queueOptions = \App\Services\ReviewQueueOrderOptions::fromArray($queueOptionsArray);
        $timezone = $this->studyTimezoneService->getStudyTimezone();
        $now = Carbon::now();
        $orderedCards = $this->reviewQueueOrderService->order(
            $allCards,
            $userId,
            $language,
            $timezone,
            $now,
            $queueOptions
        );

        // If ignoreDailyLimits, return all cards in queue order
        if ($ignoreDailyLimits) {
            return [
                'cards' => $orderedCards,
                'summary' => $this->reviewLimitSummaryService->build(
                    totalDueCount: $totalDueCount,
                    visibleCount: $orderedCards->count(),
                    reviewedTodayCount: $reviewedTodayCount,
                    remainingReviewSlots: $remainingReviewSlots,
                    reviewLimit: $reviewLimit,
                    reviewLimitEnabled: $reviewLimitEnabled,
                    newLimit: $newLimit,
                    newLimitEnabled: $newLimitEnabled,
                    newIgnoreReviewLimit: $newIgnoreReviewLimit,
                    ignoreDailyLimits: $ignoreDailyLimits,
                    limitReached: false,
                    hiddenDueCount: 0,
                    hiddenByReviewLimit: 0,
                    hiddenByNewLimit: 0,
                    isQueueEnforced: true,
                    effectiveLimits: $limits,
                ),
            ];
        }

        // ADR-0015 V1 / 任务规格第 333-339 行执行层次：
        //   1. intraday 单独最前
        //   2. interday + review 组合
        //   3. 应用 review daily limit（对 interday+review）
        //   4. 排序并裁剪 new cards（受 new limit，且当
        //      new_cards_ignore_review_limit=false 时受剩余 review limit）
        //   5. 按 new_review_order 把 new 与非 intraday 组合
        //   6. 拼接 intraday
        //
        // 因此 daily limits 必须按两阶段裁剪：先 non-new（intraday+interday+
        // review）按 queue order 应用 review limit，再 new 按 queue order
        // 应用 new limit + 剩余 review limit。最后按 queue order 过滤可见卡，
        // 保证返回顺序仍是 Queue Order 的统一顺序。
        //
        // 关键语义：review limit 的 slot 优先留给 review/learning 卡，
        // new 卡只在剩余 slot 内可见。这避免 mix 顺序下 new 卡先消耗
        // review slot 从而把 review 卡挤出去的问题。
        $nowInTz = $now->copy()->tz($timezone);

        // 按类别分组（保留 queue order）
        $intradayCards = [];
        $nonNewCards = []; // interday + review
        $newCards = [];
        foreach ($orderedCards as $card) {
            $category = $this->reviewQueueOrderService->classify($card, $timezone, $nowInTz);
            if ($category === 'new') {
                $newCards[] = $card;
            } elseif ($category === 'intraday') {
                $intradayCards[] = $card;
            } else {
                $nonNewCards[] = $card;
            }
        }

        // 阶段一：对 non-new 卡应用 review daily limit。
        // intraday 按“优先显示”语义同样计入 review limit（保守策略，
        // 与重构前 split known/new 行为一致，避免放宽限制）。
        $reviewCount = 0;
        $hiddenByReviewLimit = 0;
        $hiddenDueCount = 0;
        $visibleNonNew = [];
        foreach ($intradayCards as $card) {
            if ($reviewLimitEnabled && $reviewCount >= $remainingReviewSlots) {
                $hiddenByReviewLimit++;
                $hiddenDueCount++;
                continue;
            }
            $visibleNonNew[] = $card;
            $reviewCount++;
        }
        foreach ($nonNewCards as $card) {
            if ($reviewLimitEnabled && $reviewCount >= $remainingReviewSlots) {
                $hiddenByReviewLimit++;
                $hiddenDueCount++;
                continue;
            }
            $visibleNonNew[] = $card;
            $reviewCount++;
        }

        // 阶段二：对 new 卡应用 new limit + 剩余 review limit。
        $newCount = 0;
        $hiddenByNewLimit = 0;
        $visibleNew = [];
        foreach ($newCards as $card) {
            if ($newLimitEnabled && $newCount >= $remainingNewSlots) {
                $hiddenByNewLimit++;
                $hiddenDueCount++;
                continue;
            }
            if (!$newIgnoreReviewLimit && $reviewLimitEnabled && $reviewCount >= $remainingReviewSlots) {
                $hiddenByNewLimit++;
                $hiddenDueCount++;
                continue;
            }
            $visibleNew[] = $card;
            $newCount++;
            if (!$newIgnoreReviewLimit) {
                $reviewCount++;
            }
        }

        // 阶段三：按 queue order 过滤可见卡，保持统一顺序。
        $visibleIds = [];
        foreach ($visibleNonNew as $card) {
            $visibleIds[$card->id] = true;
        }
        foreach ($visibleNew as $card) {
            $visibleIds[$card->id] = true;
        }
        $visibleCards = collect();
        foreach ($orderedCards as $card) {
            if (isset($visibleIds[$card->id])) {
                $visibleCards->push($card);
            }
        }

        $visibleCount = $visibleCards->count();
        $limitReached = $hiddenDueCount > 0;
        $canContinueOverLimit = $totalDueCount > 0 && $reviewLimitEnabled && $limitReached;

        return [
            'cards' => $visibleCards,
            'summary' => $this->reviewLimitSummaryService->build(
                totalDueCount: $totalDueCount,
                visibleCount: $visibleCount,
                reviewedTodayCount: $reviewedTodayCount,
                remainingReviewSlots: $remainingReviewSlots,
                reviewLimit: $reviewLimit,
                reviewLimitEnabled: $reviewLimitEnabled,
                newLimit: $newLimit,
                newLimitEnabled: $newLimitEnabled,
                newIgnoreReviewLimit: $newIgnoreReviewLimit,
                ignoreDailyLimits: $ignoreDailyLimits,
                limitReached: $limitReached,
                hiddenDueCount: $hiddenDueCount,
                hiddenByReviewLimit: $hiddenByReviewLimit,
                hiddenByNewLimit: $hiddenByNewLimit,
                isQueueEnforced: true,
                canContinueOverLimit: $canContinueOverLimit,
                effectiveLimits: $limits,
            ),
        ];
    }

}


