<?php

namespace App\Services\CustomStudy\Queries;

use App\Models\ReviewCard;
use App\Services\ReviewStudyTimezoneService;
use App\Services\SenseReviewQueryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Custom Study Phase 2A — CS-4: overdue candidate query.
 *
 * Builds a composable Eloquent Builder for ReviewCard rows whose
 * fsrs_due_at is strictly before the start of the current learning-
 * timezone natural day.
 *
 * Boundary (frozen by Task 2000-17 §7.1 / §7.4):
 *   - Returns a Builder — does NOT load models, does NOT apply card_limit,
 *     does NOT implement SessionOrder, does NOT create tokens or sessions.
 *   - Reuses SenseReviewQueryService::confirmedSenseCardQuery() for
 *     user/language/target_type/confirmed-sense isolation.
 *   - Reuses ReviewCard::scopeSenseReviewEligible() for lifecycle +
 *     fsrs_enabled isolation.
 *   - Reuses ReviewStudyTimezoneService::dayStart() for the local day
 *     boundary (NOT Carbon::today()).
 *   - Strict less-than: fsrs_due_at < dayStart. Cards due exactly at
 *     dayStart, later today, or tomorrow are NOT included.
 *   - fsrs_due_at IS NULL is NOT included (NULL comparisons are never true).
 *   - Does NOT write ReviewLog, ReviewCard, or WordSense.
 *   - No N+1: single composable query.
 *
 * Task CS-4 of Custom Study 1A Phase 2A (Task 2000-17).
 */
class OverdueQuery
{
    public function __construct(
        private readonly SenseReviewQueryService $senseReviewQueryService,
        private readonly ReviewStudyTimezoneService $timezoneService
    ) {
    }

    /**
     * Build the candidate query for overdue mode.
     *
     * @param int $userId Trusted current user id.
     * @param string $language Trusted current language.
     * @param Carbon $now Current instant (used for day boundary + lifecycle).
     * @return Builder<ReviewCard>
     */
    public function build(int $userId, string $language, Carbon $now): Builder
    {
        $dayStart = $this->timezoneService->dayStart($now);

        return $this->senseReviewQueryService
            ->confirmedSenseCardQuery($userId, $language)
            ->senseReviewEligible($userId, $language, $now)
            ->where('review_cards.fsrs_due_at', '<', $dayStart);
    }
}
