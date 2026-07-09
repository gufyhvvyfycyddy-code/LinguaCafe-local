<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class SenseReviewQueryService
{
    /**
     * Base query for confirmed sense review cards scoped to a user/language.
     *
     * Joins word_senses and enforces:
     *  - target_type = 'sense'
     *  - user_id / language_id isolation on both tables
     *  - word_senses.status = 'confirmed'
     *
     * Does NOT include fsrs_enabled or fsrs_due_at — callers add those
     * as needed for their specific scenario (due queue, stats, etc.).
     */
    public function confirmedSenseCardQuery(int $userId, string $language): Builder
    {
        return ReviewCard::query()
            ->join('word_senses', function ($join) {
                $join->on('word_senses.id', '=', 'review_cards.target_id')
                    ->where('review_cards.target_type', ReviewCard::TARGET_SENSE);
            })
            ->where('review_cards.user_id', $userId)
            ->where('review_cards.language_id', $language)
            ->where('word_senses.user_id', $userId)
            ->where('word_senses.language_id', $language)
            ->where('word_senses.status', WordSense::STATUS_CONFIRMED);
    }

    /**
     * Base query for sense review logs scoped to a user/language and
     * a starting datetime.
     *
     * Joins review_cards (for target_type filtering) and word_senses
     * (for status / user / language isolation) since ReviewLog has no
     * direct sense link.
     *
     * Does NOT filter source/rating — callers add reset exclusions
     * or inclusions as needed.
     */
    public function confirmedSenseReviewLogQuery(int $userId, string $language, Carbon $since): Builder
    {
        return ReviewLog::query()
            ->join('review_cards', 'review_cards.id', '=', 'review_logs.review_card_id')
            ->join('word_senses', function ($join) {
                $join->on('word_senses.id', '=', 'review_cards.target_id')
                    ->where('review_cards.target_type', ReviewCard::TARGET_SENSE);
            })
            ->where('review_logs.user_id', $userId)
            ->where('review_logs.language_id', $language)
            ->where('review_logs.reviewed_at', '>=', $since)
            ->where('review_cards.user_id', $userId)
            ->where('review_cards.language_id', $language)
            ->where('word_senses.user_id', $userId)
            ->where('word_senses.language_id', $language)
            ->where('word_senses.status', WordSense::STATUS_CONFIRMED);
    }

    /**
     * Sense review log query that excludes reset-type entries.
     *
     * Builds on confirmedSenseReviewLogQuery() and adds both
     * source != reset and rating != reset so that daily review
     * count and stats "reviewed today" use the same definition.
     */
    public function nonResetSenseReviewLogQuery(int $userId, string $language, Carbon $since): Builder
    {
        return $this->confirmedSenseReviewLogQuery($userId, $language, $since)
            ->where('review_logs.source', '!=', 'reset')
            ->where('review_logs.rating', '!=', 'reset');
    }

    /**
     * Card-scoped ReviewLog query that excludes reset-type entries.
     *
     * Unlike the sense-scoped helpers above, this does NOT join
     * word_senses — it is scoped purely by review_card_id. User /
     * language isolation is inherent: a ReviewCard belongs to exactly
     * one user and one language, so the caller only passes card ids
     * that belong to the current user.
     *
     * Used by the card-scoped analytics path (per-card learning
     * feedback) so that reset exclusion lives in one place.
     *
     * @param  array<int>  $cardIds
     */
    public function nonResetCardReviewLogQuery(array $cardIds): Builder
    {
        return ReviewLog::query()
            ->whereIn('review_card_id', $cardIds)
            ->where('rating', '!=', 'reset')
            ->where('source', '!=', 'reset');
    }
}
