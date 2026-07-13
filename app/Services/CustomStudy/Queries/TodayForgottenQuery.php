<?php

namespace App\Services\CustomStudy\Queries;

use App\Models\ReviewCard;
use App\Services\ReviewStudyTimezoneService;
use App\Services\SenseReviewQueryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Custom Study Phase 2A — CS-3: today_forgotten candidate query.
 *
 * Builds a composable Eloquent Builder for ReviewCard rows that have at
 * least one non-undone "again" rating in a sense_review session during
 * the current learning-timezone natural day.
 *
 * Boundary (frozen by Task 2000-17 §7.1):
 *   - Returns a Builder — does NOT load models, does NOT apply card_limit,
 *     does NOT implement SessionOrder, does NOT create tokens or sessions.
 *   - Reuses SenseReviewQueryService::confirmedSenseCardQuery() for
 *     user/language/target_type/confirmed-sense isolation.
 *   - Reuses ReviewCard::scopeSenseReviewEligible() for lifecycle +
 *     fsrs_enabled isolation.
 *   - Reuses ReviewStudyTimezoneService::dayStart() for the local day
 *     boundary (NOT Carbon::today()).
 *   - Does NOT write ReviewLog, ReviewCard, or WordSense.
 *   - No N+1: uses whereExists for the ReviewLog filter.
 *
 * Task CS-3 of Custom Study 1A Phase 2A (Task 2000-17).
 */
class TodayForgottenQuery
{
    public function __construct(
        private readonly SenseReviewQueryService $senseReviewQueryService,
        private readonly ReviewStudyTimezoneService $timezoneService
    ) {
    }

    /**
     * Build the candidate query for today_forgotten mode.
     *
     * @param int $userId Trusted current user id.
     * @param string $language Trusted current language.
     * @param Carbon $now Current instant (used for day boundary + lifecycle).
     * @return Builder<ReviewCard>
     */
    public function build(int $userId, string $language, Carbon $now): Builder
    {
        $dayStart = $this->timezoneService->dayStart($now);
        $nextDayStart = $dayStart->copy()->addDay();

        return $this->senseReviewQueryService
            ->confirmedSenseCardQuery($userId, $language)
            ->senseReviewEligible($userId, $language, $now)
            ->whereExists(function (QueryBuilder $query) use ($userId, $language, $dayStart, $nextDayStart): void {
                $query->select(DB::raw(1))
                    ->from('review_logs')
                    ->whereColumn('review_logs.review_card_id', 'review_cards.id')
                    ->where('review_logs.user_id', $userId)
                    ->where('review_logs.language_id', $language)
                    ->where('review_logs.source', 'sense_review')
                    ->where('review_logs.rating', 'again')
                    ->whereNull('review_logs.undone_at')
                    ->where('review_logs.reviewed_at', '>=', $dayStart)
                    ->where('review_logs.reviewed_at', '<', $nextDayStart);
            });
    }
}
