<?php

namespace App\Services\Settings\Presets;

use App\Services\ReviewQueueOrderOptions;

final class ReviewSettingsPresetConfig
{
    public const SCHEMA_VERSION = 3;
    private const LEGACY_SCHEMA_VERSIONS = [1, 2];
    private const EASY_DAY_MODES = ['normal', 'reduced', 'minimum'];
    private const OPTIMIZATION_MODES = ['manual', 'interval'];

    private const FALLBACK_PARAMETERS = [
        0.40255, 1.18385, 3.173, 15.69105, 7.1949, 0.5345, 1.4604,
        0.0046, 1.54575, 0.1192, 1.01925, 1.9395, 0.11, 0.29605,
        2.2698, 0.2315, 2.9898, 0.51655, 0.6621,
    ];

    private function __construct(private array $config)
    {
    }

    public static function defaults(): self
    {
        $parameters = function_exists('get_default_parameters')
            ? array_values(get_default_parameters())
            : self::FALLBACK_PARAMETERS;

        return self::fromArray([
            'schema_version' => self::SCHEMA_VERSION,
            'fsrs' => [
                'desired_retention' => 0.90,
                'parameters' => $parameters,
                'parameters_source' => 'default',
                'parameters_optimized_at' => null,
                'optimization_mode' => 'manual',
                'optimization_interval_days' => 30,
            ],
            'daily_limits' => [
                'new_cards_enabled' => true,
                'new_cards_per_day' => 20,
                'reviews_enabled' => true,
                'maximum_reviews_per_day' => 200,
                'new_cards_ignore_review_limit' => false,
            ],
            'queue_order' => ReviewQueueOrderOptions::defaults()->toArray(),
            'scheduling' => self::schedulingDefaults(),
            'experience' => self::experienceDefaults(),
        ]);
    }

    public static function fromArray(array $input): self
    {
        $version = $input['schema_version'] ?? null;
        if (!in_array($version, [...self::LEGACY_SCHEMA_VERSIONS, self::SCHEMA_VERSION], true)) {
            throw new \InvalidArgumentException('Unsupported review settings preset schema.');
        }

        $fsrs = $input['fsrs'] ?? [];
        $retention = $fsrs['desired_retention'] ?? null;
        if (!is_numeric($retention) || (float) $retention < 0.70 || (float) $retention > 0.97) {
            throw new \InvalidArgumentException('Desired retention must be between 0.70 and 0.97.');
        }

        $parameters = self::normalizeParameters($fsrs['parameters'] ?? null);
        $source = $fsrs['parameters_source'] ?? 'default';
        if (!is_string($source) || !in_array($source, ['default', 'optimized', 'custom'], true)) {
            throw new \InvalidArgumentException('Invalid FSRS parameter source.');
        }
        $optimizedAt = $fsrs['parameters_optimized_at'] ?? null;
        if ($optimizedAt !== null && !is_string($optimizedAt)) {
            throw new \InvalidArgumentException('Invalid FSRS optimized timestamp.');
        }
        $optimizationMode = $fsrs['optimization_mode'] ?? 'manual';
        if (!is_string($optimizationMode) || !in_array($optimizationMode, self::OPTIMIZATION_MODES, true)) {
            throw new \InvalidArgumentException('Invalid FSRS optimization mode.');
        }
        $optimizationIntervalDays = self::integer([
            'optimization_interval_days' => $fsrs['optimization_interval_days'] ?? 30,
        ], 'optimization_interval_days', 1, 365);

        $limits = $input['daily_limits'] ?? [];
        $normalizedLimits = [
            'new_cards_enabled' => self::boolean($limits, 'new_cards_enabled'),
            'new_cards_per_day' => self::integer($limits, 'new_cards_per_day', 0, 999),
            'reviews_enabled' => self::boolean($limits, 'reviews_enabled'),
            'maximum_reviews_per_day' => self::integer($limits, 'maximum_reviews_per_day', 0, 9999),
            'new_cards_ignore_review_limit' => self::boolean($limits, 'new_cards_ignore_review_limit'),
        ];

        $queue = ReviewQueueOrderOptions::fromArray($input['queue_order'] ?? [])->toArray();
        unset($queue['scope'], $queue['preset_supported']);
        $scheduling = array_replace(
            self::schedulingDefaults(),
            is_array($input['scheduling'] ?? null) ? $input['scheduling'] : [],
        );
        $maximumInterval = self::integer($scheduling, 'maximum_interval_days', 1, 36500);
        $minimumRelearningInterval = self::integer(
            $scheduling,
            'minimum_relearning_interval_days',
            1,
            $maximumInterval,
        );
        $easyDays = $scheduling['easy_days'] ?? null;
        if (!is_array($easyDays) || count($easyDays) !== 7) {
            throw new \InvalidArgumentException('easy_days must contain exactly seven values.');
        }
        $easyDays = array_values(array_map(function (mixed $mode): string {
            if (!is_string($mode) || !in_array($mode, self::EASY_DAY_MODES, true)) {
                throw new \InvalidArgumentException('Invalid Easy Day mode.');
            }
            return $mode;
        }, $easyDays));

        $experience = array_replace(
            self::experienceDefaults(),
            is_array($input['experience'] ?? null) ? $input['experience'] : [],
        );
        $questionSeconds = self::integer($experience, 'question_timer_seconds', 0, 3600);
        $answerSeconds = self::integer($experience, 'answer_timer_seconds', 0, 3600);
        $autoAdvance = self::boolean($experience, 'auto_advance_enabled');
        if ($autoAdvance && $questionSeconds === 0 && $answerSeconds === 0) {
            throw new \InvalidArgumentException('Auto advance requires a non-zero question or answer timer.');
        }

        return new self([
            'schema_version' => self::SCHEMA_VERSION,
            'fsrs' => [
                'desired_retention' => (float) $retention,
                'parameters' => $parameters,
                'parameters_source' => $source,
                'parameters_optimized_at' => $optimizedAt,
                'optimization_mode' => $optimizationMode,
                'optimization_interval_days' => $optimizationIntervalDays,
            ],
            'daily_limits' => $normalizedLimits,
            'queue_order' => $queue,
            'scheduling' => [
                'learning_steps_minutes' => self::stepMinutes($scheduling, 'learning_steps_minutes'),
                'relearning_steps_minutes' => self::stepMinutes($scheduling, 'relearning_steps_minutes'),
                'maximum_interval_days' => $maximumInterval,
                'minimum_relearning_interval_days' => $minimumRelearningInterval,
                'easy_days' => $easyDays,
            ],
            'experience' => [
                'show_timer' => self::boolean($experience, 'show_timer'),
                'question_timer_seconds' => $questionSeconds,
                'answer_timer_seconds' => $answerSeconds,
                'auto_advance_enabled' => $autoAdvance,
                'audio_autoplay' => self::boolean($experience, 'audio_autoplay'),
                'audio_replay_answer' => self::boolean($experience, 'audio_replay_answer'),
            ],
        ]);
    }

    public function withPatch(array $patch): self
    {
        $merged = array_replace_recursive($this->config, $patch);
        foreach (['learning_steps_minutes', 'relearning_steps_minutes'] as $key) {
            if (array_key_exists($key, $patch['scheduling'] ?? [])) {
                $merged['scheduling'][$key] = $patch['scheduling'][$key];
            }
        }

        return self::fromArray($merged);
    }

    public function toArray(): array
    {
        return $this->config;
    }

    public function fsrsDesiredRetention(): float
    {
        return $this->config['fsrs']['desired_retention'];
    }

    public function fsrsParameters(): array
    {
        return $this->config['fsrs']['parameters'];
    }

    public function fsrsMetadata(): array
    {
        return [
            'parameters_source' => $this->config['fsrs']['parameters_source'],
            'parameters_optimized_at' => $this->config['fsrs']['parameters_optimized_at'],
        ];
    }

    public function fsrsOptimizationPolicy(): array
    {
        return [
            'mode' => $this->config['fsrs']['optimization_mode'],
            'interval_days' => $this->config['fsrs']['optimization_interval_days'],
        ];
    }

    public function dailyLimitsForApi(): array
    {
        $limits = $this->config['daily_limits'];
        return [
            'daily_new_limit_enabled' => $limits['new_cards_enabled'],
            'daily_new_limit' => $limits['new_cards_per_day'],
            'daily_review_limit_enabled' => $limits['reviews_enabled'],
            'daily_review_limit' => $limits['maximum_reviews_per_day'],
            'new_cards_ignore_review_limit' => $limits['new_cards_ignore_review_limit'],
        ];
    }

    public function queueOrderForApi(): array
    {
        return $this->config['queue_order'];
    }

    public function scheduling(): array
    {
        return $this->config['scheduling'];
    }

    public function experience(): array
    {
        return $this->config['experience'];
    }

    public function advancedSettingsForApi(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'scheduling' => $this->scheduling(),
            'experience' => $this->experience(),
        ];
    }

    private static function normalizeParameters(mixed $parameters): array
    {
        if (!is_array($parameters) || count($parameters) < 19 || count($parameters) > 21) {
            throw new \InvalidArgumentException('FSRS parameters must contain 19 to 21 values.');
        }
        return array_map(function (mixed $value): float {
            if (!is_numeric($value) || !is_finite((float) $value) || abs((float) $value) > 1000) {
                throw new \InvalidArgumentException('Invalid FSRS parameter value.');
            }
            return (float) $value;
        }, array_values($parameters));
    }

    private static function boolean(array $values, string $key): bool
    {
        if (!array_key_exists($key, $values) || !is_bool($values[$key])) {
            throw new \InvalidArgumentException("{$key} must be boolean.");
        }
        return $values[$key];
    }

    private static function integer(array $values, string $key, int $min, int $max): int
    {
        if (!array_key_exists($key, $values) || filter_var($values[$key], FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException("{$key} must be an integer.");
        }
        $value = (int) $values[$key];
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException("{$key} is out of range.");
        }
        return $value;
    }

    private static function schedulingDefaults(): array
    {
        return [
            'learning_steps_minutes' => [10, 30],
            'relearning_steps_minutes' => [10],
            'maximum_interval_days' => 36500,
            'minimum_relearning_interval_days' => 1,
            'easy_days' => array_fill(0, 7, 'normal'),
        ];
    }

    private static function experienceDefaults(): array
    {
        return [
            'show_timer' => true,
            'question_timer_seconds' => 0,
            'answer_timer_seconds' => 0,
            'auto_advance_enabled' => false,
            'audio_autoplay' => false,
            'audio_replay_answer' => false,
        ];
    }

    private static function stepMinutes(array $values, string $key): array
    {
        $steps = $values[$key] ?? null;
        if (!is_array($steps) || count($steps) > 10) {
            throw new \InvalidArgumentException("{$key} must be an array with at most ten values.");
        }

        $normalized = [];
        foreach (array_values($steps) as $step) {
            if (filter_var($step, FILTER_VALIDATE_INT) === false) {
                throw new \InvalidArgumentException("{$key} values must be integers.");
            }
            $minutes = (int) $step;
            if ($minutes < 1 || $minutes >= 1440) {
                throw new \InvalidArgumentException("{$key} values must be between 1 and 1439 minutes.");
            }
            if ($normalized !== [] && $minutes <= end($normalized)) {
                throw new \InvalidArgumentException("{$key} values must be strictly increasing.");
            }
            $normalized[] = $minutes;
        }

        return $normalized;
    }
}
