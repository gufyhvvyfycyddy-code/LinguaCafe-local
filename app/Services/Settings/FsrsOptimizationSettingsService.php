<?php

namespace App\Services\Settings;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\WordSense;
use App\Services\Settings\Presets\ReviewSettingsPresetConfig;
use App\Services\Settings\Presets\ReviewSettingsResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class FsrsOptimizationSettingsService
{
    public const MIN_REQUIRED = 300;
    public const INSUFFICIENT_MESSAGE = '复习记录还不够，先继续复习一段时间再来优化。';
    public const PENDING_MESSAGE = '已经有足够记录，但自动优化还需要下一步接入参数计算。';

    public function __construct(private ReviewSettingsResolver $reviewSettings)
    {
    }

    public function getStatus(int $userId, string $language, ?Carbon $now = null): array
    {
        $reviewCount = $this->eligibleReviewLogs($userId, $language)->count('review_logs.id');
        $canOptimize = $reviewCount >= self::MIN_REQUIRED;
        $config = $this->reviewSettings->resolve($userId, $language);

        return array_merge([
            'review_count' => $reviewCount,
            'min_required' => self::MIN_REQUIRED,
            'can_optimize' => $canOptimize,
            'message' => $canOptimize ? self::PENDING_MESSAGE : self::INSUFFICIENT_MESSAGE,
        ], $this->parameterSource($config), [
            'optimization_policy' => $this->optimizationPolicyStatus($config, $canOptimize, $now),
            'diagnostics' => $this->diagnostics($userId, $language),
        ]);
    }

    public function updatePolicy(int $userId, string $language, string $mode, int $intervalDays): array
    {
        $this->reviewSettings->mutate($userId, $language, ['fsrs' => [
            'optimization_mode' => $mode,
            'optimization_interval_days' => $intervalDays,
        ]]);
        $policy = $this->getStatus($userId, $language)['optimization_policy'];

        return [
            'success' => true,
            'message' => $mode === 'interval'
                ? "已设置为每 {$intervalDays} 天自动优化一次。"
                : '已设置为仅手动优化。',
            'optimization_policy' => $policy,
        ];
    }

    public function applyAutomaticallyIfDue(int $userId, string $language, ?Carbon $now = null): array
    {
        $config = $this->reviewSettings->resolve($userId, $language);
        $policy = $this->optimizationPolicyStatus($config, false, $now);
        if ($policy['mode'] !== 'interval') {
            return ['attempted' => false, 'applied' => false, 'reason' => 'manual_mode'];
        }
        if (!$policy['time_due']) {
            return ['attempted' => false, 'applied' => false, 'reason' => 'not_due'];
        }
        $status = $this->getStatus($userId, $language, $now);
        if (!$status['can_optimize']) {
            return ['attempted' => false, 'applied' => false, 'reason' => 'insufficient_reviews'];
        }

        $result = $this->apply($userId, $language);

        return [
            'attempted' => true,
            'applied' => (bool) ($result['applied'] ?? false),
            'reason' => ($result['applied'] ?? false) ? 'applied' : 'optimization_failed',
            'error_code' => $result['error_code'] ?? null,
        ];
    }

    public function preflight(int $userId, string $language): array
    {
        return array_merge(['optimized' => false], $this->getStatus($userId, $language));
    }

    public function computePreview(int $userId, string $language): array
    {
        $status = $this->getStatus($userId, $language);
        if (!$status['can_optimize']) {
            return $this->unavailablePreview($status, $status['message']);
        }

        try {
            if (!extension_loaded('fsrs-rs-php') || !class_exists(\fsrs\FSRS::class)) {
                return $this->unavailablePreview($status, 'FSRS 扩展未加载，无法进行参数优化。', 'EXTENSION_NOT_LOADED');
            }

            $trainSet = $this->buildTrainSet($userId, $language);
            if ($trainSet === []) {
                return $this->unavailablePreview(
                    $status,
                    '记录足够但无法构造训练数据，请检查复习记录是否完整。',
                    'EMPTY_TRAINSET',
                    true,
                );
            }

            $fsrs = new \fsrs\FSRS(get_default_parameters());
            $optimized = $fsrs->compute_parameters($trainSet);
            $parameters = $this->extractComputedParameters($optimized);
            $parameters = $this->validateParameters($parameters);
            $current = $this->reviewSettings->resolve($userId, $language)->fsrsParameters();

            return [
                'optimized' => false,
                'applied' => false,
                'preview_available' => true,
                'current_parameters' => is_array($current) ? array_values($current) : [],
                'optimized_parameters' => $parameters,
                'parameter_count' => count($parameters),
                'review_count' => $status['review_count'],
                'card_count' => $this->countOptimizableCards($userId, $language),
                'min_required' => $status['min_required'],
                'can_optimize' => true,
                'message' => '已根据你的复习记录计算出一组优化参数预览。此版本不会保存参数，也不会重排已有卡片。',
            ];
        } catch (\Exception $exception) {
            return $this->unavailablePreview(
                $status,
                '参数优化计算失败：' . $exception->getMessage(),
                'COMPUTE_FAILED',
            );
        }
    }

    public function apply(int $userId, string $language): array
    {
        $preview = $this->computePreview($userId, $language);
        if (!$preview['can_optimize'] || !$preview['preview_available']) {
            return [
                'optimized' => false,
                'applied' => false,
                'preview_available' => false,
                'current_parameters' => [],
                'optimized_parameters' => [],
                'parameter_count' => 0,
                'card_count' => $preview['card_count'] ?? 0,
                'review_count' => $preview['review_count'] ?? 0,
                'min_required' => $preview['min_required'] ?? 0,
                'can_optimize' => false,
                'message' => $preview['message'] ?? '优化条件不满足。',
                'error_code' => 'INSUFFICIENT_REVIEWS',
            ];
        }

        try {
            $parameters = $this->validateParameters($preview['optimized_parameters']);
        } catch (\InvalidArgumentException $exception) {
            return [
                'optimized' => false,
                'applied' => false,
                'preview_available' => false,
                'current_parameters' => [],
                'optimized_parameters' => [],
                'parameter_count' => count($preview['optimized_parameters']),
                'card_count' => $preview['card_count'],
                'review_count' => $preview['review_count'],
                'min_required' => $preview['min_required'],
                'can_optimize' => $preview['can_optimize'],
                'message' => '参数无效：' . $exception->getMessage(),
                'error_code' => 'INVALID_PARAMETERS',
            ];
        }

        $optimizedAt = Carbon::now()->toIso8601String();

        $this->reviewSettings->mutate($userId, $language, ['fsrs' => [
            'parameters' => array_values($parameters),
            'parameters_source' => 'optimized',
            'parameters_optimized_at' => $optimizedAt,
        ]]);

        $current = $this->reviewSettings->resolve($userId, $language)->fsrsParameters();
        return [
            'optimized' => true,
            'applied' => true,
            'preview_available' => false,
            'current_parameters' => is_array($current) ? array_values($current) : [],
            'optimized_parameters' => $parameters,
            'parameter_count' => count($parameters),
            'review_count' => $preview['review_count'],
            'card_count' => $this->countOptimizableCards($userId, $language),
            'min_required' => $preview['min_required'],
            'can_optimize' => false,
            'message' => '优化参数已保存。之后新的复习评分会使用这组参数；已有卡片不会自动重排。',
            'saved_keys' => [
                'fsrs_parameters',
                'fsrs_parameters_source',
                'fsrs_parameters_optimized_at',
            ],
        ];
    }

    public function restoreDefaults(int $userId, string $language): array
    {
        $keys = [
            'fsrs_parameters',
            'fsrs_parameters_source',
            'fsrs_parameters_optimized_at',
        ];
        $defaultFsrs = ReviewSettingsPresetConfig::defaults()->toArray()['fsrs'];
        $defaults = [
            'parameters' => $defaultFsrs['parameters'],
            'parameters_source' => $defaultFsrs['parameters_source'],
            'parameters_optimized_at' => $defaultFsrs['parameters_optimized_at'],
        ];
        $currentFsrs = $this->reviewSettings->resolve($userId, $language)->toArray()['fsrs'];
        $before = [
            'parameters' => $currentFsrs['parameters'],
            'parameters_source' => $currentFsrs['parameters_source'],
            'parameters_optimized_at' => $currentFsrs['parameters_optimized_at'],
        ];
        $this->reviewSettings->mutate($userId, $language, ['fsrs' => $defaults]);
        $changed = $before !== $defaults;

        return [
            'success' => true,
            'message' => $changed
                ? '已恢复 FSRS 默认参数。之后新的复习评分将使用默认参数。'
                : '当前已是默认参数，无需恢复。',
            'deleted_count' => 0,
            'deleted_keys' => $keys,
        ];
    }

    private function eligibleReviewLogs(int $userId, string $language): Builder
    {
        return ReviewLog::query()
            ->join('review_cards', 'review_cards.id', '=', 'review_logs.review_card_id')
            ->join('word_senses', function ($join): void {
                $join->on('word_senses.id', '=', 'review_cards.target_id')
                    ->where('review_cards.target_type', ReviewCard::TARGET_SENSE);
            })
            ->where('review_logs.user_id', $userId)
            ->where('review_logs.language_id', $language)
            ->where('review_logs.source', '!=', 'reset')
            ->where('review_logs.rating', '!=', 'reset')
            ->whereNull('review_logs.undone_at')
            ->where('review_cards.user_id', $userId)
            ->where('review_cards.language_id', $language)
            ->where('word_senses.user_id', $userId)
            ->where('word_senses.language_id', $language)
            ->where('word_senses.status', WordSense::STATUS_CONFIRMED);
    }

    private function diagnostics(int $userId, string $language): array
    {
        $total = ReviewLog::where('user_id', $userId)->where('language_id', $language)->count('id');
        $reset = ReviewLog::where('user_id', $userId)
            ->where('language_id', $language)
            ->where(fn ($query) => $query->where('source', 'reset')->orWhere('rating', 'reset'))
            ->count('id');
        $eligible = $this->eligibleReviewLogs($userId, $language)->count('review_logs.id');
        $eligibleCards = $this->eligibleReviewLogs($userId, $language)
            ->distinct()
            ->count('review_logs.review_card_id');
        $trainableCards = $this->eligibleReviewLogs($userId, $language)
            ->select('review_logs.review_card_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('review_logs.review_card_id')
            ->having('cnt', '>=', 2)
            ->get()
            ->count();
        $excluded = $total - $eligible;
        $inactiveOrDeleted = max(0, $excluded - $reset);
        $confirmedCards = ReviewCard::where('user_id', $userId)
            ->where('language_id', $language)
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->whereHas('sense', fn ($query) => $query->where('status', WordSense::STATUS_CONFIRMED))
            ->count('id');
        $rejectedSenses = WordSense::where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', WordSense::STATUS_REJECTED)
            ->count('id');
        $canOptimizeByCount = $eligible >= self::MIN_REQUIRED;
        $canBuildTrainset = $trainableCards > 0;
        $ratingCounts = $this->eligibleReviewLogs($userId, $language)
            ->select('review_logs.rating', DB::raw('COUNT(*) as cnt'))
            ->groupBy('review_logs.rating')
            ->pluck('cnt', 'rating')
            ->map(fn ($count): int => (int) $count)
            ->all();
        $ratingUsage = [
            'again' => $ratingCounts['again'] ?? 0,
            'hard' => $ratingCounts['hard'] ?? 0,
            'good' => $ratingCounts['good'] ?? 0,
            'easy' => $ratingCounts['easy'] ?? 0,
        ];
        $healthWarnings = [];
        if ($eligible < self::MIN_REQUIRED) {
            $healthWarnings[] = [
                'code' => 'INSUFFICIENT_HISTORY',
                'severity' => 'info',
                'message' => '有效记录不足 300 条，参数优化结果可能不稳定。',
            ];
        }
        if ($ratingUsage['hard'] >= 20 && $ratingUsage['hard'] > max(1, $ratingUsage['again']) * 3) {
            $healthWarnings[] = [
                'code' => 'HARD_MAY_REPLACE_AGAIN',
                'severity' => 'warning',
                'message' => 'Hard 明显多于 Again。忘记答案时应选择 Again，Hard 只表示费力但仍记得。',
            ];
        }
        if ($eligible >= 20 && ($ratingUsage['easy'] / $eligible) > 0.50) {
            $healthWarnings[] = [
                'code' => 'EASY_OVERUSE',
                'severity' => 'warning',
                'message' => 'Easy 占比超过一半；请只在答案确实非常轻松时使用。',
            ];
        }
        if (!$canBuildTrainset && $eligible > 0) {
            $healthWarnings[] = [
                'code' => 'SHALLOW_CARD_HISTORY',
                'severity' => 'info',
                'message' => '多数卡片还没有至少两次有效复习，暂时无法形成训练序列。',
            ];
        }

        [$level, $message] = match (true) {
            $canOptimizeByCount && $canBuildTrainset => ['ready', '已有足够有效复习记录，可以尝试计算优化参数。'],
            $canOptimizeByCount => ['needs_more_card_history', '记录数量接近要求，但部分卡片复习次数太少，暂时无法形成可靠训练数据。'],
            $eligible > 0 => ['insufficient', '当前有效复习记录还不够，继续复习后再优化参数。'],
            default => ['empty', '当前没有可用于参数优化的复习记录。先正常复习一段时间。'],
        };

        return [
            'total_review_logs' => $total,
            'eligible_review_logs' => $eligible,
            'eligible_cards' => $eligibleCards,
            'trainable_cards' => $trainableCards,
            'excluded_review_logs' => $excluded,
            'reset_review_logs' => $reset,
            'inactive_or_deleted_review_logs' => $inactiveOrDeleted,
            'confirmed_sense_cards' => $confirmedCards,
            'rejected_word_senses' => $rejectedSenses,
            'min_required' => self::MIN_REQUIRED,
            'missing_review_logs' => max(0, self::MIN_REQUIRED - $eligible),
            'can_optimize_by_count' => $canOptimizeByCount,
            'can_build_trainset' => $canBuildTrainset,
            'diagnosis_level' => $level,
            'diagnosis_message' => $message,
            'rating_usage' => $ratingUsage,
            'health_status' => collect($healthWarnings)->contains(
                fn (array $warning): bool => $warning['severity'] === 'warning'
            ) ? 'warning' : ($healthWarnings === [] ? 'healthy' : 'info'),
            'health_warnings' => $healthWarnings,
        ];
    }

    private function parameterSource(ReviewSettingsPresetConfig $config): array
    {
        $parameters = $config->fsrsParameters();
        $metadata = $config->fsrsMetadata();
        $source = $metadata['parameters_source'];
        $lastOptimizedAt = $metadata['parameters_optimized_at'];
        if ($source === 'optimized') {
            return [
                'parameters_source' => 'optimized',
                'parameters_source_label' => '正在优化参数',
                'last_optimized_at' => $lastOptimizedAt,
                'parameters_count' => count($parameters),
                'has_optimized_parameters' => true,
            ];
        }
        if ($source === 'default') {
            return array_merge($this->defaultParameterSource(), ['parameters_count' => count($parameters)]);
        }

        return [
            'parameters_source' => $source,
            'parameters_source_label' => '当前使用自定义参数',
            'last_optimized_at' => $lastOptimizedAt,
            'parameters_count' => count($parameters),
            'has_optimized_parameters' => false,
        ];
    }

    private function optimizationPolicyStatus(
        ReviewSettingsPresetConfig $config,
        bool $canOptimize = false,
        ?Carbon $now = null,
    ): array {
        $now ??= Carbon::now();
        $policy = $config->fsrsOptimizationPolicy();
        $lastOptimizedAt = $config->fsrsMetadata()['parameters_optimized_at'];
        $nextEligibleAt = null;
        if (is_string($lastOptimizedAt) && $lastOptimizedAt !== '') {
            try {
                $nextEligibleAt = Carbon::parse($lastOptimizedAt)->addDays($policy['interval_days']);
            } catch (\Throwable) {
                $nextEligibleAt = null;
            }
        }
        $timeDue = $nextEligibleAt === null || $now->greaterThanOrEqualTo($nextEligibleAt);

        return [
            'mode' => $policy['mode'],
            'interval_days' => $policy['interval_days'],
            'last_success_at' => $lastOptimizedAt,
            'next_eligible_at' => $nextEligibleAt?->toIso8601String(),
            'time_due' => $policy['mode'] === 'interval' && $timeDue,
            'automatic_eligible' => $policy['mode'] === 'interval' && $timeDue && $canOptimize,
        ];
    }

    private function defaultParameterSource(): array
    {
        return [
            'parameters_source' => 'default',
            'parameters_source_label' => '当前使用默认参数',
            'last_optimized_at' => null,
            'parameters_count' => 19,
            'has_optimized_parameters' => false,
        ];
    }

    private function unavailablePreview(array $status, string $message, ?string $errorCode = null, ?bool $canOptimize = null): array
    {
        $result = [
            'optimized' => false,
            'applied' => false,
            'preview_available' => false,
            'current_parameters' => [],
            'optimized_parameters' => [],
            'parameter_count' => 0,
            'card_count' => 0,
            'review_count' => $status['review_count'],
            'min_required' => $status['min_required'],
            'can_optimize' => $canOptimize ?? $status['can_optimize'],
            'message' => $message,
        ];
        if ($errorCode !== null) {
            $result['error_code'] = $errorCode;
        }
        return $result;
    }

    private function buildTrainSet(int $userId, string $language): array
    {
        $ratingMap = ['again' => 1, 'hard' => 2, 'good' => 3, 'easy' => 4];
        $logs = $this->eligibleReviewLogs($userId, $language)
            ->select(['review_logs.review_card_id', 'review_logs.rating', 'review_logs.reviewed_at'])
            ->orderBy('review_logs.review_card_id')
            ->orderBy('review_logs.reviewed_at')
            ->get();

        $trainSet = [];
        foreach ($logs->groupBy('review_card_id') as $cardLogs) {
            $reviews = [];
            $previousAt = null;
            foreach ($cardLogs as $log) {
                $rating = $ratingMap[$log->rating] ?? null;
                if ($rating === null) {
                    continue;
                }
                $delta = $previousAt === null
                    ? 0
                    : (int) round(max(0, $previousAt->diffInSeconds($log->reviewed_at)) / 86400.0);
                $reviews[] = new \fsrs\FSRSReview($rating, $delta);
                $previousAt = $log->reviewed_at;
            }
            if (count($reviews) >= 2) {
                $trainSet[] = new \fsrs\FSRSItem($reviews);
            }
        }
        return $trainSet;
    }

    private function extractComputedParameters(mixed $optimized): array
    {
        if (is_object($optimized) && method_exists($optimized, 'getWeights')) {
            return array_values((array) $optimized->getWeights());
        }
        if (is_object($optimized) && method_exists($optimized, 'toArray')) {
            return array_values($optimized->toArray());
        }
        if (is_object($optimized)) {
            return array_values((array) $optimized);
        }
        if (is_array($optimized)) {
            return array_values($optimized);
        }
        throw new \Exception('compute_parameters 未返回有效结果。');
    }

    private function validateParameters(array $parameters): array
    {
        $count = count($parameters);
        if ($count < 19 || $count > 21) {
            throw new \InvalidArgumentException("参数数量无效：{$count}，预期 19-21 个。");
        }

        $validated = [];
        foreach ($parameters as $index => $value) {
            if (!is_numeric($value)) {
                throw new \InvalidArgumentException("参数 #{$index} 不是有效数字: " . var_export($value, true));
            }
            $float = (float) $value;
            if (!is_finite($float)) {
                throw new \InvalidArgumentException("参数 #{$index} 不是有限值: " . var_export($value, true));
            }
            if ($float < -1000 || $float > 1000) {
                throw new \InvalidArgumentException("参数 #{$index} 超出合理范围 [-1000, 1000]: {$float}");
            }
            $validated[] = $float;
        }
        return $validated;
    }

    private function countOptimizableCards(int $userId, string $language): int
    {
        return $this->eligibleReviewLogs($userId, $language)
            ->distinct()
            ->count('review_logs.review_card_id');
    }
}
