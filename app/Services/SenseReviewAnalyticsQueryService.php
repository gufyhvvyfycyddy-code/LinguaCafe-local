<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * SenseReviewAnalyticsQueryService
 *
 * SenseReview-AnalyticsQuery-1000-1
 *
 * Centralized read-only ReviewLog statistics query layer for the SenseReview
 * feature. This is the single entry point for sense-review analytics queries
 * so that future features (weekly reports, monthly reports, learning trends,
 * forgetting analysis, sense growth curves) share one consistent data path
 * instead of each re-implementing ReviewLog queries.
 *
 * Responsibilities:
 *  - reviewsForPeriod(): non-reset sense review logs in a [start, end) window,
 *    with sense metadata, newest-first. Used by TodaySummary + DailyReport.
 *  - sensesReviewedBefore(): sense ids with any non-reset review before a time.
 *    Used by DailyReport first-review vs review-again detection.
 *  - reviewsForCards(): non-reset logs for given card ids, newest-first.
 *    Used by LearningFeedback. Single batch query regardless of card count.
 *  - ratingDistribution(): again/hard/good/easy counts from a collection.
 *  - forgetRate(): again/total, null when empty.
 *  - stabilityRate(): (good+easy)/total, null when empty.
 *  - reviewsBySense(): per-sense aggregation with counts + ratings sequence.
 *
 * Invariants:
 *  - READ-ONLY: never writes ReviewLog / ReviewCard / WordSense / FSRS.
 *  - Strict user + language isolation for sense-scoped queries (delegated to
 *    SenseReviewQueryService::nonResetSenseReviewLogQuery).
 *  - Reset exclusion (rating='reset' OR source='reset') centralized via
 *    SenseReviewQueryService for both sense-scoped and card-scoped paths.
 *  - Sense-only filtering (target_type='sense') for sense-scoped queries.
 *  - No product copy / sort / max-limit logic here — callers shape results.
 *  - No new database table, no migration, no FSRS change.
 *
 * Boundary:
 *  - Reads: ReviewLog, ReviewCard (join), WordSense (join).
 *  - Never writes: ReviewLog, ReviewCard, WordSense, FSRS.
 */
class SenseReviewAnalyticsQueryService
{
    public function __construct(
        private SenseReviewQueryService $senseReviewQueryService,
    ) {
    }

    /**
     * Fetch non-reset sense review logs in a [start, end) window.
     *
     * Returns a Collection of log rows with sense metadata, ordered
     * newest-first (reviewed_at DESC, id DESC). Each row has:
     *   id, review_card_id, rating, reviewed_at,
     *   word_sense_id (alias of review_cards.target_id),
     *   lemma, sense_zh.
     *
     * User / language isolation, sense-only filtering, and reset exclusion
     * are all delegated to SenseReviewQueryService::nonResetSenseReviewLogQuery.
     *
     * @param  int     $userId
     * @param  string  $language
     * @param  Carbon  $start  Inclusive lower bound.
     * @param  Carbon  $end    Exclusive upper bound.
     * @return Collection
     */
    public function reviewsForPeriod(int $userId, string $language, Carbon $start, Carbon $end): Collection
    {
        return $this->senseReviewQueryService
            ->nonResetSenseReviewLogQuery($userId, $language, $start)
            ->where('review_logs.reviewed_at', '<', $end)
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
    }

    /**
     * Return sense ids (review_cards.target_id) that had at least one
     * non-reset sense review BEFORE the given datetime.
     *
     * Used by DailyReport to distinguish first-review (new today) from
     * review-again (returning) senses.
     *
     * @param  int     $userId
     * @param  string  $language
     * @param  Carbon  $before  Exclusive upper bound.
     * @return array<int>  Distinct sense ids.
     */
    public function sensesReviewedBefore(int $userId, string $language, Carbon $before): array
    {
        $epoch = Carbon::create(1900, 1, 1, 0, 0, 0);

        return $this->senseReviewQueryService
            ->nonResetSenseReviewLogQuery($userId, $language, $epoch)
            ->where('review_logs.reviewed_at', '<', $before)
            ->pluck('review_cards.target_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Fetch non-reset ReviewLog rows for the given card ids, newest-first.
     *
     * Card-scoped path: user/language isolation is inherent (a ReviewCard
     * belongs to exactly one user/language). Reset exclusion is delegated
     * to SenseReviewQueryService::nonResetCardReviewLogQuery.
     *
     * Query count: exactly 1 ReviewLog query regardless of how many card
     * ids are passed (0 when the list is empty).
     *
     * @param  array<int>  $cardIds
     * @return Collection  Each row: id, review_card_id, rating, reviewed_at.
     */
    public function reviewsForCards(array $cardIds): Collection
    {
        $ids = [];
        foreach ($cardIds as $id) {
            $id = (int) $id;
            if ($id > 0 && !isset($ids[$id])) {
                $ids[$id] = $id;
            }
        }

        if (empty($ids)) {
            return collect();
        }

        return $this->senseReviewQueryService
            ->nonResetCardReviewLogQuery(array_values($ids))
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->get(['id', 'review_card_id', 'rating', 'reviewed_at']);
    }

    /**
     * Compute the rating distribution from a log collection.
     *
     * Pure computation — no database access. Accepts any collection of
     * objects with a `rating` property (e.g. the rows from
     * reviewsForPeriod / reviewsForCards).
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
            'hard' => $hard,
            'good' => $good,
            'easy' => $easy,
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
}
