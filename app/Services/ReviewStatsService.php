<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReviewStatsService
{
    /**
     * All aggregate stats for the current user/language.
     * Merges cardStats and reviewActivity into a single response.
     */
    public function all(int $userId, string $language): array
    {
        return array_merge(
            $this->cardStats($userId, $language),
            $this->reviewActivity($userId, $language),
        );
    }

    /**
     * Card-level aggregate statistics scoped to confirmed sense cards only.
     */
    public function cardStats(int $userId, string $language): array
    {
        $base = $this->baseCardQuery($userId, $language);

        // Total confirmed sense cards (enabled + archived)
        $total = (clone $base)->count();

        // Enabled cards count
        $enabled = (clone $base)->where('review_cards.fsrs_enabled', true)->count();

        // Archived cards count
        $archived = (clone $base)->where('review_cards.fsrs_enabled', false)->count();

        // Due cards (enabled and past due)
        $due = (clone $base)
            ->where('review_cards.fsrs_enabled', true)
            ->where('review_cards.fsrs_due_at', '<=', Carbon::now())
            ->count();

        // State distribution (only enabled cards)
        $stateCounts = (clone $base)
            ->where('review_cards.fsrs_enabled', true)
            ->select('review_cards.fsrs_state', DB::raw('COUNT(*) as count'))
            ->groupBy('review_cards.fsrs_state')
            ->pluck('count', 'fsrs_state')
            ->toArray();

        $byState = [
            'new'        => (int) ($stateCounts['new'] ?? 0),
            'learning'   => (int) ($stateCounts['learning'] ?? 0),
            'review'     => (int) ($stateCounts['review'] ?? 0),
            'relearning' => (int) ($stateCounts['relearning'] ?? 0),
        ];

        // Average stability (enabled cards with non-null stability only)
        $avgStability = (clone $base)
            ->where('review_cards.fsrs_enabled', true)
            ->whereNotNull('review_cards.fsrs_stability')
            ->avg('review_cards.fsrs_stability');
        $averageStability = $avgStability !== null ? round((float) $avgStability, 2) : null;

        // Average difficulty (enabled cards with non-null difficulty only)
        $avgDifficulty = (clone $base)
            ->where('review_cards.fsrs_enabled', true)
            ->whereNotNull('review_cards.fsrs_difficulty')
            ->avg('review_cards.fsrs_difficulty');
        $averageDifficulty = $avgDifficulty !== null ? round((float) $avgDifficulty, 2) : null;

        // Total lapses across all enabled cards
        $lapsesTotal = (int) (clone $base)
            ->where('review_cards.fsrs_enabled', true)
            ->sum('review_cards.fsrs_lapses');

        return [
            'total'              => $total,
            'enabled'            => $enabled,
            'archived'           => $archived,
            'due'                => $due,
            'by_state'           => $byState,
            'average_stability'  => $averageStability,
            'average_difficulty' => $averageDifficulty,
            'lapses_total'       => $lapsesTotal,
        ];
    }

    /**
     * Today's review activity stats from review_logs.
     * All queries join through review_cards to enforce sense-only filtering.
     */
    public function reviewActivity(int $userId, string $language): array
    {
        $today = Carbon::today();

        $reviewedToday = $this->baseLogQuery($userId, $language, $today)
            ->where('review_logs.source', '!=', 'reset')
            ->count();

        $resetCount = $this->baseLogQuery($userId, $language, $today)
            ->where('review_logs.source', '=', 'reset')
            ->count();

        return [
            'reviewed_today' => $reviewedToday,
            'reset_count'    => $resetCount,
        ];
    }

    // ==================== Private helpers ====================

    /**
     * Base query for confirmed sense review cards scoped to user/language.
     *
     * Joins word_senses to enforce:
     *  - target_type = 'sense'
     *  - word_senses.status = 'confirmed'
     *  - user_id / language_id isolation on both tables
     */
    private function baseCardQuery(int $userId, string $language)
    {
        return ReviewCard::query()
            ->select('review_cards.*')
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
     * Base query for today's sense-only review logs.
     *
     * Joins review_cards (for target_type filtering) and word_senses
     * (for status/user/language isolation) since ReviewLog has no
     * target_type or direct sense link.
     */
    private function baseLogQuery(int $userId, string $language, Carbon $since)
    {
        return ReviewLog::query()
            ->select('review_logs.*')
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
}
