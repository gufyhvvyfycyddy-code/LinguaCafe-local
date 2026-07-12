<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Services\SenseReviewQueryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReviewStatsService
{
    public function __construct(
        private SenseReviewQueryService $senseReviewQueryService,
    ) {
    }
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
     *
     * ADR-0010: The old `fsrs_enabled` binary (enabled/archived) is split
     * into 4 lifecycle state counts: active, buried, suspended, archived.
     * The legacy `enabled`/`archived` keys are kept as deprecated aliases:
     *   - enabled  = active + buried  (fsrs_enabled mirror = true)
     *   - archived_legacy = suspended + archived (fsrs_enabled mirror = false)
     *
     * "Queue-eligible" = lifecycle_state=active AND not effectively buried
     * (buried_until IS NULL OR buried_until <= now). This matches the
     * unified scopeSenseReviewEligible in ReviewCard model.
     */
    public function cardStats(int $userId, string $language): array
    {
        $base = $this->baseCardQuery($userId, $language);
        $now = Carbon::now();

        // Total confirmed sense cards (all lifecycle states)
        $total = (clone $base)->count();

        // ADR-0010: lifecycle state distribution
        $activeCount = (clone $base)
            ->where('review_cards.lifecycle_state', ReviewCard::LIFECYCLE_ACTIVE)
            ->count();
        $buriedCount = (clone $base)
            ->where('review_cards.lifecycle_state', ReviewCard::LIFECYCLE_BURIED)
            ->count();
        $suspendedCount = (clone $base)
            ->where('review_cards.lifecycle_state', ReviewCard::LIFECYCLE_SUSPENDED)
            ->count();
        $archivedCount = (clone $base)
            ->where('review_cards.lifecycle_state', ReviewCard::LIFECYCLE_ARCHIVED)
            ->count();

        // Legacy aliases (deprecated, will be removed in a future release)
        $enabled = $activeCount + $buriedCount;
        $archivedLegacy = $suspendedCount + $archivedCount;

        // Due cards: only queue-eligible (active + not effectively buried) and past due
        $due = (clone $base)
            ->where('review_cards.lifecycle_state', ReviewCard::LIFECYCLE_ACTIVE)
            ->where(function ($q) use ($now) {
                $q->whereNull('review_cards.buried_until')
                    ->orWhere('review_cards.buried_until', '<=', $now);
            })
            ->where('review_cards.fsrs_due_at', '<=', $now)
            ->count();

        // State distribution (only queue-eligible active cards)
        $stateCounts = (clone $base)
            ->where('review_cards.lifecycle_state', ReviewCard::LIFECYCLE_ACTIVE)
            ->where(function ($q) use ($now) {
                $q->whereNull('review_cards.buried_until')
                    ->orWhere('review_cards.buried_until', '<=', $now);
            })
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

        // Average stability (queue-eligible active cards with non-null stability only)
        $avgStability = (clone $base)
            ->where('review_cards.lifecycle_state', ReviewCard::LIFECYCLE_ACTIVE)
            ->where(function ($q) use ($now) {
                $q->whereNull('review_cards.buried_until')
                    ->orWhere('review_cards.buried_until', '<=', $now);
            })
            ->whereNotNull('review_cards.fsrs_stability')
            ->avg('review_cards.fsrs_stability');
        $averageStability = $avgStability !== null ? round((float) $avgStability, 2) : null;

        // Average difficulty (queue-eligible active cards with non-null difficulty only)
        $avgDifficulty = (clone $base)
            ->where('review_cards.lifecycle_state', ReviewCard::LIFECYCLE_ACTIVE)
            ->where(function ($q) use ($now) {
                $q->whereNull('review_cards.buried_until')
                    ->orWhere('review_cards.buried_until', '<=', $now);
            })
            ->whereNotNull('review_cards.fsrs_difficulty')
            ->avg('review_cards.fsrs_difficulty');
        $averageDifficulty = $avgDifficulty !== null ? round((float) $avgDifficulty, 2) : null;

        // Total lapses across all queue-eligible active cards
        $lapsesTotal = (int) (clone $base)
            ->where('review_cards.lifecycle_state', ReviewCard::LIFECYCLE_ACTIVE)
            ->where(function ($q) use ($now) {
                $q->whereNull('review_cards.buried_until')
                    ->orWhere('review_cards.buried_until', '<=', $now);
            })
            ->sum('review_cards.fsrs_lapses');

        return [
            'total'              => $total,
            // ADR-0010: lifecycle state distribution
            'active'             => $activeCount,
            'buried'             => $buriedCount,
            'suspended'          => $suspendedCount,
            'archived'           => $archivedCount,
            // Legacy aliases (deprecated)
            'enabled'            => $enabled,
            'archived_legacy'    => $archivedLegacy,
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

        $reviewedToday = $this->senseReviewQueryService
            ->nonResetSenseReviewLogQuery($userId, $language, $today)
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
     * Delegates common joins/filters to SenseReviewQueryService and adds
     * select('review_cards.*') for card-level stats.
     */
    private function baseCardQuery(int $userId, string $language)
    {
        return $this->senseReviewQueryService
            ->confirmedSenseCardQuery($userId, $language)
            ->select('review_cards.*');
    }

    /**
     * Base query for sense-only review logs.
     *
     * Delegates common joins/filters to SenseReviewQueryService and adds
     * select('review_logs.*') for log-level aggregation.
     */
    private function baseLogQuery(int $userId, string $language, Carbon $since)
    {
        return $this->senseReviewQueryService
            ->confirmedSenseReviewLogQuery($userId, $language, $since)
            ->select('review_logs.*');
    }
}
