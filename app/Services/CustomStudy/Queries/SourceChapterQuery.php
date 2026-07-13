<?php

namespace App\Services\CustomStudy\Queries;

use App\Models\ReviewCard;
use App\Services\SenseReviewQueryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Custom Study Phase 2B — CS-5: source_chapter candidate query.
 *
 * Builds a composable Eloquent Builder for ReviewCard rows whose
 * underlying WordSense is associated with the given chapter via either:
 *   - `word_senses.source_chapter_id = $chapterId` (direct path), OR
 *   - a bound `WordSenseOccurrence` with `chapter_id = $chapterId`,
 *     `status = bound`, matching `user_id` + `language_id` (occurrence path).
 *
 * Boundary (frozen by Task 2000-18 §8):
 *   - Returns a Builder — does NOT load models, does NOT apply card_limit,
 *     does NOT implement SessionOrder, does NOT create tokens or sessions.
 *   - Reuses SenseReviewQueryService::confirmedSenseCardQuery() for
 *     user/language/target_type/confirmed-sense isolation.
 *   - Reuses ReviewCard::scopeSenseReviewEligible() for lifecycle +
 *     fsrs_enabled isolation.
 *   - Uses `whereExists` for both paths so the candidate-card row is never
 *     duplicated even when multiple occurrences of the same WordSense exist
 *     in the same chapter.
 *   - Does NOT re-check chapter ownership — that belongs to
 *     CustomStudyCriteriaValidator → ChapterLocatorInterface.
 *   - Does NOT write ReviewLog, ReviewCard, WordSense, WordSenseOccurrence.
 *   - Single composable SQL when terminated. No N+1.
 *
 * Task CS-5 of Custom Study 1A Phase 2B (Task 2000-18).
 */
class SourceChapterQuery
{
    public function __construct(
        private readonly SenseReviewQueryService $senseReviewQueryService
    ) {
    }

    /**
     * Build the candidate query for source_chapter mode.
     *
     * @param int $userId Trusted current user id.
     * @param string $language Trusted current language.
     * @param int $chapterId Trusted chapter id (already validated as owned
     *                       by the current user + language via ChapterLocator).
     * @param Carbon $now Current instant (used for lifecycle eligibility).
     * @return Builder<ReviewCard>
     */
    public function build(int $userId, string $language, int $chapterId, Carbon $now): Builder
    {
        return $this->senseReviewQueryService
            ->confirmedSenseCardQuery($userId, $language)
            ->senseReviewEligible($userId, $language, $now)
            ->where(function (Builder $outer) use ($userId, $language, $chapterId): void {
                // Path 1: WordSense.source_chapter_id direct match.
                $outer->whereExists(function (QueryBuilder $q) use ($chapterId): void {
                    $q->select(\Illuminate\Support\Facades\DB::raw(1))
                        ->from('word_senses as ws_direct')
                        ->whereColumn('ws_direct.id', 'review_cards.target_id')
                        ->where('ws_direct.source_chapter_id', $chapterId);
                });

                // Path 2: bound WordSenseOccurrence with matching chapter.
                $outer->orWhereExists(function (QueryBuilder $q) use ($userId, $language, $chapterId): void {
                    $q->select(\Illuminate\Support\Facades\DB::raw(1))
                        ->from('word_sense_occurrences as wso')
                        ->join('word_senses as ws_occ', 'ws_occ.id', '=', 'wso.word_sense_id')
                        ->whereColumn('ws_occ.id', 'review_cards.target_id')
                        ->where('wso.chapter_id', $chapterId)
                        ->where('wso.status', 'bound')
                        ->where('wso.user_id', $userId)
                        ->where('wso.language_id', $language);
                });
            });
    }
}
