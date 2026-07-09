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
 * SenseReview-DailyReport-1000-1
 *
 * Verifies the read-only "今日学习日报" (daily learning report) service.
 * Distinct from SenseReviewTodaySummaryService (simpler today summary) —
 * this service produces a richer four-block report: overview, quality,
 * focus_senses, progress_senses.
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
 *  - reset exclusion, user/language isolation, sense-only, legacy word excluded.
 *  - READ-ONLY: no ReviewLog writes, no FSRS changes.
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
     * 26. HTTP: authenticated user gets the report JSON with all four blocks.
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
        ]);
        $this->assertSame(3, $response->json('overview.total_reviews'));
        // again(1) + good(3) + easy(4) = 8 / 3 = 2.67
        $this->assertEquals(2.67, $response->json('overview.average_rating'));
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
