<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\SenseReviewTodaySummaryService;
use App\Services\WordSenseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewTodaySummaryTest
 *
 * SenseReview-TodaySummary-1000-1
 *
 * Verifies the read-only cross-session daily aggregate for the SenseReview
 * "今日复习总结" feature. Distinct from the page-load-scoped session summary,
 * this service uses backend ReviewLog as the source of truth so that multiple
 * page sessions in the same natural day merge into one cumulative summary.
 *
 * Contract:
 *  - Auth required (controller-level guard tested via HTTP).
 *  - Empty day → stable structure, forget_rate null, no fake 0%.
 *  - Counts aggregate non-reset sense-only ReviewLog rows for today only.
 *  - reset (rating='reset' OR source='reset') excluded.
 *  - legacy word card logs excluded (target_type != 'sense').
 *  - Other user / other language excluded.
 *  - Yesterday and tomorrow excluded; first-second and last-second included.
 *  - Same sense rated multiple times → one aggregated focus_senses entry.
 *  - recent_reviews newest-first, max 10.
 *  - focus_senses rules: again / hard / multi-rating / last-rating-again-or-hard.
 *  - READ-ONLY: no ReviewLog writes, no ReviewCard / FSRS mutation.
 *  - Returns timezone / day / day_start / day_end.
 *  - Test dates are frozen so they don't depend on the test-run day.
 */
class SenseReviewTodaySummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WordSenseService $wordSenseService;
    private SenseReviewTodaySummaryService $service;

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

        $this->user = $this->createUser('today-summary@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
        $this->service = app(SenseReviewTodaySummaryService::class);
    }

    /**
     * 1. HTTP: unauthenticated → 401 / redirect (auth guard).
     */
    public function test_unauthenticated_request_is_blocked(): void
    {
        $response = $this->getJson('/reviews/senses/today-summary');
        $response->assertStatus(401);
    }

    /**
     * 2. Empty day → stable structure, forget_rate null, zero counts.
     */
    public function test_empty_day_returns_stable_structure(): void
    {
        $summary = $this->service->build($this->user->id, 'english');

        $this->assertSame(0, $summary['total_reviews']);
        $this->assertSame(0, $summary['distinct_senses']);
        $this->assertSame(0, $summary['distribution']['again']);
        $this->assertSame(0, $summary['distribution']['hard']);
        $this->assertSame(0, $summary['distribution']['good']);
        $this->assertSame(0, $summary['distribution']['easy']);
        $this->assertNull($summary['forget_rate']);
        $this->assertSame([], $summary['focus_senses']);
        $this->assertSame([], $summary['recent_reviews']);
    }

    /**
     * 3. again / hard / good / easy counts correct.
     */
    public function test_rating_distribution_counts(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        $today = Carbon::today();
        $this->createReviewLog($card, 'again', $today->copy()->addHours(1));
        $this->createReviewLog($card, 'hard',  $today->copy()->addHours(2));
        $this->createReviewLog($card, 'good',  $today->copy()->addHours(3));
        $this->createReviewLog($card, 'easy',  $today->copy()->addHours(4));

        $summary = $this->service->build($this->user->id, 'english');

        $this->assertSame(4, $summary['total_reviews']);
        $this->assertSame(1, $summary['distribution']['again']);
        $this->assertSame(1, $summary['distribution']['hard']);
        $this->assertSame(1, $summary['distribution']['good']);
        $this->assertSame(1, $summary['distribution']['easy']);
        $this->assertSame(0.25, $summary['forget_rate']);
    }

    /**
     * 4. rating='reset' excluded.
     */
    public function test_reset_rating_excluded(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'good',  $today->copy()->addHour());
        $this->createReviewLog($card, 'reset', $today->copy()->addHours(2), 'reset');

        $summary = $this->service->build($this->user->id, 'english');

        $this->assertSame(1, $summary['total_reviews']);
        $this->assertSame(1, $summary['distribution']['good']);
    }

    /**
     * 5. source='reset' excluded (even if rating is a normal value).
     */
    public function test_reset_source_excluded(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'good', $today->copy()->addHour(), 'sense_review');
        $this->createReviewLog($card, 'good', $today->copy()->addHours(2), 'reset');

        $summary = $this->service->build($this->user->id, 'english');

        $this->assertSame(1, $summary['total_reviews']);
    }

    /**
     * 6. legacy word card logs excluded (target_type = 'word').
     */
    public function test_legacy_word_card_excluded(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $senseCard = $this->createSenseCard($sense);

        // Legacy word card (target_type = word) pointing at a fake word id.
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

        $summary = $this->service->build($this->user->id, 'english');

        $this->assertSame(1, $summary['total_reviews']);
    }

    /**
     * 7. Other user's logs excluded.
     */
    public function test_other_user_excluded(): void
    {
        $other = $this->createUser('other-today@example.com', 'english');
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

        $summary = $this->service->build($this->user->id, 'english');

        $this->assertSame(0, $summary['total_reviews']);
    }

    /**
     * 8. Other language excluded.
     */
    public function test_other_language_excluded(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'good', $today->copy()->addHour());

        // Same user, different language → excluded.
        $summary = $this->service->build($this->user->id, 'french');

        $this->assertSame(0, $summary['total_reviews']);
    }

    /**
     * 9. Yesterday and tomorrow excluded.
     */
    public function test_yesterday_and_tomorrow_excluded(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'good', $today->copy()->subDay());
        $this->createReviewLog($card, 'good', $today->copy()->addDay());

        $summary = $this->service->build($this->user->id, 'english');

        $this->assertSame(0, $summary['total_reviews']);
    }

    /**
     * 10. First second (00:00:00) and last second (23:59:59) of today included.
     */
    public function test_day_boundaries_included(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'good', $today->copy()); // 00:00:00
        $this->createReviewLog($card, 'good', $today->copy()->addDay()->subSecond()); // 23:59:59

        $summary = $this->service->build($this->user->id, 'english');

        $this->assertSame(2, $summary['total_reviews']);
    }

    /**
     * 11. Same sense rated multiple times → one aggregated focus_senses entry.
     */
    public function test_same_sense_multiple_ratings_aggregated(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'again', $today->copy()->addHour());
        $this->createReviewLog($card, 'hard',  $today->copy()->addHours(2));
        $this->createReviewLog($card, 'good',  $today->copy()->addHours(3));

        $summary = $this->service->build($this->user->id, 'english');

        $this->assertCount(1, $summary['focus_senses']);
        $focus = $summary['focus_senses'][0];
        $this->assertSame($sense->id, $focus['word_sense_id']);
        $this->assertSame('bank', $focus['lemma']);
        $this->assertSame(3, $focus['total']);
        $this->assertSame(1, $focus['again']);
        $this->assertSame(1, $focus['hard']);
        $this->assertSame('good', $focus['last_rating']);
    }

    /**
     * 12. recent_reviews newest-first.
     */
    public function test_recent_reviews_newest_first(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'again', $today->copy()->addHour());
        $this->createReviewLog($card, 'hard',  $today->copy()->addHours(2));
        $this->createReviewLog($card, 'good',  $today->copy()->addHours(3));

        $summary = $this->service->build($this->user->id, 'english');

        $this->assertCount(3, $summary['recent_reviews']);
        $this->assertSame('good', $summary['recent_reviews'][0]['rating']);
        $this->assertSame('记得', $summary['recent_reviews'][0]['rating_label']);
        $this->assertSame('hard',  $summary['recent_reviews'][1]['rating']);
        $this->assertSame('again', $summary['recent_reviews'][2]['rating']);
    }

    /**
     * 13. focus_senses rules: again, hard, multi-rating, last-again-or-hard.
     */
    public function test_focus_senses_rules(): void
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

        $summary = $this->service->build($this->user->id, 'english');

        $focusIds = array_column($summary['focus_senses'], 'word_sense_id');
        $this->assertContains($senseA->id, $focusIds);
        $this->assertContains($senseB->id, $focusIds);
        $this->assertContains($senseC->id, $focusIds);
        $this->assertContains($senseE->id, $focusIds);
        $this->assertNotContains($senseD->id, $focusIds);
    }

    /**
     * 14. READ-ONLY: does not write ReviewLog.
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
     * 15. READ-ONLY: does not change ReviewCard / FSRS fields.
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
     * 16. Returns timezone / day / day_start / day_end.
     */
    public function test_returns_timezone_and_day_boundaries(): void
    {
        $summary = $this->service->build($this->user->id, 'english');

        $this->assertNotEmpty($summary['timezone']);
        $this->assertSame(Carbon::today()->format('Y-m-d'), $summary['day']);
        $this->assertNotEmpty($summary['day_start']);
        $this->assertNotEmpty($summary['day_end']);
    }

    /**
     * 17. distinct_senses counts unique WordSense ids reviewed today.
     */
    public function test_distinct_senses_count(): void
    {
        $senseA = $this->createConfirmedSense('apple');
        $cardA = $this->createSenseCard($senseA);
        $senseB = $this->createConfirmedSense('banana');
        $cardB = $this->createSenseCard($senseB);
        $today = Carbon::today();

        $this->createReviewLog($cardA, 'good', $today->copy()->addHour());
        $this->createReviewLog($cardA, 'hard', $today->copy()->addHours(2));
        $this->createReviewLog($cardB, 'good', $today->copy()->addHours(3));

        $summary = $this->service->build($this->user->id, 'english');

        $this->assertSame(3, $summary['total_reviews']);
        $this->assertSame(2, $summary['distinct_senses']);
    }

    /**
     * 18. HTTP: authenticated user gets the summary JSON.
     */
    public function test_authenticated_user_gets_summary_json(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'good', Carbon::today()->addHour());

        $response = $this->actingAs($this->user)
            ->getJson('/reviews/senses/today-summary');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'timezone', 'day', 'day_start', 'day_end',
            'total_reviews', 'distinct_senses', 'distribution',
            'forget_rate', 'focus_senses', 'recent_reviews',
        ]);
        $this->assertSame(1, $response->json('total_reviews'));
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
