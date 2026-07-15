<?php

namespace App\Services\Settings\Presets;

use App\Services\ReviewQueueOrderOptions;

final class ReviewSettingsPresetConfig
{
    public const SCHEMA_VERSION = 1;

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
            ],
            'daily_limits' => [
                'new_cards_enabled' => true,
                'new_cards_per_day' => 20,
                'reviews_enabled' => true,
                'maximum_reviews_per_day' => 200,
                'new_cards_ignore_review_limit' => false,
            ],
            'queue_order' => ReviewQueueOrderOptions::defaults()->toArray(),
        ]);
    }

    public static function fromArray(array $input): self
    {
        if (($input['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
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

        return new self([
            'schema_version' => self::SCHEMA_VERSION,
            'fsrs' => [
                'desired_retention' => (float) $retention,
                'parameters' => $parameters,
                'parameters_source' => $source,
                'parameters_optimized_at' => $optimizedAt,
            ],
            'daily_limits' => $normalizedLimits,
            'queue_order' => $queue,
        ]);
    }

    public function withPatch(array $patch): self
    {
        return self::fromArray(array_replace_recursive($this->config, $patch));
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
}
