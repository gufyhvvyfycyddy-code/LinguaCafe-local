<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\SenseReviewDailyReportService;
use App\Services\SenseReviewSevenDayTrendService;
use App\Services\WordSenseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewSevenDayTrendTest
 *
 * SenseReview-SevenDayTrend-1000-1
 *
 * Verifies the read-only "近 7 天学习趋势" (fixed rolling 7-day window:
 * today + previous 6 natural days, NOT natural week). Source of truth:
 * ReviewLog. Sense-review only, reset excluded, legacy word excluded.
 *
 * Contract:
 *  - Auth required (HTTP guard).
 *  - Fixed 7 days: today + previous 6 natural days (app timezone).
 *  - days array always has exactly 7 entries, ascending by date.
 *  - Empty days → 0 counts, null rates (NOT misleading "0%").
 *  - summary: total_reviews, active_days, distinct_senses,
 *    average_per_active_day, distribution, forget_rate, stability_rate.
 *  - Today's row matches DailyReport (total, distinct, distribution,
 *    forget_rate, stability_rate).
 *  - reset / source=reset / legacy word / other user / other language excluded.
 *  - READ-ONLY: no ReviewLog writes, no FSRS changes, no ReviewCard changes.
 *  - Query budget: constant ReviewLog queries regardless of sense count.
 */
class SenseReviewSevenDayTrendTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WordSenseService $wordSenseService;
    private SenseReviewSevenDayTrendService $service;
    private SenseReviewDailyReportService $dailyReportService;

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

        $this->user = $this->createUser('trend@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
        $this->service = app(SenseReviewSevenDayTrendService::class);
        $this->dailyReportService = app(SenseReviewDailyReportService::class);
    }

    /**
     * 1. HTTP: unauthenticated → 401.
     */
    public function test_unauthenticated_request_is_blocked(): void
    {
        $response = $this->getJson('/reviews/senses/seven-day-trend');
        $response->assertStatus(401);
    }

    /**
     * 2. 7 days completely empty → 7 entries, all zeros, null rates.
     */
    public function test_seven_days_completely_empty(): void
    {
        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(7, count($result['days']));
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

        // Last day (today) has data, previous 6 are zero.
        $lastDay = $result['days'][6];
        $this->assertSame(1, $lastDay['total_reviews']);
        for ($i = 0; $i < 6; $i++) {
            $this->assertSame(0, $result['days'][$i]['total_reviews']);
        }
    }

    /**
     * 4. 7 days all have records.
     */
    public function test_all_seven_days_have_records(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        for ($i = 6; $i >= 0; $i--) {
            $this->createReviewLog($card, 'good', $today->copy()->subDays($i)->addHour());
        }

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(7, $result['summary']['active_days']);
        $this->assertSame(7, $result['summary']['total_reviews']);
        foreach ($result['days'] as $day) {
            $this->assertSame(1, $day['total_reviews']);
        }
    }

    /**
     * 5. Empty days auto zero-filled (middle of window).
     */
    public function test_empty_days_auto_zero_filled(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        // Only today and 3 days ago.
        $this->createReviewLog($card, 'good', $today->copy()->addHour());
        $this->createReviewLog($card, 'hard', $today->copy()->subDays(3)->addHour());

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(7, count($result['days']));
        // today (index 6) and 3-days-ago (index 3) have data.
        $this->assertSame(1, $result['days'][6]['total_reviews']);
        $this->assertSame(1, $result['days'][3]['total_reviews']);
        // The rest are zero.
        $this->assertSame(0, $result['days'][0]['total_reviews']);
        $this->assertSame(0, $result['days'][1]['total_reviews']);
        $this->assertSame(0, $result['days'][2]['total_reviews']);
        $this->assertSame(0, $result['days'][4]['total_reviews']);
        $this->assertSame(0, $result['days'][5]['total_reviews']);
    }

    /**
     * 6. Logs before the window start are excluded.
     */
    public function test_logs_before_window_excluded(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $windowStart = $today->copy()->subDays(6);

        // 7 days before window start.
        $this->createReviewLog($card, 'good', $windowStart->copy()->subDay()->addHour());

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(0, $result['summary']['total_reviews']);
    }

    /**
     * 7. Logs after the window end are excluded.
     */
    public function test_logs_after_window_excluded(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        // Tomorrow (after today's 23:59:59).
        $this->createReviewLog($card, 'good', $today->copy()->addDay()->addHour());

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(0, $result['summary']['total_reviews']);
    }

    /**
     * 8. reset rating excluded.
     */
    public function test_reset_rating_excluded(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        $this->createReviewLog($card, 'good', $today->copy()->addHour());
        $this->createReviewLog($card, 'reset', $today->copy()->addHours(2));

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(1, $result['summary']['total_reviews']);
    }

    /**
     * 9. source=reset excluded.
     */
    public function test_source_reset_excluded(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        $this->createReviewLog($card, 'good', $today->copy()->addHour());
        // rating=good but source=reset → excluded.
        $this->createReviewLog($card, 'good', $today->copy()->addHours(2), 'reset');

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(1, $result['summary']['total_reviews']);
    }

    /**
     * 10. Legacy word card excluded.
     */
    public function test_legacy_word_card_excluded(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $senseCard = $this->createSenseCard($sense);

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
        $today = Carbon::today();
        $this->createReviewLog($senseCard, 'good', $today->copy()->addHour());
        $this->createReviewLog($wordCard, 'good', $today->copy()->addHours(2));

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(1, $result['summary']['total_reviews']);
    }

    /**
     * 11. Other user excluded.
     */
    public function test_other_user_excluded(): void
    {
        $other = $this->createUser('other-trend@example.com', 'english');
        $otherSense = $this->wordSenseService->createSense([
            'user_id' => $other->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'river',
            'surface_form' => 'River',
            'pos' => 'noun',
            'sense_zh' => '河',
            'sense_en' => 'river',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => '',
            'example_sentence_zh' => '',
        ]);
        $otherSense->update(['status' => WordSense::STATUS_CONFIRMED]);
        $otherCard = ReviewCard::forceCreate([
            'user_id' => $other->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $otherSense->id,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->subDay(),
            'fsrs_stability' => 1.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDay(),
        ]);
        $today = Carbon::today();
        $this->createReviewLog($otherCard, 'good', $today->copy()->addHour());

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(0, $result['summary']['total_reviews']);
    }

    /**
     * 12. Other language excluded.
     */
    public function test_other_language_excluded(): void
    {
        $otherLangUser = $this->createUser('other-lang@example.com', 'japanese');
        $otherSense = $this->wordSenseService->createSense([
            'user_id' => $otherLangUser->id,
            'language' => 'japanese',
            'language_id' => 'japanese',
            'lemma' => 'kawa',
            'surface_form' => 'Kawa',
            'pos' => 'noun',
            'sense_zh' => '河',
            'sense_en' => 'river',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => '',
            'example_sentence_zh' => '',
        ]);
        $otherSense->update(['status' => WordSense::STATUS_CONFIRMED]);
        $otherCard = ReviewCard::forceCreate([
            'user_id' => $otherLangUser->id,
            'language_id' => 'japanese',
            'language' => 'japanese',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $otherSense->id,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->subDay(),
            'fsrs_stability' => 1.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDay(),
        ]);
        $today = Carbon::today();
        $this->createReviewLog($otherCard, 'good', $today->copy()->addHour());

        // Note: querying for 'english' should NOT see the japanese card.
        // Even though the card belongs to the same project, language isolation
        // is enforced. We test via the service on this->user (english).
        $result = $this->service->build($otherLangUser->id, 'english');

        $this->assertSame(0, $result['summary']['total_reviews']);
    }

    /**
     * 13. Days are sorted ascending by date.
     */
    public function test_days_sorted_ascending(): void
    {
        $result = $this->service->build($this->user->id, 'english');

        $dates = array_column($result['days'], 'day');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);
    }

    /**
     * 14. Fixed 7 items returned.
     */
    public function test_fixed_seven_items(): void
    {
        $result = $this->service->build($this->user->id, 'english');

        $this->assertCount(7, $result['days']);
    }

    /**
     * 15. active_days correct.
     */
    public function test_active_days_correct(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        // today, 2 days ago, 5 days ago → 3 active days.
        $this->createReviewLog($card, 'good', $today->copy()->addHour());
        $this->createReviewLog($card, 'hard', $today->copy()->subDays(2)->addHour());
        $this->createReviewLog($card, 'easy', $today->copy()->subDays(5)->addHour());

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(3, $result['summary']['active_days']);
    }

    /**
     * 16. average_per_active_day correct.
     */
    public function test_average_per_active_day_correct(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        // 4 reviews across 2 active days → average = 2.0
        $this->createReviewLog($card, 'good', $today->copy()->addHour());
        $this->createReviewLog($card, 'hard', $today->copy()->addHours(2));
        $this->createReviewLog($card, 'easy', $today->copy()->subDays(2)->addHour());
        $this->createReviewLog($card, 'again', $today->copy()->subDays(2)->addHours(2));

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(2, $result['summary']['active_days']);
        $this->assertSame(4, $result['summary']['total_reviews']);
        $this->assertSame(2.0, $result['summary']['average_per_active_day']);
    }

    /**
     * 17. average_per_active_day null when no active days.
     */
    public function test_average_per_active_day_null_when_empty(): void
    {
        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(0, $result['summary']['active_days']);
        // When active_days = 0, average is null (not a fake 0).
        $this->assertNull($result['summary']['average_per_active_day']);
    }

    /**
     * 18. distinct_senses correct.
     */
    public function test_distinct_senses_correct(): void
    {
        $senseA = $this->createConfirmedSense('apple');
        $cardA = $this->createSenseCard($senseA);
        $senseB = $this->createConfirmedSense('banana');
        $cardB = $this->createSenseCard($senseB);
        $today = Carbon::today();

        $this->createReviewLog($cardA, 'good', $today->copy()->addHour());
        $this->createReviewLog($cardB, 'hard', $today->copy()->subDays(2)->addHour());

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(2, $result['summary']['distinct_senses']);
    }

    /**
     * 19. distribution correct in summary.
     */
    public function test_summary_distribution_correct(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        $this->createReviewLog($card, 'again', $today->copy()->addHour());
        $this->createReviewLog($card, 'hard', $today->copy()->addHours(2));
        $this->createReviewLog($card, 'good', $today->copy()->addHours(3));
        $this->createReviewLog($card, 'easy', $today->copy()->addHours(4));

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(1, $result['summary']['distribution']['again']);
        $this->assertSame(1, $result['summary']['distribution']['hard']);
        $this->assertSame(1, $result['summary']['distribution']['good']);
        $this->assertSame(1, $result['summary']['distribution']['easy']);
    }

    /**
     * 20. forget_rate correct.
     */
    public function test_summary_forget_rate_correct(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        // 2 again, 1 good, 1 easy → forget = 2/4 = 0.5
        $this->createReviewLog($card, 'again', $today->copy()->addHour());
        $this->createReviewLog($card, 'again', $today->copy()->addHours(2));
        $this->createReviewLog($card, 'good', $today->copy()->addHours(3));
        $this->createReviewLog($card, 'easy', $today->copy()->addHours(4));

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(0.5, $result['summary']['forget_rate']);
    }

    /**
     * 21. stability_rate correct.
     */
    public function test_summary_stability_rate_correct(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        // 1 again, 1 hard, 2 good → stability = 2/4 = 0.5
        $this->createReviewLog($card, 'again', $today->copy()->addHour());
        $this->createReviewLog($card, 'hard', $today->copy()->addHours(2));
        $this->createReviewLog($card, 'good', $today->copy()->addHours(3));
        $this->createReviewLog($card, 'good', $today->copy()->addHours(4));

        $result = $this->service->build($this->user->id, 'english');

        $this->assertSame(0.5, $result['summary']['stability_rate']);
    }

    /**
     * 22. Today's row matches DailyReport.
     */
    public function test_today_row_matches_daily_report(): void
    {
        $senseA = $this->createConfirmedSense('apple');
        $cardA = $this->createSenseCard($senseA);
        $senseB = $this->createConfirmedSense('banana');
        $cardB = $this->createSenseCard($senseB);
        $today = Carbon::today();

        $this->createReviewLog($cardA, 'again', $today->copy()->addHour());
        $this->createReviewLog($cardA, 'good', $today->copy()->addHours(2));
        $this->createReviewLog($cardB, 'easy', $today->copy()->addHours(3));

        $trend = $this->service->build($this->user->id, 'english');
        $daily = $this->dailyReportService->build($this->user->id, 'english');

        $todayRow = $trend['days'][6]; // last day = today

        $this->assertSame($daily['overview']['total_reviews'], $todayRow['total_reviews']);
        $this->assertSame($daily['overview']['distinct_senses'], $todayRow['distinct_senses']);
        $this->assertSame($daily['quality']['distribution'], $todayRow['distribution']);
        $this->assertSame($daily['quality']['forget_rate'], $todayRow['forget_rate']);
        $this->assertSame($daily['quality']['stability_rate'], $todayRow['stability_rate']);
    }

    /**
     * 23. Empty data days have null rates (not "0%").
     */
    public function test_empty_days_have_null_rates(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'good', $today->copy()->addHour());

        $result = $this->service->build($this->user->id, 'english');

        // First 6 days are empty.
        for ($i = 0; $i < 6; $i++) {
            $this->assertNull($result['days'][$i]['forget_rate']);
            $this->assertNull($result['days'][$i]['stability_rate']);
        }
        // Today has data → non-null.
        $this->assertNotNull($result['days'][6]['forget_rate']);
        $this->assertNotNull($result['days'][6]['stability_rate']);
    }

    /**
     * 24. Service is read-only: no ReviewLog writes.
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
     * 25. Service does not change ReviewCard.
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
     * 26. Does not create ReviewLog.
     */
    public function test_service_does_not_create_review_log(): void
    {
        $before = ReviewLog::count();
        $this->service->build($this->user->id, 'english');
        $after = ReviewLog::count();

        $this->assertSame($before, $after);
    }

    /**
     * 27. timezone / start_day / end_day correct.
     */
    public function test_returns_timezone_and_day_range(): void
    {
        $result = $this->service->build($this->user->id, 'english');

        $today = Carbon::today(config('app.timezone', 'UTC'));
        $startDay = $today->copy()->subDays(6);

        $this->assertSame(config('app.timezone', 'UTC'), $result['timezone']);
        $this->assertSame($startDay->format('Y-m-d'), $result['start_day']);
        $this->assertSame($today->format('Y-m-d'), $result['end_day']);
    }

    /**
     * 28. Frozen time: works with a fixed "today" (not real-time dependent).
     */
    public function test_frozen_time_window(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 15, 10, 0, 0));

        try {
            $sense = $this->createConfirmedSense('apple');
            $card = $this->createSenseCard($sense);

            // Reviews on Jan 9, 10, 15 (within Jan 9-15 window).
            $this->createReviewLog($card, 'good', Carbon::create(2026, 1, 9, 12, 0, 0));
            $this->createReviewLog($card, 'hard', Carbon::create(2026, 1, 10, 12, 0, 0));
            $this->createReviewLog($card, 'easy', Carbon::create(2026, 1, 15, 9, 0, 0));

            // Review on Jan 8 (outside window — should be excluded).
            $this->createReviewLog($card, 'again', Carbon::create(2026, 1, 8, 12, 0, 0));

            $result = $this->service->build($this->user->id, 'english');

            $this->assertSame('2026-01-09', $result['start_day']);
            $this->assertSame('2026-01-15', $result['end_day']);
            $this->assertSame(3, $result['summary']['total_reviews']);
            $this->assertSame(3, $result['summary']['active_days']);

            // 7 days: Jan 9, 10, 11, 12, 13, 14, 15.
            $this->assertSame('2026-01-09', $result['days'][0]['day']);
            $this->assertSame('2026-01-15', $result['days'][6]['day']);
        } finally {
            Carbon::setTestNow(null);
        }
    }

    /**
     * 29. Query budget: 1 sense → constant ReviewLog queries.
     */
    public function test_query_budget_one_sense(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'good', $today->copy()->addHour());

        $reviewLogQueries = $this->countReviewLogQueries(fn() => $this->service->build($this->user->id, 'english'));

        // Exactly 1 ReviewLog query for the whole 7-day window.
        $this->assertSame(1, $reviewLogQueries);
    }

    /**
     * 30. Query budget: 10 senses → same constant.
     */
    public function test_query_budget_ten_senses(): void
    {
        $today = Carbon::today();
        for ($i = 0; $i < 10; $i++) {
            $sense = $this->createConfirmedSense('word' . $i);
            $card = $this->createSenseCard($sense);
            $this->createReviewLog($card, 'good', $today->copy()->subDays($i % 7)->addHour());
        }

        $reviewLogQueries = $this->countReviewLogQueries(fn() => $this->service->build($this->user->id, 'english'));

        $this->assertSame(1, $reviewLogQueries);
    }

    /**
     * 31. Query budget: 50 senses → same constant.
     */
    public function test_query_budget_fifty_senses(): void
    {
        $today = Carbon::today();
        for ($i = 0; $i < 50; $i++) {
            $sense = $this->createConfirmedSense('word' . $i);
            $card = $this->createSenseCard($sense);
            $this->createReviewLog($card, 'good', $today->copy()->subDays($i % 7)->addHour());
        }

        $reviewLogQueries = $this->countReviewLogQueries(fn() => $this->service->build($this->user->id, 'english'));

        $this->assertSame(1, $reviewLogQueries);
    }

    /**
     * 32. HTTP: authenticated user gets the trend JSON.
     */
    public function test_authenticated_user_gets_trend_json(): void
    {
        $sense = $this->createConfirmedSense('apple');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'again', $today->copy()->addHour());
        $this->createReviewLog($card, 'good', $today->copy()->subDays(2)->addHour());

        $response = $this->actingAs($this->user)
            ->getJson('/reviews/senses/seven-day-trend');

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
        $this->assertSame(7, count($response->json('days')));
        $this->assertSame(2, $response->json('summary.total_reviews'));
    }

    // ==================== Helpers ====================

    /**
     * Count the number of ReviewLog queries executed by $callback.
     * Filters the query log to only queries touching the review_logs table.
     */
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
