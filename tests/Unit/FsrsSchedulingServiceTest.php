<?php

namespace Tests\Unit;

use App\Models\ReviewCard;
use App\Models\Setting;
use App\Models\User;
use App\Services\FsrsSchedulingService;
use App\Services\Settings\Presets\ReviewSettingsResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FsrsSchedulingServiceTest extends TestCase
{
    use RefreshDatabase;
    public function test_good_rating_advances_a_new_card_to_the_second_learning_step(): void
    {
        $card = $this->contextCard([
            'fsrs_state' => 'new',
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
        ]);

        $reviewedAt = Carbon::parse('2026-06-17 10:00:00');
        $schedule = (new FsrsSchedulingService())->schedule($card, FsrsSchedulingService::RATING_GOOD, $reviewedAt);

        $this->assertSame('learning', $schedule['state']);
        $this->assertSame(1, $schedule['step_index']);
        $this->assertTrue($schedule['due_at']->equalTo($reviewedAt->copy()->addMinutes(30)));
        $this->assertGreaterThan(0, $schedule['stability']);
        $this->assertGreaterThan(0, $schedule['difficulty']);
        $this->assertSame(0, $schedule['lapses']);
    }

    public function test_again_rating_records_a_lapse(): void
    {
        $card = $this->contextCard([
            'fsrs_state' => 'review',
            'fsrs_stability' => 4.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 1,
            'fsrs_last_reviewed_at' => Carbon::parse('2026-06-10 10:00:00'),
            'fsrs_enabled' => true,
        ]);

        $schedule = (new FsrsSchedulingService())->schedule(
            $card,
            FsrsSchedulingService::RATING_AGAIN,
            Carbon::parse('2026-06-17 10:00:00')
        );

        $this->assertSame('relearning', $schedule['state']);
        $this->assertSame(0, $schedule['step_index']);
        $this->assertTrue($schedule['due_at']->equalTo(Carbon::parse('2026-06-17 10:10:00')));
        $this->assertSame(2, $schedule['lapses']);
        $this->assertLessThanOrEqual(4.0, $schedule['stability']);
    }

    public function test_invalid_rating_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new FsrsSchedulingService())->schedule(new ReviewCard(), 'perfect', Carbon::now());
    }

    public function test_schedule_uses_default_when_fsrs_parameters_not_saved(): void
    {
        $card = new ReviewCard([
            'fsrs_state' => 'new',
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
        ]);

        $reviewedAt = Carbon::parse('2026-06-17 10:00:00');
        $schedule = (new FsrsSchedulingService())->schedule($card, FsrsSchedulingService::RATING_GOOD, $reviewedAt);

        $this->assertSame('learning', $schedule['state']);
        $this->assertSame(1, $schedule['step_index']);
        $this->assertTrue($schedule['due_at']->greaterThan($reviewedAt));
        $this->assertGreaterThan(0, $schedule['stability']);
        $this->assertGreaterThan(0, $schedule['difficulty']);
    }

    public function test_schedule_uses_saved_valid_fsrs_parameters(): void
    {
        $s = new Setting();
        $s->user_id = -1;
        $s->name = 'fsrs_parameters';
        $s->value = json_encode(array_fill(0, 19, 1.0));
        $s->save();

        $card = $this->contextCard([
            'fsrs_state' => 'review',
            'fsrs_stability' => 4.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => Carbon::parse('2026-06-10 10:00:00'),
            'fsrs_enabled' => true,
        ]);

        $reviewedAt = Carbon::parse('2026-06-17 10:00:00');
        $schedule = (new FsrsSchedulingService())->schedule($card, FsrsSchedulingService::RATING_GOOD, $reviewedAt);

        $this->assertTrue($schedule['due_at']->greaterThan($reviewedAt));
        $this->assertGreaterThan(0, $schedule['stability']);
        $this->assertGreaterThan(0, $schedule['difficulty']);

        // Verify the helper returns the saved params via reflection
        $ref = new \ReflectionMethod(FsrsSchedulingService::class, 'getActiveFsrsParameters');
        $params = $ref->invoke(new FsrsSchedulingService(), $card->user_id, $card->language_id);
        $this->assertCount(19, $params);
        foreach ($params as $p) {
            $this->assertSame(1.0, $p);
        }
    }

    public function test_schedule_falls_back_on_malformed_json(): void
    {
        $s = new Setting();
        $s->user_id = -1;
        $s->name = 'fsrs_parameters';
        $s->value = 'not-json{{';
        $s->save();

        $context = $this->contextCard([]);
        $ref = new \ReflectionMethod(FsrsSchedulingService::class, 'getActiveFsrsParameters');
        $params = $ref->invoke(new FsrsSchedulingService(), $context->user_id, $context->language_id);

        // Fallback: should return default 19 params, not throw
        $this->assertCount(19, $params);
        $this->assertIsFloat($params[0]);

        // schedule() must still work
        $card = new ReviewCard([
            'fsrs_state' => 'new',
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
        ]);

        $schedule = (new FsrsSchedulingService())->schedule($card, FsrsSchedulingService::RATING_GOOD, Carbon::now());
        $this->assertGreaterThan(0, $schedule['stability']);
    }

    public function test_schedule_falls_back_on_wrong_count(): void
    {
        $s = new Setting();
        $s->user_id = -1;
        $s->name = 'fsrs_parameters';
        $s->value = json_encode([1.0, 2.0, 3.0]);
        $s->save();

        $context = $this->contextCard([]);
        $ref = new \ReflectionMethod(FsrsSchedulingService::class, 'getActiveFsrsParameters');
        $params = $ref->invoke(new FsrsSchedulingService(), $context->user_id, $context->language_id);

        // Fallback: should return default 19 params, not 3
        $this->assertCount(19, $params);

        $card = new ReviewCard([
            'fsrs_state' => 'new',
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
        ]);

        $schedule = (new FsrsSchedulingService())->schedule($card, FsrsSchedulingService::RATING_GOOD, Carbon::now());
        $this->assertGreaterThan(0, $schedule['stability']);
    }

    public function test_schedule_falls_back_on_non_numeric(): void
    {
        $params = array_fill(0, 19, 1.0);
        $params[5] = 'bad';

        $s = new Setting();
        $s->user_id = -1;
        $s->name = 'fsrs_parameters';
        $s->value = json_encode($params);
        $s->save();

        $context = $this->contextCard([]);
        $ref = new \ReflectionMethod(FsrsSchedulingService::class, 'getActiveFsrsParameters');
        $result = $ref->invoke(new FsrsSchedulingService(), $context->user_id, $context->language_id);

        // Fallback: should return default 19 params
        $this->assertCount(19, $result);

        $card = new ReviewCard([
            'fsrs_state' => 'new',
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
        ]);

        $schedule = (new FsrsSchedulingService())->schedule($card, FsrsSchedulingService::RATING_GOOD, Carbon::now());
        $this->assertGreaterThan(0, $schedule['stability']);
    }

    public function test_schedule_falls_back_on_out_of_range(): void
    {
        $params = array_fill(0, 19, 1.0);
        $params[5] = 1000000;

        $s = new Setting();
        $s->user_id = -1;
        $s->name = 'fsrs_parameters';
        $s->value = json_encode($params);
        $s->save();

        $context = $this->contextCard([]);
        $ref = new \ReflectionMethod(FsrsSchedulingService::class, 'getActiveFsrsParameters');
        $result = $ref->invoke(new FsrsSchedulingService(), $context->user_id, $context->language_id);

        // Fallback: should return default 19 params
        $this->assertCount(19, $result);

        $card = new ReviewCard([
            'fsrs_state' => 'new',
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
        ]);

        $schedule = (new FsrsSchedulingService())->schedule($card, FsrsSchedulingService::RATING_GOOD, Carbon::now());
        $this->assertGreaterThan(0, $schedule['stability']);
    }

    public function test_good_graduates_after_the_last_learning_step(): void
    {
        $card = $this->contextCard([
            'fsrs_state' => 'learning',
            'fsrs_step_index' => 1,
            'fsrs_reps' => 1,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
        ]);
        $reviewedAt = Carbon::parse('2026-06-17 10:00:00');

        $schedule = (new FsrsSchedulingService())->schedule($card, FsrsSchedulingService::RATING_GOOD, $reviewedAt);

        $this->assertSame('review', $schedule['state']);
        $this->assertNull($schedule['step_index']);
        $this->assertTrue($schedule['due_at']->gte($reviewedAt->copy()->addDay()));
    }

    public function test_blank_relearning_steps_apply_the_minimum_interval(): void
    {
        $card = $this->contextCard([
            'fsrs_state' => 'review',
            'fsrs_stability' => 4.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => Carbon::parse('2026-06-10 10:00:00'),
            'fsrs_enabled' => true,
        ]);
        app(ReviewSettingsResolver::class)->mutate($card->user_id, $card->language_id, [
            'scheduling' => [
                'relearning_steps_minutes' => [],
                'minimum_relearning_interval_days' => 5,
                'maximum_interval_days' => 100,
            ],
        ]);
        $reviewedAt = Carbon::parse('2026-06-17 10:00:00');

        $schedule = (new FsrsSchedulingService())->schedule($card, FsrsSchedulingService::RATING_AGAIN, $reviewedAt);

        $this->assertSame('review', $schedule['state']);
        $this->assertNull($schedule['step_index']);
        $this->assertTrue($schedule['due_at']->gte($reviewedAt->copy()->addDays(5)));
    }

    public function test_maximum_interval_caps_a_review_schedule(): void
    {
        $card = $this->contextCard([
            'fsrs_state' => 'review',
            'fsrs_stability' => 500.0,
            'fsrs_difficulty' => 2.0,
            'fsrs_reps' => 20,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => Carbon::parse('2026-01-01 10:00:00'),
            'fsrs_enabled' => true,
        ]);
        app(ReviewSettingsResolver::class)->mutate($card->user_id, $card->language_id, [
            'scheduling' => ['maximum_interval_days' => 3],
        ]);
        $reviewedAt = Carbon::parse('2026-06-17 10:00:00');

        $schedule = (new FsrsSchedulingService())->schedule($card, FsrsSchedulingService::RATING_EASY, $reviewedAt);

        $this->assertTrue($schedule['due_at']->lte($reviewedAt->copy()->addDays(3)));
    }

    public function test_easy_days_prefers_a_nearby_normal_day_without_retroactive_write(): void
    {
        $service = new FsrsSchedulingService();
        $method = new \ReflectionMethod($service, 'applyEasyDays');
        $reviewedAt = Carbon::parse('2026-07-29 10:00:00'); // Wednesday
        $easyDays = ['normal', 'minimum', 'normal', 'normal', 'normal', 'normal', 'normal'];

        $dueAt = $method->invoke($service, $reviewedAt, 5, 36500, $easyDays);

        $this->assertSame('2026-08-02', $dueAt->toDateString()); // Sunday, one day before minimum Monday
    }

    private function contextCard(array $attributes): ReviewCard
    {
        $user = User::factory()->create();
        return new ReviewCard(array_merge([
            'user_id' => $user->id,
            'language_id' => 'english',
        ], $attributes));
    }
}
