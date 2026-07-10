<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\SenseReviewDailyReportService;
use App\Services\SenseReviewSevenDayTrendService;
use App\Services\SenseReviewThirtyDayCalendarService;
use App\Services\WordSenseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewThirtyDayCalendarTest
 *
 * SenseReview-ThirtyDayCalendar-1000-1
 *
 * Verifies the read-only "近 30 天复习日历" (fixed rolling 30-day window:
 * today + previous 29 natural days, NOT natural month). Source of truth:
 * ReviewLog. Sense-review only, reset excluded, legacy word excluded.
 *
 * Contract:
 *  - Auth required (HTTP guard).
 *  - Fixed 30 days: today + previous 29 natural days (app timezone).
 *  - days array always has exactly 30 entries, ascending by date.
 *  - Empty days → 0 counts, null rates (NOT misleading "0%").
 *  - summary: total_reviews, active_days, distinct_senses,
 *    average_per_active_day, distribution, forget_rate, stability_rate.
 *  - Today's row matches DailyReport (total, distinct, distribution,
 *    forget_rate, stability_rate).
 *  - Last 7 days rows match SevenDayTrend.
 *  - reset / source=reset / legacy word / other user / other language excluded.
 *  - READ-ONLY: no ReviewLog writes, no FSRS changes, no ReviewCard changes.
 *  - Query budget: constant ReviewLog queries regardless of sense count.
 */
class SenseReviewThirtyDayCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WordSenseService $wordSenseService;
    private SenseReviewThirtyDayCalendarService $service;
    private SenseReviewDailyReportService $dailyReportService;
    private SenseReviewSevenDayTrendService $sevenDayTrendService;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Setting::where('name', 'reviewIntervals')->exists()) {
            Setting::forceCreate([
                'name' => 'reviewIntervals',
                'value' => json_encode([
                    '-7' => [0], '-6' => [1], '-5' => [2], '-4' => [3],
                    '-3' => [7], '-2' => [15], '-1' => [30],
                ]),
            ]);
        }

        $this->user = $this->createUser('calendar@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
        $this->service = app(SenseReviewThirtyDayCalendarService::class);
        $this->dailyReportService = app(SenseReviewDailyReportService::class);
        $this->sevenDayTrendService = app(SenseReviewSevenDayTrendService::class);
    }

    /**
     * 1. HTTP: unauthenticated → 401.
     */
    public function test_unauthenticated_request_is_blocked(): void
    {
        $response = $this->getJson('/reviews/senses/thirty-day-calendar');
        $response->assertStatus(401);
    }

    /**
     * 2. 30 days completely empty → 30 entries, all zeros, null rates.
     */
    public function test_thirty_days_completely_empty(): void
    {
        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(30, count($result['days']));
        foreach ($result['days'] as $day) {
            $this->assertSame(0, $day['total_reviews']);
            $this->assertSame(0, $day['distinct_senses']);
            $this->assertSame(0, $day['distribution']['again']);
            $this->assertSame(0, $day['distribution']['hard']);
            $this->assertSame(0, $day['distribution']['good']);
            $this->assertSame(0, $day['distribution']['easy']);
            $this->assertNull($day['forget_rate']);
            $this->assertNull($day['stability_rate']);
        }

        $this->assertSame(0, $result['summary']['total_reviews']);
        $this->assertSame(0, $result['summary']['active_days']);
        $this->assertSame(0, $result['summary']['distinct_senses']);
        $this->assertNull($result['summary']['average_per_active_day']);
        $this->assertNull($result['summary']['forget_rate']);
        $this->assertNull($result['summary']['stability_rate']);
    }

    /**
     * 3. Only today has records.
     */
    public function test_only_today_has_records(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'good', $today->copy()->addHour());

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(1, $result['summary']['active_days']);
        $this->assertSame(1, $result['summary']['total_reviews']);
        $this->assertSame(1, $result['summary']['distinct_senses']);
        // Last day (index 29) is today.
        $this->assertSame(1, $result['days'][29]['total_reviews']);
        $this->assertSame(0, $result['days'][0]['total_reviews']);
    }

    /**
     * 4. Start and end day boundaries.
     */
    public function test_start_and_end_day_boundaries(): void
    {
        $result = $this->service->build($this->user->id, 'english');

        $today = Carbon::today(config('app.timezone', 'UTC'));
        $startDay = $today->copy()->subDays(29);

        $this->assertSame($startDay->format('Y-m-d'), $result['start_day']);
        $this->assertSame($today->format('Y-m-d'), $result['end_day']);
    }

    /**
     * 5. Cross-month and cross-year.
     */
    public function test_cross_month_and_cross_year(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 15, 10, 0, 0));

        try {
            $sense = $this->createConfirmedSense('apple');
            $card = $this->createSenseCard($sense);
            $this->createReviewLog($card, 'good', Carbon::create(2025, 12, 20, 12, 0, 0));
            $this->createReviewLog($card, 'hard', Carbon::create(2026, 1, 5, 12, 0, 0));
            $this->createReviewLog($card, 'easy', Carbon::create(2026, 1, 15, 9, 0, 0));

            $result = $this->service->build($this->user->id, 'english');

            $this->assertSame('2025-12-17', $result['start_day']);
            $this->assertSame('2026-01-15', $result['end_day']);
            $this->assertSame(3, $result['summary']['total_reviews']);
            $this->assertContains('2025-12-17', array_column($result['days'], 'day'));
            $this->assertContains('2025-12-31', array_column($result['days'], 'day'));
            $this->assertContains('2026-01-01', array_column($result['days'], 'day'));
        } finally {
            Carbon::setTestNow(null);
        }
    }

    /**
     * 6. Days ascending order.
     */
    public function test_days_ascending_order(): void
    {
        $result = $this->service->build($this->user->id, 'english');

        for ($i = 1; $i < 30; $i++) {
            $prev = $result['days'][$i - 1]['day'];
            $curr = $result['days'][$i]['day'];
            $this->assertSame(1.0, Carbon::parse($prev)->diffInDays(Carbon::parse($curr)));
        }
    }

    /**
     * 7. Missing days zero-filled.
     */
    public function test_missing_days_zero_filled(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'good', $today->copy()->addHour());

        $result = $this->service->build($this->user->id, 'english');

        $emptyDays = 0;
        foreach ($result['days'] as $day) {
            if ($day['total_reviews'] === 0) {
                $emptyDays++;
                $this->assertNull($day['forget_rate']);
                $this->assertNull($day['stability_rate']);
            }
        }
        $this->assertSame(29, $emptyDays);
    }

    /**
     * 8. Empty data → null rates (not 0%).
     */
    public function test_empty_data_null_rates(): void
    {
        $result = $this->service->build($this->user->id, 'english');

        foreach ($result['days'] as $day) {
            $this->assertNull($day['forget_rate']);
            $this->assertNull($day['stability_rate']);
        }
        $this->assertNull($result['summary']['forget_rate']);
        $this->assertNull($result['summary']['stability_rate']);
    }

    /**
     * 9. User isolation.
     */
    public function test_user_isolation(): void
    {
        $other = $this->createUser('other-calendar@example.com', 'english');
        $otherSense = $this->createConfirmedSenseForUser($other, 'river');
        $otherCard = $this->createSenseCardForUser($other, $otherSense);
        $this->createReviewLog($otherCard, 'again', Carbon::today()->addHour());

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(0, $result['summary']['total_reviews']);
    }

    /**
     * 10. Language isolation.
     */
    public function test_language_isolation(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'good', Carbon::today()->addHour());

        $result = $this->service->build($this->user->id, 'french');

        $this->assertSame(0, $result['summary']['total_reviews']);
    }

    /**
     * 11. Legacy word card excluded.
     */
    public function test_legacy_word_card_excluded(): void
    {
        $wordCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => 999999,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->subDay(),
            'fsrs_stability' => 1.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDay(),
        ]);
        $this->createReviewLog($wordCard, 'good', Carbon::today()->addHour());

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(0, $result['summary']['total_reviews']);
    }

    /**
     * 12. Reset rating excluded.
     */
    public function test_reset_rating_excluded(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'good', Carbon::today()->addHour());
        $this->createReviewLog($card, 'reset', Carbon::today()->addHours(2), 'reset');

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(1, $result['summary']['total_reviews']);
    }

    /**
     * 13. source=reset excluded.
     */
    public function test_source_reset_excluded(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'good', Carbon::today()->addHour());
        $this->createReviewLog($card, 'good', Carbon::today()->addHours(2), 'reset');

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(1, $result['summary']['total_reviews']);
    }

    /**
     * 14. Multiple logs same sense.
     */
    public function test_multiple_logs_same_sense(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'again', $today->copy()->addHour());
        $this->createReviewLog($card, 'hard', $today->copy()->addHours(2));
        $this->createReviewLog($card, 'good', $today->copy()->addHours(3));

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(3, $result['summary']['total_reviews']);
        $this->assertSame(1, $result['summary']['distinct_senses']);
        $todayEntry = $result['days'][29];
        $this->assertSame(3, $todayEntry['total_reviews']);
        $this->assertSame(1, $todayEntry['distinct_senses']);
    }

    /**
     * 15. Distinct sense calculation.
     */
    public function test_distinct_sense_calculation(): void
    {
        $senseA = $this->createConfirmedSense('apple');
        $cardA = $this->createSenseCard($senseA);
        $senseB = $this->createConfirmedSense('banana');
        $cardB = $this->createSenseCard($senseB);
        $senseC = $this->createConfirmedSense('cherry');
        $cardC = $this->createSenseCard($senseC);
        $today = Carbon::today();
        $this->createReviewLog($cardA, 'good', $today->copy()->addHour());
        $this->createReviewLog($cardB, 'hard', $today->copy()->addHours(2));
        $this->createReviewLog($cardC, 'easy', $today->copy()->addHours(3));

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(3, $result['summary']['distinct_senses']);
        $this->assertSame(3, $result['days'][29]['distinct_senses']);
    }

    /**
     * 16. Summary calculation.
     */
    public function test_summary_calculation(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'good', $today->copy()->addHour());
        $this->createReviewLog($card, 'hard', $today->copy()->subDays(1)->addHour());
        $this->createReviewLog($card, 'easy', $today->copy()->subDays(2)->addHour());

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(3, $result['summary']['total_reviews']);
        $this->assertSame(3, $result['summary']['active_days']);
        $this->assertSame(1, $result['summary']['distinct_senses']);
        $this->assertSame(1.0, $result['summary']['average_per_active_day']);
        $this->assertSame(1, $result['summary']['distribution']['good']);
        $this->assertSame(1, $result['summary']['distribution']['hard']);
        $this->assertSame(1, $result['summary']['distribution']['easy']);
    }

    /**
     * 17. Query budget: 1 sense → 1 ReviewLog query.
     */
    public function test_query_budget_one_sense(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'good', Carbon::today()->addHour());

        $reviewLogQueries = $this->countReviewLogQueries(fn() => $this->service->build($this->user->id, 'english'));

        $this->assertSame(1, $reviewLogQueries);
    }

    /**
     * 18. Query budget: 10 senses → 1 ReviewLog query.
     */
    public function test_query_budget_ten_senses(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $sense = $this->createConfirmedSense('w' . $i);
            $card = $this->createSenseCard($sense);
            $this->createReviewLog($card, 'good', Carbon::today()->addHours($i));
        }

        $reviewLogQueries = $this->countReviewLogQueries(fn() => $this->service->build($this->user->id, 'english'));

        $this->assertSame(1, $reviewLogQueries);
    }

    /**
     * 19. Query budget: 50 senses → 1 ReviewLog query.
     */
    public function test_query_budget_fifty_senses(): void
    {
        for ($i = 1; $i <= 50; $i++) {
            $sense = $this->createConfirmedSense('w' . $i);
            $card = $this->createSenseCard($sense);
            $this->createReviewLog($card, 'good', Carbon::today()->addMinutes($i));
        }

        $reviewLogQueries = $this->countReviewLogQueries(fn() => $this->service->build($this->user->id, 'english'));

        $this->assertSame(1, $reviewLogQueries);
    }

    /**
     * 20. Service is read-only: does NOT write ReviewLog.
     */
    public function test_service_does_not_write_review_log(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'good', Carbon::today()->addHour());

        $before = ReviewLog::count();
        for ($i = 0; $i < 3; $i++) {
            $this->service->build($this->user->id, 'english');
        }
        $after = ReviewLog::count();

        $this->assertSame($before, $after);
    }

    /**
     * 21. Service does not change ReviewCard FSRS fields.
     */
    public function test_service_does_not_change_review_card(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense, [
            'fsrs_stability' => 9.5,
            'fsrs_difficulty' => 4.2,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 1,
        ]);
        $this->createReviewLog($card, 'good', Carbon::today()->addHour());

        $before = $card->fresh();
        for ($i = 0; $i < 3; $i++) {
            $this->service->build($this->user->id, 'english');
        }
        $after = $card->fresh();

        $this->assertSame($before->fsrs_stability, $after->fsrs_stability);
        $this->assertSame($before->fsrs_difficulty, $after->fsrs_difficulty);
        $this->assertSame($before->fsrs_reps, $after->fsrs_reps);
        $this->assertSame($before->fsrs_lapses, $after->fsrs_lapses);
        $this->assertSame($before->fsrs_due_at->toDateTimeString(), $after->fsrs_due_at->toDateTimeString());
    }

    /**
     * 22. Does not create ReviewLog.
     */
    public function test_service_does_not_create_review_log(): void
    {
        $before = ReviewLog::count();
        $this->service->build($this->user->id, 'english');
        $after = ReviewLog::count();

        $this->assertSame($before, $after);
    }

    /**
     * 23. Does not create WordSense.
     */
    public function test_service_does_not_create_word_sense(): void
    {
        $before = WordSense::count();
        $this->service->build($this->user->id, 'english');
        $after = WordSense::count();

        $this->assertSame($before, $after);
    }

    /**
     * 24. Does not create ReviewCard.
     */
    public function test_service_does_not_create_review_card(): void
    {
        $before = ReviewCard::count();
        $this->service->build($this->user->id, 'english');
        $after = ReviewCard::count();

        $this->assertSame($before, $after);
    }

    /**
     * 25. Today's row matches DailyReport.
     */
    public function test_today_row_matches_daily_report(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'again', $today->copy()->addHour());
        $this->createReviewLog($card, 'good', $today->copy()->addHours(2));

        $calendar = $this->service->build($this->user->id, 'english');
        $dailyReport = $this->dailyReportService->build($this->user->id, 'english');

        $todayRow = $calendar['days'][29];

        $this->assertSame($dailyReport['overview']['total_reviews'], $todayRow['total_reviews']);
        $this->assertSame($dailyReport['overview']['distinct_senses'], $todayRow['distinct_senses']);
        $this->assertSame($dailyReport['quality']['distribution'], $todayRow['distribution']);
        $this->assertSame($dailyReport['quality']['forget_rate'], $todayRow['forget_rate']);
        $this->assertSame($dailyReport['quality']['stability_rate'], $todayRow['stability_rate']);
    }

    /**
     * 26. Last 7 days match SevenDayTrend.
     */
    public function test_last_seven_days_match_seven_day_trend(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'good', $today->copy()->addHour());
        $this->createReviewLog($card, 'hard', $today->copy()->subDays(3)->addHour());
        $this->createReviewLog($card, 'easy', $today->copy()->subDays(6)->addHour());

        $calendar = $this->service->build($this->user->id, 'english');
        $trend = $this->sevenDayTrendService->build($this->user->id, 'english');

        // Last 7 entries of calendar must match trend days.
        $lastSeven = array_slice($calendar['days'], 23, 7);

        for ($i = 0; $i < 7; $i++) {
            $this->assertSame($trend['days'][$i]['day'], $lastSeven[$i]['day']);
            $this->assertSame($trend['days'][$i]['total_reviews'], $lastSeven[$i]['total_reviews']);
            $this->assertSame($trend['days'][$i]['distinct_senses'], $lastSeven[$i]['distinct_senses']);
            $this->assertSame($trend['days'][$i]['distribution'], $lastSeven[$i]['distribution']);
            $this->assertSame($trend['days'][$i]['forget_rate'], $lastSeven[$i]['forget_rate']);
            $this->assertSame($trend['days'][$i]['stability_rate'], $lastSeven[$i]['stability_rate']);
        }
    }

    /**
     * 27. timezone / start_day / end_day correct.
     */
    public function test_returns_timezone_and_day_range(): void
    {
        $result = $this->service->build($this->user->id, 'english');

        $today = Carbon::today(config('app.timezone', 'UTC'));
        $startDay = $today->copy()->subDays(29);

        $this->assertSame(config('app.timezone', 'UTC'), $result['timezone']);
        $this->assertSame($startDay->format('Y-m-d'), $result['start_day']);
        $this->assertSame($today->format('Y-m-d'), $result['end_day']);
    }

    /**
     * 28. Frozen time window.
     */
    public function test_frozen_time_window(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 15, 10, 0, 0));

        try {
            $sense = $this->createConfirmedSense('apple');
            $card = $this->createSenseCard($sense);

            $this->createReviewLog($card, 'good', Carbon::create(2025, 12, 20, 12, 0, 0));
            $this->createReviewLog($card, 'hard', Carbon::create(2026, 1, 5, 12, 0, 0));
            $this->createReviewLog($card, 'easy', Carbon::create(2026, 1, 15, 9, 0, 0));
            // Outside window (before Dec 17).
            $this->createReviewLog($card, 'again', Carbon::create(2025, 12, 10, 12, 0, 0));

            $result = $this->service->build($this->user->id, 'english');

            $this->assertSame('2025-12-17', $result['start_day']);
            $this->assertSame('2026-01-15', $result['end_day']);
            $this->assertSame(3, $result['summary']['total_reviews']);
            $this->assertSame(30, count($result['days']));
            $this->assertSame('2025-12-17', $result['days'][0]['day']);
            $this->assertSame('2026-01-15', $result['days'][29]['day']);
        } finally {
            Carbon::setTestNow(null);
        }
    }

    /**
     * 29. Fixed 30 items.
     */
    public function test_fixed_thirty_items(): void
    {
        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(30, count($result['days']));
    }

    /**
     * 30. active_days correct.
     */
    public function test_active_days_correct(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'good', $today->copy()->addHour());
        $this->createReviewLog($card, 'hard', $today->copy()->subDays(5)->addHour());
        $this->createReviewLog($card, 'easy', $today->copy()->subDays(10)->addHour());

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(3, $result['summary']['active_days']);
    }

    /**
     * 31. average_per_active_day correct.
     */
    public function test_average_per_active_day_correct(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'good', $today->copy()->addHour());
        $this->createReviewLog($card, 'hard', $today->copy()->addHours(2));
        $this->createReviewLog($card, 'easy', $today->copy()->subDays(1)->addHour());

        $result = $this->service->build($this->user->id, 'english');

        // 3 total / 2 active days = 1.5
        $this->assertSame(1.5, $result['summary']['average_per_active_day']);
    }

    /**
     * 32. authenticated user gets calendar JSON.
     */
    public function test_authenticated_user_gets_calendar_json(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'again', $today->copy()->addHour());

        $response = $this->actingAs($this->user)
            ->getJson('/reviews/senses/thirty-day-calendar');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'timezone', 'start_day', 'end_day',
            'summary' => [
                'total_reviews', 'active_days', 'distinct_senses',
                'average_per_active_day', 'distribution',
                'forget_rate', 'stability_rate',
            ],
            'days' => [
                ['day', 'total_reviews', 'distinct_senses', 'distribution', 'forget_rate', 'stability_rate'],
            ],
        ]);
        $this->assertSame(30, count($response->json('days')));
    }

    // ==================== Helpers ====================

    private function countReviewLogQueries(callable $callback): int
    {
        DB::connection()->enableQueryLog();
        DB::flushQueryLog();

        $callback();

        $queries = DB::getQueryLog();
        DB::connection()->disableQueryLog();

        $count = 0;
        foreach ($queries as $q) {
            $sql = $q['query'] ?? '';
            if (preg_match('/review_logs/i', $sql)) {
                $count++;
            }
        }
        return $count;
    }

    private function createReviewLog(ReviewCard $card, string $rating, Carbon $reviewedAt, string $source = 'sense_review'): ReviewLog
    {
        return ReviewLog::create([
            'user_id' => $card->user_id,
            'language_id' => $card->language_id,
            'language' => $card->language,
            'review_card_id' => $card->id,
            'rating' => $rating,
            'reviewed_at' => $reviewedAt,
            'previous_state' => 'review',
            'new_state' => 'review',
            'previous_due_at' => $reviewedAt->copy()->subDay(),
            'new_due_at' => $reviewedAt->copy()->addDay(),
            'previous_stability' => 1.0,
            'new_stability' => 1.5,
            'previous_difficulty' => 5.0,
            'new_difficulty' => 4.8,
            'source' => $source,
        ]);
    }

    private function createConfirmedSense(string $lemma, string $exampleEn = ''): WordSense
    {
        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => ucfirst($lemma),
            'pos' => 'noun',
            'sense_zh' => '测试',
            'sense_en' => 'test',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => $exampleEn,
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);
        return $sense->fresh();
    }

    private function createConfirmedSenseForUser(User $user, string $lemma): WordSense
    {
        $wordSenseService = app(WordSenseService::class);
        $sense = $wordSenseService->createSense([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => ucfirst($lemma),
            'pos' => 'noun',
            'sense_zh' => '测试',
            'sense_en' => 'test',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => '',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);
        return $sense->fresh();
    }

    private function createSenseCard(WordSense $sense, array $overrides = []): ReviewCard
    {
        $data = array_merge([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->subDay(),
            'fsrs_stability' => 1.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDay(),
        ], $overrides);

        return ReviewCard::forceCreate($data);
    }

    private function createSenseCardForUser(User $user, WordSense $sense): ReviewCard
    {
        return ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->subDay(),
            'fsrs_stability' => 1.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDay(),
        ]);
    }

    private function createUser(string $email, string $language): User
    {
        return User::forceCreate([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }
}
