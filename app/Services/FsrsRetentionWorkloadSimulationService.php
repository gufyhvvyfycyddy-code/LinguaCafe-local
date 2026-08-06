<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\WordSense;
use Carbon\Carbon;
use App\Models\ReviewLog;
use App\Services\Settings\Presets\ReviewSettingsResolver;

/**
 * Read-only retention workload simulator.
 *
 * Estimates the impact of different desired retention rates on
 * today's due count and next-7-days due count for eligible sense cards.
 * Does NOT write to the database, create ReviewLog entries, or modify
 * any ReviewCard records.
 */
class FsrsRetentionWorkloadSimulationService
{
    private FsrsSchedulingService $fsrsSchedulingService;
    private ReviewSettingsResolver $reviewSettings;

    /** @var float[] Retention rates to simulate */
    private array $retentionRates = [0.85, 0.90, 0.93, 0.95];

    public function __construct(
        ?FsrsSchedulingService $fsrsSchedulingService = null,
        ?ReviewSettingsResolver $reviewSettings = null,
    )
    {
        $this->fsrsSchedulingService = $fsrsSchedulingService ?? new FsrsSchedulingService();
        $this->reviewSettings = $reviewSettings ?? app(ReviewSettingsResolver::class);
    }

    /**
     * Run the workload simulation for the given user and language.
     *
     * @param int    $userId
     * @param string $language
     *
     * @return array
     */
    public function simulate(int $userId, string $language): array
    {
        $planner = $this->buildPlanner($userId, $language);
        if (!$this->extensionAvailable()) {
            return [
                'success' => true,
                'simulation_available' => false,
                'current_retention' => $this->fsrsSchedulingService->desiredRetention($userId, $language),
                'target_type' => 'sense',
                'language' => $language,
                'total_candidates' => 0,
                'options' => [],
                'warnings' => ['FSRS 扩展未加载，暂时无法生成工作量模拟。'],
                'planner' => $planner,
            ];
        }

        $cards = $this->candidateCardsQuery($userId, $language)->get();
        $count = $cards->count();

        if ($count === 0) {
            return [
                'success' => true,
                'simulation_available' => true,
                'current_retention' => $this->fsrsSchedulingService->desiredRetention($userId, $language),
                'target_type' => 'sense',
                'language' => $language,
                'total_candidates' => 0,
                'options' => [],
                'warnings' => ['当前没有足够的 review 状态卡片，暂时无法估算工作量。'],
                'planner' => $planner,
            ];
        }

        $currentRetention = $this->fsrsSchedulingService->desiredRetention($userId, $language);
        $activeParams = $this->fsrsSchedulingService->getActiveFsrsParameters($userId, $language);
        $now = Carbon::now();
        $sevenDaysFromNow = (new Carbon())->addDays(7);

        // Compute for all retention rates first
        $results = [];
        foreach ($this->retentionRates as $rate) {
            $results[(string) $rate] = $this->simulateRetention($cards, $rate, $activeParams, $now, $sevenDaysFromNow);
        }

        // Build options array
        $options = [];
        $currentKey = (string) $currentRetention;

        // Find the closest retention in our list
        $closestRetention = $this->findClosestRetention($currentRetention);

        $messages = [
            0.85 => '复习压力较低，但遗忘风险更高。',
            0.90 => '推荐默认，记忆效果和复习量较平衡。',
            0.93 => '记得更稳，复习量会增加。',
            0.95 => '保持率更高，但复习压力可能明显增加。',
        ];

        $recommendations = [
            0.85 => '轻负担',
            0.90 => '推荐默认',
            0.93 => '更稳',
            0.95 => '高负担',
        ];

        foreach ($this->retentionRates as $rate) {
            $data = $results[(string) $rate];
            $isCurrent = abs($rate - $closestRetention) < 0.001;

            $next7Delta = $data['next7_due'] - $results[(string) $closestRetention]['next7_due'];

            $options[] = [
                'retention' => $rate,
                'label' => (int) ($rate * 100) . '%',
                'recommendation' => $recommendations[$rate] ?? '',
                'today_due' => $data['today_due'],
                'next7_due' => $data['next7_due'],
                'next7_delta_vs_current' => $next7Delta,
                'changed_cards' => $data['changed_cards'],
                'message' => $messages[$rate] ?? '',
                'is_current' => $isCurrent,
            ];
        }

        return [
            'success' => true,
            'simulation_available' => true,
            'current_retention' => $currentRetention,
            'target_type' => 'sense',
            'language' => $language,
            'total_candidates' => $count,
            'options' => $options,
            'warnings' => ['这是估算，不会修改卡片。'],
            'planner' => $planner,
        ];
    }

    private function buildPlanner(int $userId, string $language): array
    {
        $config = $this->reviewSettings->resolve($userId, $language);
        $scheduling = $config->scheduling();
        $limits = $config->dailyLimitsForApi();
        $dailyLimit = $limits['daily_review_limit_enabled']
            ? $limits['daily_review_limit']
            : PHP_INT_MAX;
        $retention = $config->fsrsDesiredRetention();
        $now = Carbon::now();
        $planningQuery = $this->planningCardsQuery($userId, $language, $now);
        $totalCandidates = (clone $planningQuery)->count('review_cards.id');
        $cards = $planningQuery->limit(10000)->get();
        $sampleScale = $cards->isEmpty() ? 1.0 : max(1.0, $totalCandidates / $cards->count());
        $daily = array_fill(0, 365, 0);
        $retrievabilitySum = 0.0;
        $retrievabilityCount = 0;
        $difficultySum = 0.0;

        foreach ($cards as $card) {
            $stability = max(0.01, (float) $card->fsrs_stability);
            $difficulty = min(10.0, max(1.0, (float) $card->fsrs_difficulty));
            $difficultySum += $difficulty;
            $dueAt = Carbon::parse($card->fsrs_due_at);
            $lastReviewedAt = Carbon::parse($card->fsrs_last_reviewed_at);

            for ($guard = 0; $guard < 366; $guard++) {
                $day = max(0, (int) floor($now->diffInSeconds($dueAt, false) / 86400));
                if ($day >= 365) {
                    break;
                }
                $daily[$day] += $sampleScale;
                $projectedReviewAt = $dueAt->lt($now) ? $now->copy() : $dueAt->copy();
                $elapsedDays = max(0.0, $lastReviewedAt->diffInSeconds($projectedReviewAt, false) / 86400);
                $retrievabilitySum += pow(1.0 + ($elapsedDays / (9.0 * $stability)), -1);
                $retrievabilityCount++;

                $interval = (int) round(
                    $stability * 9.0 * ((1.0 / max(0.70, $retention)) - 1.0)
                );
                $interval = min(
                    $scheduling['maximum_interval_days'],
                    max(1, $interval),
                );
                $lastReviewedAt = $projectedReviewAt;
                $dueAt = $projectedReviewAt->copy()->addDays($interval);
                $stability *= 1.0 + ((11.0 - $difficulty) / 20.0);
            }
        }

        $averageDurationMs = (int) round(
            ReviewLog::query()
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->whereNull('undone_at')
                ->whereNotNull('review_duration_ms')
                ->where('review_duration_ms', '>', 0)
                ->avg('review_duration_ms') ?? 15000
        );
        $backlog = 0;
        $processedCumulative = 0;
        $dueCumulative = 0;
        $minutesCumulative = 0.0;
        $peakDue = 0;
        $summary = [];
        $rows = [];
        foreach ($daily as $index => $due) {
            $due = (int) round($due);
            $available = $backlog + $due;
            $processed = $dailyLimit === PHP_INT_MAX ? $available : min($available, $dailyLimit);
            $backlog = max(0, $available - $processed);
            $processedCumulative += $processed;
            $dueCumulative += $due;
            $minutes = ($processed * $averageDurationMs) / 60000;
            $minutesCumulative += $minutes;
            $peakDue = max($peakDue, $available);
            $dayNumber = $index + 1;
            $rows[] = [
                'day' => $dayNumber,
                'date' => $now->copy()->addDays($index)->toDateString(),
                'due' => $due,
                'reviews' => $processed,
                'backlog' => $backlog,
                'minutes' => round($minutes, 1),
            ];
            if (in_array($dayNumber, [30, 90, 365], true)) {
                $summary[(string) $dayNumber] = [
                    'days' => $dayNumber,
                    'projected_due' => $dueCumulative,
                    'projected_reviews' => $processedCumulative,
                    'projected_minutes' => round($minutesCumulative, 1),
                    'ending_backlog' => $backlog,
                    'peak_daily_load' => $peakDue,
                ];
            }
        }

        return [
            'available' => $cards->isNotEmpty(),
            'horizons' => array_values($summary),
            'daily' => $rows,
            'assumptions' => [
                'candidate_cards' => $totalCandidates,
                'simulated_cards' => $cards->count(),
                'desired_retention' => $retention,
                'maximum_interval_days' => $scheduling['maximum_interval_days'],
                'daily_review_limit' => $dailyLimit === PHP_INT_MAX ? null : $dailyLimit,
                'average_review_seconds' => round($averageDurationMs / 1000, 1),
                'average_difficulty' => $cards->isEmpty()
                    ? null
                    : round($difficultySum / $cards->count(), 3),
                'average_retrievability_at_due' => $retrievabilityCount === 0
                    ? null
                    : round($retrievabilitySum / $retrievabilityCount, 4),
                'model' => 'Deterministic Good-rating heuristic based on FSRS stability, difficulty and retrievability fields; actual ratings and new cards will change the result.',
            ],
            'warnings' => $cards->isEmpty()
                ? ['当前没有带完整 stability / difficulty 的可规划卡片。']
                : array_values(array_filter([
                    '这是只读估算，不会重排卡片；实际评分和新增卡片会改变结果。',
                    $totalCandidates > $cards->count()
                        ? '卡片超过 10,000 张，规划器使用有界样本并按总量缩放。'
                        : null,
                ])),
        ];
    }

    /**
     * Simulate workload for a specific retention rate.
     *
     * @param \Illuminate\Support\Collection $cards
     * @param float                          $retention
     * @param array                          $activeParams
     * @param Carbon                         $now
     * @param Carbon                         $sevenDaysFromNow
     *
     * @return array{today_due: int, next7_due: int, changed_cards: int}
     */
    private function simulateRetention($cards, float $retention, array $activeParams, Carbon $now, Carbon $sevenDaysFromNow): array
    {
        $todayDue = 0;
        $next7Due = 0;
        $changedCards = 0;

        foreach ($cards as $card) {
            $preview = $this->buildPreviewForCard($card, $activeParams, $retention, $now);
            if ($preview === null) {
                continue;
            }

            $previewDueAt = Carbon::parse($preview['preview_due_at']);

            if ($previewDueAt->lte($now)) {
                $todayDue++;
            }

            if ($previewDueAt->lte($sevenDaysFromNow)) {
                $next7Due++;
            }

            // Changed: compare dates (not timestamps) of current vs preview due
            $currentDueAt = $card->fsrs_due_at instanceof Carbon
                ? $card->fsrs_due_at
                : Carbon::parse($card->fsrs_due_at);

            if ($currentDueAt->toDateString() !== $previewDueAt->toDateString()) {
                $changedCards++;
            }
        }

        return [
            'today_due' => $todayDue,
            'next7_due' => $next7Due,
            'changed_cards' => $changedCards,
        ];
    }

    /**
     * Build a preview row for a single card at a given retention rate.
     */
    private function buildPreviewForCard($card, array $activeParams, float $retention, Carbon $now): ?array
    {
        if ($card->fsrs_stability === null || $card->fsrs_difficulty === null) {
            return null;
        }

        $elapsedDays = 0;
        if ($card->fsrs_last_reviewed_at !== null) {
            $lastReview = $card->fsrs_last_reviewed_at instanceof Carbon
                ? $card->fsrs_last_reviewed_at
                : Carbon::parse($card->fsrs_last_reviewed_at);
            $elapsedDays = (int) max(0, $lastReview->diffInDays($now));
        }

        try {
            $memory = new \fsrs\MemoryState(
                (float) $card->fsrs_stability,
                (float) $card->fsrs_difficulty
            );
            $fsrs = new \fsrs\FSRS($activeParams);
            $states = $fsrs->next_states($memory, $retention, $elapsedDays);
            $goodState = $states->get_good();
            $newInterval = max(1, (int) round($goodState->get_interval()));
        } catch (\Throwable $e) {
            return null;
        }

        $lastReview = $card->fsrs_last_reviewed_at instanceof Carbon
            ? $card->fsrs_last_reviewed_at
            : Carbon::parse($card->fsrs_last_reviewed_at);

        $previewDueAt = $lastReview->copy()->addDays($newInterval);

        return [
            'preview_due_at' => $previewDueAt->toIso8601String(),
        ];
    }

    /**
     * Query eligible candidate cards (same criteria as reschedule preview).
     */
    private function candidateCardsQuery(int $userId, string $language)
    {
        return ReviewCard::query()
            ->join('word_senses', function ($join) {
                $join->on('word_senses.id', '=', 'review_cards.target_id')
                    ->where('review_cards.target_type', ReviewCard::TARGET_SENSE);
            })
            ->where('review_cards.user_id', $userId)
            ->where('review_cards.language_id', $language)
            ->where('review_cards.target_type', ReviewCard::TARGET_SENSE)
            ->where('review_cards.fsrs_state', 'review')
            ->where('review_cards.fsrs_enabled', true)
            ->whereNotNull('review_cards.fsrs_stability')
            ->whereNotNull('review_cards.fsrs_difficulty')
            ->whereNotNull('review_cards.fsrs_last_reviewed_at')
            ->whereNotNull('review_cards.fsrs_due_at')
            ->where('word_senses.user_id', $userId)
            ->where('word_senses.language_id', $language)
            ->where('word_senses.status', WordSense::STATUS_CONFIRMED)
            ->select([
                'review_cards.id as review_card_id',
                'review_cards.target_id as word_sense_id',
                'review_cards.fsrs_due_at',
                'review_cards.fsrs_stability',
                'review_cards.fsrs_difficulty',
                'review_cards.fsrs_last_reviewed_at',
            ]);
    }

    private function planningCardsQuery(int $userId, string $language, Carbon $now)
    {
        return ReviewCard::query()
            ->join('word_senses', function ($join) {
                $join->on('word_senses.id', '=', 'review_cards.target_id')
                    ->where('review_cards.target_type', ReviewCard::TARGET_SENSE);
            })
            ->senseReviewEligible($userId, $language, $now)
            ->where('word_senses.user_id', $userId)
            ->where('word_senses.language_id', $language)
            ->where('word_senses.status', WordSense::STATUS_CONFIRMED)
            ->whereIn('review_cards.fsrs_state', ['review', 'relearning'])
            ->whereNotNull('review_cards.fsrs_stability')
            ->whereNotNull('review_cards.fsrs_difficulty')
            ->whereNotNull('review_cards.fsrs_last_reviewed_at')
            ->whereNotNull('review_cards.fsrs_due_at')
            ->orderBy('review_cards.id')
            ->select([
                'review_cards.id as review_card_id',
                'review_cards.fsrs_due_at',
                'review_cards.fsrs_stability',
                'review_cards.fsrs_difficulty',
                'review_cards.fsrs_last_reviewed_at',
            ]);
    }

    /**
     * Find the closest retention rate in our list to the current one.
     */
    private function findClosestRetention(float $current): float
    {
        $closest = 0.90;
        $minDiff = 100;
        foreach ($this->retentionRates as $rate) {
            $diff = abs($rate - $current);
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $closest = $rate;
            }
        }
        return $closest;
    }

    private function extensionAvailable(): bool
    {
        return extension_loaded('fsrs-rs-php')
            && class_exists('\fsrs\FSRS')
            && function_exists('get_default_parameters');
    }
}
