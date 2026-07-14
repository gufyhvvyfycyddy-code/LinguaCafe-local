<?php

namespace App\Services\CustomStudy\Queries;

use App\Models\ReviewCard;
use App\Services\SenseReviewQueryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

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
        $reviewCardIds = $this->eligibleChapterMatches($userId, $language, $now)
            ->where('chapter_id', $chapterId)
            ->select('review_card_id');

        return ReviewCard::query()->whereIn('review_cards.id', $reviewCardIds);
    }

    /**
     * Build the shared pre-limit mapping of eligible review cards to chapters.
     * UNION and the outer distinct count keep direct/occurrence duplicates from
     * changing either the session candidates or chapter option counts.
     */
    public function eligibleChapterMatches(int $userId, string $language, Carbon $now): QueryBuilder
    {
        $base = $this->senseReviewQueryService
            ->confirmedSenseCardQuery($userId, $language)
            ->senseReviewEligible($userId, $language, $now);

        $direct = (clone $base)
            ->select('review_cards.id as review_card_id', 'word_senses.source_chapter_id as chapter_id')
            ->whereNotNull('word_senses.source_chapter_id');

        $boundOccurrence = (clone $base)
            ->join('word_sense_occurrences as occurrences', function ($join) use ($userId, $language) {
                $join->on('occurrences.word_sense_id', '=', 'review_cards.target_id')
                    ->where('occurrences.user_id', $userId)
                    ->where('occurrences.language_id', $language)
                    ->where('occurrences.status', 'bound');
            })
            ->select('review_cards.id as review_card_id', 'occurrences.chapter_id as chapter_id')
            ->whereNotNull('occurrences.chapter_id');

        return DB::query()
            ->fromSub($direct->union($boundOccurrence), 'eligible_chapter_matches')
            ->select('review_card_id', 'chapter_id');
    }
}
