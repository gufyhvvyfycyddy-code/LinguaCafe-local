<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\WordSense;
use Carbon\Carbon;

/**
 * D.4-a: Read-only preview of FSRS reschedule impact.
 *
 * Computes what would happen if all eligible cards were rescheduled
 * using the currently active FSRS parameters and a simulated "Good" rating.
 * Does NOT write to the database, create ReviewLog entries, or modify
 * any ReviewCard records.
 */
class FsrsReschedulePreviewService
{
    private FsrsSchedulingService $fsrsSchedulingService;

    public function __construct(?FsrsSchedulingService $fsrsSchedulingService = null)
    {
        $this->fsrsSchedulingService = $fsrsSchedulingService ?? new FsrsSchedulingService();
    }

    /**
     * Compute a read-only preview of rescheduling eligible cards.
     *
     * @param int    $userId   Current user ID.
     * @param string $language Language code (must be 'english' for D.4-a).
     *
     * @return array Structured preview response.
     */
    public function preview(int $userId, string $language): array
    {
        if ($language !== 'english') {
            return $this->unsupportedLanguageResponse($language);
        }

        // 1) Check fsrs-rs-php extension availability
        if (!$this->extensionAvailable()) {
            return $this->unavailableResponse($language, 'FSRS 扩展未加载，无法预览。扩展要求：fsrs-rs-php。');
        }

        $activeParams = $this->fsrsSchedulingService->getActiveFsrsParameters();

        // 2) Build candidate query
        $cards = $this->candidateCardsQuery($userId, $language)->get();

        if ($cards->isEmpty()) {
            return $this->emptyPreviewResponse($userId, $language);
        }

        $desiredRetention = $this->fsrsSchedulingService->desiredRetention();
        $now = Carbon::now();

        // 3) Compute preview for each card
        $skippedCount = 0;
        $previewRows = [];
        $willMoveEarlier = 0;
        $willMoveLater = 0;
        $unchanged = 0;
        $currentlyDue = 0;
        $newlyDueToday = 0;
        $maxEarlierDays = 0;
        $maxLaterDays = 0;

        foreach ($cards as $card) {
            $row = $this->buildPreviewForCard($card, $activeParams, $desiredRetention, $now);
            if ($row === null) {
                $skippedCount++;
                continue;
            }

            $previewRows[] = $row;

            if ($row['days_change'] < 0) {
                $willMoveEarlier++;
                $maxEarlierDays = min($maxEarlierDays, $row['days_change']);
            } elseif ($row['days_change'] > 0) {
                $willMoveLater++;
                $maxLaterDays = max($maxLaterDays, $row['days_change']);
            } else {
                $unchanged++;
            }

            if ($card->fsrs_due_at !== null && $card->fsrs_due_at->lte($now)) {
                $currentlyDue++;
            }

            if ($row['is_newly_due_today']) {
                $newlyDueToday++;
            }
        }

        // 4) Sort samples by |days_change| descending, take top 20
        usort($previewRows, fn ($a, $b) => abs($b['days_change']) <=> abs($a['days_change']));
        $samples = array_slice($previewRows, 0, 20);

        $totalCandidates = $cards->count();
        $totalChanged = $willMoveEarlier + $willMoveLater;
        $dueTodayAfter = $currentlyDue + $newlyDueToday;

        return [
            'success' => true,
            'preview_available' => true,
            'language' => $language,
            'target_type' => 'sense',
            'total_candidates' => $totalCandidates,
            'total_changed' => $totalChanged,
            'skipped_count' => $skippedCount,
            'summary' => [
                'will_move_earlier' => $willMoveEarlier,
                'will_move_later' => $willMoveLater,
                'unchanged' => $unchanged,
                'currently_due' => $currentlyDue,
                'newly_due_today' => $newlyDueToday,
                'due_today_after_reschedule' => $dueTodayAfter,
                'max_earlier_days' => $maxEarlierDays < 0 ? $maxEarlierDays : 0,
                'max_later_days' => $maxLaterDays,
            ],
            'samples' => $samples,
            'warnings' => [
                '这是预览，不会修改任何卡片。',
                '正式重排可能让更多卡片今天到期。',
            ],
        ];
    }

    /**
     * Build the query for eligible candidate cards.
     *
     * Conditions:
     * - target_type = sense
     * - fsrs_state = review
     * - fsrs_enabled = true
     * - fsrs_stability IS NOT NULL
     * - fsrs_difficulty IS NOT NULL
     * - fsrs_last_reviewed_at IS NOT NULL
     * - WordSense is confirmed
     * - Excludes word, phrase, new, learning, relearning
     */
    private function candidateCardsQuery(int $userId, string $language)
    {
        return ReviewCard::query()
            ->join('word_senses', function ($join) {
                $join->on('word_senses.id', '=', 'review_cards.target_id')
                    ->where('review_cards.target_type', ReviewCard::TARGET_SENSE);
            })
            ->where('review_cards.user_id', $userId)
            ->where('review_cards.language_id', $language)
            ->where('review_cards.target_type', ReviewCard::TARGET_SENSE)
            ->where('review_cards.fsrs_state', 'review')
            ->where('review_cards.fsrs_enabled', true)
            ->whereNotNull('review_cards.fsrs_stability')
            ->whereNotNull('review_cards.fsrs_difficulty')
            ->whereNotNull('review_cards.fsrs_last_reviewed_at')
            ->whereNotNull('review_cards.fsrs_due_at')
            ->where('word_senses.user_id', $userId)
            ->where('word_senses.language_id', $language)
            ->where('word_senses.status', WordSense::STATUS_CONFIRMED)
            ->select([
                'review_cards.id as review_card_id',
                'review_cards.target_id as word_sense_id',
                'review_cards.fsrs_state',
                'review_cards.fsrs_due_at',
                'review_cards.fsrs_stability',
                'review_cards.fsrs_difficulty',
                'review_cards.fsrs_last_reviewed_at',
                'review_cards.fsrs_reps',
                'review_cards.fsrs_lapses',
                'word_senses.lemma',
                'word_senses.sense_zh',
                'word_senses.sense_en',
            ]);
    }

    /**
     * Compute the preview row for a single card.
     *
     * Uses fsrs-rs-php next_states() with a simulated "Good" rating
     * to determine the new interval. Does NOT write back to the database.
     *
     * @param object $card             Eloquent model/stdClass with ReviewCard + WordSense fields
     * @param array  $activeParams     Current FSRS parameters
     * @param float  $desiredRetention Current desired retention
     * @param Carbon $now              Current timestamp
     *
     * @return array|null Null if computation fails or days_elapsed is invalid
     */
    private function buildPreviewForCard($card, array $activeParams, float $desiredRetention, Carbon $now): ?array
    {
        if ($card->fsrs_stability === null || $card->fsrs_difficulty === null) {
            return null;
        }

        $elapsedDays = 0;
        if ($card->fsrs_last_reviewed_at !== null) {
            $lastReview = $card->fsrs_last_reviewed_at instanceof Carbon
                ? $card->fsrs_last_reviewed_at
                : Carbon::parse($card->fsrs_last_reviewed_at);
            $elapsedDays = (int) max(0, $lastReview->diffInDays($now));
        }

        try {
            $memory = new \fsrs\MemoryState(
                (float) $card->fsrs_stability,
                (float) $card->fsrs_difficulty
            );
            $fsrs = new \fsrs\FSRS($activeParams);
            $states = $fsrs->next_states($memory, $desiredRetention, $elapsedDays);
            $goodState = $states->get_good();
            $newInterval = max(1, (int) round($goodState->get_interval()));
        } catch (\Throwable $e) {
            return null;
        }

        $currentDueAt = $card->fsrs_due_at instanceof Carbon
            ? $card->fsrs_due_at
            : ($card->fsrs_due_at !== null ? Carbon::parse($card->fsrs_due_at) : null);

        $lastReview = $card->fsrs_last_reviewed_at instanceof Carbon
            ? $card->fsrs_last_reviewed_at
            : Carbon::parse($card->fsrs_last_reviewed_at);

        $previewDueAt = $lastReview->copy()->addDays($newInterval);

        // days_change is the difference between preview_due_at and current_due_at
        $daysChange = 0;
        if ($currentDueAt !== null) {
            $daysChange = $previewDueAt->diffInDays($currentDueAt, false);
            if ($previewDueAt->greaterThan($currentDueAt)) {
                $daysChange = $currentDueAt->diffInDays($previewDueAt, false);
            } else {
                $daysChange = -$previewDueAt->diffInDays($currentDueAt, false);
            }
        }

        $isNewlyDueToday = $currentDueAt !== null
            && $currentDueAt->gt($now)
            && $previewDueAt->lte($now);

        return [
            'review_card_id' => (int) $card->review_card_id,
            'word_sense_id' => (int) $card->word_sense_id,
            'lemma' => $card->lemma ?? '',
            'sense_zh' => $card->sense_zh ?? '',
            'current_due_at' => $currentDueAt?->toIso8601String(),
            'preview_due_at' => $previewDueAt->toIso8601String(),
            'days_change' => $daysChange,
            'fsrs_stability' => (float) $card->fsrs_stability,
            'fsrs_difficulty' => (float) $card->fsrs_difficulty,
            'fsrs_last_reviewed_at' => $lastReview->toIso8601String(),
            'is_newly_due_today' => $isNewlyDueToday,
        ];
    }

    private function extensionAvailable(): bool
    {
        return extension_loaded('fsrs-rs-php')
            && class_exists('\fsrs\FSRS')
            && function_exists('get_default_parameters');
    }

    private function unavailableResponse(string $language, string $message): array
    {
        return [
            'success' => true,
            'preview_available' => false,
            'language' => $language,
            'target_type' => 'sense',
            'total_candidates' => 0,
            'total_changed' => 0,
            'skipped_count' => 0,
            'summary' => [
                'will_move_earlier' => 0,
                'will_move_later' => 0,
                'unchanged' => 0,
                'currently_due' => 0,
                'newly_due_today' => 0,
                'due_today_after_reschedule' => 0,
                'max_earlier_days' => 0,
                'max_later_days' => 0,
            ],
            'samples' => [],
            'warnings' => [
                $message,
            ],
        ];
    }

    private function emptyPreviewResponse(int $userId, string $language): array
    {
        return [
            'success' => true,
            'preview_available' => true,
            'language' => $language,
            'target_type' => 'sense',
            'total_candidates' => 0,
            'total_changed' => 0,
            'skipped_count' => 0,
            'summary' => [
                'will_move_earlier' => 0,
                'will_move_later' => 0,
                'unchanged' => 0,
                'currently_due' => 0,
                'newly_due_today' => 0,
                'due_today_after_reschedule' => 0,
                'max_earlier_days' => 0,
                'max_later_days' => 0,
            ],
            'samples' => [],
            'warnings' => [
                '这是预览，不会修改任何卡片。',
                '当前没有符合条件的卡片。确认条件：sense card + review 状态 + 已 confirmed WordSense + 有 FSRS 记忆状态。',
            ],
        ];
    }

    private function unsupportedLanguageResponse(string $language): array
    {
        return [
            'success' => true,
            'preview_available' => false,
            'language' => $language,
            'target_type' => 'sense',
            'total_candidates' => 0,
            'total_changed' => 0,
            'skipped_count' => 0,
            'summary' => [
                'will_move_earlier' => 0,
                'will_move_later' => 0,
                'unchanged' => 0,
                'currently_due' => 0,
                'newly_due_today' => 0,
                'due_today_after_reschedule' => 0,
                'max_earlier_days' => 0,
                'max_later_days' => 0,
            ],
            'samples' => [],
            'warnings' => [
                '当前阶段只支持英语卡片重排预览。',
            ],
        ];
    }
}
