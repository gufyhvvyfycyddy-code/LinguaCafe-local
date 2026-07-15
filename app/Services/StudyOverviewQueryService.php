<?php

namespace App\Services;

use App\Models\ReviewCardSavedSearch;
use App\Models\ReviewLog;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class StudyOverviewQueryService
{
    private const MAX_CARDS = 10000;
    private const MAX_LOGS = 100000;

    public function __construct(
        private ReviewCardManageQueryService $manageQueryService,
        private ReviewStudyTimezoneService $timezoneService,
        private EffectiveReviewLimitsService $effectiveLimitsService,
        private SenseReviewQueryService $senseReviewQueryService,
        private ReviewQueueOrderService $queueOrderService,
        private FsrsSchedulingService $fsrsSchedulingService,
    ) {
    }

    public function build(int $userId, string $language, int $period, ?int $savedSearchId = null, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $savedSearch = $savedSearchId ? ReviewCardSavedSearch::query()
            ->where('id', $savedSearchId)->where('user_id', $userId)->where('language_id', $language)->firstOrFail() : null;
        if ($savedSearch && $savedSearch->filter_state_version !== 1) {
            throw ValidationException::withMessages(['saved_search_id' => 'Saved Search version is not supported.']);
        }
        $state = ReviewCardManageFilterState::fromArray($savedSearch?->filter_state ?? ['filter' => 'all']);
        $criteria = $this->manageQueryService->parseCriteriaForState($state);
        $query = $this->manageQueryService->buildFromFilterState($state, $criteria, $userId, $language);
        $cardCount = (clone $query)->count('review_cards.id');
        if ($cardCount > self::MAX_CARDS) {
            throw ValidationException::withMessages(['saved_search_id' => 'Study Overview scope exceeds 10,000 cards.']);
        }
        $cards = $query->get();
        $cardIds = $cards->pluck('id')->all();
        $bounds = $this->timezoneService->dayBounds($now);
        $periodStart = $bounds['day_start']->copy()->subDays($period - 1);
        $logs = collect();
        if ($cardIds) {
            $logs = ReviewLog::query()->notUndone()
                ->whereIn('review_card_id', $cardIds)
                ->where('user_id', $userId)->where('language_id', $language)
                ->where('source', 'sense_review')->where('rating', '!=', 'reset')
                ->where('reviewed_at', '<', $bounds['next_day_start'])
                ->orderBy('review_card_id')->orderBy('reviewed_at')->orderBy('id')
                ->limit(self::MAX_LOGS + 1)->get();
            if ($logs->count() > self::MAX_LOGS) {
                throw ValidationException::withMessages(['period' => 'Study Overview scope exceeds 100,000 review logs.']);
            }
        }
        $periodLogs = $logs->filter(fn ($log) => $log->reviewed_at >= $periodStart);
        $effective = $this->effectiveLimitsService->resolve($userId, $language, $now);

        // Workload metrics are the Saved Search inventory intersected with the
        // canonical formal Sense Review eligibility scope. Inventory metrics
        // still use the full $cards collection. This adds one constant-size
        // query and prevents lifecycle eligibility from drifting here.
        $eligibleCardIds = $cardIds
            ? $this->senseReviewQueryService
                ->confirmedSenseCardQuery($userId, $language)
                ->senseReviewEligible($userId, $language, $now)
                ->whereIn('review_cards.id', $cardIds)
                ->pluck('review_cards.id')
                ->all()
            : [];
        $eligibleCards = $cards->whereIn('id', $eligibleCardIds);

        return [
            'meta' => [
                'language' => $language, 'period' => $period, 'timezone' => $bounds['timezone'],
                'scope_card_count' => $cards->count(), 'saved_search' => $savedSearch ? ['id' => $savedSearch->id, 'name' => $savedSearch->name] : null,
                'filter_state' => $state->toArray(), 'generated_at' => $now->toIso8601String(),
                'limits' => ['max_cards' => self::MAX_CARDS, 'max_logs' => self::MAX_LOGS],
            ],
            'today' => array_merge([
                'due_count' => $eligibleCards->where('fsrs_due_at', '<=', $now)->count(),
                'overdue_backlog' => $eligibleCards->filter(fn ($card) => $card->fsrs_due_at && $card->fsrs_due_at < $bounds['day_start'])->count(),
                'daily_limit_scope' => 'user_language_global',
            ], $effective),
            'future_due' => $this->futureDue($eligibleCards, $now, $bounds['timezone']),
            'cards' => $this->cardMetrics($cards, $now),
            'memory' => $this->memoryMetrics($cards, $now, $userId, $language),
            'ratings' => $this->ratingMetrics($periodLogs),
            'review_time' => $this->durationMetrics($periodLogs, $bounds['timezone']),
            'true_retention' => $this->retentionMetrics($logs, $periodStart, $bounds['timezone']),
            'deep_link' => '/review-cards/manage' . ($savedSearch ? '?saved_search_id=' . $savedSearch->id : ''),
        ];
    }

    private function futureDue($cards, Carbon $now, string $timezone): array
    {
        $start = $now->copy()->tz($timezone)->startOfDay();
        $counts = [];
        for ($i = 0; $i < 30; $i++) $counts[$start->copy()->addDays($i)->format('Y-m-d')] = 0;
        foreach ($cards as $card) {
            if (!$card->fsrs_due_at || $card->fsrs_due_at <= $now) continue;
            $date = $card->fsrs_due_at->copy()->tz($timezone)->format('Y-m-d');
            if (array_key_exists($date, $counts)) $counts[$date]++;
        }
        return collect($counts)->map(fn ($count, $date) => ['date' => $date, 'count' => $count])->values()->all();
    }

    private function cardMetrics($cards, Carbon $now): array
    {
        $states = array_fill_keys(['new', 'learning', 'review', 'relearning'], 0);
        $lifecycle = array_fill_keys(['active', 'buried', 'suspended', 'archived'], 0);
        $intervals = array_fill_keys(['lt_1', '1_6', '7_20', '21_89', '90_364', 'gte_365', 'unavailable'], 0);
        foreach ($cards as $card) {
            if (isset($states[$card->fsrs_state])) $states[$card->fsrs_state]++;
            $life = $card->lifecycle_state ?: 'active';
            if ($life === 'active' && $card->buried_until && $card->buried_until->gt($now)) $life = 'buried';
            if (isset($lifecycle[$life])) $lifecycle[$life]++;
            if (!$card->fsrs_due_at || !$card->fsrs_last_reviewed_at) { $intervals['unavailable']++; continue; }
            $days = $card->fsrs_last_reviewed_at->diffInSeconds($card->fsrs_due_at, false) / 86400;
            $key = $days < 1 ? 'lt_1' : ($days < 7 ? '1_6' : ($days < 21 ? '7_20' : ($days < 90 ? '21_89' : ($days < 365 ? '90_364' : 'gte_365'))));
            $intervals[$key]++;
        }
        return ['state_distribution' => $states, 'lifecycle_distribution' => $lifecycle, 'interval_distribution' => $intervals];
    }

    private function memoryMetrics($cards, Carbon $now, int $userId, string $language): array
    {
        $stability = $cards->pluck('fsrs_stability')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->values()->all();
        $difficulty = $cards->pluck('fsrs_difficulty')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->values()->all();
        $retrievability = [];
        foreach ($cards as $card) if ($card->fsrs_stability !== null) $retrievability[] = $this->queueOrderService->computeRetrievability($card, $now);
        $desired = $this->fsrsSchedulingService->desiredRetention($userId, $language);
        return [
            'stability' => $this->numberSummary($stability, [1, 7, 30, 90]),
            'difficulty' => $this->numberSummary($difficulty, [3, 5, 7, 9]),
            'retrievability' => array_merge($this->numberSummary($retrievability, [.8, .9, .95]), [
                'desired_retention' => $desired,
                'below_desired_count' => collect($retrievability)->filter(fn ($v) => $v < $desired)->count(),
                'below_0_8_count' => collect($retrievability)->filter(fn ($v) => $v < .8)->count(),
                'estimated_remembered_count' => round(array_sum($retrievability), 2),
            ]),
        ];
    }

    private function ratingMetrics($logs): array
    {
        $counts = array_fill_keys(['again', 'hard', 'good', 'easy'], 0);
        foreach ($logs as $log) if (isset($counts[$log->rating])) $counts[$log->rating]++;
        return ['counts' => $counts, 'total' => array_sum($counts)];
    }

    private function durationMetrics($logs, string $timezone): array
    {
        $timed = $logs->filter(fn ($log) => $log->review_duration_ms !== null);
        $values = $timed->pluck('review_duration_ms')->map(fn ($v) => (int) $v)->all();
        $daily = $timed->groupBy(fn ($log) => $log->reviewed_at->copy()->tz($timezone)->format('Y-m-d'))
            ->map(fn ($rows, $date) => ['date' => $date, 'duration_ms' => $rows->sum('review_duration_ms'), 'count' => $rows->count()])->values()->all();
        return [
            'total_duration_ms' => array_sum($values), 'average_duration_ms' => $values ? round(array_sum($values) / count($values), 2) : null,
            'median_duration_ms' => $this->median($values), 'timed_review_count' => count($values),
            'untimed_review_count' => $logs->count() - count($values),
            'coverage_percentage' => $logs->count() ? round(count($values) * 100 / $logs->count(), 2) : 0,
            'daily' => $daily,
        ];
    }

    private function retentionMetrics($logs, Carbon $periodStart, string $timezone): array
    {
        $samples = [];
        $unavailable = 0;
        foreach ($logs->groupBy('review_card_id') as $cardLogs) {
            $previous = null; $seenDays = [];
            foreach ($cardLogs as $log) {
                $day = $log->reviewed_at->copy()->tz($timezone)->format('Y-m-d');
                if ($log->reviewed_at >= $periodStart && !isset($seenDays[$day]) && !in_array($log->previous_state, ['new', 'learning'], true)) {
                    $seenDays[$day] = true;
                    if (!$previous || !$log->previous_due_at) { $unavailable++; }
                    else {
                        $plannedDays = $previous->reviewed_at->diffInSeconds($log->previous_due_at, false) / 86400;
                        if ($plannedDays < 0) $unavailable++;
                        else $samples[] = ['pass' => $log->rating !== 'again', 'mature' => $plannedDays >= 21];
                    }
                }
                $previous = $log;
            }
        }
        $young = array_values(array_filter($samples, fn ($s) => !$s['mature']));
        $mature = array_values(array_filter($samples, fn ($s) => $s['mature']));
        return [
            'overall_retention' => $this->retention($samples), 'overall_sample_size' => count($samples),
            'young_retention' => $this->retention($young), 'young_sample_size' => count($young),
            'mature_retention' => $this->retention($mature), 'mature_sample_size' => count($mature),
            'unavailable_count' => $unavailable,
            'coverage_percentage' => ($unavailable + count($samples)) ? round(count($samples) * 100 / ($unavailable + count($samples)), 2) : 0,
        ];
    }

    private function numberSummary(array $values, array $thresholds): array
    {
        $buckets = array_fill(0, count($thresholds) + 1, 0);
        foreach ($values as $value) { $i = 0; while ($i < count($thresholds) && $value >= $thresholds[$i]) $i++; $buckets[$i]++; }
        return ['average' => $values ? round(array_sum($values) / count($values), 4) : null, 'median' => $this->median($values), 'buckets' => $buckets, 'available_count' => count($values)];
    }

    private function median(array $values): ?float
    {
        if (!$values) return null; sort($values); $n = count($values); $m = intdiv($n, 2);
        return (float) ($n % 2 ? $values[$m] : ($values[$m - 1] + $values[$m]) / 2);
    }

    private function retention(array $samples): ?float
    {
        return $samples ? round(count(array_filter($samples, fn ($s) => $s['pass'])) * 100 / count($samples), 2) : null;
    }
}
