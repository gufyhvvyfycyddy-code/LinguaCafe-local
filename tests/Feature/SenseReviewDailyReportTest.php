<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\SenseReviewDailyReportService;
use App\Services\WordSenseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewDailyReportTest
 *
 * SenseReview-DailyReport-1000-1 (consolidated in 1000-3 / ADR-0006)
 *
 * Verifies the read-only "今日学习日报" (daily learning report) service.
 * This is the SINGLE formal today-report Product Service after ADR-0006
 * consolidation — the former SenseReviewTodaySummaryService was merged
 * into this service. Produces a five-block report: overview, quality,
 * focus_senses, progress_senses, recent_reviews.
 *
 * Contract:
 *  - Auth required (HTTP guard).
 *  - Empty day → stable structure, average_rating null, no fake 0.
 *  - overview: total_reviews, distinct_senses, first_review_senses,
 *    review_again_senses, average_rating (again=1,hard=2,good=3,easy=4).
 *  - quality: distribution, forget_rate (again/total), stability_rate
 *    ((good+easy)/total).
 *  - focus_senses: max 10, sorted by again desc, hard desc, total desc.
 *  - progress_senses: senses with again→good or hard→easy transitions today.
 *  - recent_reviews: max 10, newest first, with rating_label (hard→勉强记得).
 *  - reset exclusion, user/language isolation, sense-only, legacy word excluded.
 *  - READ-ONLY: no ReviewLog writes, no FSRS changes.
 *  - Query budget: empty=1 query, non-empty≤2 queries (locked by test 31).
 *  - Returns timezone / day / day_start / day_end.
 */
class SenseReviewDailyReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WordSenseService $wordSenseService;
    private SenseReviewDailyReportService $service;

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

        $this->user = $this->createUser('daily-report@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
        $this->service = app(SenseReviewDailyReportService::class);
    }

    /**
     * 1. HTTP: unauthenticated → 401.
     */
    public function test_unauthenticated_request_is_blocked(): void
    {
        $response = $this->getJson('/reviews/senses/daily-report');
        $response->assertStatus(401);
    }

    /**
     * 2. Empty day → stable structure, average_rating null.
     */
    public function test_empty_day_returns_stable_structure(): void
    {
        $report = $this->service->build($this->user->id, 'english');

        $this->assertSame(0, $report['overview']['total_reviews']);
        $this->assertSame(0, $report['overview']['distinct_senses']);
        $this->assertSame(0, $report['overview']['first_review_senses']);
        $this->assertSame(0, $report['overview']['review_again_senses']);
        $this->assertNull($report['overview']['average_rating']);

        $this->assertSame(0, $report['quality']['distribution']['again']);
        $this->assertSame(0, $report['quality']['distribution']['hard']);
        $this->assertSame(0, $report['quality']['distribution']['good']);
        $this->assertSame(0, $report['quality']['distribution']['easy']);
        $this->assertNull($report['quality']['forget_rate']);
        $this->assertNull($report['quality']['stability_rate']);

        $this->assertSame([], $report['focus_senses']);
        $this->assertSame([], $report['progress_senses']);
        $this->assertSame([], $report['recent_reviews']);
    }

    /**
     * 3. overview: total_reviews and distinct_senses.
     */
    public function test_overview_total_and_distinct(): void
    {
        $senseA = $this->createConfirmedSense('apple');
        $cardA = $this->createSenseCard($senseA);
        $senseB = $this->createConfirmedSense('banana');
        $cardB = $this->createSenseCard($senseB);
        $today = Carbon::today();

        $this->createReviewLog($cardA, 'good', $today->copy()->addHour());
        $this->createReviewLog($cardA, 'hard', $today->copy()->addHours(2));
        $this->createReviewLog($cardB, 'good', $today->copy()->addHours(3));

        $report = $this->service->build($this->user->id, 'english');

        $this->assertSame(3, $report['overview']['total_reviews']);
        $this->assertSame(2, $report['overview']['distinct_senses']);
    }

    /**
     * 4. overview: first_review_senses vs review_again_senses.
     *
     * Sense A: reviewed yesterday AND today → review_again.
     * Sense B: reviewed today only → first_review.
     */
    public function test_first_review_vs_review_again_senses(): void
    {
        $senseA = $this->createConfirmedSense('apple');
        $cardA = $this->createSenseCard($senseA);
        $senseB = $this->createConfirmedSense('banana');
        $cardB = $this->createSenseCard($senseB);
        $today = Carbon::today();
        $yesterday = $today->copy()->subDay();

        // Sense A: reviewed yesterday + today → review_again
        $this->createReviewLog($cardA, 'good', $yesterday->copy()->addHour());
        $this->createReviewLog($cardA, 'good', $today->copy()->addHour());

        // Sense B: reviewed today only → first_review
        $this->createReviewLog($cardB, 'good', $today->copy()->addHours(2));

        $report = $this->service->build($this->user->id, 'english');

        $this->assertSame(1, $report['overview']['first_review_senses']);
        $this->assertSame(1, $report['overview']['review_again_senses']);
    }

    /**
     * 5. overview: average_rating (again=1, hard=2, good=3, easy=4).
     */
    public function test_average_rating_formula(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        // again(1) + hard(2) + good(3) + easy(4) = 10 / 4 = 2.5
        $this->createReviewLog($card, 'again', $today->copy()->addHour());
        $this->createReviewLog($card, 'hard',  $today->copy()->addHours(2));
        $this->createReviewLog($card, 'good',  $today->copy()->addHours(3));
        $this->createReviewLog($card, 'easy',  $today->copy()->addHours(4));

        $report = $this->service->build($this->user->id, 'english');

        $this->assertSame(2.5, $report['overview']['average_rating']);
    }

    /**
     * 6. quality: distribution counts.
     */
    public function test_quality_distribution(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        $this->createReviewLog($card, 'again', $today->copy()->addHour());
        $this->createReviewLog($card, 'hard',  $today->copy()->addHours(2));
        $this->createReviewLog($card, 'good',  $today->copy()->addHours(3));
        $this->createReviewLog($card, 'easy',  $today->copy()->addHours(4));

        $report = $this->service->build($this->user->id, 'english');

        $this->assertSame(1, $report['quality']['distribution']['again']);
        $this->assertSame(1, $report['quality']['distribution']['hard']);
        $this->assertSame(1, $report['quality']['distribution']['good']);
        $this->assertSame(1, $report['quality']['distribution']['easy']);
    }

    /**
     * 7. quality: forget_rate = again / total.
     */
    public function test_quality_forget_rate(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        // 2 again, 8 good → forget_rate = 0.2
        for ($i = 0; $i < 2; $i++) {
            $this->createReviewLog($card, 'again', $today->copy()->addHours($i + 1));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->createReviewLog($card, 'good', $today->copy()->addHours($i + 3));
        }

        $report = $this->service->build($this->user->id, 'english');

        $this->assertSame(0.2, $report['quality']['forget_rate']);
    }

    /**
     * 8. quality: stability_rate = (good + easy) / total.
     */
    public function test_quality_stability_rate(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        // 3 good + 1 easy = 4 stable, 2 again + 1 hard = 3 unstable, total 7
        // stability_rate = 4/7
        $this->createReviewLog($card, 'again', $today->copy()->addHour());
        $this->createReviewLog($card, 'again', $today->copy()->addHours(2));
        $this->createReviewLog($card, 'hard',  $today->copy()->addHours(3));
        $this->createReviewLog($card, 'good',  $today->copy()->addHours(4));
        $this->createReviewLog($card, 'good',  $today->copy()->addHours(5));
        $this->createReviewLog($card, 'good',  $today->copy()->addHours(6));
        $this->createReviewLog($card, 'easy',  $today->copy()->addHours(7));

        $report = $this->service->build($this->user->id, 'english');

        $this->assertSame(round(4 / 7, 4), $report['quality']['stability_rate']);
    }

    /**
     * 9. focus_senses: sorted by again desc, hard desc, total desc.
     */
    public function test_focus_senses_sorting(): void
    {
        $senseA = $this->createConfirmedSense('apple'); // 2 again, 1 good
        $cardA = $this->createSenseCard($senseA);
        $senseB = $this->createConfirmedSense('banana'); // 1 again, 2 hard
        $cardB = $this->createSenseCard($senseB);
        $senseC = $this->createConfirmedSense('cherry'); // 0 again, 3 hard
        $cardC = $this->createSenseCard($senseC);
        $today = Carbon::today();

        $this->createReviewLog($cardA, 'again', $today->copy()->addHour());
        $this->createReviewLog($cardA, 'again', $today->copy()->addHours(2));
        $this->createReviewLog($cardA, 'good',  $today->copy()->addHours(3));

        $this->createReviewLog($cardB, 'again', $today->copy()->addHour());
        $this->createReviewLog($cardB, 'hard',  $today->copy()->addHours(2));
        $this->createReviewLog($cardB, 'hard',  $today->copy()->addHours(3));

        $this->createReviewLog($cardC, 'hard', $today->copy()->addHour());
        $this->createReviewLog($cardC, 'hard', $today->copy()->addHours(2));
        $this->createReviewLog($cardC, 'hard', $today->copy()->addHours(3));

        $report = $this->service->build($this->user->id, 'english');

        $this->assertCount(3, $report['focus_senses']);
        // A: 2 again → first
        $this->assertSame($senseA->id, $report['focus_senses'][0]['word_sense_id']);
        // B: 1 again, 2 hard → second
        $this->assertSame($senseB->id, $report['focus_senses'][1]['word_sense_id']);
        // C: 0 again, 3 hard → third
        $this->assertSame($senseC->id, $report['focus_senses'][2]['word_sense_id']);
    }

    /**
     * 10. focus_senses: each item has lemma, sense_zh, total, again, hard, last_rating.
     */
    public function test_focus_senses_item_shape(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        $this->createReviewLog($card, 'again', $today->copy()->addHour());
        $this->createReviewLog($card, 'good',  $today->copy()->addHours(2));

        $report = $this->service->build($this->user->id, 'english');

        $this->assertCount(1, $report['focus_senses']);
        $item = $report['focus_senses'][0];
        $this->assertSame($sense->id, $item['word_sense_id']);
        $this->assertSame('bank', $item['lemma']);
        $this->assertSame('测试', $item['sense_zh']);
        $this->assertSame(2, $item['total']);
        $this->assertSame(1, $item['again']);
        $this->assertSame(0, $item['hard']);
        $this->assertSame('good', $item['last_rating']);
    }

    /**
     * 11. focus_senses: max 10 items.
     */
    public function test_focus_senses_max_10(): void
    {
        $today = Carbon::today();
        for ($i = 0; $i < 15; $i++) {
            $sense = $this->createConfirmedSense('word' . $i);
            $card = $this->createSenseCard($sense);
            $this->createReviewLog($card, 'again', $today->copy()->addHours($i + 1));
        }

        $report = $this->service->build($this->user->id, 'english');

        $this->assertCount(10, $report['focus_senses']);
    }

    /**
     * 12. progress_senses: again → good transition detected.
     */
    public function test_progress_senses_again_to_good(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        // again then good → progress
        $this->createReviewLog($card, 'again', $today->copy()->addHour());
        $this->createReviewLog($card, 'good',  $today->copy()->addHours(2));

        $report = $this->service->build($this->user->id, 'english');

        $this->assertCount(1, $report['progress_senses']);
        $item = $report['progress_senses'][0];
        $this->assertSame($sense->id, $item['word_sense_id']);
        $this->assertSame('bank', $item['lemma']);
        $this->assertSame('again', $item['from_rating']);
        $this->assertSame('good', $item['to_rating']);
    }

    /**
     * 13. progress_senses: hard → easy transition detected.
     */
    public function test_progress_senses_hard_to_easy(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        $this->createReviewLog($card, 'hard', $today->copy()->addHour());
        $this->createReviewLog($card, 'easy', $today->copy()->addHours(2));

        $report = $this->service->build($this->user->id, 'english');

        $this->assertCount(1, $report['progress_senses']);
        $this->assertSame('hard', $report['progress_senses'][0]['from_rating']);
        $this->assertSame('easy', $report['progress_senses'][0]['to_rating']);
    }

    /**
     * 14. progress_senses: no transition → not included.
     */
    public function test_progress_senses_no_transition_excluded(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        // good then good → no progress
        $this->createReviewLog($card, 'good', $today->copy()->addHour());
        $this->createReviewLog($card, 'good', $today->copy()->addHours(2));

        $report = $this->service->build($this->user->id, 'english');

        $this->assertSame([], $report['progress_senses']);
    }

    /**
     * 15. progress_senses: again → hard is NOT progress (hard is not good/easy).
     */
    public function test_progress_senses_again_to_hard_not_progress(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        $this->createReviewLog($card, 'again', $today->copy()->addHour());
        $this->createReviewLog($card, 'hard',  $today->copy()->addHours(2));

        $report = $this->service->build($this->user->id, 'english');

        $this->assertSame([], $report['progress_senses']);
    }

    /**
     * 16. progress_senses: same sense with again→good AND hard→easy → one entry
     *     (first qualifying transition), no duplicates.
     */
    public function test_progress_senses_no_duplicates(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        $this->createReviewLog($card, 'again', $today->copy()->addHour());
        $this->createReviewLog($card, 'good',  $today->copy()->addHours(2));
        $this->createReviewLog($card, 'hard',  $today->copy()->addHours(3));
        $this->createReviewLog($card, 'easy',  $today->copy()->addHours(4));

        $report = $this->service->build($this->user->id, 'english');

        $this->assertCount(1, $report['progress_senses']);
    }

    /**
     * 17. reset exclusion: rating='reset' and source='reset' excluded.
     *     Also covers source='reset' with a normal rating value (migrated
     *     from TodaySummaryTest::test_reset_source_excluded).
     */
    public function test_reset_excluded(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        $this->createReviewLog($card, 'good',  $today->copy()->addHour());
        $this->createReviewLog($card, 'reset', $today->copy()->addHours(2), 'reset');

        $report = $this->service->build($this->user->id, 'english');

        $this->assertSame(1, $report['overview']['total_reviews']);
        $this->assertSame(1, $report['quality']['distribution']['good']);
    }

    /**
     * 17b. source='reset' excluded even when rating is a normal value
     *      (migrated from TodaySummaryTest::test_reset_source_excluded).
     */
    public function test_reset_source_excluded_even_with_normal_rating(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        $this->createReviewLog($card, 'good', $today->copy()->addHour(), 'sense_review');
        $this->createReviewLog($card, 'good', $today->copy()->addHours(2), 'reset');

        $report = $this->service->build($this->user->id, 'english');

        $this->assertSame(1, $report['overview']['total_reviews']);
    }

    /**
     * 18. legacy word card excluded.
     */
    public function test_legacy_word_card_excluded(): void
    {
        $sense = $this->createConfirmedSense('bank');
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
        $this->createReviewLog($wordCard,   'good', $today->copy()->addHours(2));

        $report = $this->service->build($this->user->id, 'english');

        $this->assertSame(1, $report['overview']['total_reviews']);
    }

    /**
     * 19. Other user excluded.
     */
    public function test_other_user_excluded(): void
    {
        $other = $this->createUser('other-daily@example.com', 'english');
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
        $this->createReviewLog($otherCard, 'again', $today->copy()->addHour());

        $report = $this->service->build($this->user->id, 'english');

        $this->assertSame(0, $report['overview']['total_reviews']);
    }

    /**
     * 20. Other language excluded.
     */
    public function test_other_language_excluded(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'good', $today->copy()->addHour());

        $report = $this->service->build($this->user->id, 'french');

        $this->assertSame(0, $report['overview']['total_reviews']);
    }

    /**
     * 21. Yesterday and tomorrow excluded.
     */
    public function test_yesterday_and_tomorrow_excluded(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'good', $today->copy()->subDay());
        $this->createReviewLog($card, 'good', $today->copy()->addDay());

        $report = $this->service->build($this->user->id, 'english');

        $this->assertSame(0, $report['overview']['total_reviews']);
    }

    /**
     * 22. Day boundaries: 00:00:00 and 23:59:59 included.
     */
    public function test_day_boundaries_included(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'good', $today->copy()); // 00:00:00
        $this->createReviewLog($card, 'good', $today->copy()->addDay()->subSecond()); // 23:59:59

        $report = $this->service->build($this->user->id, 'english');

        $this->assertSame(2, $report['overview']['total_reviews']);
    }

    /**
     * 23. READ-ONLY: does not write ReviewLog.
     */
    public function test_service_does_not_write_review_log(): void
    {
        $sense = $this->createConfirmedSense('bank');
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
     * 24. READ-ONLY: does not change FSRS fields.
     */
    public function test_service_does_not_change_fsrs_fields(): void
    {
        $sense = $this->createConfirmedSense('bank');
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
    }

    /**
     * 25. Returns timezone / day / day_start / day_end.
     */
    public function test_returns_timezone_and_day_boundaries(): void
    {
        $report = $this->service->build($this->user->id, 'english');

        $this->assertNotEmpty($report['timezone']);
        $this->assertSame(Carbon::today()->format('Y-m-d'), $report['day']);
        $this->assertNotEmpty($report['day_start']);
        $this->assertNotEmpty($report['day_end']);
    }

    /**
     * 26. HTTP: authenticated user gets the report JSON with all five blocks.
     */
    public function test_authenticated_user_gets_report_json(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'again', $today->copy()->addHour());
        $this->createReviewLog($card, 'good',  $today->copy()->addHours(2));
        $this->createReviewLog($card, 'easy',  $today->copy()->addHours(3));

        $response = $this->actingAs($this->user)
            ->getJson('/reviews/senses/daily-report');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'timezone', 'day', 'day_start', 'day_end',
            'overview' => ['total_reviews', 'distinct_senses', 'first_review_senses', 'review_again_senses', 'average_rating'],
            'quality' => ['distribution', 'forget_rate', 'stability_rate'],
            'focus_senses',
            'progress_senses',
            'recent_reviews',
        ]);
        $this->assertSame(3, $response->json('overview.total_reviews'));
        // again(1) + good(3) + easy(4) = 8 / 3 = 2.67
        $this->assertEquals(2.67, $response->json('overview.average_rating'));
    }

    /**
     * 27. focus_senses rules: again, hard, multi-rating, last-again-or-hard.
     *     (Migrated from TodaySummaryTest::test_focus_senses_rules.)
     */
    public function test_focus_senses_filter_rules(): void
    {
        // Sense A: has 'again' → focus.
        $senseA = $this->createConfirmedSense('apple');
        $cardA = $this->createSenseCard($senseA);
        // Sense B: has 'hard' only → focus.
        $senseB = $this->createConfirmedSense('banana');
        $cardB = $this->createSenseCard($senseB);
        // Sense C: rated twice, both 'good' → focus (multi-rating).
        $senseC = $this->createConfirmedSense('cherry');
        $cardC = $this->createSenseCard($senseC);
        // Sense D: one 'good' only → NOT focus.
        $senseD = $this->createConfirmedSense('date');
        $cardD = $this->createSenseCard($senseD);
        // Sense E: last rating 'hard' → focus.
        $senseE = $this->createConfirmedSense('egg');
        $cardE = $this->createSenseCard($senseE);

        $today = Carbon::today();
        $this->createReviewLog($cardA, 'again', $today->copy()->addHour());
        $this->createReviewLog($cardB, 'hard',  $today->copy()->addHour());
        $this->createReviewLog($cardC, 'good',  $today->copy()->addHour());
        $this->createReviewLog($cardC, 'good',  $today->copy()->addHours(2));
        $this->createReviewLog($cardD, 'good',  $today->copy()->addHour());
        $this->createReviewLog($cardE, 'good',  $today->copy()->addHour());
        $this->createReviewLog($cardE, 'hard',  $today->copy()->addHours(2));

        $report = $this->service->build($this->user->id, 'english');

        $focusIds = array_column($report['focus_senses'], 'word_sense_id');
        $this->assertContains($senseA->id, $focusIds);
        $this->assertContains($senseB->id, $focusIds);
        $this->assertContains($senseC->id, $focusIds);
        $this->assertContains($senseE->id, $focusIds);
        $this->assertNotContains($senseD->id, $focusIds);
    }

    /**
     * 28. focus_senses: includes last_reviewed_at (superset shape, migrated
     *     from TodaySummary which always carried this field).
     */
    public function test_focus_senses_includes_last_reviewed_at(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();

        $this->createReviewLog($card, 'again', $today->copy()->addHour());
        $this->createReviewLog($card, 'good',  $today->copy()->addHours(2));

        $report = $this->service->build($this->user->id, 'english');

        $this->assertCount(1, $report['focus_senses']);
        $this->assertArrayHasKey('last_reviewed_at', $report['focus_senses'][0]);
        $this->assertNotNull($report['focus_senses'][0]['last_reviewed_at']);
    }

    /**
     * 29. recent_reviews: newest first, max 10, with rating_label.
     *     (Migrated from TodaySummaryTest::test_recent_reviews_newest_first.)
     */
    public function test_recent_reviews_newest_first_with_rating_label(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'again', $today->copy()->addHour());
        $this->createReviewLog($card, 'hard',  $today->copy()->addHours(2));
        $this->createReviewLog($card, 'good',  $today->copy()->addHours(3));

        $report = $this->service->build($this->user->id, 'english');

        $this->assertCount(3, $report['recent_reviews']);
        // newest first: good (3h), hard (2h), again (1h)
        $this->assertSame('good',  $report['recent_reviews'][0]['rating']);
        $this->assertSame('记得',   $report['recent_reviews'][0]['rating_label']);
        $this->assertSame('hard',  $report['recent_reviews'][1]['rating']);
        $this->assertSame('勉强记得', $report['recent_reviews'][1]['rating_label']);
        $this->assertSame('again', $report['recent_reviews'][2]['rating']);
        $this->assertSame('忘了',   $report['recent_reviews'][2]['rating_label']);
    }

    /**
     * 30. recent_reviews: max 10 items.
     */
    public function test_recent_reviews_max_10(): void
    {
        $today = Carbon::today();
        for ($i = 0; $i < 15; $i++) {
            $sense = $this->createConfirmedSense('word' . $i);
            $card = $this->createSenseCard($sense);
            $this->createReviewLog($card, 'good', $today->copy()->addHours($i + 1));
        }

        $report = $this->service->build($this->user->id, 'english');

        $this->assertCount(10, $report['recent_reviews']);
    }

    /**
     * 31. Query budget: empty day → exactly 1 ReviewLog query.
     *     Non-empty day → at most 2 ReviewLog queries (reviewsForPeriod +
     *     sensesReviewedBefore). Constant regardless of sense count.
     *
     * Uses a single DB::listen callback with a phase flag because
     * DB::listen returns void in Laravel (the callback cannot be cancelled),
     * so two separate listeners would both count the second build's queries.
     */
    public function test_query_budget_constant(): void
    {
        $emptyCount = 0;
        $nonEmptyCount = 0;
        $phase = 'empty';
        \DB::listen(function ($query) use (&$emptyCount, &$nonEmptyCount, &$phase) {
            if (!str_contains($query->sql, 'review_logs')) {
                return;
            }
            if ($phase === 'empty') {
                $emptyCount++;
            } elseif ($phase === 'nonempty') {
                $nonEmptyCount++;
            }
        });

        // Phase 1: empty day → exactly 1 ReviewLog query (reviewsForPeriod
        // returns empty; sensesReviewedBefore is skipped).
        $this->service->build($this->user->id, 'english');
        $this->assertSame(1, $emptyCount, 'empty daily report must issue exactly 1 ReviewLog query');

        // Phase 2: non-empty day with 5 senses → at most 2 ReviewLog queries.
        $today = Carbon::today();
        for ($i = 0; $i < 5; $i++) {
            $sense = $this->createConfirmedSense('budget' . $i);
            $card = $this->createSenseCard($sense);
            $this->createReviewLog($card, 'good', $today->copy()->addHours($i + 1));
        }

        $phase = 'nonempty';
        $this->service->build($this->user->id, 'english');
        $this->assertLessThanOrEqual(2, $nonEmptyCount, 'non-empty daily report must issue at most 2 ReviewLog queries');
    }

    // ==================== Helpers ====================

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
