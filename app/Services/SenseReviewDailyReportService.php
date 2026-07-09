<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * SenseReviewDailyReportService
 *
 * SenseReview-DailyReport-1000-1
 *
 * Read-only "今日学习日报" (daily learning report) service. Distinct from
 * SenseReviewTodaySummaryService (simpler summary) — this service produces
 * a richer four-block report designed for daily learning reflection:
 *
 *   1. overview    — totals, distinct senses, first-vs-again, average rating.
 *   2. quality     — distribution, forget rate, stability rate.
 *   3. focus_senses — max 10 senses needing attention, sorted by difficulty.
 *   4. progress_senses — senses that improved (again→good, hard→easy) today.
 *
 * Responsibilities:
 *  - Compute the natural-day boundary in the app timezone.
 *  - Delegate ReviewLog querying to SenseReviewAnalyticsQueryService so the
 *    shared sense-only / user-isolated / language-isolated / reset-excluded
 *    query rules live in one place.
 *  - Delegate rating distribution / forget rate / stability rate / per-sense
 *    aggregation to the analytics layer so the formulas are shared with
 *    TodaySummary + LearningFeedback.
 *  - Determine first-review vs review-again senses via
 *    SenseReviewAnalyticsQueryService::sensesReviewedBefore().
 *  - Build focus_senses (max 10) with the same factual rules as TodaySummary.
 *  - Build progress_senses by scanning each sense's today rating sequence
 *    for again→good or hard→easy transitions.
 *
 * Invariants:
 *  - READ-ONLY: never writes ReviewLog, never touches FSRS, never creates
 *    WordSense or ReviewCard.
 *  - Strict user + language isolation (enforced by analytics layer).
 *  - ReviewLog sense-only filtering + reset exclusion centralized in
 *    SenseReviewAnalyticsQueryService / SenseReviewQueryService.
 *  - No new database table, no migration, no FSRS change.
 */
class SenseReviewDailyReportService
{
    /**
     * Rating value → numeric score for average rating computation.
     * again=1, hard=2, good=3, easy=4. 'reset' is absent (excluded).
     */
    private const RATING_SCORES = [
        'again' => 1,
        'hard'  => 2,
        'good'  => 3,
        'easy'  => 4,
    ];

    public function __construct(
        private SenseReviewAnalyticsQueryService $analytics,
    ) {
    }

    /**
     * Build the read-only daily learning report.
     *
     * @param  int     $userId
     * @param  string  $language  The user's selected_language (language_id).
     * @return array{
     *   timezone: string,
     *   day: string,
     *   day_start: string,
     *   day_end: string,
     *   overview: array{
     *     total_reviews: int,
     *     distinct_senses: int,
     *     first_review_senses: int,
     *     review_again_senses: int,
     *     average_rating: float|null,
     *   },
     *   quality: array{
     *     distribution: array{again: int, hard: int, good: int, easy: int},
     *     forget_rate: float|null,
     *     stability_rate: float|null,
     *   },
     *   focus_senses: list<array>,
     *   progress_senses: list<array>,
     * }
     */
    public function build(int $userId, string $language): array
    {
        $timezone = config('app.timezone', 'UTC');
        $dayStart = Carbon::today($timezone);
        $dayEnd = Carbon::tomorrow($timezone); // exclusive upper bound

        // Delegate log fetching to the centralized analytics layer.
        $todayLogs = $this->analytics->reviewsForPeriod($userId, $language, $dayStart, $dayEnd);

        // Distinct sense ids reviewed today.
        $todaySenseIds = $todayLogs->pluck('word_sense_id')->unique()->values()->all();

        // Sense ids reviewed before today (for first-review vs review-again).
        $beforeTodaySenseIds = [];
        if (!empty($todaySenseIds)) {
            $beforeTodaySenseIds = $this->analytics->sensesReviewedBefore($userId, $language, $dayStart);
        }
        $beforeTodaySet = array_flip($beforeTodaySenseIds);

        return [
            'timezone' => $timezone,
            'day' => $dayStart->format('Y-m-d'),
            'day_start' => $dayStart->toIso8601String(),
            'day_end' => $dayEnd->toIso8601String(),
            'overview' => $this->buildOverview($todayLogs, $todaySenseIds, $beforeTodaySet),
            'quality' => $this->buildQuality($todayLogs),
            'focus_senses' => $this->buildFocusSenses($todayLogs),
            'progress_senses' => $this->buildProgressSenses($todayLogs),
        ];
    }

    /**
     * Build the overview block.
     *
     * @param  Collection  $logs           Today's non-reset logs (newest-first).
     * @param  array       $todaySenseIds  Distinct sense ids reviewed today.
     * @param  array       $beforeTodaySet Map of sense_id => true (reviewed before today).
     */
    private function buildOverview(Collection $logs, array $todaySenseIds, array $beforeTodaySet): array
    {
        $total = $logs->count();
        $distinct = count($todaySenseIds);

        $firstReview = 0;
        $reviewAgain = 0;
        foreach ($todaySenseIds as $sid) {
            if (isset($beforeTodaySet[$sid])) {
                $reviewAgain++;
            } else {
                $firstReview++;
            }
        }

        // average_rating: null when empty (frontend shows "暂无数据").
        $averageRating = null;
        if ($total > 0) {
            $sum = 0;
            foreach ($logs as $log) {
                $sum += self::RATING_SCORES[$log->rating] ?? 0;
            }
            $averageRating = round($sum / $total, 2);
        }

        return [
            'total_reviews' => $total,
            'distinct_senses' => $distinct,
            'first_review_senses' => $firstReview,
            'review_again_senses' => $reviewAgain,
            'average_rating' => $averageRating,
        ];
    }

    /**
     * Build the quality block: distribution, forget_rate, stability_rate.
     *
     * Distribution + rates delegate to the analytics layer so the formulas
     * are shared with TodaySummary + LearningFeedback.
     *
     * forget_rate = again / total (null when empty).
     * stability_rate = (good + easy) / total (null when empty).
     */
    private function buildQuality(Collection $logs): array
    {
        return [
            'distribution' => $this->analytics->ratingDistribution($logs),
            'forget_rate' => $this->analytics->forgetRate($logs),
            'stability_rate' => $this->analytics->stabilityRate($logs),
        ];
    }

    /**
     * Build the focus-senses list (max 10, aggregated by word_sense_id).
     *
     * Same factual rules as TodaySummary:
     *  - has 'again' today; OR
     *  - has 'hard' today; OR
     *  - rated more than once today; OR
     *  - last rating today is again/hard.
     *
     * Sort: again desc, hard desc, total desc.
     *
     * Per-sense aggregation delegates to SenseReviewAnalyticsQueryService::
     * reviewsBySense(); the focus filter + sort + max-10 are product logic
     * that stays here.
     */
    private function buildFocusSenses(Collection $logs): array
    {
        $bySense = $this->analytics->reviewsBySense($logs);

        $focus = array_filter($bySense, function ($e) {
            return $e['again'] > 0
                || $e['hard'] > 0
                || $e['total'] > 1
                || in_array($e['last_rating'], ['again', 'hard'], true);
        });

        usort($focus, function ($a, $b) {
            if ($a['again'] !== $b['again']) {
                return $b['again'] <=> $a['again'];
            }
            if ($a['hard'] !== $b['hard']) {
                return $b['hard'] <=> $a['hard'];
            }
            return $b['total'] <=> $a['total'];
        });

        // Shape to the DailyReport focus_senses contract (no last_reviewed_at).
        $shaped = array_map(function ($e) {
            return [
                'word_sense_id' => $e['word_sense_id'],
                'lemma' => $e['lemma'],
                'sense_zh' => $e['sense_zh'],
                'total' => $e['total'],
                'again' => $e['again'],
                'hard' => $e['hard'],
                'last_rating' => $e['last_rating'],
            ];
        }, $focus);

        return array_slice(array_values($shaped), 0, 10);
    }

    /**
     * Build the progress-senses list: senses that showed improvement today.
     *
     * A sense qualifies when its today rating sequence contains a transition:
     *   - again → good  (forgot then remembered)
     *   - hard → easy   (struggled then mastered)
     *
     * The transition must be temporal: the 'good'/'easy' rating happens AFTER
     * the 'again'/'hard' rating. We scan old→new and report the first
     * qualifying transition per sense. No duplicates: one entry per sense.
     *
     * Per-sense grouping delegates to SenseReviewAnalyticsQueryService::
     * reviewsBySense() (ratings array is newest-first; we reverse to
     * old→new for temporal scanning). The transition detection is product
     * logic that stays here.
     *
     * Each item: word_sense_id, lemma, sense_zh, from_rating, to_rating.
     */
    private function buildProgressSenses(Collection $logs): array
    {
        $bySense = $this->analytics->reviewsBySense($logs);

        $progress = [];
        foreach ($bySense as $sid => $info) {
            // ratings are newest-first; reverse to old→new for temporal scan.
            $oldToNew = array_reverse($info['ratings']);
            $transition = $this->findProgressTransition($oldToNew);
            if ($transition !== null) {
                $progress[] = [
                    'word_sense_id' => $sid,
                    'lemma' => $info['lemma'],
                    'sense_zh' => $info['sense_zh'],
                    'from_rating' => $transition['from'],
                    'to_rating' => $transition['to'],
                ];
            }
        }

        return $progress;
    }

    /**
     * Scan an old→new rating sequence for the first qualifying progress
     * transition: again→good or hard→easy.
     *
     * Returns ['from' => rating, 'to' => rating] or null.
     *
     * @param  array  $ratings  Ratings ordered old→new.
     */
    private function findProgressTransition(array $ratings): ?array
    {
        for ($i = 0; $i < count($ratings) - 1; $i++) {
            $from = $ratings[$i];
            for ($j = $i + 1; $j < count($ratings); $j++) {
                $to = $ratings[$j];
                if ($from === 'again' && $to === 'good') {
                    return ['from' => 'again', 'to' => 'good'];
                }
                if ($from === 'hard' && $to === 'easy') {
                    return ['from' => 'hard', 'to' => 'easy'];
                }
            }
        }
        return null;
    }
}
