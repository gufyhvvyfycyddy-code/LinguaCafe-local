<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * SenseReviewDailyInsightBuilder
 *
 * SenseReview-DailyInsightBuilder-1000-3
 *
 * Pure-computation insight layer for the SenseReview daily report. This is
 * the SINGLE source of truth for the per-day "insight" lists (focus_senses,
 * progress_senses, recent_reviews) shared by the consolidated daily report.
 *
 * It unifies the algorithms that previously lived in two separate Product
 * Services (TodaySummary + DailyReport) so there is exactly one focus-senses
 * filter / sort / shape, one progress-transition detector, and one
 * recent-reviews builder.
 *
 * Layering contract:
 *
 *   Query Layer   (SenseReviewAnalyticsQueryService)
 *       → only DB reads + user/language/sense/reset isolation.
 *
 *   Metrics Layer (SenseReviewReportMetricsService)
 *       → only pure computation on in-memory Collections (distribution,
 *         rates, per-sense aggregation).
 *
 *   Insight Layer (this builder)
 *       → only pure computation on an in-memory Collection supplied by the
 *         caller. Applies product rules (focus filter / sort / max-10,
 *         progress transitions, recent max-10) and shapes the output.
 *
 *   Product Service (SenseReviewDailyReportService)
 *       → decides payload shape, calls Query + Metrics + Insight.
 *
 * Hard rules:
 *  - NEVER accesses the database (no Eloquent, no DB facade, no query).
 *  - NEVER depends on Auth / Request / Controller / config / .env.
 *  - NEVER depends on SenseReviewAnalyticsQueryService (that would break
 *    the pure-computation contract — the caller supplies the logs).
 *  - NEVER writes ReviewLog / ReviewCard / WordSense / FSRS.
 *  - NEVER contains Chinese product copy beyond the rating labels sourced
 *    from SenseReviewRatingContract (which is the single source of truth
 *    for rating→label mapping).
 *  - The input Collection MUST be ordered newest-first
 *    (reviewed_at DESC, id DESC) — this matches what
 *    SenseReviewAnalyticsQueryService::reviewsForPeriod() returns.
 */
class SenseReviewDailyInsightBuilder
{
    public function __construct(
        private SenseReviewReportMetricsService $metrics,
        private SenseReviewRatingContract $contract,
    ) {
    }

    /**
     * Build the three insight lists from a batch of today's ReviewLog rows.
     *
     * @param  Collection  $logs  Non-reset sense review logs for today,
     *                            ordered newest-first (reviewed_at DESC, id DESC).
     * @return array{
     *   focus_senses: list<array{
     *     word_sense_id: int,
     *     lemma: string,
     *     sense_zh: string,
     *     total: int,
     *     again: int,
     *     hard: int,
     *     last_rating: string,
     *     last_reviewed_at: string|null,
     *   }>,
     *   progress_senses: list<array{
     *     word_sense_id: int,
     *     lemma: string,
     *     sense_zh: string,
     *     from_rating: string,
     *     to_rating: string,
     *   }>,
     *   recent_reviews: list<array{
     *     lemma: string,
     *     sense_zh: string,
     *     rating: string,
     *     rating_label: string,
     *     reviewed_at: string|null,
     *   }>,
     * }
     */
    public function build(Collection $logs): array
    {
        // Build word_sense_id → review_card_id map from the newest-first logs.
        // The first log seen for a sense is the newest (matching reviewsBySense
        // semantics). Used by focus_senses and progress_senses for precise
        // card navigation (ADR-0007). 0 DB queries — reads from in-memory logs.
        // review_card_id <= 0 → null (do not fabricate fake navigation targets).
        $cardIdBySense = [];
        foreach ($logs as $log) {
            $sid = $log->word_sense_id;
            if (!isset($cardIdBySense[$sid])) {
                $rid = $log->review_card_id ?? 0;
                $cardIdBySense[$sid] = $rid > 0 ? $rid : null;
            }
        }

        return [
            'focus_senses' => $this->buildFocusSenses($logs, $cardIdBySense),
            'progress_senses' => $this->buildProgressSenses($logs, $cardIdBySense),
            'recent_reviews' => $this->buildRecentReviews($logs),
        ];
    }

    /**
     * Build the focus-senses list (aggregated by word_sense_id, max 10).
     *
     * Unified rules (previously duplicated in TodaySummary + DailyReport):
     *  - has at least one 'again' today; OR
     *  - has at least one 'hard' today; OR
     *  - was rated more than once today (same sense, multiple ratings); OR
     *  - today's last rating for the sense is 'again' or 'hard'.
     *
     * Sort: again desc, then hard desc, then total desc.
     * Limit: max 10.
     *
     * Output shape is the superset (includes last_reviewed_at) so the
     * consolidated daily report carries the richer field.
     *
     * @param  Collection  $logs  Newest-first log collection.
     * @return list<array>
     */
    private function buildFocusSenses(Collection $logs, array $cardIdBySense): array
    {
        $bySense = $this->metrics->reviewsBySense($logs);

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

        $shaped = array_map(function ($e) use ($cardIdBySense) {
            return [
                'word_sense_id' => $e['word_sense_id'],
                'review_card_id' => $cardIdBySense[$e['word_sense_id']] ?? null,
                'lemma' => $e['lemma'],
                'sense_zh' => $e['sense_zh'],
                'total' => $e['total'],
                'again' => $e['again'],
                'hard' => $e['hard'],
                'last_rating' => $e['last_rating'],
                'last_reviewed_at' => $e['last_reviewed_at'],
            ];
        }, $focus);

        return array_slice(array_values($shaped), 0, 10);
    }

    /**
     * Build the progress-senses list: senses that showed improvement today.
     *
     * A sense qualifies when its today rating sequence contains a temporal
     * transition:
     *   - again → good  (forgot then remembered)
     *   - hard → easy   (struggled then mastered)
     *
     * The transition must be temporal: the 'good'/'easy' rating happens AFTER
     * the 'again'/'hard' rating. We scan old→new and report the first
     * qualifying transition per sense. No duplicates: one entry per sense.
     *
     * @param  Collection  $logs  Newest-first log collection.
     * @return list<array>
     */
    private function buildProgressSenses(Collection $logs, array $cardIdBySense): array
    {
        $bySense = $this->metrics->reviewsBySense($logs);

        $progress = [];
        foreach ($bySense as $sid => $info) {
            // ratings are newest-first; reverse to old→new for temporal scan.
            $oldToNew = array_reverse($info['ratings']);
            $transition = $this->findProgressTransition($oldToNew);
            if ($transition !== null) {
                $progress[] = [
                    'word_sense_id' => $sid,
                    'review_card_id' => $cardIdBySense[$sid] ?? null,
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
     * Build the recent-reviews list (max 10, newest first).
     *
     * Each item: lemma, sense_zh, rating, rating_label, reviewed_at.
     * rating_label uses SenseReviewRatingContract::labelFor() so the label
     * mapping is the single source of truth shared with all report services.
     *
     * @param  Collection  $logs  Newest-first log collection.
     * @return list<array>
     */
    private function buildRecentReviews(Collection $logs): array
    {
        $recent = [];
        foreach ($logs->take(10) as $log) {
            $rating = $log->rating;
            $rid = $log->review_card_id ?? 0;
            $recent[] = [
                'review_card_id' => $rid > 0 ? $rid : null,
                'word_sense_id' => $log->word_sense_id,
                'lemma' => $log->lemma,
                'sense_zh' => $log->sense_zh,
                'rating' => $rating,
                'rating_label' => $this->contract->labelFor($rating) ?? $rating,
                'reviewed_at' => $log->reviewed_at ? $log->reviewed_at->toIso8601String() : null,
            ];
        }
        return $recent;
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
