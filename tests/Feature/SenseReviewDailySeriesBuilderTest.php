<?php

namespace Tests\Feature;

use App\Services\SenseReviewDailySeriesBuilder;
use App\Services\SenseReviewRatingContract;
use App\Services\SenseReviewReportMetricsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SenseReviewDailySeriesBuilderTest
 *
 * Verifies the shared Product-layer helper that turns a ReviewLog
 * Collection + fixed day keys into a zero-filled daily series.
 *
 * Contract:
 *  - Pure computation: no DB, no Auth, no config.
 *  - Receives a ReviewLog Collection (already queried) + day_keys array.
 *  - Reuses SenseReviewReportMetricsService for all metric math.
 *  - Returns one entry per day key, ascending, zero-filled.
 *  - Empty days: total_reviews=0, distinct_senses=0, distribution all 0,
 *    forget_rate=null, stability_rate=null.
 *  - No Chinese product copy. No payload-shape decisions beyond the daily
 *    entry contract.
 *
 * Rule tests:
 *  1. empty logs → all zero-filled days
 *  2. single day with data
 *  3. multiple days with data
 *  4. fixed length matches day_keys count
 *  5. ascending order
 *  6. empty day rate is null (not 0)
 *  7. distribution correct
 *  8. forget rate correct
 *  9. stability rate correct
 * 10. distinct senses correct
 * 11. zero DB queries
 * 12. logs outside day_keys ignored
 * 13. input order invariance
 */
class SenseReviewDailySeriesBuilderTest extends TestCase
{
    use RefreshDatabase;

    private SenseReviewDailySeriesBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $contract = new SenseReviewRatingContract();
        $metrics = new SenseReviewReportMetricsService($contract);
        $this->builder = new SenseReviewDailySeriesBuilder($metrics);
    }

    /**
     * 1. Empty logs → all zero-filled days.
     */
    public function test_empty_logs_all_zero_filled(): void
    {
        $dayKeys = ['2026-07-04', '2026-07-05', '2026-07-06'];
        $series = $this->builder->build(collect(), $dayKeys);

        $this->assertCount(3, $series);
        foreach ($series as $entry) {
            $this->assertSame(0, $entry['total_reviews']);
            $this->assertSame(0, $entry['distinct_senses']);
            $this->assertSame(0, $entry['distribution']['again']);
            $this->assertSame(0, $entry['distribution']['hard']);
            $this->assertSame(0, $entry['distribution']['good']);
            $this->assertSame(0, $entry['distribution']['easy']);
            $this->assertNull($entry['forget_rate']);
            $this->assertNull($entry['stability_rate']);
        }
    }

    /**
     * 2. Single day with data.
     */
    public function test_single_day_with_data(): void
    {
        $dayKeys = ['2026-07-10'];
        $logs = collect([
            (object) ['rating' => 'good', 'reviewed_at' => Carbon::create(2026, 7, 10, 10), 'word_sense_id' => 1],
            (object) ['rating' => 'again', 'reviewed_at' => Carbon::create(2026, 7, 10, 11), 'word_sense_id' => 1],
        ]);

        $series = $this->builder->build($logs, $dayKeys);

        $this->assertCount(1, $series);
        $this->assertSame('2026-07-10', $series[0]['day']);
        $this->assertSame(2, $series[0]['total_reviews']);
        $this->assertSame(1, $series[0]['distinct_senses']);
        $this->assertSame(1, $series[0]['distribution']['again']);
        $this->assertSame(1, $series[0]['distribution']['good']);
        $this->assertSame(0.5, $series[0]['forget_rate']);
    }

    /**
     * 3. Multiple days with data.
     */
    public function test_multiple_days_with_data(): void
    {
        $dayKeys = ['2026-07-08', '2026-07-09', '2026-07-10'];
        $logs = collect([
            (object) ['rating' => 'good', 'reviewed_at' => Carbon::create(2026, 7, 8, 10), 'word_sense_id' => 1],
            (object) ['rating' => 'hard', 'reviewed_at' => Carbon::create(2026, 7, 10, 14), 'word_sense_id' => 2],
            (object) ['rating' => 'easy', 'reviewed_at' => Carbon::create(2026, 7, 10, 15), 'word_sense_id' => 2],
        ]);

        $series = $this->builder->build($logs, $dayKeys);

        $this->assertCount(3, $series);
        $this->assertSame(1, $series[0]['total_reviews']);  // 07-08
        $this->assertSame(0, $series[1]['total_reviews']);  // 07-09 empty
        $this->assertSame(2, $series[2]['total_reviews']);  // 07-10
    }

    /**
     * 4. Fixed length matches day_keys count.
     */
    public function test_fixed_length_matches_day_keys(): void
    {
        for ($n = 1; $n <= 7; $n++) {
            $dayKeys = [];
            for ($i = 0; $i < $n; $i++) {
                $dayKeys[] = '2026-07-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            }
            $series = $this->builder->build(collect(), $dayKeys);
            $this->assertCount($n, $series, "day_keys={$n} must produce {$n} entries");
        }
    }

    /**
     * 5. Ascending order.
     */
    public function test_ascending_order(): void
    {
        $dayKeys = ['2026-07-04', '2026-07-05', '2026-07-06', '2026-07-07'];
        $series = $this->builder->build(collect(), $dayKeys);

        $this->assertSame('2026-07-04', $series[0]['day']);
        $this->assertSame('2026-07-05', $series[1]['day']);
        $this->assertSame('2026-07-06', $series[2]['day']);
        $this->assertSame('2026-07-07', $series[3]['day']);
    }

    /**
     * 6. Empty day rate is null (not 0).
     */
    public function test_empty_day_rate_is_null(): void
    {
        $dayKeys = ['2026-07-04', '2026-07-05'];
        $logs = collect([
            (object) ['rating' => 'good', 'reviewed_at' => Carbon::create(2026, 7, 5, 10), 'word_sense_id' => 1],
        ]);

        $series = $this->builder->build($logs, $dayKeys);

        // 07-04 empty → null rates
        $this->assertNull($series[0]['forget_rate']);
        $this->assertNull($series[0]['stability_rate']);
        // 07-05 has data → non-null rate
        $this->assertNotNull($series[1]['forget_rate']);
        $this->assertNotNull($series[1]['stability_rate']);
    }

    /**
     * 7. Distribution correct.
     */
    public function test_distribution_correct(): void
    {
        $dayKeys = ['2026-07-10'];
        $logs = collect([
            (object) ['rating' => 'again', 'reviewed_at' => Carbon::create(2026, 7, 10, 10), 'word_sense_id' => 1],
            (object) ['rating' => 'again', 'reviewed_at' => Carbon::create(2026, 7, 10, 11), 'word_sense_id' => 1],
            (object) ['rating' => 'hard', 'reviewed_at' => Carbon::create(2026, 7, 10, 12), 'word_sense_id' => 1],
            (object) ['rating' => 'good', 'reviewed_at' => Carbon::create(2026, 7, 10, 13), 'word_sense_id' => 1],
            (object) ['rating' => 'easy', 'reviewed_at' => Carbon::create(2026, 7, 10, 14), 'word_sense_id' => 1],
        ]);

        $series = $this->builder->build($logs, $dayKeys);

        $this->assertSame(2, $series[0]['distribution']['again']);
        $this->assertSame(1, $series[0]['distribution']['hard']);
        $this->assertSame(1, $series[0]['distribution']['good']);
        $this->assertSame(1, $series[0]['distribution']['easy']);
    }

    /**
     * 8. Forget rate correct.
     */
    public function test_forget_rate_correct(): void
    {
        $dayKeys = ['2026-07-10'];
        $logs = collect([
            (object) ['rating' => 'again', 'reviewed_at' => Carbon::create(2026, 7, 10, 10), 'word_sense_id' => 1],
            (object) ['rating' => 'good', 'reviewed_at' => Carbon::create(2026, 7, 10, 11), 'word_sense_id' => 1],
            (object) ['rating' => 'good', 'reviewed_at' => Carbon::create(2026, 7, 10, 12), 'word_sense_id' => 1],
            (object) ['rating' => 'good', 'reviewed_at' => Carbon::create(2026, 7, 10, 13), 'word_sense_id' => 1],
        ]);

        $series = $this->builder->build($logs, $dayKeys);

        $this->assertSame(0.25, $series[0]['forget_rate']);
    }

    /**
     * 9. Stability rate correct.
     */
    public function test_stability_rate_correct(): void
    {
        $dayKeys = ['2026-07-10'];
        $logs = collect([
            (object) ['rating' => 'again', 'reviewed_at' => Carbon::create(2026, 7, 10, 10), 'word_sense_id' => 1],
            (object) ['rating' => 'hard', 'reviewed_at' => Carbon::create(2026, 7, 10, 11), 'word_sense_id' => 1],
            (object) ['rating' => 'good', 'reviewed_at' => Carbon::create(2026, 7, 10, 12), 'word_sense_id' => 1],
            (object) ['rating' => 'easy', 'reviewed_at' => Carbon::create(2026, 7, 10, 13), 'word_sense_id' => 1],
            (object) ['rating' => 'good', 'reviewed_at' => Carbon::create(2026, 7, 10, 14), 'word_sense_id' => 1],
        ]);

        $series = $this->builder->build($logs, $dayKeys);

        // (good=2 + easy=1) / 5 = 0.6
        $this->assertSame(0.6, $series[0]['stability_rate']);
    }

    /**
     * 10. Distinct senses correct.
     */
    public function test_distinct_senses_correct(): void
    {
        $dayKeys = ['2026-07-10'];
        $logs = collect([
            (object) ['rating' => 'good', 'reviewed_at' => Carbon::create(2026, 7, 10, 10), 'word_sense_id' => 1],
            (object) ['rating' => 'good', 'reviewed_at' => Carbon::create(2026, 7, 10, 11), 'word_sense_id' => 1],
            (object) ['rating' => 'good', 'reviewed_at' => Carbon::create(2026, 7, 10, 12), 'word_sense_id' => 2],
            (object) ['rating' => 'good', 'reviewed_at' => Carbon::create(2026, 7, 10, 13), 'word_sense_id' => 3],
        ]);

        $series = $this->builder->build($logs, $dayKeys);

        $this->assertSame(3, $series[0]['distinct_senses']);
    }

    /**
     * 11. Zero DB queries.
     */
    public function test_daily_series_zero_db_queries(): void
    {
        $dayKeys = ['2026-07-10'];
        $logs = collect([
            (object) ['rating' => 'good', 'reviewed_at' => Carbon::create(2026, 7, 10, 10), 'word_sense_id' => 1],
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->builder->build($logs, $dayKeys);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(0, count($queries), 'DailySeriesBuilder must issue zero DB queries');
    }

    /**
     * 12. Logs outside day_keys ignored.
     */
    public function test_logs_outside_day_keys_ignored(): void
    {
        $dayKeys = ['2026-07-10'];
        $logs = collect([
            (object) ['rating' => 'good', 'reviewed_at' => Carbon::create(2026, 7, 9, 10), 'word_sense_id' => 1],
            (object) ['rating' => 'good', 'reviewed_at' => Carbon::create(2026, 7, 11, 10), 'word_sense_id' => 1],
            (object) ['rating' => 'good', 'reviewed_at' => Carbon::create(2026, 7, 10, 10), 'word_sense_id' => 1],
        ]);

        $series = $this->builder->build($logs, $dayKeys);

        $this->assertCount(1, $series);
        $this->assertSame(1, $series[0]['total_reviews']);
    }

    /**
     * 13. Input order invariance.
     */
    public function test_input_order_invariance(): void
    {
        $dayKeys = ['2026-07-08', '2026-07-09', '2026-07-10'];
        $logsA = collect([
            (object) ['rating' => 'good', 'reviewed_at' => Carbon::create(2026, 7, 8, 10), 'word_sense_id' => 1],
            (object) ['rating' => 'again', 'reviewed_at' => Carbon::create(2026, 7, 10, 10), 'word_sense_id' => 2],
        ]);
        $logsB = collect([
            (object) ['rating' => 'again', 'reviewed_at' => Carbon::create(2026, 7, 10, 10), 'word_sense_id' => 2],
            (object) ['rating' => 'good', 'reviewed_at' => Carbon::create(2026, 7, 8, 10), 'word_sense_id' => 1],
        ]);

        $seriesA = $this->builder->build($logsA, $dayKeys);
        $seriesB = $this->builder->build($logsB, $dayKeys);

        $this->assertSame($seriesA, $seriesB);
    }
}
