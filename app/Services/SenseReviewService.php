<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\WordSense;
use App\Services\SettingsService;
use Carbon\Carbon;

class SenseReviewService
{
    public function __construct(
        private SettingsService $settingsService,
        private SenseReviewQueryService $senseReviewQueryService,
        private ReviewLimitSummaryService $reviewLimitSummaryService,
        private SenseTokenPayloadService $senseTokenPayloadService,
        private SenseSourceContextService $senseSourceContextService,
    ) {
    }
    /**
     * Base query builder for due sense review cards.
     *
     * Shared between dueCards() and dueCount() so that the filtering logic
     * stays in one place and the two methods cannot drift apart.
     *
     * Callers must add their own terminal methods:
     *   - dueCards(): select, with('sense'), orderBy, get()
     *   - dueCount(): count()
     */
    private function dueSenseReviewCardQuery(int $userId, string $language): \Illuminate\Database\Eloquent\Builder
    {
        return $this->senseReviewQueryService
            ->confirmedSenseCardQuery($userId, $language)
            ->where('review_cards.fsrs_enabled', true)
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
        $card = $this->dueCards($userId, $language)->first();

        return $card ? $this->serializeCard($card) : null;
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
     */
    public function reviewedTodayCount(int $userId, string $language): int
    {
        $today = Carbon::today();

        return $this->senseReviewQueryService
            ->nonResetSenseReviewLogQuery($userId, $language, $today)
            ->count('review_logs.id');
    }

    /**
     * Get due sense review cards with daily limits applied.
     *
     * @return array{cards: \Illuminate\Support\Collection, summary: array}
     */
    public function dueCardsWithLimits(int $userId, string $language, bool $ignoreDailyLimits = false): array
    {
        $limits = $this->settingsService->getFsrsDailyLimits();

        $reviewLimitEnabled = $limits['daily_review_limit_enabled'] ?? true;
        $reviewLimit = $limits['daily_review_limit'] ?? 200;
        $newLimitEnabled = $limits['daily_new_limit_enabled'] ?? true;
        $newLimit = $limits['daily_new_limit'] ?? 20;
        $newIgnoreReviewLimit = $limits['new_cards_ignore_review_limit'] ?? false;

        // Base due cards query
        $allCards = $this->dueCards($userId, $language);

        $totalDueCount = $allCards->count();

        // Count today's reviewed sense cards
        $reviewedTodayCount = $this->reviewedTodayCount($userId, $language);
        $remainingReviewSlots = max(0, $reviewLimit - $reviewedTodayCount);
        $limitReached = false;
        $hiddenDueCount = 0;
        $hiddenByReviewLimit = 0;
        $hiddenByNewLimit = 0;

        // If ignoreDailyLimits, return all cards
        if ($ignoreDailyLimits) {
            $visibleCards = $allCards;

            return [
                'cards' => $visibleCards,
                'summary' => $this->reviewLimitSummaryService->build(
                    totalDueCount: $totalDueCount,
                    visibleCount: $visibleCards->count(),
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
                ),
            ];
        }

        // Split into new cards and known cards (review/learning/relearning)
        $newCards = $allCards->filter(fn ($card) => $card->fsrs_state === 'new');
        $knownCards = $allCards->filter(fn ($card) => $card->fsrs_state !== 'new');

        $knownCount = $knownCards->count();
        $newCount = $newCards->count();

        // Apply review limit
        $allowedReviewCards = $knownCards;
        if ($reviewLimitEnabled && $remainingReviewSlots < $knownCount) {
            $allowedReviewCards = $knownCards->slice(0, $remainingReviewSlots);
            $hiddenByReviewLimit = $knownCount - $allowedReviewCards->count();
        }

        // Calculate how many review slots remain after filling known cards
        $reviewSlotsUsed = $allowedReviewCards->count();
        $remainingAfterKnown = max(0, $remainingReviewSlots - $reviewSlotsUsed);

        // Apply new cards under the review limit
        $allowedNewCards = collect();
        if ($newLimitEnabled && $newCount > 0) {
            if ($newIgnoreReviewLimit) {
                // New cards ignore review limit — show up to newLimit
                $allowedNewCards = $newCards->slice(0, $newLimit);
                $hiddenByNewLimit = $newCount - $allowedNewCards->count();
            } elseif ($remainingAfterKnown > 0) {
                // New cards compete for remaining review slots
                $maxNew = min($newLimit, $remainingAfterKnown);
                $allowedNewCards = $newCards->slice(0, $maxNew);
                $hiddenByNewLimit = $newCount - $allowedNewCards->count();
            } else {
                // No review slots remaining, hide all new cards
                $hiddenByNewLimit = $newCount;
            }
        } elseif (!$newLimitEnabled) {
            // No separate new-card limit — new cards are still subject to the
            // daily review limit unless new_cards_ignore_review_limit is enabled.
            if ($newIgnoreReviewLimit) {
                $allowedNewCards = $newCards;
            } elseif ($remainingAfterKnown > 0) {
                $allowedNewCards = $newCards->slice(0, $remainingAfterKnown);
                $hiddenByNewLimit = $newCount - $allowedNewCards->count();
            } else {
                $hiddenByNewLimit = $newCount;
            }
        }

        // Merge known + new, preserving order (known first, ordered by due_at)
        $visibleCards = $allowedReviewCards->concat($allowedNewCards);
        $visibleCount = $visibleCards->count();
        $hiddenDueCount = $totalDueCount - $visibleCount;
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
            ),
        ];
    }

    public function serializeCard(ReviewCard $card): array
    {
        $sense = $card->sense;
        $tokenPayload = $this->senseTokenPayloadService->exampleSentenceTokenPayload($sense);

        return [
            'review_card_id' => $card->id,
            'word_sense_id' => $sense->id,
            'lemma' => $sense->lemma,
            'surface_form' => $sense->surface_form,
            'pos' => $sense->pos,
            'sense_zh' => $sense->sense_zh,
            'sense_en' => $sense->sense_en,
            'aliases_zh' => $sense->aliases_zh ?: [],
            'collocations' => $sense->collocations ?: [],
            'example_sentence_en' => $sense->example_sentence_en,
            'example_sentence_zh' => $sense->example_sentence_zh,
            'example_sentence_tokens' => $tokenPayload['tokens'],
            'example_sentence_token_source' => $tokenPayload['source'],
            'fsrs_state' => $card->fsrs_state,
            'fsrs_due_at' => $card->fsrs_due_at,
            'fsrs_stability' => $card->fsrs_stability,
            'fsrs_difficulty' => $card->fsrs_difficulty,
            'fsrs_reps' => $card->fsrs_reps,
            'fsrs_lapses' => $card->fsrs_lapses,
        ];
    }

    public function sourceContext(int $userId, string $language, int $senseId): array
    {
        return $this->senseSourceContextService->sourceContext($userId, $language, $senseId);
    }
}
