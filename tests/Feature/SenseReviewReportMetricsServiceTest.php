<?php

namespace Tests\Feature;

use App\Services\SenseReviewRatingContract;
use App\Services\SenseReviewReportMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;
use Tests\TestCase;

/**
 * SenseReviewReportMetricsServiceTest
 *
 * SenseReview-ReportMetrics-1000-1
 *
 * Pure-computation test suite for the SenseReview report metrics layer.
 * The Metrics Service MUST be a stateless pure-computation layer: no DB,
 * no Eloquent, no Auth, no config. All inputs are in-memory Collections
 * of log rows (stdClass objects with rating / reviewed_at / word_sense_id
 * / lemma / sense_zh properties, mirroring the rows produced by
 * SenseReviewAnalyticsQueryService).
 *
 * These tests also assert formula compatibility with the current
 * TodaySummary / DailyReport / LearningFeedback aggregates so the
 * migration to the centralized Metrics layer does not change any
 * numeric output.
 */
class SenseReviewReportMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    private SenseReviewReportMetricsService $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = new SenseReviewReportMetricsService(
            new SenseReviewRatingContract()
        );
    }

    /**
     * Build a fake log row matching the shape produced by
     * SenseReviewAnalyticsQueryService::reviewsForPeriod().
     */
    private function log(
        string $rating,
        string $reviewedAt,
        int $wordSenseId = 1,
        string $lemma = 'word',
        string $senseZh = '释义'
    ): stdClass {
        $row = new stdClass();
        $row->id = rand(1, PHP_INT_MAX);
        $row->review_card_id = 100 + $wordSenseId;
        $row->rating = $rating;
        $row->reviewed_at = \Carbon\Carbon::parse($reviewedAt);
        $row->word_sense_id = $wordSenseId;
        $row->lemma = $lemma;
        $row->sense_zh = $senseZh;
        return $row;
    }

    public function test_empty_collection_distribution(): void
    {
        $dist = $this->metrics->ratingDistribution(collect());
        $this->assertSame(
            ['again' => 0, 'hard' => 0, 'good' => 0, 'easy' => 0],
            $dist
        );
    }

    public function test_rating_distribution(): void
    {
        $logs = collect([
            $this->log('again', '2026-07-10 10:00:00'),
            $this->log('hard', '2026-07-10 10:01:00'),
            $this->log('good', '2026-07-10 10:02:00'),
            $this->log('easy', '2026-07-10 10:03:00'),
            $this->log('again', '2026-07-10 10:04:00'),
            $this->log('good', '2026-07-10 10:05:00'),
        ]);

        $dist = $this->metrics->ratingDistribution($logs);

        $this->assertSame(2, $dist['again']);
        $this->assertSame(1, $dist['hard']);
        $this->assertSame(2, $dist['good']);
        $this->assertSame(1, $dist['easy']);
    }

    public function test_forget_rate_null_when_empty(): void
    {
        $this->assertNull($this->metrics->forgetRate(collect()));
    }

    public function test_forget_rate_calculation(): void
    {
        // again=2, total=5 → 0.4
        $logs = collect([
            $this->log('again', '2026-07-10 10:00:00'),
            $this->log('good', '2026-07-10 10:01:00'),
            $this->log('again', '2026-07-10 10:02:00'),
            $this->log('hard', '2026-07-10 10:03:00'),
            $this->log('easy', '2026-07-10 10:04:00'),
        ]);

        $this->assertSame(0.4, $this->metrics->forgetRate($logs));
    }

    public function test_stability_rate_null_when_empty(): void
    {
        $this->assertNull($this->metrics->stabilityRate(collect()));
    }

    public function test_stability_rate_calculation(): void
    {
        // (good + easy) / total = (2 + 1) / 5 = 0.6
        $logs = collect([
            $this->log('again', '2026-07-10 10:00:00'),
            $this->log('good', '2026-07-10 10:01:00'),
            $this->log('easy', '2026-07-10 10:02:00'),
            $this->log('hard', '2026-07-10 10:03:00'),
            $this->log('good', '2026-07-10 10:04:00'),
        ]);

        $this->assertSame(0.6, $this->metrics->stabilityRate($logs));
    }

    public function test_average_rating_null_when_empty(): void
    {
        $this->assertNull($this->metrics->averageRating(collect()));
    }

    public function test_average_rating_calculation(): void
    {
        // (1 + 2 + 3 + 4) / 4 = 2.5
        $logs = collect([
            $this->log('again', '2026-07-10 10:00:00'),
            $this->log('hard', '2026-07-10 10:01:00'),
            $this->log('good', '2026-07-10 10:02:00'),
            $this->log('easy', '2026-07-10 10:03:00'),
        ]);

        $this->assertSame(2.5, $this->metrics->averageRating($logs));
    }

    public function test_distinct_sense_count(): void
    {
        $logs = collect([
            $this->log('again', '2026-07-10 10:00:00', 1),
            $this->log('good', '2026-07-10 10:01:00', 1),
            $this->log('hard', '2026-07-10 10:02:00', 2),
            $this->log('easy', '2026-07-10 10:03:00', 3),
            $this->log('good', '2026-07-10 10:04:00', 3),
        ]);

        $this->assertSame(3, $this->metrics->distinctSenseCount($logs));
    }

    public function test_group_by_day(): void
    {
        $logs = collect([
            $this->log('again', '2026-07-08 10:00:00'),
            $this->log('good', '2026-07-09 11:00:00'),
            $this->log('hard', '2026-07-09 12:00:00'),
            $this->log('easy', '2026-07-10 09:00:00'),
        ]);

        $grouped = $this->metrics->groupByDay($logs);

        // Only days with data appear — Metrics does NOT zero-fill.
        $this->assertCount(3, $grouped);
        $this->assertArrayHasKey('2026-07-08', $grouped);
        $this->assertArrayHasKey('2026-07-09', $grouped);
        $this->assertArrayHasKey('2026-07-10', $grouped);
        $this->assertArrayNotHasKey('2026-07-07', $grouped);

        $this->assertSame(1, $grouped['2026-07-08']->count());
        $this->assertSame(2, $grouped['2026-07-09']->count());
        $this->assertSame(1, $grouped['2026-07-10']->count());
    }

    public function test_zero_data_day_fill_is_not_metrics_responsibility(): void
    {
        // Metrics groupByDay returns ONLY days that have logs. The fixed
        // 7-day window zero-fill is the Product Service's job.
        $logs = collect([
            $this->log('good', '2026-07-10 11:00:00'),
        ]);

        $grouped = $this->metrics->groupByDay($logs);

        $this->assertCount(1, $grouped);
        $this->assertSame(['2026-07-10'], array_keys($grouped));
    }

    public function test_invalid_rating_ignored_in_distribution(): void
    {
        // Invalid ratings must NOT be silently treated as 'good'. They are
        // ignored by the distribution (counted as 0 across all buckets).
        $row = $this->log('bogus', '2026-07-10 10:00:00');
        $logs = collect([$row]);

        $dist = $this->metrics->ratingDistribution($logs);

        $this->assertSame(0, $dist['again']);
        $this->assertSame(0, $dist['hard']);
        $this->assertSame(0, $dist['good']);
        $this->assertSame(0, $dist['easy']);
    }

    public function test_input_order_does_not_change_distribution(): void
    {
        $a = collect([
            $this->log('again', '2026-07-10 10:00:00'),
            $this->log('good', '2026-07-10 10:01:00'),
            $this->log('hard', '2026-07-10 10:02:00'),
        ]);
        $b = collect([
            $this->log('hard', '2026-07-10 10:02:00'),
            $this->log('good', '2026-07-10 10:01:00'),
            $this->log('again', '2026-07-10 10:00:00'),
        ]);

        $this->assertSame(
            $this->metrics->ratingDistribution($a),
            $this->metrics->ratingDistribution($b)
        );
        $this->assertSame(
            $this->metrics->forgetRate($a),
            $this->metrics->forgetRate($b)
        );
        $this->assertSame(
            $this->metrics->stabilityRate($a),
            $this->metrics->stabilityRate($b)
        );
        $this->assertSame(
            $this->metrics->averageRating($a),
            $this->metrics->averageRating($b)
        );
    }

    public function test_metrics_service_does_not_access_database(): void
    {
        // The Metrics Service MUST be pure computation. Running every
        // method against an in-memory collection must produce ZERO DB
        // queries.
        $logs = collect([
            $this->log('again', '2026-07-08 10:00:00', 1),
            $this->log('good', '2026-07-09 11:00:00', 2),
            $this->log('hard', '2026-07-10 12:00:00', 1),
            $this->log('easy', '2026-07-10 13:00:00', 3),
        ]);

        DB::connection()->enableQueryLog();
        DB::flushQueryLog();

        $this->metrics->ratingDistribution($logs);
        $this->metrics->forgetRate($logs);
        $this->metrics->stabilityRate($logs);
        $this->metrics->averageRating($logs);
        $this->metrics->distinctSenseCount($logs);
        $this->metrics->groupByDay($logs);
        $this->metrics->reviewsBySense($logs);
        $this->metrics->periodMetrics($logs);

        $queries = DB::getQueryLog();
        $this->assertSame(0, count($queries), 'Metrics Service issued DB queries: ' . json_encode($queries));
    }

    public function test_reviews_by_sense_aggregation(): void
    {
        $logs = collect([
            $this->log('again', '2026-07-10 10:00:00', 1, 'apple', '苹果'),
            $this->log('good', '2026-07-10 10:01:00', 1, 'apple', '苹果'),
            $this->log('hard', '2026-07-10 10:02:00', 2, 'pear', '梨'),
        ]);

        $bySense = $this->metrics->reviewsBySense($logs);

        $this->assertCount(2, $bySense);
        $this->assertArrayHasKey(1, $bySense);
        $this->assertArrayHasKey(2, $bySense);

        $apple = $bySense[1];
        $this->assertSame(2, $apple['total']);
        $this->assertSame(1, $apple['again']);
        $this->assertSame(1, $apple['good']);
        // newest-first log is 'again' at 10:00 → but wait, logs are passed
        // in chronological order here. reviewsBySense treats the FIRST log
        // seen as the most recent (callers must pre-sort newest-first).
        $this->assertSame('again', $apple['last_rating']);
    }

    public function test_period_metrics_aggregate(): void
    {
        $logs = collect([
            $this->log('again', '2026-07-08 10:00:00', 1),
            $this->log('good', '2026-07-09 11:00:00', 2),
            $this->log('hard', '2026-07-10 12:00:00', 1),
            $this->log('easy', '2026-07-10 13:00:00', 3),
        ]);

        $metrics = $this->metrics->periodMetrics($logs);

        $this->assertSame(4, $metrics['total_reviews']);
        $this->assertSame(3, $metrics['distinct_senses']);
        $this->assertSame(1, $metrics['distribution']['again']);
        $this->assertSame(1, $metrics['distribution']['hard']);
        $this->assertSame(1, $metrics['distribution']['good']);
        $this->assertSame(1, $metrics['distribution']['easy']);
        // forget = again/total = 1/4 = 0.25
        $this->assertSame(0.25, $metrics['forget_rate']);
        // stability = (good+easy)/total = 2/4 = 0.5
        $this->assertSame(0.5, $metrics['stability_rate']);
        // average = (1+3+2+4)/4 = 2.5
        $this->assertSame(2.5, $metrics['average_rating']);
    }

    public function test_period_metrics_empty_collection(): void
    {
        $metrics = $this->metrics->periodMetrics(collect());

        $this->assertSame(0, $metrics['total_reviews']);
        $this->assertSame(0, $metrics['distinct_senses']);
        $this->assertSame(
            ['again' => 0, 'hard' => 0, 'good' => 0, 'easy' => 0],
            $metrics['distribution']
        );
        $this->assertNull($metrics['forget_rate']);
        $this->assertNull($metrics['stability_rate']);
        $this->assertNull($metrics['average_rating']);
    }

    public function test_today_summary_formula_compatibility(): void
    {
        // The forget_rate formula used by TodaySummary is again/total,
        // null when empty. Metrics must produce identical values.
        $logs = collect([
            $this->log('again', '2026-07-10 10:00:00'),
            $this->log('again', '2026-07-10 10:01:00'),
            $this->log('good', '2026-07-10 10:02:00'),
        ]);

        // again/total = 2/3 = 0.6667 (rounded to 4 dp)
        $this->assertSame(0.6667, $this->metrics->forgetRate($logs));
        // (good+easy)/total = 1/3 = 0.3333
        $this->assertSame(0.3333, $this->metrics->stabilityRate($logs));
    }

    public function test_daily_report_formula_compatibility(): void
    {
        // DailyReport uses the same distribution/forget/stability formulas
        // plus average_rating via RATING_SCORES. Metrics must match.
        $logs = collect([
            $this->log('hard', '2026-07-10 10:00:00'),  // score 2
            $this->log('good', '2026-07-10 10:01:00'),  // score 3
            $this->log('easy', '2026-07-10 10:02:00'),  // score 4
        ]);

        // average = (2+3+4)/3 = 3.0
        $this->assertSame(3.0, $this->metrics->averageRating($logs));
        // forget = 0/3 = 0.0
        $this->assertSame(0.0, $this->metrics->forgetRate($logs));
        // stability = 2/3 = 0.6667
        $this->assertSame(0.6667, $this->metrics->stabilityRate($logs));
    }

    public function test_learning_feedback_formula_compatibility(): void
    {
        // LearningFeedback computes forget_rate as again/total, but
        // returns 0.0 (not null) when total=0 because emptyFeedback()
        // hard-codes 0.0. The Metrics layer returns null for empty —
        // the Product Service is responsible for the 0.0 fallback when
        // shaping the learning-feedback payload. This test documents
        // the difference: Metrics.null vs Product.0.0.
        $this->assertNull($this->metrics->forgetRate(collect()));

        $logs = collect([
            $this->log('again', '2026-07-10 10:00:00'),
            $this->log('hard', '2026-07-10 10:01:00'),
        ]);

        // again/total = 1/2 = 0.5
        $this->assertSame(0.5, $this->metrics->forgetRate($logs));
    }

    public function test_group_by_day_supports_seven_day_window(): void
    {
        // SevenDayTrend groups 7 days of logs. Metrics groupByDay must
        // return each day that has data, keyed by 'Y-m-d' in the app
        // timezone. The Product Service fills missing days.
        $logs = collect([
            $this->log('again', '2026-07-04 10:00:00'),
            $this->log('good', '2026-07-05 10:00:00'),
            $this->log('hard', '2026-07-07 10:00:00'),
            $this->log('easy', '2026-07-10 10:00:00'),
        ]);

        $grouped = $this->metrics->groupByDay($logs);

        // 4 distinct days present; 3 days missing (07-06, 07-08, 07-09).
        $this->assertCount(4, $grouped);
        $this->assertSame(['2026-07-04', '2026-07-05', '2026-07-07', '2026-07-10'], array_keys($grouped));
    }
}
