<?php

namespace Tests\Unit;

use App\Models\ReviewCard;
use App\Services\FsrsSchedulingService;
use Carbon\Carbon;
use Tests\TestCase;

class FsrsSchedulingServiceTest extends TestCase
{
    public function test_good_rating_schedules_a_new_card_for_review(): void
    {
        $card = new ReviewCard([
            'fsrs_state' => 'new',
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
        ]);

        $reviewedAt = Carbon::parse('2026-06-17 10:00:00');
        $schedule = (new FsrsSchedulingService())->schedule($card, FsrsSchedulingService::RATING_GOOD, $reviewedAt);

        $this->assertSame('review', $schedule['state']);
        $this->assertTrue($schedule['due_at']->greaterThan($reviewedAt));
        $this->assertGreaterThan(0, $schedule['stability']);
        $this->assertGreaterThan(0, $schedule['difficulty']);
        $this->assertSame(0, $schedule['lapses']);
    }

    public function test_again_rating_records_a_lapse(): void
    {
        $card = new ReviewCard([
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
        $this->assertSame(2, $schedule['lapses']);
        $this->assertLessThanOrEqual(4.0, $schedule['stability']);
    }

    public function test_invalid_rating_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new FsrsSchedulingService())->schedule(new ReviewCard(), 'perfect', Carbon::now());
    }
}
