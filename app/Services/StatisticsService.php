<?php

namespace App\Services;

use App\Models\EncounteredWord;
use App\Models\Goal;
use App\Models\GoalAchievement;
use App\Models\ReviewLog;
use App\Models\WordSenseOccurrence;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * ADR-0046: the single read-only definition source for Statistics V3.
 */
class StatisticsService
{
    private const PERIODS = [7, 30, 90, 365];

    public function __construct(
        private ReviewCardManageQueryService $cardQueries,
        private FsrsRetentionWorkloadSimulationService $workloadPlanner,
        private ReviewStudyTimezoneService $studyTimezone,
    ) {
    }

    public function getStatistics(
        int $userId,
        string $language,
        ?Request $request = null,
        string $timezone = 'UTC',
    ): array {
        $request ??= Request::create('/statistics/get', 'POST');
        $periodDays = (int) $request->input('period_days', 30);
        if (!in_array($periodDays, self::PERIODS, true)) {
            $periodDays = 30;
        }

        $criteria = $this->cardQueries->parseCriteria($request);
        $cardQuery = $this->cardQueries->buildFromCriteria(
            $request,
            $criteria,
            $userId,
            $language,
        );
        $learningDateRange = null;
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        if (is_string($dateFrom) && is_string($dateTo) && $dateFrom !== '' && $dateTo !== '') {
            $learningDateRange = $this->studyTimezone->inclusiveDateRangeBounds($dateFrom, $dateTo);
            $cardQuery->whereHas('sense', function ($senseQuery) use ($learningDateRange): void {
                $senseQuery->whereNotNull('learning_started_at')
                    ->where('learning_started_at', '>=', $learningDateRange['range_start'])
                    ->where('learning_started_at', '<', $learningDateRange['range_end']);
            });
        }
        $cards = $cardQuery
            ->reorder('review_cards.id')
            ->get([
                'review_cards.id',
                'review_cards.target_id',
                'review_cards.fsrs_state',
                'review_cards.fsrs_due_at',
                'review_cards.fsrs_stability',
                'review_cards.fsrs_difficulty',
                'review_cards.fsrs_last_reviewed_at',
                'review_cards.fsrs_reps',
                'review_cards.fsrs_lapses',
                'review_cards.fsrs_enabled',
                'review_cards.lifecycle_state',
            ]);
        $cardIds = $cards->pluck('id')->map(fn ($id) => (int) $id)->all();

        $now = Carbon::now($timezone);
        $periodStart = $now->copy()->subDays($periodDays - 1)->startOfDay()->utc();
        $logs = $cardIds === []
            ? collect()
            : ReviewLog::query()
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->whereIn('review_card_id', $cardIds)
                ->whereIn('source', ReviewLog::FORMAL_RATING_SOURCES)
                ->notUndone()
                ->where('reviewed_at', '>=', $periodStart)
                ->orderBy('reviewed_at')
                ->orderBy('id')
                ->get();

        $calendar = $this->calendar($logs, $periodDays, $now, $timezone);
        $futureDue = $this->futureDue($cards, $now, $timezone);
        $ratingDistribution = $this->ratingDistribution($logs);
        $duration = $this->reviewDuration($logs);
        $trueRetention = $this->trueRetention($logs, $timezone);
        $retrievability = $this->retrievability($cards, $now);
        $planner = $this->workloadPlanner->planner($userId, $language, $cardIds, 91);

        return [
            'schema_version' => 3,
            'generated_at' => $now->toIso8601String(),
            'scope' => [
                'period_days' => $periodDays,
                'query' => $criteria->toSearchMeta(),
                'card_count' => $cards->count(),
                'timezone' => $timezone,
                'learning_date_range' => $learningDateRange === null ? null : [
                    'date_from' => $learningDateRange['date_from'],
                    'date_to' => $learningDateRange['date_to'],
                    'timezone' => $learningDateRange['timezone'],
                ],
            ],
            'summary_cards' => [
                ['key' => 'due_today', 'label' => '今日到期', 'value' => $futureDue['today']],
                ['key' => 'reviews', 'label' => "{$periodDays} 天复习", 'value' => $logs->count()],
                ['key' => 'retention', 'label' => '真实保持率', 'value' => $trueRetention['rate_percent'], 'unit' => '%'],
                ['key' => 'average_seconds', 'label' => '平均答题', 'value' => $duration['average_seconds'], 'unit' => '秒'],
            ],
            'future_due' => $futureDue,
            'calendar' => $calendar,
            'card_states' => $this->cardStates($cards, $logs),
            'review_time' => $duration,
            'interval_distribution' => $this->intervalDistribution($cards),
            'fsrs' => [
                'stability' => $this->numericDistribution($cards->pluck('fsrs_stability'), [1, 7, 30, 90, 365], '天'),
                'difficulty' => $this->numericDistribution($cards->pluck('fsrs_difficulty'), [2, 4, 6, 8, 10], ''),
                'retrievability' => $retrievability,
            ],
            'memory_durability' => $this->memoryDurability($cards, $now),
            'future_pressure' => [
                'available' => $planner['available'],
                'horizons' => $planner['ordinary_horizons'],
                'curve' => $planner['ordinary_curve'],
                'assumptions' => $planner['assumptions'],
                'warnings' => $planner['warnings'],
            ],
            'ratings' => $ratingDistribution,
            'true_retention' => $trueRetention,
            'hardest_senses' => $this->hardestSenses($cards, $logs),
            'reading_conversion' => $this->readingConversion($userId, $language, $cards),
            'definitions' => [
                'future_due' => '仅统计未来到期日；当前逾期积压不进入预测。',
                'review_time' => '正式且未撤销评分；每次最长按 60 秒计入。',
                'true_retention' => '每张卡每天首次正式评分；Again 为失败，其余为通过。',
                'retrievability' => '由当前 stability 与距上次复习的天数估算；缺失 FSRS 数据的卡片不计。',
            ],
        ];
    }

    private function futureDue(Collection $cards, Carbon $now, string $timezone): array
    {
        $today = $now->copy()->startOfDay();
        $daily = array_fill(0, 365, 0);
        foreach ($cards as $card) {
            if (!$card->fsrs_due_at || !$card->fsrs_enabled || $card->lifecycle_state !== 'active') {
                continue;
            }
            $due = Carbon::parse($card->fsrs_due_at)->setTimezone($timezone);
            $day = $today->diffInDays($due->copy()->startOfDay(), false);
            if ($day >= 0 && $day < 365) {
                $daily[$day]++;
            }
        }

        $rows = [];
        foreach ($daily as $offset => $count) {
            $rows[] = [
                'date' => $today->copy()->addDays($offset)->toDateString(),
                'count' => $count,
            ];
        }

        return [
            'today' => $daily[0],
            'horizons' => [
                '7' => array_sum(array_slice($daily, 0, 7)),
                '30' => array_sum(array_slice($daily, 0, 30)),
                '90' => array_sum(array_slice($daily, 0, 90)),
                '365' => array_sum($daily),
            ],
            'daily' => $rows,
            'overdue_excluded' => $cards->filter(fn ($card) => $card->fsrs_due_at
                && Carbon::parse($card->fsrs_due_at)->lt($today->copy()->utc()))->count(),
        ];
    }

    private function calendar(Collection $logs, int $days, Carbon $now, string $timezone): array
    {
        $counts = $logs->groupBy(fn ($log) => Carbon::parse($log->reviewed_at)
            ->setTimezone($timezone)->toDateString())->map->count();
        $rows = [];
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = $now->copy()->subDays($offset)->toDateString();
            $rows[] = ['date' => $date, 'count' => (int) ($counts[$date] ?? 0)];
        }
        return ['days' => $rows, 'active_days' => $counts->filter()->count()];
    }

    private function cardStates(Collection $cards, Collection $logs): array
    {
        $counts = array_fill_keys(['new', 'learning', 'review', 'relearning'], 0);
        foreach ($cards as $card) {
            if (isset($counts[$card->fsrs_state])) {
                $counts[$card->fsrs_state]++;
            }
        }
        $counts['special_study_reviews'] = $logs
            ->where('source', ReviewLog::SOURCE_SPECIAL_STUDY)->count();
        return $counts;
    }

    private function reviewDuration(Collection $logs): array
    {
        $durations = $logs->pluck('review_duration_ms')->filter(fn ($value) => $value !== null)
            ->map(fn ($value) => min(60000, max(0, (int) $value)));
        return [
            'total_seconds' => round($durations->sum() / 1000, 1),
            'average_seconds' => $durations->isEmpty() ? null : round($durations->avg() / 1000, 1),
            'timed_reviews' => $durations->count(),
            'total_reviews' => $logs->count(),
        ];
    }

    private function ratingDistribution(Collection $logs): array
    {
        $labels = ['again' => 'Again', 'hard' => 'Hard', 'good' => 'Good', 'easy' => 'Easy'];
        return collect($labels)->map(fn ($label, $rating) => [
            'rating' => $rating,
            'label' => $label,
            'count' => $logs->where('rating', $rating)->count(),
        ])->values()->all();
    }

    private function trueRetention(Collection $logs, string $timezone): array
    {
        $first = $logs->groupBy(fn ($log) => $log->review_card_id . ':'
            . Carbon::parse($log->reviewed_at)->setTimezone($timezone)->toDateString())
            ->map(fn (Collection $group) => $group->first());
        $passed = $first->filter(fn ($log) => $log->rating !== 'again')->count();
        $total = $first->count();
        $reviewState = $first->filter(fn ($log) => $log->previous_state === 'review');
        $reviewPassed = $reviewState->filter(fn ($log) => $log->rating !== 'again')->count();

        return [
            'passed' => $passed,
            'failed' => $total - $passed,
            'total' => $total,
            'rate_percent' => $total === 0 ? null : round($passed * 100 / $total, 1),
            'review_state' => [
                'passed' => $reviewPassed,
                'total' => $reviewState->count(),
                'rate_percent' => $reviewState->isEmpty()
                    ? null
                    : round($reviewPassed * 100 / $reviewState->count(), 1),
            ],
        ];
    }

    private function intervalDistribution(Collection $cards): array
    {
        $values = $cards->filter(fn ($card) => $card->fsrs_due_at && $card->fsrs_last_reviewed_at)
            ->map(fn ($card) => max(0, Carbon::parse($card->fsrs_last_reviewed_at)
                ->diffInDays(Carbon::parse($card->fsrs_due_at), false)));
        return $this->numericDistribution($values, [1, 7, 30, 90, 365], '天');
    }

    private function numericDistribution(Collection $values, array $bounds, string $unit): array
    {
        $values = $values->filter(fn ($value) => $value !== null && is_numeric($value))->map(fn ($value) => (float) $value);
        $bins = [];
        $lower = 0;
        foreach ($bounds as $bound) {
            $bins[] = [
                'label' => "{$lower}-{$bound}{$unit}",
                'count' => $values->filter(fn ($value) => $value >= $lower && $value < $bound)->count(),
            ];
            $lower = $bound;
        }
        $bins[] = ['label' => "{$lower}+{$unit}", 'count' => $values->filter(fn ($value) => $value >= $lower)->count()];
        return [
            'bins' => $bins,
            'count' => $values->count(),
            'average' => $values->isEmpty() ? null : round($values->avg(), 3),
        ];
    }

    private function retrievability(Collection $cards, Carbon $now): array
    {
        $values = $cards->filter(fn ($card) => $card->fsrs_stability !== null && $card->fsrs_last_reviewed_at)
            ->map(fn ($card) => $this->cardRetrievability($card, $now));
        $distribution = $this->numericDistribution($values, [0.5, 0.7, 0.8, 0.9, 1.0], '');
        $distribution['coverage'] = [
            'included' => $values->count(),
            'total' => $cards->count(),
        ];
        return $distribution;
    }

    private function memoryDurability(Collection $cards, Carbon $now): array
    {
        $counts = array_fill_keys(['fragile', 'consolidating', 'stable', 'evidence_poor'], 0);
        foreach ($cards as $card) {
            $reps = (int) ($card->fsrs_reps ?? 0);
            if ($reps < 2 || $card->fsrs_stability === null || !$card->fsrs_last_reviewed_at) {
                $counts['evidence_poor']++;
                continue;
            }

            $retrievability = $this->cardRetrievability($card, $now);
            $lapses = (int) ($card->fsrs_lapses ?? 0);
            if ($retrievability < 0.70 || $lapses >= 3) {
                $counts['fragile']++;
            } elseif ($reps >= 3 && $lapses < 3
                && (float) $card->fsrs_stability >= 30.0 && $retrievability >= 0.90) {
                $counts['stable']++;
            } else {
                $counts['consolidating']++;
            }
        }

        return [
            'states' => [
                ['key' => 'fragile', 'label' => '容易遗忘', 'count' => $counts['fragile']],
                ['key' => 'consolidating', 'label' => '正在巩固', 'count' => $counts['consolidating']],
                ['key' => 'stable', 'label' => '掌握稳定', 'count' => $counts['stable']],
                ['key' => 'evidence_poor', 'label' => '证据不足', 'count' => $counts['evidence_poor']],
            ],
            'coverage' => [
                'sufficient' => $cards->count() - $counts['evidence_poor'],
                'total' => $cards->count(),
            ],
            'criteria' => [
                'fragile' => '有足够复习证据，且当前可回忆概率低于 70%，或已出现至少 3 次遗忘。',
                'consolidating' => '已有复习证据，但稳定度或当前可回忆概率尚未达到稳定标准。',
                'stable' => '至少 3 次复习、稳定度至少 30 天、当前可回忆概率至少 90%，且未达到易遗忘阈值。',
                'evidence_poor' => '少于 2 次复习，或缺少稳定度/上次复习时间；不会被标为掌握稳定。',
            ],
        ];
    }

    private function cardRetrievability($card, Carbon $now): float
    {
        $elapsed = max(0.0, Carbon::parse($card->fsrs_last_reviewed_at)
            ->diffInSeconds($now, false) / 86400);

        return pow(1.0 + ($elapsed / (9.0 * max(0.01, (float) $card->fsrs_stability))), -1);
    }

    private function hardestSenses(Collection $cards, Collection $logs): array
    {
        $again = $logs->where('rating', 'again')->countBy('review_card_id');
        $present = function ($card, $value, string $metric): array {
            return [
                'review_card_id' => (int) $card->id,
                'word_sense_id' => (int) $card->target_id,
                'lemma' => optional($card->sense)->lemma,
                'sense_zh' => optional($card->sense)->sense_zh,
                $metric => $value,
            ];
        };

        return [
            'most_failed' => $cards->filter(fn ($card) => ($again[$card->id] ?? 0) > 0)
                ->sortByDesc(fn ($card) => $again[$card->id])->take(10)->values()
                ->map(fn ($card) => $present($card, (int) $again[$card->id], 'again_count'))->all(),
            'most_difficult' => $cards->whereNotNull('fsrs_difficulty')->sortByDesc('fsrs_difficulty')
                ->take(10)->values()->map(fn ($card) => $present($card, (float) $card->fsrs_difficulty, 'difficulty'))->all(),
            'least_stable' => $cards->whereNotNull('fsrs_stability')->sortBy('fsrs_stability')
                ->take(10)->values()->map(fn ($card) => $present($card, (float) $card->fsrs_stability, 'stability'))->all(),
        ];
    }

    private function readingConversion(int $userId, string $language, Collection $cards): array
    {
        $goalId = Goal::query()->where('user_id', $userId)->where('language', $language)
            ->where('type', 'read_words')->value('id');
        $readWords = $goalId ? (int) GoalAchievement::query()->where('user_id', $userId)
            ->where('language', $language)->where('goal_id', $goalId)->sum('achieved_quantity') : 0;
        $encountered = EncounteredWord::query()->where('user_id', $userId)
            ->where('language', $language)->count();
        $senseIds = $cards->pluck('target_id')->unique()->values();
        $senses = $senseIds->count();
        $boundOccurrences = $senseIds->isEmpty() ? 0 : WordSenseOccurrence::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->whereIn('word_sense_id', $senseIds)
            ->where('status', WordSenseOccurrence::STATUS_BOUND)
            ->count();

        return [
            'baseline_scope' => 'user_language',
            'card_scope' => 'unified_query',
            'read_words' => $readWords,
            'encountered_words' => $encountered,
            'confirmed_senses' => $senses,
            'sense_cards' => $cards->count(),
            'bound_source_occurrences' => $boundOccurrences,
            'sense_conversion_percent' => $encountered === 0 ? null : round($senses * 100 / $encountered, 1),
        ];
    }
}
