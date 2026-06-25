<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SettingsService {
    public const FSRS_OPTIMIZATION_MIN_REQUIRED = 300;
    public const FSRS_OPTIMIZATION_INSUFFICIENT_MESSAGE = '复习记录还不够，先继续复习一段时间再来优化。';
    public const FSRS_OPTIMIZATION_PENDING_MESSAGE = '已经有足够记录，但自动优化还需要下一步接入参数计算。';
    
    public function __construct() {
    }

    public function isJellyfinEnabled() {
        $isJellyfinEnabled = Setting
            ::select('value', 'name')
            ->where('user_id', -1)
            ->where('name', 'jellyfinEnabled')
            ->first();

        if (!$isJellyfinEnabled) {
            throw new \Exception('Missing jellyfinEnabled setting. This should never occur.');
        }

        return json_decode($isJellyfinEnabled->value);
    }

    public function getAnkiSettings() {
        $ankiSettings = Setting
            ::select('value', 'name')
            ->where('user_id', -1)
            ->whereIn('name', ['ankiAutoAddCards', 'ankiShowNotifications'])
            ->get()
            ->keyBy('name')
            ->map(function ($item, $key) {
                return json_decode($item->value);
            });

        if ($ankiSettings->isEmpty()) {
            throw new \Exception('Missing anki settings. This should never occur.');
        }

        return $ankiSettings;
    }

    public function getGlobalSettingsByName($settingNames) {
        $settings = Setting
            ::select('value', 'name')
            ->where('user_id', -1)
            ->whereIn('name', $settingNames)
            ->get()
            ->keyBy('name')
            ->map(function ($item, $key) {
                return json_decode($item->value);
            });

        if ($settings->isEmpty()) {
            throw new \Exception('No settings were found in the database.');
        }

        return $settings;
    }

    public function updateGlobalSettings($settings) {
        foreach ($settings as $settingName => $settingValue) {
            $setting = Setting
                ::where('name', $settingName)
                ->where('user_id', -1)
                ->first();

            if ($setting) {
                $setting->value = json_encode($settingValue);
                $setting->save();
            }
        }

        return true;
    }

    public function getUserSettingsByName($userId, $settingNames) {
        $settings = Setting
            ::select('value', 'name')
            ->where('user_id', $userId)
            ->whereIn('name', $settingNames)
            ->get()
            ->keyBy('name')
            ->map(function ($item, $key) {
                return json_decode($item->value);
            });

        if ($settings->isEmpty()) {
            return null;
        }

        return $settings;
    }

    public function updateUserSettings($userId, $settings) {
        foreach ($settings as $settingName => $settingValue) {
            $setting = Setting
                ::where('name', $settingName)
                ->where('user_id', $userId)
                ->first();

            if (!$setting) {
                $setting = new Setting();
                $setting->user_id = $userId;
                $setting->name = $settingName;
            }

            $setting->value = json_encode($settingValue);
            $setting->save();                
        }

        return true;
    }

    public function getFsrsOptimizationStatus(int $userId, string $language): array {
        $reviewCount = $this->countOptimizableFsrsReviews($userId, $language);
        $canOptimize = $reviewCount >= self::FSRS_OPTIMIZATION_MIN_REQUIRED;

        $status = [
            'review_count' => $reviewCount,
            'min_required' => self::FSRS_OPTIMIZATION_MIN_REQUIRED,
            'can_optimize' => $canOptimize,
            'message' => $canOptimize
                ? self::FSRS_OPTIMIZATION_PENDING_MESSAGE
                : self::FSRS_OPTIMIZATION_INSUFFICIENT_MESSAGE,
        ];

        return array_merge($status, $this->resolveFsrsParameterSource());
    }

    private function resolveFsrsParameterSource(): array {
        $paramSetting = Setting::where('name', 'fsrs_parameters')->where('user_id', -1)->first();

        // No saved parameters → system default
        if (!$paramSetting) {
            return [
                'parameters_source' => 'default',
                'parameters_source_label' => '当前使用默认参数',
                'last_optimized_at' => null,
                'parameters_count' => 19,
                'has_optimized_parameters' => false,
            ];
        }

        // Try to parse the saved parameters JSON
        $params = json_decode($paramSetting->value, true);
        if (!is_array($params) || empty($params)) {
            return [
                'parameters_source' => 'unknown',
                'parameters_source_label' => '参数来源异常，请重新优化或检查设置',
                'last_optimized_at' => null,
                'parameters_count' => 0,
                'has_optimized_parameters' => false,
                'parameters_warning' => '已保存的 fsrs_parameters 无法解析为有效参数数组。',
            ];
        }

        $source = $this->decodeSettingValue('fsrs_parameters_source', 'default');
        $lastOptimizedAt = $this->decodeSettingValue('fsrs_parameters_optimized_at');

        if ($source === 'optimized') {
            return [
                'parameters_source' => 'optimized',
                'parameters_source_label' => '正在优化参数',
                'last_optimized_at' => $lastOptimizedAt,
                'parameters_count' => count($params),
                'has_optimized_parameters' => true,
            ];
        }

        if ($source === 'default') {
            return [
                'parameters_source' => 'default',
                'parameters_source_label' => '当前使用默认参数',
                'last_optimized_at' => null,
                'parameters_count' => count($params),
                'has_optimized_parameters' => false,
            ];
        }

        // Unknown source value — display without crashing
        return [
            'parameters_source' => $source,
            'parameters_source_label' => '当前使用自定义参数',
            'last_optimized_at' => $lastOptimizedAt,
            'parameters_count' => count($params),
            'has_optimized_parameters' => false,
        ];
    }

    private function decodeSettingValue(string $name, mixed $fallback = null): mixed {
        $setting = Setting::where('name', $name)->where('user_id', -1)->first();
        if (!$setting) {
            return $fallback;
        }

        $value = $setting->value;
        if ($value === null) {
            return $fallback;
        }

        // Attempt json_decode: handles both JSON-encoded strings (e.g. '"optimized"')
        // and bare strings (e.g. 'optimized') from older writes.
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Not valid JSON — return the raw string value as-is
        return $value;
    }

    public function preflightFsrsOptimization(int $userId, string $language): array {
        return array_merge(
            ['optimized' => false],
            $this->getFsrsOptimizationStatus($userId, $language),
        );
    }

    private function countOptimizableFsrsReviews(int $userId, string $language): int {
        return ReviewLog::query()
            ->join('review_cards', 'review_cards.id', '=', 'review_logs.review_card_id')
            ->join('word_senses', function ($join) {
                $join->on('word_senses.id', '=', 'review_cards.target_id')
                    ->where('review_cards.target_type', ReviewCard::TARGET_SENSE);
            })
            ->where('review_logs.user_id', $userId)
            ->where('review_logs.language_id', $language)
            ->where('review_logs.source', '!=', 'reset')
            ->where('review_cards.user_id', $userId)
            ->where('review_cards.language_id', $language)
            ->where('word_senses.user_id', $userId)
            ->where('word_senses.language_id', $language)
            ->where('word_senses.status', WordSense::STATUS_CONFIRMED)
            ->count('review_logs.id');
    }

    public function computeFsrsOptimizationPreview(int $userId, string $language): array {
        $status = $this->getFsrsOptimizationStatus($userId, $language);

        if (!$status['can_optimize']) {
            return [
                'optimized' => false,
                'applied' => false,
                'preview_available' => false,
                'current_parameters' => [],
                'optimized_parameters' => [],
                'parameter_count' => 0,
                'card_count' => 0,
                'review_count' => $status['review_count'],
                'min_required' => $status['min_required'],
                'can_optimize' => $status['can_optimize'],
                'message' => $status['message'],
            ];
        }

        try {
            if (!extension_loaded('fsrs-rs-php') || !class_exists('\fsrs\FSRS')) {
                return [
                    'optimized' => false,
                    'applied' => false,
                    'preview_available' => false,
                    'current_parameters' => [],
                    'optimized_parameters' => [],
                    'parameter_count' => 0,
                    'card_count' => 0,
                    'review_count' => $status['review_count'],
                    'min_required' => $status['min_required'],
                    'can_optimize' => $status['can_optimize'],
                    'message' => 'FSRS 扩展未加载，无法进行参数优化。',
                    'error_code' => 'EXTENSION_NOT_LOADED',
                ];
            }

            $trainSet = $this->buildFsrsOptimizationTrainSet($userId, $language);

            if (empty($trainSet)) {
                return [
                    'optimized' => false,
                    'applied' => false,
                    'preview_available' => false,
                    'current_parameters' => [],
                    'optimized_parameters' => [],
                    'parameter_count' => 0,
                    'card_count' => 0,
                    'review_count' => $status['review_count'],
                    'min_required' => $status['min_required'],
                    'can_optimize' => true,
                    'message' => '记录足够但无法构造训练数据，请检查复习记录是否完整。',
                    'error_code' => 'EMPTY_TRAINSET',
                ];
            }

            $fsrs = new \fsrs\FSRS(get_default_parameters());
            $optimized = $fsrs->compute_parameters($trainSet);

            // compute_parameters returns an object; extract weights based on API
            if (is_object($optimized)) {
                if (method_exists($optimized, 'getWeights')) {
                    $params = array_values((array) $optimized->getWeights());
                } elseif (method_exists($optimized, 'toArray')) {
                    $params = array_values($optimized->toArray());
                } else {
                    $params = array_values((array) $optimized);
                }
            } elseif (is_array($optimized)) {
                $params = array_values($optimized);
            } else {
                throw new \Exception('compute_parameters 未返回有效结果。');
            }

            if (count($params) < 19 || count($params) > 21) {
                throw new \Exception('优化返回 ' . count($params) . ' 个参数，预期 19-21 个。');
            }

            foreach ($params as $i => $p) {
                if (!is_numeric($p) || !is_finite((float) $p)) {
                    throw new \Exception("参数 #{$i} 无效: " . var_export($p, true));
                }
            }

            $params = array_map('floatval', $params);

            $defaultParams = get_default_parameters();
            $currentParams = is_array($defaultParams) ? array_values($defaultParams) : [];
            $cardCount = $this->countOptimizableCards($userId, $language);

            return [
                'optimized' => false,
                'applied' => false,
                'preview_available' => true,
                'current_parameters' => $currentParams,
                'optimized_parameters' => $params,
                'parameter_count' => count($params),
                'review_count' => $status['review_count'],
                'card_count' => $cardCount,
                'min_required' => $status['min_required'],
                'can_optimize' => true,
                'message' => '已根据你的复习记录计算出一组优化参数预览。此版本不会保存参数，也不会重排已有卡片。',
            ];
        } catch (\Exception $e) {
            return [
                'optimized' => false,
                'applied' => false,
                'preview_available' => false,
                'current_parameters' => [],
                'optimized_parameters' => [],
                'parameter_count' => 0,
                'card_count' => 0,
                'review_count' => $status['review_count'],
                'min_required' => $status['min_required'],
                'can_optimize' => $status['can_optimize'],
                'message' => '参数优化计算失败：' . $e->getMessage(),
                'error_code' => 'COMPUTE_FAILED',
            ];
        }
    }

    private function buildFsrsOptimizationTrainSet(int $userId, string $language): array {
        $ratingMap = [
            'again' => 1,
            'hard' => 2,
            'good' => 3,
            'easy' => 4,
        ];

        $logs = ReviewLog::query()
            ->join('review_cards', 'review_cards.id', '=', 'review_logs.review_card_id')
            ->join('word_senses', function ($join) {
                $join->on('word_senses.id', '=', 'review_cards.target_id')
                    ->where('review_cards.target_type', ReviewCard::TARGET_SENSE);
            })
            ->where('review_logs.user_id', $userId)
            ->where('review_logs.language_id', $language)
            ->where('review_logs.source', '!=', 'reset')
            ->where('review_logs.rating', '!=', 'reset')
            ->where('review_cards.user_id', $userId)
            ->where('review_cards.language_id', $language)
            ->where('word_senses.user_id', $userId)
            ->where('word_senses.language_id', $language)
            ->where('word_senses.status', WordSense::STATUS_CONFIRMED)
            ->select([
                'review_logs.review_card_id',
                'review_logs.rating',
                'review_logs.reviewed_at',
            ])
            ->orderBy('review_logs.review_card_id')
            ->orderBy('review_logs.reviewed_at')
            ->get();

        $grouped = $logs->groupBy('review_card_id');
        $trainSet = [];

        foreach ($grouped as $cardId => $cardLogs) {
            if ($cardLogs->isEmpty()) {
                continue;
            }

            $reviews = [];
            $previousAt = null;

            foreach ($cardLogs as $log) {
                $ratingInt = $ratingMap[$log->rating] ?? null;
                if ($ratingInt === null) {
                    continue;
                }

                $deltaT = 0;
                if ($previousAt !== null) {
                    $seconds = max(0, $previousAt->diffInSeconds($log->reviewed_at));
                    $deltaT = (int) round($seconds / 86400.0);
                }

                $reviews[] = new \fsrs\FSRSReview($ratingInt, $deltaT);
                $previousAt = $log->reviewed_at;
            }

            // FSRS requires at least 1 review with delta_t > 0 per item
            if (count($reviews) >= 2) {
                $trainSet[] = new \fsrs\FSRSItem($reviews);
            }
        }

        return $trainSet;
    }

    public function applyFsrsOptimizedParameters(int $userId, string $language): array {
        // Re-run the full optimization preview to compute fresh parameters internally.
        // We never trust client-submitted parameters.
        $preview = $this->computeFsrsOptimizationPreview($userId, $language);

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

        $optimizedParams = $preview['optimized_parameters'];

        // Validate the computed parameters
        try {
            $params = $this->validateFsrsParameters($optimizedParams);
        } catch (\InvalidArgumentException $e) {
            return [
                'optimized' => false,
                'applied' => false,
                'preview_available' => false,
                'current_parameters' => [],
                'optimized_parameters' => [],
                'parameter_count' => count($optimizedParams),
                'card_count' => $preview['card_count'],
                'review_count' => $preview['review_count'],
                'min_required' => $preview['min_required'],
                'can_optimize' => $preview['can_optimize'],
                'message' => '参数无效：' . $e->getMessage(),
                'error_code' => 'INVALID_PARAMETERS',
            ];
        }

        // Get current parameters (default or previously saved) as "previous"
        $previousParams = get_default_parameters();
        $existingSetting = Setting::where('name', 'fsrs_parameters')
            ->where('user_id', -1)
            ->first();
        if ($existingSetting) {
            $previousParams = json_decode($existingSetting->value, true);
        }

        // Save all four settings in a transaction
        DB::transaction(function () use ($params, $previousParams) {
            $now = Carbon::now()->toIso8601String();

            $this->upsertGlobalSetting('fsrs_parameters', array_values($params));
            $this->upsertGlobalSetting('fsrs_parameters_source', 'optimized');
            $this->upsertGlobalSetting('fsrs_parameters_optimized_at', $now);
            $this->upsertGlobalSetting('fsrs_parameters_previous', array_values($previousParams));
        });

        $defaultParams = get_default_parameters();

        return [
            'optimized' => true,
            'applied' => true,
            'preview_available' => false,
            'current_parameters' => is_array($defaultParams) ? array_values($defaultParams) : [],
            'optimized_parameters' => $params,
            'parameter_count' => count($params),
            'review_count' => $preview['review_count'],
            'card_count' => $this->countOptimizableCards($userId, $language),
            'min_required' => $preview['min_required'],
            'can_optimize' => false,
            'message' => '优化参数已保存。之后新的复习评分会使用这组参数；已有卡片不会自动重排。',
            'saved_keys' => [
                'fsrs_parameters',
                'fsrs_parameters_source',
                'fsrs_parameters_optimized_at',
                'fsrs_parameters_previous',
            ],
        ];
    }

    private function validateFsrsParameters(array $parameters): array {
        if (empty($parameters)) {
            throw new \InvalidArgumentException('参数数组不能为空。');
        }

        $count = count($parameters);
        if ($count < 19 || $count > 21) {
            throw new \InvalidArgumentException("参数数量无效：{$count}，预期 19-21 个。");
        }

        $validated = [];
        $i = 0;
        foreach ($parameters as $val) {
            if (!is_numeric($val)) {
                throw new \InvalidArgumentException("参数 #{$i} 不是有效数字: " . var_export($val, true));
            }

            $float = (float) $val;
            if (!is_finite($float)) {
                throw new \InvalidArgumentException("参数 #{$i} 不是有限值: " . var_export($val, true));
            }

            if ($float < -1000 || $float > 1000) {
                throw new \InvalidArgumentException("参数 #{$i} 超出合理范围 [-1000, 1000]: {$float}");
            }

            $validated[] = $float;
            $i++;
        }

        return $validated;
    }

    private function upsertGlobalSetting(string $name, mixed $value): void {
        $setting = Setting::where('name', $name)
            ->where('user_id', -1)
            ->first();

        if ($setting) {
            $setting->value = json_encode($value);
            $setting->save();
        } else {
            Setting::forceCreate([
                'user_id' => -1,
                'name' => $name,
                'value' => json_encode($value),
            ]);
        }
    }

    private function countOptimizableCards(int $userId, string $language): int {
        return DB::table('review_logs')
            ->join('review_cards', 'review_cards.id', '=', 'review_logs.review_card_id')
            ->join('word_senses', function ($join) {
                $join->on('word_senses.id', '=', 'review_cards.target_id')
                    ->where('review_cards.target_type', ReviewCard::TARGET_SENSE);
            })
            ->where('review_logs.user_id', $userId)
            ->where('review_logs.language_id', $language)
            ->where('review_logs.source', '!=', 'reset')
            ->where('review_cards.user_id', $userId)
            ->where('review_cards.language_id', $language)
            ->where('word_senses.user_id', $userId)
            ->where('word_senses.language_id', $language)
            ->where('word_senses.status', WordSense::STATUS_CONFIRMED)
            ->distinct()
            ->count('review_logs.review_card_id');
    }
}
