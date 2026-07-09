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
 *  - Reuse SenseReviewQueryService::nonResetSenseReviewLogQuery() for the
 *    shared sense-only / user-isolated / language-isolated / reset-excluded
 *    log base (same rule as TodaySummary and LearningFeedback).
 *  - Determine first-review vs review-again senses by checking whether each
 *    sense had any non-reset review BEFORE today.
 *  - Aggregate rating counts, average rating, forget rate, stability rate.
 *  - Build focus_senses (max 10) with the same factual rules as TodaySummary.
 *  - Build progress_senses by scanning each sense's today rating sequence
 *    for again→good or hard→easy transitions.
 *
 * Invariants:
 *  - READ-ONLY: never writes ReviewLog, never touches FSRS, never creates
 *    WordSense or ReviewCard.
 *  - Strict user + language isolation (enforced by SenseReviewQueryService).
 *  - ReviewLog sense-only filtering via review_cards.target_type = 'sense'.
 *  - Reset exclusion (rating='reset' OR source='reset').
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
        private SenseReviewQueryService $senseReviewQueryService,
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

        // Today's non-reset sense review logs, newest-first.
        $todayLogs = $this->senseReviewQueryService
            ->nonResetSenseReviewLogQuery($userId, $language, $dayStart)
            ->where('review_logs.reviewed_at', '<', $dayEnd)
            ->select([
                'review_logs.id',
                'review_logs.review_card_id',
                'review_logs.rating',
                'review_logs.reviewed_at',
                'review_cards.target_id as word_sense_id',
                'word_senses.lemma',
                'word_senses.sense_zh',
            ])
            ->orderByDesc('review_logs.reviewed_at')
            ->orderByDesc('review_logs.id')
            ->get();

        // Distinct sense ids reviewed today.
        $todaySenseIds = $todayLogs->pluck('word_sense_id')->unique()->values()->all();

        // Sense ids that had any non-reset review BEFORE today. Used to
        // distinguish first-review (new today) from review-again (returning).
        // Use a very early $since so the shared query includes all history;
        // the < $dayStart constraint narrows to before-today only.
        $beforeTodaySenseIds = [];
        if (!empty($todaySenseIds)) {
            $epoch = Carbon::create(1900, 1, 1, 0, 0, 0);
            $beforeTodaySenseIds = $this->senseReviewQueryService
                ->nonResetSenseReviewLogQuery($userId, $language, $epoch)
                ->where('review_logs.reviewed_at', '<', $dayStart)
                ->whereIn('review_cards.target_id', $todaySenseIds)
                ->pluck('review_cards.target_id')
                ->unique()
                ->all();
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
     * forget_rate = again / total (null when empty).
     * stability_rate = (good + easy) / total (null when empty).
     */
    private function buildQuality(Collection $logs): array
    {
        $total = $logs->count();
        $again = $logs->where('rating', 'again')->count();
        $hard = $logs->where('rating', 'hard')->count();
        $good = $logs->where('rating', 'good')->count();
        $easy = $logs->where('rating', 'easy')->count();

        return [
            'distribution' => [
                'again' => $again,
                'hard' => $hard,
                'good' => $good,
                'easy' => $easy,
            ],
            'forget_rate' => $total > 0 ? round($again / $total, 4) : null,
            'stability_rate' => $total > 0 ? round(($good + $easy) / $total, 4) : null,
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
     */
    private function buildFocusSenses(Collection $logs): array
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
                    'last_rating' => null,
                ];
            }
            $entry = &$bySense[$sid];
            $entry['total']++;
            if ($log->rating === 'again') {
                $entry['again']++;
            }
            if ($log->rating === 'hard') {
                $entry['hard']++;
            }
            // logs are newest-first; first log seen = most recent rating.
            if ($entry['last_rating'] === null) {
                $entry['last_rating'] = $log->rating;
            }
            unset($entry);
        }

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

        return array_slice(array_values($focus), 0, 10);
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
     * Each item: word_sense_id, lemma, sense_zh, from_rating, to_rating.
     */
    private function buildProgressSenses(Collection $logs): array
    {
        // Group by sense, preserving newest-first order within each group.
        $bySense = [];
        foreach ($logs as $log) {
            $sid = $log->word_sense_id;
            if (!isset($bySense[$sid])) {
                $bySense[$sid] = [
                    'word_sense_id' => $sid,
                    'lemma' => $log->lemma,
                    'sense_zh' => $log->sense_zh,
                    'ratings' => [], // will be newest-first
                ];
            }
            $bySense[$sid]['ratings'][] = $log->rating;
        }

        $progress = [];
        foreach ($bySense as $sid => $info) {
            // Reverse to old→new for temporal scanning.
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
