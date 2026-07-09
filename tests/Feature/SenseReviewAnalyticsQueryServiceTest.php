<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\SenseReviewAnalyticsQueryService;
use App\Services\WordSenseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewAnalyticsQueryServiceTest
 *
 * SenseReview-AnalyticsQuery-1000-1
 *
 * Verifies the centralized read-only ReviewLog statistics query layer.
 * This service is the single entry point for sense-review analytics
 * queries. It composes SenseReviewQueryService for the shared isolation
 * rules (sense-only filtering, user/language isolation, reset exclusion)
 * and adds analytics-specific result shaping.
 *
 * Contract:
 *  - READ-ONLY: never writes ReviewLog / ReviewCard / WordSense / FSRS.
 *  - reviewsForPeriod(): non-reset sense logs in [start, end), newest-first.
 *  - sensesReviewedBefore(): sense ids with any non-reset review before a time.
 *  - reviewsForCards(): non-reset logs for given card ids, newest-first.
 *  - ratingDistribution(): again/hard/good/easy counts from a collection.
 *  - forgetRate(): again/total, null when empty.
 *  - stabilityRate(): (good+easy)/total, null when empty.
 *  - reviewsBySense(): per-sense aggregation with counts + ratings sequence.
 *
 * Rule tests:
 *  1. user isolation   2. language isolation  3. sense-only filtering
 *  4. reset exclusion  5. date boundaries     6. empty data
 *  7. multi-sense aggregation
 *  8. multi-ReviewLog aggregation
 *
 * Query count tests:
 *  - reviewsForCards: 1 query for 1/10/50 cards (NOT linear).
 *  - reviewsForPeriod: 1 query regardless of sense count.
 */
class SenseReviewAnalyticsQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WordSenseService $wordSenseService;
    private SenseReviewAnalyticsQueryService $service;

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

        $this->user = $this->createUser('analytics@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
        $this->service = app(SenseReviewAnalyticsQueryService::class);
    }

    // ==================== reviewsForPeriod ====================

    /**
     * 1. User isolation: other user's logs excluded.
     */
    public function test_reviews_for_period_user_isolation(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'good', $today->copy()->addHour());

        $other = $this->createUser('other-analytics@example.com', 'english');
        $otherSense = $this->createConfirmedSenseForUser($other, 'river');
        $otherCard = $this->createSenseCardForUser($other, $otherSense);
        $this->createReviewLog($otherCard, 'again', $today->copy()->addHours(2));

        $logs = $this->service->reviewsForPeriod(
            $this->user->id,
            'english',
            $today,
            Carbon::tomorrow(),
        );

        $this->assertSame(1, $logs->count());
        $this->assertSame('good', $logs->first()->rating);
    }

    /**
     * 2. Language isolation: other language excluded.
     */
    public function test_reviews_for_period_language_isolation(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'good', $today->copy()->addHour());

        $logs = $this->service->reviewsForPeriod(
            $this->user->id,
            'french',
            $today,
            Carbon::tomorrow(),
        );

        $this->assertSame(0, $logs->count());
    }

    /**
     * 3. Sense-only filtering: legacy word card logs excluded.
     */
    public function test_reviews_for_period_excludes_legacy_word_cards(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $senseCard = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($senseCard, 'good', $today->copy()->addHour());

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
        $this->createReviewLog($wordCard, 'good', $today->copy()->addHours(2));

        $logs = $this->service->reviewsForPeriod(
            $this->user->id,
            'english',
            $today,
            Carbon::tomorrow(),
        );

        $this->assertSame(1, $logs->count());
    }

    /**
     * 4. Reset exclusion: rating='reset' OR source='reset' excluded.
     */
    public function test_reviews_for_period_excludes_reset(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'good',  $today->copy()->addHour());
        $this->createReviewLog($card, 'reset', $today->copy()->addHours(2), 'reset');
        $this->createReviewLog($card, 'good',  $today->copy()->addHours(3), 'reset');

        $logs = $this->service->reviewsForPeriod(
            $this->user->id,
            'english',
            $today,
            Carbon::tomorrow(),
        );

        $this->assertSame(1, $logs->count());
    }

    /**
     * 5. Date boundaries: [start, end) — start included, end excluded.
     */
    public function test_reviews_for_period_date_boundaries(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $tomorrow = Carbon::tomorrow();

        $this->createReviewLog($card, 'good', $today->copy());                    // included
        $this->createReviewLog($card, 'good', $today->copy()->addDay()->subSecond()); // included (23:59:59)
        $this->createReviewLog($card, 'good', $tomorrow->copy());                 // excluded (next day 00:00:00)
        $this->createReviewLog($card, 'good', $today->copy()->subSecond());       // excluded (yesterday 23:59:59)

        $logs = $this->service->reviewsForPeriod(
            $this->user->id,
            'english',
            $today,
            $tomorrow,
        );

        $this->assertSame(2, $logs->count());
    }

    /**
     * 6. Empty data → empty collection.
     */
    public function test_reviews_for_period_empty(): void
    {
        $logs = $this->service->reviewsForPeriod(
            $this->user->id,
            'english',
            Carbon::today(),
            Carbon::tomorrow(),
        );

        $this->assertCount(0, $logs);
    }

    /**
     * 7. Multi-sense aggregation: multiple senses in one period.
     */
    public function test_reviews_for_period_multi_sense(): void
    {
        $senseA = $this->createConfirmedSense('apple');
        $cardA = $this->createSenseCard($senseA);
        $senseB = $this->createConfirmedSense('banana');
        $cardB = $this->createSenseCard($senseB);
        $today = Carbon::today();
        $this->createReviewLog($cardA, 'good', $today->copy()->addHour());
        $this->createReviewLog($cardB, 'hard', $today->copy()->addHours(2));

        $logs = $this->service->reviewsForPeriod(
            $this->user->id,
            'english',
            $today,
            Carbon::tomorrow(),
        );

        $this->assertSame(2, $logs->count());
        $senseIds = $logs->pluck('word_sense_id')->unique();
        $this->assertSame(2, $senseIds->count());
    }

    /**
     * 8. Multi-ReviewLog aggregation: same sense rated multiple times.
     */
    public function test_reviews_for_period_multi_log_same_sense(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'again', $today->copy()->addHour());
        $this->createReviewLog($card, 'hard',  $today->copy()->addHours(2));
        $this->createReviewLog($card, 'good',  $today->copy()->addHours(3));

        $logs = $this->service->reviewsForPeriod(
            $this->user->id,
            'english',
            $today,
            Carbon::tomorrow(),
        );

        $this->assertSame(3, $logs->count());
        // Newest-first ordering.
        $this->assertSame('good', $logs->first()->rating);
        $this->assertSame('again', $logs->last()->rating);
    }

    /**
     * 9. reviewsForPeriod returns sense metadata (lemma, sense_zh, word_sense_id).
     */
    public function test_reviews_for_period_includes_sense_metadata(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        $this->createReviewLog($card, 'good', $today->copy()->addHour());

        $logs = $this->service->reviewsForPeriod(
            $this->user->id,
            'english',
            $today,
            Carbon::tomorrow(),
        );

        $log = $logs->first();
        $this->assertSame($sense->id, $log->word_sense_id);
        $this->assertSame('bank', $log->lemma);
        $this->assertSame('测试', $log->sense_zh);
    }

    /**
     * 10. reviewsForPeriod query count is constant regardless of sense count.
     */
    public function test_reviews_for_period_query_count_constant(): void
    {
        $today = Carbon::today();
        $tomorrow = Carbon::tomorrow();

        // 1 sense
        $sense1 = $this->createConfirmedSense('w1');
        $card1 = $this->createSenseCard($sense1);
        $this->createReviewLog($card1, 'good', $today->copy()->addHour());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service->reviewsForPeriod($this->user->id, 'english', $today, $tomorrow);
        $queries1 = $this->countReviewLogQueries(DB::getQueryLog());
        DB::disableQueryLog();

        // 10 senses
        for ($i = 2; $i <= 10; $i++) {
            $s = $this->createConfirmedSense('w' . $i);
            $c = $this->createSenseCard($s);
            $this->createReviewLog($c, 'good', $today->copy()->addHours($i));
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service->reviewsForPeriod($this->user->id, 'english', $today, $tomorrow);
        $queries10 = $this->countReviewLogQueries(DB::getQueryLog());
        DB::disableQueryLog();

        // 50 senses
        for ($i = 11; $i <= 50; $i++) {
            $s = $this->createConfirmedSense('w' . $i);
            $c = $this->createSenseCard($s);
            $this->createReviewLog($c, 'good', $today->copy()->addMinutes($i));
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service->reviewsForPeriod($this->user->id, 'english', $today, $tomorrow);
        $queries50 = $this->countReviewLogQueries(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries1,  "1 sense: expected 1 review_logs query, got $queries1");
        $this->assertSame(1, $queries10, "10 senses: expected 1 review_logs query, got $queries10");
        $this->assertSame(1, $queries50, "50 senses: expected 1 review_logs query, got $queries50");
    }

    // ==================== sensesReviewedBefore ====================

    /**
     * 11. sensesReviewedBefore returns sense ids reviewed before a time.
     */
    public function test_senses_reviewed_before_returns_ids(): void
    {
        $senseA = $this->createConfirmedSense('apple');
        $cardA = $this->createSenseCard($senseA);
        $senseB = $this->createConfirmedSense('banana');
        $cardB = $this->createSenseCard($senseB);
        $senseC = $this->createConfirmedSense('cherry');
        $cardC = $this->createSenseCard($senseC);

        $today = Carbon::today();
        // A and B reviewed before today; C only reviewed today.
        $this->createReviewLog($cardA, 'good', $today->copy()->subDay());
        $this->createReviewLog($cardB, 'hard', $today->copy()->subDays(2));
        $this->createReviewLog($cardC, 'good', $today->copy()->addHour());

        $ids = $this->service->sensesReviewedBefore($this->user->id, 'english', $today);

        $this->assertContains($senseA->id, $ids);
        $this->assertContains($senseB->id, $ids);
        $this->assertNotContains($senseC->id, $ids);
    }

    /**
     * 12. sensesReviewedBefore excludes reset logs.
     */
    public function test_senses_reviewed_before_excludes_reset(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $today = Carbon::today();
        // Only a reset log before today → sense should NOT appear.
        $this->createReviewLog($card, 'reset', $today->copy()->subDay(), 'reset');

        $ids = $this->service->sensesReviewedBefore($this->user->id, 'english', $today);

        $this->assertNotContains($sense->id, $ids);
    }

    /**
     * 13. sensesReviewedBefore empty when no prior reviews.
     */
    public function test_senses_reviewed_before_empty(): void
    {
        $ids = $this->service->sensesReviewedBefore($this->user->id, 'english', Carbon::today());

        $this->assertSame([], $ids);
    }

    // ==================== reviewsForCards ====================

    /**
     * 14. reviewsForCards returns non-reset logs for given cards, newest-first.
     */
    public function test_reviews_for_cards_returns_logs(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'again', Carbon::now()->subDays(2));
        $this->createReviewLog($card, 'good',  Carbon::now()->subDays(1));

        $logs = $this->service->reviewsForCards([$card->id]);

        $this->assertSame(2, $logs->count());
        $this->assertSame('good', $logs->first()->rating);
    }

    /**
     * 15. reviewsForCards excludes reset logs.
     */
    public function test_reviews_for_cards_excludes_reset(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'good',  Carbon::now()->subDays(2));
        $this->createReviewLog($card, 'reset', Carbon::now()->subDays(1), 'reset');

        $logs = $this->service->reviewsForCards([$card->id]);

        $this->assertSame(1, $logs->count());
    }

    /**
     * 16. reviewsForCards empty ids → empty collection, 0 queries.
     */
    public function test_reviews_for_cards_empty_ids(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $logs = $this->service->reviewsForCards([]);
        $queries = $this->countReviewLogQueries(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(0, $logs);
        $this->assertSame(0, $queries);
    }

    /**
     * 17. reviewsForCards query count constant (1) for 1/10/50 cards.
     */
    public function test_reviews_for_cards_query_count_constant(): void
    {
        $cards = [];
        for ($i = 0; $i < 50; $i++) {
            $sense = $this->createConfirmedSense('card' . $i);
            $card = $this->createSenseCard($sense);
            $this->createReviewLog($card, 'again', Carbon::now()->subDays(5));
            $this->createReviewLog($card, 'good',  Carbon::now()->subDays(1));
            $cards[] = $card;
        }
        $allIds = array_map(fn ($c) => $c->id, $cards);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service->reviewsForCards(array_slice($allIds, 0, 1));
        $queries1 = $this->countReviewLogQueries(DB::getQueryLog());
        DB::disableQueryLog();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service->reviewsForCards(array_slice($allIds, 0, 10));
        $queries10 = $this->countReviewLogQueries(DB::getQueryLog());
        DB::disableQueryLog();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service->reviewsForCards($allIds);
        $queries50 = $this->countReviewLogQueries(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries1,  "1 card: expected 1 review_logs query, got $queries1");
        $this->assertSame(1, $queries10, "10 cards: expected 1 review_logs query, got $queries10");
        $this->assertSame(1, $queries50, "50 cards: expected 1 review_logs query, got $queries50");
    }

    // ==================== ratingDistribution / forgetRate / stabilityRate ====================

    /**
     * 18. ratingDistribution counts again/hard/good/easy.
     */
    public function test_rating_distribution_counts(): void
    {
        $logs = collect([
            (object) ['rating' => 'again'],
            (object) ['rating' => 'again'],
            (object) ['rating' => 'hard'],
            (object) ['rating' => 'good'],
            (object) ['rating' => 'good'],
            (object) ['rating' => 'easy'],
        ]);

        $dist = $this->service->ratingDistribution($logs);

        $this->assertSame(2, $dist['again']);
        $this->assertSame(1, $dist['hard']);
        $this->assertSame(2, $dist['good']);
        $this->assertSame(1, $dist['easy']);
    }

    /**
     * 19. ratingDistribution empty → all zeros.
     */
    public function test_rating_distribution_empty(): void
    {
        $dist = $this->service->ratingDistribution(collect());

        $this->assertSame(0, $dist['again']);
        $this->assertSame(0, $dist['hard']);
        $this->assertSame(0, $dist['good']);
        $this->assertSame(0, $dist['easy']);
    }

    /**
     * 20. forgetRate = again/total, null when empty.
     */
    public function test_forget_rate_formula(): void
    {
        $logs = collect([
            (object) ['rating' => 'again'],
            (object) ['rating' => 'good'],
            (object) ['rating' => 'good'],
            (object) ['rating' => 'good'],
        ]);

        $this->assertSame(0.25, $this->service->forgetRate($logs));
    }

    /**
     * 21. forgetRate null when empty.
     */
    public function test_forget_rate_null_when_empty(): void
    {
        $this->assertNull($this->service->forgetRate(collect()));
    }

    /**
     * 22. stabilityRate = (good+easy)/total, null when empty.
     */
    public function test_stability_rate_formula(): void
    {
        $logs = collect([
            (object) ['rating' => 'again'],
            (object) ['rating' => 'hard'],
            (object) ['rating' => 'good'],
            (object) ['rating' => 'easy'],
            (object) ['rating' => 'good'],
        ]);

        // (good=2 + easy=1) / 5 = 0.6
        $this->assertSame(0.6, $this->service->stabilityRate($logs));
    }

    /**
     * 23. stabilityRate null when empty.
     */
    public function test_stability_rate_null_when_empty(): void
    {
        $this->assertNull($this->service->stabilityRate(collect()));
    }

    // ==================== reviewsBySense ====================

    /**
     * 24. reviewsBySense groups logs by sense with aggregated counts.
     */
    public function test_reviews_by_sense_aggregation(): void
    {
        // Input MUST be newest-first (contract).
        $logs = collect([
            (object) ['word_sense_id' => 1, 'lemma' => 'a', 'sense_zh' => '甲', 'rating' => 'good',  'reviewed_at' => Carbon::create(2026, 7, 2, 10)],
            (object) ['word_sense_id' => 1, 'lemma' => 'a', 'sense_zh' => '甲', 'rating' => 'again', 'reviewed_at' => Carbon::create(2026, 7, 1, 10)],
            (object) ['word_sense_id' => 2, 'lemma' => 'b', 'sense_zh' => '乙', 'rating' => 'hard',  'reviewed_at' => Carbon::create(2026, 7, 3, 10)],
        ]);

        $bySense = $this->service->reviewsBySense($logs);

        $this->assertCount(2, $bySense);
        $this->assertArrayHasKey(1, $bySense);
        $this->assertArrayHasKey(2, $bySense);

        $a = $bySense[1];
        $this->assertSame(1, $a['word_sense_id']);
        $this->assertSame('a', $a['lemma']);
        $this->assertSame('甲', $a['sense_zh']);
        $this->assertSame(2, $a['total']);
        $this->assertSame(1, $a['again']);
        $this->assertSame(0, $a['hard']);
        $this->assertSame(1, $a['good']);
        $this->assertSame(0, $a['easy']);
        // logs are newest-first; first seen = most recent = 'good'.
        $this->assertSame('good', $a['last_rating']);
        // ratings preserve the input (newest-first) order.
        $this->assertSame(['good', 'again'], $a['ratings']);
    }

    /**
     * 25. reviewsBySense empty → empty array.
     */
    public function test_reviews_by_sense_empty(): void
    {
        $this->assertSame([], $this->service->reviewsBySense(collect()));
    }

    // ==================== Read-only safety ====================

    /**
     * 26. Analytics service is READ-ONLY: does NOT create ReviewLog.
     */
    public function test_service_does_not_write_review_log(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'good', Carbon::now()->subDay());

        $before = ReviewLog::count();

        $this->service->reviewsForPeriod($this->user->id, 'english', Carbon::today(), Carbon::tomorrow());
        $this->service->reviewsForCards([$card->id]);
        $this->service->sensesReviewedBefore($this->user->id, 'english', Carbon::today());
        $this->service->ratingDistribution(collect());
        $this->service->forgetRate(collect());
        $this->service->stabilityRate(collect());
        $this->service->reviewsBySense(collect());

        $this->assertSame($before, ReviewLog::count());
    }

    // ==================== Helpers ====================

    private function countReviewLogQueries(array $queryLog): int
    {
        $count = 0;
        foreach ($queryLog as $entry) {
            $sql = $entry['query'] ?? '';
            if (preg_match('/\breview_logs\b/i', $sql)) {
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
        return $this->createConfirmedSenseForUser($this->user, $lemma, $exampleEn);
    }

    private function createConfirmedSenseForUser(User $user, string $lemma, string $exampleEn = ''): WordSense
    {
        $sense = $this->wordSenseService->createSense([
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
            'example_sentence_en' => $exampleEn,
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);
        return $sense->fresh();
    }

    private function createSenseCard(WordSense $sense, array $overrides = []): ReviewCard
    {
        return $this->createSenseCardForUser($this->user, $sense, $overrides);
    }

    private function createSenseCardForUser(User $user, WordSense $sense, array $overrides = []): ReviewCard
    {
        $data = array_merge([
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
