<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * SenseReviewReportMetricsService
 *
 * SenseReview-ReportMetrics-1000-1
 *
 * Pure-computation metrics layer for the SenseReview report family. This
 * service is the SINGLE source of truth for every numeric formula shared
 * by TodaySummary / DailyReport / SevenDayTrend / LearningFeedback:
 *
 *   - rating distribution (again / hard / good / easy counts)
 *   - forget rate (again / total, null when empty)
 *   - stability rate ((good + easy) / total, null when empty)
 *   - average rating (mean numeric score, null when empty)
 *   - distinct sense count
 *   - per-sense aggregation
 *   - per-day grouping
 *   - period-level aggregate metrics
 *
 * Layering contract:
 *
 *   Query Layer   (SenseReviewAnalyticsQueryService)
 *       → only DB reads + user/language/sense/reset isolation.
 *
 *   Metrics Layer (this service)
 *       → only pure computation on in-memory Collections.
 *
 *   Product Service (TodaySummary / DailyReport / SevenDayTrend /
 *                    LearningFeedback)
 *       → decides payload shape, focus rules, max-10, recent count,
 *         product copy, zero-day fill for fixed windows.
 *
 *   Controller    → request coordination only.
 *
 * Hard rules:
 *  - NEVER accesses the database (no Eloquent, no DB facade, no query).
 *  - NEVER depends on Auth / user context.
 *  - NEVER reads config (timezone / limits come from the caller).
 *  - NEVER returns Chinese product copy (labels live in
 *    SenseReviewRatingContract).
 *  - NEVER decides display limits, sort order for product lists, or
 *    fixed-window zero-fill (those are Product Service concerns).
 *  - NEVER auto-corrects invalid ratings: invalid ratings are ignored
 *    by the distribution (counted as 0 across all buckets), preserving
 *    fail-closed semantics.
 *  - NEVER creates a mega-service: only the listed pure metrics live here.
 *
 * Rating metadata (allowed ratings, labels, numeric scores) is sourced
 * from SenseReviewRatingContract so there is exactly one source of truth.
 */
class SenseReviewReportMetricsService
{
    public function __construct(
        private SenseReviewRatingContract $contract,
    ) {
    }

    /**
     * Compute the rating distribution from a log collection.
     *
     * Pure computation — no database access. Accepts any collection of
     * objects with a `rating` property (e.g. rows from
     * SenseReviewAnalyticsQueryService::reviewsForPeriod /
     * reviewsForCards).
     *
     * Invalid ratings (not in the Contract's allowed set) are ignored:
     * they contribute 0 to every bucket. This is fail-closed — invalid
     * ratings are never silently re-bucketed as 'good'.
     *
     * @param  Collection  $logs
     * @return array{again: int, hard: int, good: int, easy: int}
     */
    public function ratingDistribution(Collection $logs): array
    {
        $again = 0;
        $hard = 0;
        $good = 0;
        $easy = 0;

        foreach ($logs as $log) {
            $rating = $log->rating;
            if ($rating === 'again') {
                $again++;
            } elseif ($rating === 'hard') {
                $hard++;
            } elseif ($rating === 'good') {
                $good++;
            } elseif ($rating === 'easy') {
                $easy++;
            }
        }

        return [
            'again' => $again,
            'hard'  => $hard,
            'good'  => $good,
            'easy'  => $easy,
        ];
    }

    /**
     * Compute the forget rate: again / total.
     *
     * Returns null when the collection is empty (callers show "暂无数据",
     * never a fake "0%"). Rounded to 4 decimal places.
     *
     * @param  Collection  $logs
     * @return float|null
     */
    public function forgetRate(Collection $logs): ?float
    {
        $total = $logs->count();
        if ($total === 0) {
            return null;
        }
        $again = 0;
        foreach ($logs as $log) {
            if ($log->rating === 'again') {
                $again++;
            }
        }
        return round($again / $total, 4);
    }

    /**
     * Compute the stability rate: (good + easy) / total.
     *
     * Returns null when the collection is empty. Rounded to 4 decimal
     * places.
     *
     * @param  Collection  $logs
     * @return float|null
     */
    public function stabilityRate(Collection $logs): ?float
    {
        $total = $logs->count();
        if ($total === 0) {
            return null;
        }
        $stable = 0;
        foreach ($logs as $log) {
            if ($log->rating === 'good' || $log->rating === 'easy') {
                $stable++;
            }
        }
        return round($stable / $total, 4);
    }

    /**
     * Compute the average numeric rating across a log collection.
     *
     * Uses SenseReviewRatingContract::scoreFor() so the score mapping
     * (again=1, hard=2, good=3, easy=4) has one source of truth. Invalid
     * ratings contribute 0 to the sum but still count toward total —
     * matching the previous DailyReport behavior. Returns null when the
     * collection is empty.
     *
     * @param  Collection  $logs
     * @return float|null
     */
    public function averageRating(Collection $logs): ?float
    {
        $total = $logs->count();
        if ($total === 0) {
            return null;
        }
        $sum = 0;
        foreach ($logs as $log) {
            $score = $this->contract->scoreFor($log->rating);
            $sum += $score ?? 0;
        }
        return round($sum / $total, 2);
    }

    /**
     * Count distinct word_sense_id values in a log collection.
     *
     * @param  Collection  $logs
     * @return int
     */
    public function distinctSenseCount(Collection $logs): int
    {
        return $logs->pluck('word_sense_id')->unique()->count();
    }

    /**
     * Group logs by their reviewed_at day (Y-m-d).
     *
     * Returns an array keyed by 'Y-m-d' string, where each value is the
     * sub-collection of logs that fell on that day. ONLY days that have
     * at least one log appear in the result.
     *
     * IMPORTANT: This method does NOT zero-fill missing days. Filling a
     * fixed window (e.g. the 7-day trend's 7 entries) is the Product
     * Service's responsibility — Metrics must stay pure and not know
     * about window sizes.
     *
     * The day boundary is computed in whatever timezone the reviewed_at
     * Carbon instance already carries. Callers are responsible for
     * ensuring logs are fetched in the desired timezone (the Query Layer
     * uses the app timezone).
     *
     * @param  Collection  $logs
     * @return array<string, Collection>  Keyed by 'Y-m-d'.
     */
    public function groupByDay(Collection $logs): array
    {
        $grouped = [];

        foreach ($logs as $log) {
            $day = $log->reviewed_at
                ? $log->reviewed_at->format('Y-m-d')
                : null;
            if ($day === null) {
                continue;
            }
            if (!isset($grouped[$day])) {
                $grouped[$day] = collect();
            }
            $grouped[$day]->push($log);
        }

        // Sort by day ascending so callers can rely on chronological order.
        ksort($grouped);

        return $grouped;
    }

    /**
     * Group logs by word_sense_id with aggregated counts and metadata.
     *
     * Pure computation — no database access. Accepts a collection of log
     * rows that have word_sense_id, lemma, sense_zh, rating, reviewed_at
     * (e.g. rows from reviewsForPeriod). The collection MUST be ordered
     * newest-first so that "first seen" = most recent rating.
     *
     * Returns an array keyed by word_sense_id. Each value has:
     *   word_sense_id, lemma, sense_zh,
     *   total, again, hard, good, easy,
     *   last_rating        — most recent rating (string),
     *   last_reviewed_at   — ISO 8601 string of most recent log (or null),
     *   ratings            — array of ratings newest-first.
     *
     * Callers apply their own focus rules / sort / max-limit / transition
     * detection — this method returns the raw per-sense aggregation only.
     *
     * @param  Collection  $logs  Newest-first log collection.
     * @return array<int, array>
     */
    public function reviewsBySense(Collection $logs): array
    {
        $bySense = [];
        foreach ($logs as $log) {
            $sid = $log->word_sense_id;
            if (!isset($bySense[$sid])) {
                $bySense[$sid] = [
                    'word_sense_id' => $sid,
                    'lemma' => $log->lemma,
                    'sense_zh' => $log->sense_zh,
                    'total' => 0,
                    'again' => 0,
                    'hard' => 0,
                    'good' => 0,
                    'easy' => 0,
                    'last_rating' => null,
                    'last_reviewed_at' => null,
                    'ratings' => [],
                ];
            }
            $entry = &$bySense[$sid];
            $entry['total']++;
            $rating = $log->rating;
            if ($rating === 'again') {
                $entry['again']++;
            } elseif ($rating === 'hard') {
                $entry['hard']++;
            } elseif ($rating === 'good') {
                $entry['good']++;
            } elseif ($rating === 'easy') {
                $entry['easy']++;
            }
            // logs are newest-first; first log seen = most recent rating.
            if ($entry['last_rating'] === null) {
                $entry['last_rating'] = $rating;
                $entry['last_reviewed_at'] = $log->reviewed_at
                    ? $log->reviewed_at->toIso8601String()
                    : null;
            }
            $entry['ratings'][] = $rating;
            unset($entry);
        }

        return $bySense;
    }

    /**
     * Compute the full period-level aggregate metrics in one call.
     *
     * Convenience method for report services that need every metric for
     * a window (e.g. SevenDayTrend's summary block, or a single day's
     * metrics). Returns the same values the individual methods would —
     * this is pure composition, no extra logic.
     *
     * Shape:
     *   total_reviews:     int
     *   distinct_senses:   int
     *   distribution:      array{again, hard, good, easy}
     *   forget_rate:       float|null
     *   stability_rate:    float|null
     *   average_rating:    float|null
     *
     * @param  Collection  $logs
     * @return array{
     *   total_reviews: int,
     *   distinct_senses: int,
     *   distribution: array{again: int, hard: int, good: int, easy: int},
     *   forget_rate: float|null,
     *   stability_rate: float|null,
     *   average_rating: float|null,
     * }
     */
    public function periodMetrics(Collection $logs): array
    {
        return [
            'total_reviews' => $logs->count(),
            'distinct_senses' => $this->distinctSenseCount($logs),
            'distribution' => $this->ratingDistribution($logs),
            'forget_rate' => $this->forgetRate($logs),
            'stability_rate' => $this->stabilityRate($logs),
            'average_rating' => $this->averageRating($logs),
        ];
    }
}
