<?php

namespace Tests\Unit;

use App\Services\Settings\Presets\ReviewSettingsPresetConfig;
use PHPUnit\Framework\TestCase;

class ReviewSettingsPresetConfigTest extends TestCase
{
    public function test_defaults_are_exact_v3_schema(): void
    {
        $config = ReviewSettingsPresetConfig::defaults()->toArray();

        $this->assertSame(3, $config['schema_version']);
        $this->assertSame(0.90, $config['fsrs']['desired_retention']);
        $this->assertSame('default', $config['fsrs']['parameters_source']);
        $this->assertNull($config['fsrs']['parameters_optimized_at']);
        $this->assertSame('manual', $config['fsrs']['optimization_mode']);
        $this->assertSame(30, $config['fsrs']['optimization_interval_days']);
        $this->assertSame(19, count($config['fsrs']['parameters']));
        $this->assertSame([
            'new_cards_enabled' => true,
            'new_cards_per_day' => 20,
            'reviews_enabled' => true,
            'maximum_reviews_per_day' => 200,
            'new_cards_ignore_review_limit' => false,
        ], $config['daily_limits']);
        $this->assertSame([
            'interday_learning_review_order' => 'mix',
            'new_review_order' => 'mix',
            'review_sort_order' => 'due_random',
            'new_sort_order' => 'created_asc',
        ], $config['queue_order']);
        $this->assertSame([10, 30], $config['scheduling']['learning_steps_minutes']);
        $this->assertSame([10], $config['scheduling']['relearning_steps_minutes']);
        $this->assertSame(36500, $config['scheduling']['maximum_interval_days']);
        $this->assertSame(array_fill(0, 7, 'normal'), $config['scheduling']['easy_days']);
        $this->assertFalse($config['experience']['auto_advance_enabled']);
        $this->assertArrayNotHasKey('fsrs_parameters_previous', $config);
    }

    public function test_invalid_v1_values_are_rejected(): void
    {
        $input = ReviewSettingsPresetConfig::defaults()->toArray();
        $input['queue_order']['review_sort_order'] = 'overdue_desc';

        $this->expectException(\InvalidArgumentException::class);
        ReviewSettingsPresetConfig::fromArray($input);
    }

    public function test_unknown_and_excluded_fields_are_not_serialized(): void
    {
        $input = ReviewSettingsPresetConfig::defaults()->toArray();
        $input['today_only'] = ['new_limit' => 999];
        $input['fsrs']['parameters_previous'] = [1, 2, 3];

        $normalized = ReviewSettingsPresetConfig::fromArray($input)->toArray();

        $this->assertArrayNotHasKey('today_only', $normalized);
        $this->assertArrayNotHasKey('parameters_previous', $normalized['fsrs']);
    }

    public function test_v1_and_v2_schemas_are_normalized_to_v3_defaults(): void
    {
        $input = ReviewSettingsPresetConfig::defaults()->toArray();
        $input['schema_version'] = 1;
        unset($input['scheduling'], $input['experience']);

        $normalized = ReviewSettingsPresetConfig::fromArray($input)->toArray();

        $this->assertSame(3, $normalized['schema_version']);
        $this->assertSame([10, 30], $normalized['scheduling']['learning_steps_minutes']);
        $this->assertSame(0, $normalized['experience']['question_timer_seconds']);
        $this->assertSame('manual', $normalized['fsrs']['optimization_mode']);
        $this->assertSame(30, $normalized['fsrs']['optimization_interval_days']);

        $input = ReviewSettingsPresetConfig::defaults()->toArray();
        $input['schema_version'] = 2;
        unset($input['fsrs']['optimization_mode'], $input['fsrs']['optimization_interval_days']);
        $normalized = ReviewSettingsPresetConfig::fromArray($input)->toArray();
        $this->assertSame(3, $normalized['schema_version']);
        $this->assertSame('manual', $normalized['fsrs']['optimization_mode']);
        $this->assertSame(30, $normalized['fsrs']['optimization_interval_days']);
    }

    public function test_optimization_policy_is_bounded(): void
    {
        $input = ReviewSettingsPresetConfig::defaults()->toArray();
        $input['fsrs']['optimization_mode'] = 'interval';
        $input['fsrs']['optimization_interval_days'] = 365;
        $policy = ReviewSettingsPresetConfig::fromArray($input)->fsrsOptimizationPolicy();
        $this->assertSame(['mode' => 'interval', 'interval_days' => 365], $policy);

        $input['fsrs']['optimization_interval_days'] = 0;
        $this->expectException(\InvalidArgumentException::class);
        ReviewSettingsPresetConfig::fromArray($input);
    }

    public function test_optimization_policy_rejects_unknown_mode(): void
    {
        $input = ReviewSettingsPresetConfig::defaults()->toArray();
        $input['fsrs']['optimization_mode'] = 'weekly';

        $this->expectException(\InvalidArgumentException::class);
        ReviewSettingsPresetConfig::fromArray($input);
    }

    public function test_auto_advance_requires_a_timer(): void
    {
        $input = ReviewSettingsPresetConfig::defaults()->toArray();
        $input['experience']['auto_advance_enabled'] = true;

        $this->expectException(\InvalidArgumentException::class);
        ReviewSettingsPresetConfig::fromArray($input);
    }

    public function test_steps_must_be_same_day_and_increasing(): void
    {
        $input = ReviewSettingsPresetConfig::defaults()->toArray();
        $input['scheduling']['learning_steps_minutes'] = [30, 10, 1440];

        $this->expectException(\InvalidArgumentException::class);
        ReviewSettingsPresetConfig::fromArray($input);
    }
}
