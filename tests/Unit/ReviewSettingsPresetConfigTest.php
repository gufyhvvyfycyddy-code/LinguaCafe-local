<?php

namespace Tests\Unit;

use App\Services\Settings\Presets\ReviewSettingsPresetConfig;
use PHPUnit\Framework\TestCase;

class ReviewSettingsPresetConfigTest extends TestCase
{
    public function test_defaults_are_exact_v1_schema(): void
    {
        $config = ReviewSettingsPresetConfig::defaults()->toArray();

        $this->assertSame(1, $config['schema_version']);
        $this->assertSame(0.90, $config['fsrs']['desired_retention']);
        $this->assertSame('default', $config['fsrs']['parameters_source']);
        $this->assertNull($config['fsrs']['parameters_optimized_at']);
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
}
