<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\SenseReviewDailyInsightBuilder;
use App\Services\WordSenseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewDailyInsightBuilderTest
 *
 * SenseReview-DailyInsightBuilder-1000-3
 *
 * Verifies the pure-computation insight layer for the consolidated SenseReview
 * daily report. This builder is the single source of truth for focus_senses,
 * progress_senses, and recent_reviews — unifying the algorithms that
 * previously lived in both TodaySummary and DailyReport.
 *
 * Contract:
 *  - PURE COMPUTATION: zero DB queries (verified by test).
 *  - Never depends on Eloquent / DB / Auth / Request / config / .env /
 *    SenseReviewAnalyticsQueryService.
 *  - Input: a Collection of log rows (newest-first) supplied by the caller.
 *  - focus_senses: filter (again / hard / multi-rating / last-again-or-hard),
 *    sort (again desc, hard desc, total desc), max 10, includes
 *    last_reviewed_at (the superset shape).
 *  - progress_senses: temporal again→good or hard→easy, one entry per sense.
 *  - recent_reviews: max 10, newest first, rating_label from contract,
 *    hard label is "勉强记得".
 *  - Empty collection → stable empty arrays.
 *  - Input order does not change final semantic result.
 */
class SenseReviewDailyInsightBuilderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WordSenseService $wordSenseService;
    private SenseReviewDailyInsightBuilder $builder;

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

        $this->user = $this->createUser('insight-builder@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
        $this->builder = app(SenseReviewDailyInsightBuilder::class);
    }

    /**
     * Helper: build a raw log row (stdClass) matching what
     * SenseReviewAnalyticsQueryService::reviewsForPeriod() returns.
     */
    private function makeLog(
        int $wordSenseId,
        string $lemma,
        string $senseZh,
        string $rating,
        Carbon $reviewedAt,
        int $id = 0,
        int $reviewCardId = 1
    ): object {
        $row = new \stdClass();
        $row->id = $id;
        $row->review_card_id = $reviewCardId;
        $row->rating = $rating;
        $row->reviewed_at = $reviewedAt;
        $row->word_sense_id = $wordSenseId;
        $row->lemma = $lemma;
        $row->sense_zh = $senseZh;
        return $row;
    }

    /**
     * 1. Pure computation: zero DB queries.
     */
    public function test_zero_db_queries(): void
    {
        $logs = collect([
            $this->makeLog(1, 'bank', '银行', 'good', Carbon::now()),
        ]);

        $queryCount = 0;
        \DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $this->builder->build($logs);

        $this->assertSame(0, $queryCount, 'DailyInsightBuilder must issue 0 DB queries');
    }

    /**
     * 2. Empty collection → stable empty arrays.
     */
    public function test_empty_collection_returns_stable_structure(): void
    {
        $result = $this->builder->build(collect());

        $this->assertSame([], $result['focus_senses']);
        $this->assertSame([], $result['progress_senses']);
        $this->assertSame([], $result['recent_reviews']);
    }

    /**
     * 3. focus_senses: filter rules — again, hard, multi-rating, last-again-or-hard.
     */
    public function test_focus_senses_filter_rules(): void
    {
        $now = Carbon::now();
        // Sense A: has 'again' → focus
        // Sense B: has 'hard' only → focus
        // Sense C: rated twice, both 'good' → focus (multi-rating)
        // Sense D: one 'good' only → NOT focus
        // Sense E: last rating 'hard' → focus
        $logs = collect([
            $this->makeLog(1, 'apple', '苹果', 'again', $now, 1),
            $this->makeLog(2, 'banana', '香蕉', 'hard', $now, 2),
            $this->makeLog(3, 'cherry', '樱桃', 'good', $now->copy()->subMinute(), 3),
            $this->makeLog(3, 'cherry', '樱桃', 'good', $now, 4), // C multi
            $this->makeLog(4, 'date', '枣', 'good', $now, 5),
            $this->makeLog(5, 'egg', '蛋', 'good', $now->copy()->subMinute(), 6),
            $this->makeLog(5, 'egg', '蛋', 'hard', $now, 7), // E last=hard
        ]);

        $result = $this->builder->build($logs);

        $focusIds = array_column($result['focus_senses'], 'word_sense_id');
        $this->assertContains(1, $focusIds); // A
        $this->assertContains(2, $focusIds); // B
        $this->assertContains(3, $focusIds); // C
        $this->assertContains(5, $focusIds); // E
        $this->assertNotContains(4, $focusIds); // D excluded
    }

    /**
     * 4. focus_senses: sort by again desc, hard desc, total desc.
     */
    public function test_focus_senses_sorting(): void
    {
        $now = Carbon::now();
        // A: 2 again, 1 good
        // B: 1 again, 2 hard
        // C: 0 again, 3 hard
        $logs = collect([
            $this->makeLog(1, 'apple', '苹果', 'again', $now->copy()->subMinutes(3), 1),
            $this->makeLog(1, 'apple', '苹果', 'again', $now->copy()->subMinutes(2), 2),
            $this->makeLog(1, 'apple', '苹果', 'good',  $now, 3),
            $this->makeLog(2, 'banana', '香蕉', 'again', $now->copy()->subMinutes(3), 4),
            $this->makeLog(2, 'banana', '香蕉', 'hard',  $now->copy()->subMinutes(2), 5),
            $this->makeLog(2, 'banana', '香蕉', 'hard',  $now, 6),
            $this->makeLog(3, 'cherry', '樱桃', 'hard', $now->copy()->subMinutes(3), 7),
            $this->makeLog(3, 'cherry', '樱桃', 'hard', $now->copy()->subMinutes(2), 8),
            $this->makeLog(3, 'cherry', '樱桃', 'hard', $now, 9),
        ]);

        $result = $this->builder->build($logs);

        $this->assertCount(3, $result['focus_senses']);
        $this->assertSame(1, $result['focus_senses'][0]['word_sense_id']); // 2 again → first
        $this->assertSame(2, $result['focus_senses'][1]['word_sense_id']); // 1 again, 2 hard → second
        $this->assertSame(3, $result['focus_senses'][2]['word_sense_id']); // 0 again, 3 hard → third
    }

    /**
     * 5. focus_senses: max 10 items.
     */
    public function test_focus_senses_max_10(): void
    {
        $now = Carbon::now();
        $logs = [];
        for ($i = 1; $i <= 15; $i++) {
            $logs[] = $this->makeLog($i, 'word' . $i, '义', 'again', $now, $i);
        }

        $result = $this->builder->build(collect($logs));

        $this->assertCount(10, $result['focus_senses']);
    }

    /**
     * 6. focus_senses: includes last_reviewed_at (superset shape).
     */
    public function test_focus_senses_includes_last_reviewed_at(): void
    {
        $now = Carbon::now();
        $logs = collect([
            $this->makeLog(1, 'bank', '银行', 'again', $now, 1),
        ]);

        $result = $this->builder->build($logs);

        $this->assertCount(1, $result['focus_senses']);
        $this->assertArrayHasKey('last_reviewed_at', $result['focus_senses'][0]);
        $this->assertNotNull($result['focus_senses'][0]['last_reviewed_at']);
    }

    /**
     * 7. focus_senses: item shape has all required fields.
     */
    public function test_focus_senses_item_shape(): void
    {
        $now = Carbon::now();
        $logs = collect([
            $this->makeLog(1, 'bank', '银行', 'again', $now, 1),
            $this->makeLog(1, 'bank', '银行', 'good',  $now->copy()->subMinute(), 2),
        ]);

        $result = $this->builder->build($logs);

        $item = $result['focus_senses'][0];
        $this->assertSame(1, $item['word_sense_id']);
        $this->assertSame('bank', $item['lemma']);
        $this->assertSame('银行', $item['sense_zh']);
        $this->assertSame(2, $item['total']);
        $this->assertSame(1, $item['again']);
        $this->assertSame(0, $item['hard']);
        $this->assertSame('again', $item['last_rating']); // newest-first → first seen is 'again'
        $this->assertNotNull($item['last_reviewed_at']);
    }

    /**
     * 8. progress_senses: again → good transition detected.
     */
    public function test_progress_senses_again_to_good(): void
    {
        $now = Carbon::now();
        // old→new: again then good
        $logs = collect([
            $this->makeLog(1, 'bank', '银行', 'good',  $now, 1),             // newest
            $this->makeLog(1, 'bank', '银行', 'again', $now->copy()->subMinute(), 2), // older
        ]);

        $result = $this->builder->build($logs);

        $this->assertCount(1, $result['progress_senses']);
        $item = $result['progress_senses'][0];
        $this->assertSame(1, $item['word_sense_id']);
        $this->assertSame('again', $item['from_rating']);
        $this->assertSame('good', $item['to_rating']);
    }

    /**
     * 9. progress_senses: hard → easy transition detected.
     */
    public function test_progress_senses_hard_to_easy(): void
    {
        $now = Carbon::now();
        $logs = collect([
            $this->makeLog(1, 'bank', '银行', 'easy', $now, 1),             // newest
            $this->makeLog(1, 'bank', '银行', 'hard', $now->copy()->subMinute(), 2), // older
        ]);

        $result = $this->builder->build($logs);

        $this->assertCount(1, $result['progress_senses']);
        $this->assertSame('hard', $result['progress_senses'][0]['from_rating']);
        $this->assertSame('easy', $result['progress_senses'][0]['to_rating']);
    }

    /**
     * 10. progress_senses: no transition → excluded.
     */
    public function test_progress_senses_no_transition_excluded(): void
    {
        $now = Carbon::now();
        // good then good → no progress
        $logs = collect([
            $this->makeLog(1, 'bank', '银行', 'good', $now, 1),
            $this->makeLog(1, 'bank', '银行', 'good', $now->copy()->subMinute(), 2),
        ]);

        $result = $this->builder->build($logs);

        $this->assertSame([], $result['progress_senses']);
    }

    /**
     * 11. progress_senses: again → hard is NOT progress (hard is not good/easy).
     */
    public function test_progress_senses_again_to_hard_not_progress(): void
    {
        $now = Carbon::now();
        $logs = collect([
            $this->makeLog(1, 'bank', '银行', 'hard',  $now, 1),
            $this->makeLog(1, 'bank', '银行', 'again', $now->copy()->subMinute(), 2),
        ]);

        $result = $this->builder->build($logs);

        $this->assertSame([], $result['progress_senses']);
    }

    /**
     * 12. progress_senses: same sense with again→good AND hard→easy → one entry
     *     (first qualifying transition), no duplicates.
     */
    public function test_progress_senses_no_duplicates(): void
    {
        $now = Carbon::now();
        // old→new: again, good, hard, easy
        $logs = collect([
            $this->makeLog(1, 'bank', '银行', 'easy',  $now, 1),                       // newest
            $this->makeLog(1, 'bank', '银行', 'hard',  $now->copy()->subMinute(), 2),
            $this->makeLog(1, 'bank', '银行', 'good',  $now->copy()->subMinutes(2), 3),
            $this->makeLog(1, 'bank', '银行', 'again', $now->copy()->subMinutes(3), 4), // oldest
        ]);

        $result = $this->builder->build($logs);

        $this->assertCount(1, $result['progress_senses']);
    }

    /**
     * 13. recent_reviews: newest first.
     */
    public function test_recent_reviews_newest_first(): void
    {
        $now = Carbon::now();
        $logs = collect([
            $this->makeLog(1, 'bank', '银行', 'good',  $now, 1),                       // newest
            $this->makeLog(1, 'bank', '银行', 'hard',  $now->copy()->subMinute(), 2),
            $this->makeLog(1, 'bank', '银行', 'again', $now->copy()->subMinutes(2), 3), // oldest
        ]);

        $result = $this->builder->build($logs);

        $this->assertCount(3, $result['recent_reviews']);
        $this->assertSame('good',  $result['recent_reviews'][0]['rating']);
        $this->assertSame('hard',  $result['recent_reviews'][1]['rating']);
        $this->assertSame('again', $result['recent_reviews'][2]['rating']);
    }

    /**
     * 14. recent_reviews: max 10.
     */
    public function test_recent_reviews_max_10(): void
    {
        $now = Carbon::now();
        $logs = [];
        for ($i = 0; $i < 15; $i++) {
            $logs[] = $this->makeLog($i + 1, 'word' . $i, '义', 'good', $now->copy()->subMinutes(15 - $i), $i + 1);
        }

        $result = $this->builder->build(collect($logs));

        $this->assertCount(10, $result['recent_reviews']);
    }

    /**
     * 15. recent_reviews: rating_label from contract (hard → "勉强记得").
     */
    public function test_recent_reviews_rating_label_hard_is_勉强记得(): void
    {
        $now = Carbon::now();
        $logs = collect([
            $this->makeLog(1, 'bank', '银行', 'hard', $now, 1),
        ]);

        $result = $this->builder->build($logs);

        $this->assertSame('hard', $result['recent_reviews'][0]['rating']);
        $this->assertSame('勉强记得', $result['recent_reviews'][0]['rating_label']);
    }

    /**
     * 16. recent_reviews: all four rating labels correct.
     */
    public function test_recent_reviews_all_rating_labels(): void
    {
        $now = Carbon::now();
        $logs = collect([
            $this->makeLog(1, 'a', '义', 'easy', $now, 1),
            $this->makeLog(2, 'b', '义', 'good', $now->copy()->subMinute(), 2),
            $this->makeLog(3, 'c', '义', 'hard', $now->copy()->subMinutes(2), 3),
            $this->makeLog(4, 'd', '义', 'again', $now->copy()->subMinutes(3), 4),
        ]);

        $result = $this->builder->build($logs);

        $this->assertSame('很熟', $result['recent_reviews'][0]['rating_label']);
        $this->assertSame('记得', $result['recent_reviews'][1]['rating_label']);
        $this->assertSame('勉强记得', $result['recent_reviews'][2]['rating_label']);
        $this->assertSame('忘了', $result['recent_reviews'][3]['rating_label']);
    }

    /**
     * 17. Input order does not change final semantic result.
     *
     * Same logs in different order (but still newest-first within same sense)
     * should produce the same focus/progress/recent semantic result.
     */
    public function test_input_order_does_not_change_semantics(): void
    {
        $now = Carbon::now();
        // Both collections are newest-first (reviewed_at DESC) — the builder's
        // stated contract. We only reorder across senses, not within a sense.
        $logsA = collect([
            $this->makeLog(2, 'banana', '香蕉', 'hard', $now, 3),
            $this->makeLog(1, 'apple', '苹果', 'good',  $now, 2),
            $this->makeLog(1, 'apple', '苹果', 'again', $now->copy()->subMinute(), 1),
        ]);
        // Same logs, different cross-sense order (still newest-first within
        // each sense).
        $logsB = collect([
            $this->makeLog(1, 'apple', '苹果', 'good',  $now, 2),
            $this->makeLog(2, 'banana', '香蕉', 'hard', $now, 3),
            $this->makeLog(1, 'apple', '苹果', 'again', $now->copy()->subMinute(), 1),
        ]);

        $resultA = $this->builder->build($logsA);
        $resultB = $this->builder->build($logsB);

        // focus_senses: same senses included (order may differ because both
        // have 1 again, but the set of word_sense_ids must match)
        $idsA = array_column($resultA['focus_senses'], 'word_sense_id');
        $idsB = array_column($resultB['focus_senses'], 'word_sense_id');
        sort($idsA); sort($idsB);
        $this->assertSame($idsA, $idsB);

        // progress_senses: same count (apple has again→good)
        $this->assertCount(count($resultA['progress_senses']), $resultB['progress_senses']);

        // recent_reviews: same set of ratings (order within same timestamp
        // depends on collection order, which is allowed to differ).
        $ratingsA = array_column($resultA['recent_reviews'], 'rating');
        $ratingsB = array_column($resultB['recent_reviews'], 'rating');
        sort($ratingsA); sort($ratingsB);
        $this->assertSame($ratingsA, $ratingsB);
    }

    // ==================== Navigation ID tests (ADR-0007) ====================

    /**
     * N1. recent_reviews: each item contains review_card_id and word_sense_id.
     */
    public function test_recent_reviews_contains_navigation_ids(): void
    {
        $now = Carbon::now();
        $logs = collect([
            $this->makeLog(10, 'bank', '银行', 'good', $now, 1, 100),
            $this->makeLog(20, 'river', '河', 'hard', $now->copy()->subMinute(), 2, 200),
        ]);

        $result = $this->builder->build($logs);

        $this->assertCount(2, $result['recent_reviews']);
        $this->assertSame(100, $result['recent_reviews'][0]['review_card_id']);
        $this->assertSame(10, $result['recent_reviews'][0]['word_sense_id']);
        $this->assertSame(200, $result['recent_reviews'][1]['review_card_id']);
        $this->assertSame(20, $result['recent_reviews'][1]['word_sense_id']);
    }

    /**
     * N2. focus_senses: each item contains review_card_id and word_sense_id.
     */
    public function test_focus_senses_contains_navigation_ids(): void
    {
        $now = Carbon::now();
        $logs = collect([
            $this->makeLog(10, 'bank', '银行', 'again', $now, 1, 100),
            $this->makeLog(20, 'river', '河', 'hard', $now->copy()->subMinute(), 2, 200),
        ]);

        $result = $this->builder->build($logs);

        $this->assertCount(2, $result['focus_senses']);
        $ids = [];
        foreach ($result['focus_senses'] as $item) {
            $ids[$item['word_sense_id']] = $item['review_card_id'];
        }
        $this->assertSame(100, $ids[10]);
        $this->assertSame(200, $ids[20]);
    }

    /**
     * N3. progress_senses: each item contains review_card_id and word_sense_id.
     */
    public function test_progress_senses_contains_navigation_ids(): void
    {
        $now = Carbon::now();
        $logs = collect([
            $this->makeLog(10, 'bank', '银行', 'good',  $now, 1, 100),
            $this->makeLog(10, 'bank', '银行', 'again', $now->copy()->subMinute(), 2, 100),
        ]);

        $result = $this->builder->build($logs);

        $this->assertCount(1, $result['progress_senses']);
        $this->assertSame(100, $result['progress_senses'][0]['review_card_id']);
        $this->assertSame(10, $result['progress_senses'][0]['word_sense_id']);
    }

    /**
     * N4. recent_reviews: each item uses the accurate review_card_id from
     *     its own ReviewLog row (not a shared map).
     */
    public function test_recent_reviews_uses_correct_review_card_id_per_log(): void
    {
        $now = Carbon::now();
        // Same sense (word_sense_id=10) but two different review_card_ids in
        // different logs — each recent_review entry must carry its own log's
        // review_card_id. (In production the unique constraint means same
        // sense = same card, but the Builder must not assume this — it reads
        // the value from each log row.)
        $logs = collect([
            $this->makeLog(10, 'bank', '银行', 'good', $now, 1, 100),
            $this->makeLog(10, 'bank', '银行', 'hard', $now->copy()->subMinute(), 2, 200),
        ]);

        $result = $this->builder->build($logs);

        $this->assertCount(2, $result['recent_reviews']);
        $this->assertSame(100, $result['recent_reviews'][0]['review_card_id']);
        $this->assertSame(200, $result['recent_reviews'][1]['review_card_id']);
    }

    /**
     * N5. focus_senses: uses the latest (newest) review_card_id for the sense.
     *
     * Logs are newest-first; the first log seen for a sense is the newest.
     * The focus item should carry that newest log's review_card_id.
     */
    public function test_focus_senses_uses_latest_review_card_id(): void
    {
        $now = Carbon::now();
        // Sense 10 has two logs with different card IDs (for testing only;
        // production unique constraint guarantees same card, but the Builder
        // reads from the log row directly).
        $logs = collect([
            $this->makeLog(10, 'bank', '银行', 'again', $now, 1, 100),              // newest
            $this->makeLog(10, 'bank', '银行', 'good',  $now->copy()->subMinute(), 2, 200), // older
        ]);

        $result = $this->builder->build($logs);

        $this->assertCount(1, $result['focus_senses']);
        $this->assertSame(100, $result['focus_senses'][0]['review_card_id'],
            'focus_senses must use the newest log\'s review_card_id');
    }

    /**
     * N6. No valid review_card_id (0 or negative) → do not fabricate.
     *
     * If a log row somehow has review_card_id <= 0, the Builder must not
     * invent a fake ID. The field is still present but set to null so the
     * frontend can disable the click action.
     */
    public function test_no_valid_review_card_id_does_not_fabricate(): void
    {
        $now = Carbon::now();
        $row = new \stdClass();
        $row->id = 1;
        $row->review_card_id = 0; // invalid
        $row->rating = 'again';
        $row->reviewed_at = $now;
        $row->word_sense_id = 10;
        $row->lemma = 'bank';
        $row->sense_zh = '银行';

        $result = $this->builder->build(collect([$row]));

        // recent_reviews: review_card_id should be null (not fabricated)
        $this->assertNull($result['recent_reviews'][0]['review_card_id']);
        // focus_senses: review_card_id should be null (not fabricated)
        $this->assertNull($result['focus_senses'][0]['review_card_id']);
    }

    /**
     * N7. Navigation IDs do not increase DB query count (still 0).
     */
    public function test_navigation_ids_do_not_increase_db_queries(): void
    {
        $logs = collect([
            $this->makeLog(10, 'bank', '银行', 'again', Carbon::now(), 1, 100),
            $this->makeLog(20, 'river', '河', 'hard', Carbon::now()->copy()->subMinute(), 2, 200),
        ]);

        $queryCount = 0;
        \DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $result = $this->builder->build($logs);

        $this->assertSame(0, $queryCount, 'Navigation IDs must not add DB queries');
        // Verify IDs are actually present (not just that queries stayed 0)
        $this->assertSame(100, $result['recent_reviews'][0]['review_card_id']);
        $this->assertSame(100, $result['focus_senses'][0]['review_card_id']);
    }

    // ==================== Helpers ====================

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
