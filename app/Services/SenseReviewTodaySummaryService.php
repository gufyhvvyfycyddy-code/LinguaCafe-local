<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * SenseReviewTodaySummaryService
 *
 * SenseReview-TodaySummary-1000-1
 *
 * Read-only cross-session daily aggregate for the SenseReview "今日复习总结"
 * feature. Distinct from SenseReviewSessionTracker (page-load scoped) — this
 * service uses backend ReviewLog as the source of truth so that multiple page
 * sessions in the same natural day are merged into one cumulative summary.
 *
 * Responsibilities:
 *  - Compute the natural-day boundary in the Laravel app timezone (config/app.php
 *    'timezone'). The user timezone is NOT introduced in this round — the app
 *    timezone is the single source so frontend and backend never disagree.
 *  - Reuse SenseReviewQueryService::nonResetSenseReviewLogQuery() for the
 *    shared sense-only / user-isolated / language-isolated / reset-excluded
 *    log base, so reset exclusion rules are identical to:
 *      * ReviewStatsService::reviewActivity() (reviewed_today counter)
 *      * SenseReviewLearningFeedbackService (per-card feedback aggregate)
 *  - Aggregate total / again / hard / good / easy counts for today.
 *  - Count distinct WordSenses reviewed today.
 *  - Build focus_senses (aggregated by sense, max 10) using factual rules:
 *      today has 'again' OR today has 'hard' OR same sense rated multiple
 *      times today OR today's last rating is again/hard.
 *  - Build recent_reviews (max 10, newest first).
 *  - Return timezone / day / day_start / day_end so the frontend never
 *    guesses the date boundary.
 *
 * Invariants:
 *  - READ-ONLY: never writes ReviewLog, never touches any FSRS field,
 *    never creates WordSense or ReviewCard.
 *  - Strict user + language isolation (enforced by SenseReviewQueryService).
 *  - ReviewLog sense-only filtering via review_cards.target_type = 'sense'
 *    join — ReviewLog has NO target_type field.
 *  - Reset exclusion (rating='reset' OR source='reset') is delegated to
 *    SenseReviewQueryService::nonResetSenseReviewLogQuery().
 *  - No new database table, no migration, no FSRS change.
 */
class SenseReviewTodaySummaryService
{
    public function __construct(
        private SenseReviewQueryService $senseReviewQueryService,
    ) {
    }

    /**
     * Build the read-only today summary for a user/language.
     *
     * The day boundary is computed in the app timezone using Carbon::today()
     * (00:00:00) and Carbon::tomorrow() (next 00:00:00), matching
     * ReviewStatsService::reviewActivity()'s notion of "today".
     *
     * @param  int     $userId
     * @param  string  $language  The user's selected_language (language_id).
     * @return array{
     *   timezone: string,
     *   day: string,
     *   day_start: string,
     *   day_end: string,
     *   total_reviews: int,
     *   distinct_senses: int,
     *   distribution: array{again: int, hard: int, good: int, easy: int},
     *   forget_rate: float|null,
     *   focus_senses: list<array>,
     *   recent_reviews: list<array>,
     * }
     */
    public function build(int $userId, string $language): array
    {
        $timezone = config('app.timezone', 'UTC');
        $dayStart = Carbon::today($timezone);
        $dayEnd = Carbon::tomorrow($timezone); // exclusive upper bound

        // Reuse the shared non-reset sense review log query, then narrow to
        // today's [dayStart, dayEnd) window. This guarantees the reset
        // exclusion rule is identical to ReviewStatsService and
        // SenseReviewLearningFeedbackService.
        $baseQuery = $this->senseReviewQueryService
            ->nonResetSenseReviewLogQuery($userId, $language, $dayStart)
            ->where('review_logs.reviewed_at', '<', $dayEnd);

        // Pull only the columns we need for aggregation + sense metadata.
        // Join word_senses is already done by nonResetSenseReviewLogQuery,
        // so we can select sense columns directly.
        $logs = (clone $baseQuery)
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

        return $this->formatSummary($logs, $timezone, $dayStart, $dayEnd);
    }

    /**
     * Format the raw log collection into the summary payload.
     *
     * Separated from build() so tests can supply frozen log collections
     * without hitting the database.
     *
     * @param  Collection  $logs  Non-reset sense review logs for today,
     *                            ordered newest-first (reviewed_at DESC, id DESC).
     */
    private function formatSummary(Collection $logs, string $timezone, Carbon $dayStart, Carbon $dayEnd): array
    {
        $total = $logs->count();
        $again = $logs->where('rating', 'again')->count();
        $hard = $logs->where('rating', 'hard')->count();
        $good = $logs->where('rating', 'good')->count();
        $easy = $logs->where('rating', 'easy')->count();

        // forget_rate: null when no reviews (frontend shows "无记录", not "0%").
        $forgetRate = $total > 0 ? round($again / $total, 4) : null;

        $distinctSenses = $logs->pluck('word_sense_id')->unique()->count();

        return [
            'timezone' => $timezone,
            'day' => $dayStart->format('Y-m-d'),
            'day_start' => $dayStart->toIso8601String(),
            'day_end' => $dayEnd->toIso8601String(),
            'total_reviews' => $total,
            'distinct_senses' => $distinctSenses,
            'distribution' => [
                'again' => $again,
                'hard' => $hard,
                'good' => $good,
                'easy' => $easy,
            ],
            'forget_rate' => $forgetRate,
            'focus_senses' => $this->buildFocusSenses($logs),
            'recent_reviews' => $this->buildRecentReviews($logs),
        ];
    }

    /**
     * Build the focus-senses list (aggregated by word_sense_id, max 10).
     *
     * A sense is included when ANY of these factual conditions holds:
     *  - has at least one 'again' today;
     *  - has at least one 'hard' today;
     *  - was rated more than once today (same sense, multiple ratings);
     *  - today's last rating for the sense is 'again' or 'hard'.
     *
     * Each item shows: lemma, sense_zh, total count, again count, hard count,
     * last rating, last reviewed_at. Same sense → one aggregated row only.
     *
     * Ordering: senses with 'again' first (desc by again count), then 'hard'
     * (desc by hard count), then by total count desc. This surfaces the most
     * problematic senses without guessing "why" they were forgotten.
     *
     * @param  Collection  $logs  Newest-first log collection.
     * @return list<array>
     */
    private function buildFocusSenses(Collection $logs): array
    {
        // Group by word_sense_id, preserving newest-first order within each group.
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
                    'last_reviewed_at' => null,
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
            // logs are newest-first, so the first log we see for a sense is
            // its most recent rating today.
            if ($entry['last_rating'] === null) {
                $entry['last_rating'] = $log->rating;
                $entry['last_reviewed_at'] = $log->reviewed_at
                    ? $log->reviewed_at->toIso8601String()
                    : null;
            }
            unset($entry);
        }

        // Apply the factual focus rules.
        $focus = array_filter($bySense, function ($e) {
            return $e['again'] > 0
                || $e['hard'] > 0
                || $e['total'] > 1
                || in_array($e['last_rating'], ['again', 'hard'], true);
        });

        // Sort: again desc, then hard desc, then total desc.
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
     * Build the recent-reviews list (max 10, newest first).
     *
     * Each item: lemma, sense_zh, rating, rating_label, reviewed_at.
     * rating_label uses SenseReviewLearningFeedbackService::RATING_LABELS
     * so the label mapping is shared with the per-card feedback aggregate.
     *
     * @param  Collection  $logs  Newest-first log collection.
     * @return list<array>
     */
    private function buildRecentReviews(Collection $logs): array
    {
        $recent = [];
        foreach ($logs->take(10) as $log) {
            $rating = $log->rating;
            $recent[] = [
                'lemma' => $log->lemma,
                'sense_zh' => $log->sense_zh,
                'rating' => $rating,
                'rating_label' => SenseReviewLearningFeedbackService::RATING_LABELS[$rating] ?? $rating,
                'reviewed_at' => $log->reviewed_at ? $log->reviewed_at->toIso8601String() : null,
            ];
        }
        return $recent;
    }
}
