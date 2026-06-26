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
    private ?FsrsRescheduleSnapshotService $snapshotService = null;

    protected function getMaxNewlyDueToday(): int { return 200; }
    protected function getMaxTotalChanged(): int { return 2000; }

    public function __construct(?FsrsSchedulingService $fsrsSchedulingService = null, ?FsrsRescheduleSnapshotService $snapshotService = null)
    {
        $this->fsrsSchedulingService = $fsrsSchedulingService ?? new FsrsSchedulingService();
        $this->snapshotService = $snapshotService ?? new FsrsRescheduleSnapshotService();
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
            $response = $this->unsupportedLanguageResponse($language);
            $response['preview_hash'] = null;
            return $response;
        }

        $data = $this->computePreviewData($userId, $language);
        if ($data === null) {
            $response = $this->unavailableResponse($language, 'FSRS 扩展未加载，无法预览。扩展要求：fsrs-rs-php。');
            $response['preview_hash'] = null;
            return $response;
        }

        $previewHash = $this->buildPreviewHash($data['cards'], $language, $userId, $data['activeParams'], $data['desiredRetention']);

        if ($data['summary']['total_candidates'] === 0) {
            $response = $this->emptyPreviewResponse($userId, $language);
            $response['preview_hash'] = $previewHash;
            return $response;
        }

        $samples = array_slice($data['previewRows'], 0, 20);

        return [
            'success' => true,
            'preview_available' => true,
            'preview_hash' => $previewHash,
            'language' => $language,
            'target_type' => 'sense',
            'total_candidates' => $data['summary']['total_candidates'],
            'total_changed' => $data['summary']['total_changed'],
            'skipped_count' => $data['summary']['skipped_count'],
            'summary' => [
                'will_move_earlier' => $data['summary']['will_move_earlier'],
                'will_move_later' => $data['summary']['will_move_later'],
                'unchanged' => $data['summary']['unchanged'],
                'currently_due' => $data['summary']['currently_due'],
                'newly_due_today' => $data['summary']['newly_due_today'],
                'due_today_after_reschedule' => $data['summary']['due_today_after_reschedule'],
                'max_earlier_days' => $data['summary']['max_earlier_days'],
                'max_later_days' => $data['summary']['max_later_days'],
            ],
            'samples' => $samples,
            'warnings' => [
                '这是预览，不会修改任何卡片。',
                '正式重排可能让更多卡片今天到期。',
            ],
        ];
    }

    public function confirmPreflight(int $userId, string $language, ?string $previewHash, bool $confirmed): array
    {
        if ($language !== 'english') {
            return ['success' => true, 'confirm_available' => false, 'write_enabled' => false, 'message' => '当前阶段只支持英语卡片重排确认。'];
        }
        if (empty($previewHash)) {
            return ['success' => false, 'confirm_available' => false, 'write_enabled' => false, 'message' => '缺少 preview_hash，无法确认预览。'];
        }
        if (!$confirmed) {
            return ['success' => false, 'confirm_available' => false, 'write_enabled' => false, 'message' => '缺少确认标志（confirm=true 必填）。'];
        }
        $data = $this->computePreviewData($userId, $language);
        if ($data === null) {
            return ['success' => true, 'confirm_available' => false, 'write_enabled' => false, 'message' => 'FSRS 扩展未加载，无法确认。扩展要求：fsrs-rs-php。'];
        }
        $freshHash = $this->buildPreviewHash($data['cards'], $language, $userId, $data['activeParams'], $data['desiredRetention']);
        if ($freshHash !== $previewHash) {
            return ['success' => false, 'confirm_available' => false, 'write_enabled' => false, 'message' => '预览已过期，请重新获取预览后再确认。', 'preview_hash' => $freshHash];
        }
        $newlyDueToday = $data['summary']['newly_due_today'];
        $totalChanged = $data['summary']['total_changed'];
        if ($newlyDueToday > $this->getMaxNewlyDueToday()) {
            return [
                'success' => false,
                'confirm_available' => false,
                'write_enabled' => false,
                'risk_level' => 'high',
                'requires_risk_confirm' => true,
                'message' => "本次重排会新增 {$newlyDueToday} 张今天到期卡。请二次确认后继续。",
            ];
        }
        if ($totalChanged > $this->getMaxTotalChanged()) {
            return ['success' => false, 'confirm_available' => false, 'write_enabled' => false, 'risk_level' => 'blocked', 'requires_risk_confirm' => false, 'message' => "受影响卡片总数（{$totalChanged}）超过系统稳定性上限（" . $this->getMaxTotalChanged() . "），已拒绝执行。"];
        }
        return [
            'success' => true, 'confirm_available' => true, 'write_enabled' => false,
            'message' => '预览仍然有效。',
            'total_candidates' => $data['summary']['total_candidates'], 'total_changed' => $data['summary']['total_changed'],
            'skipped_count' => $data['summary']['skipped_count'],
            'summary' => [
                'will_move_earlier' => $data['summary']['will_move_earlier'], 'will_move_later' => $data['summary']['will_move_later'],
                'unchanged' => $data['summary']['unchanged'], 'currently_due' => $data['summary']['currently_due'],
                'newly_due_today' => $data['summary']['newly_due_today'], 'due_today_after_reschedule' => $data['summary']['due_today_after_reschedule'],
                'max_earlier_days' => $data['summary']['max_earlier_days'], 'max_later_days' => $data['summary']['max_later_days'],
            ],
            'preview_hash' => $freshHash,
        ];
    }

    public function confirmAndApply(int $userId, string $language, ?string $previewHash, bool $confirmed, bool $riskConfirmed = false): array
    {
        if ($language !== 'english') {
            return ['success' => true, 'confirm_available' => false, 'write_enabled' => false, 'message' => '当前阶段只支持英语卡片重排确认。'];
        }
        if (empty($previewHash)) {
            return ['success' => false, 'confirm_available' => false, 'write_enabled' => false, 'message' => '缺少 preview_hash，无法确认预览。'];
        }
        if (!$confirmed) {
            return ['success' => false, 'confirm_available' => false, 'write_enabled' => false, 'message' => '缺少确认标志（confirm=true 必填）。'];
        }
        $data = $this->computePreviewData($userId, $language);
        if ($data === null) {
            return ['success' => true, 'confirm_available' => false, 'write_enabled' => false, 'message' => 'FSRS 扩展未加载，无法确认。扩展要求：fsrs-rs-php。'];
        }
        $freshHash = $this->buildPreviewHash($data['cards'], $language, $userId, $data['activeParams'], $data['desiredRetention']);
        if ($freshHash !== $previewHash) {
            return ['success' => false, 'confirm_available' => false, 'write_enabled' => false, 'message' => '预览已过期，请重新获取预览后再确认。', 'preview_hash' => $freshHash];
        }
        $newlyDueToday = $data['summary']['newly_due_today'];
        $totalChanged = $data['summary']['total_changed'];
        if ($newlyDueToday > $this->getMaxNewlyDueToday() && $riskConfirmed !== true) {
            return [
                'success' => false,
                'confirm_available' => false,
                'write_enabled' => false,
                'risk_level' => 'high',
                'requires_risk_confirm' => true,
                'message' => "本次重排会新增 {$newlyDueToday} 张今天到期卡。请二次确认后继续。",
            ];
        }
        if ($totalChanged > $this->getMaxTotalChanged()) {
            return [
                'success' => false,
                'risk_level' => 'blocked',
                'requires_risk_confirm' => false,
                'message' => "受影响卡片总数（{$totalChanged}）超过系统稳定性上限（" . $this->getMaxTotalChanged() . "），已拒绝执行。",
            ];
        }

        $candidateIds = $data['cards']->pluck('review_card_id')->toArray();
        $appliedCount = 0;
        $skippedCount = 0;
        $newlyDueCount = 0;
        $now = Carbon::now();
        $snapshotBatchId = null;

        \DB::transaction(function () use ($userId, $language, $previewHash, $candidateIds, $data, $now, &$appliedCount, &$skippedCount, &$newlyDueCount, &$snapshotBatchId) {
            $lockedCards = ReviewCard::whereIn('id', $candidateIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $snapshotItems = [];

            foreach ($candidateIds as $cardId) {
                $card = $lockedCards->get($cardId);
                if (!$card) {
                    $skippedCount++;
                    continue;
                }
                if (
                    $card->user_id !== $userId
                    || $card->language_id !== $language
                    || $card->target_type !== ReviewCard::TARGET_SENSE
                    || $card->fsrs_state !== 'review'
                    || $card->fsrs_enabled !== true
                    || $card->fsrs_stability === null
                    || $card->fsrs_difficulty === null
                    || $card->fsrs_last_reviewed_at === null
                    || $card->fsrs_due_at === null
                ) {
                    $skippedCount++;
                    continue;
                }

                $cardDto = new \stdClass();
                $cardDto->review_card_id = (int) $card->id;
                $cardDto->word_sense_id = (int) $card->target_id;
                $cardDto->lemma = '';
                $cardDto->sense_zh = '';
                $cardDto->fsrs_stability = $card->fsrs_stability;
                $cardDto->fsrs_difficulty = $card->fsrs_difficulty;
                $cardDto->fsrs_last_reviewed_at = $card->fsrs_last_reviewed_at;
                $cardDto->fsrs_due_at = $card->fsrs_due_at;
                $cardDto->fsrs_state = $card->fsrs_state;
                $cardDto->fsrs_enabled = $card->fsrs_enabled;

                $preview = $this->buildPreviewForCard($cardDto, $data['activeParams'], $data['desiredRetention'], $now);
                if ($preview === null) {
                    $skippedCount++;
                    continue;
                }

                $snapshotItems[] = [
                    'review_card_id' => $card->id,
                    'previous_due_at' => $card->getOriginal('fsrs_due_at'),
                    'previous_stability' => $card->getOriginal('fsrs_stability'),
                    'previous_difficulty' => $card->getOriginal('fsrs_difficulty'),
                    'new_due_at' => $preview['preview_due_at'],
                    'new_stability' => $preview['new_fsrs_stability'],
                    'new_difficulty' => $preview['new_fsrs_difficulty'],
                ];

                $card->fsrs_due_at = $preview['preview_due_at'];
                $card->fsrs_stability = $preview['new_fsrs_stability'];
                $card->fsrs_difficulty = $preview['new_fsrs_difficulty'];
                $card->save();

                $appliedCount++;
                if ($preview['is_newly_due_today']) {
                    $newlyDueCount++;
                }
            }

            if ($appliedCount > 0) {
                $snapshot = $this->snapshotService->createSnapshotForAppliedCards(
                    $userId,
                    $language,
                    $previewHash,
                    [
                        'total_cards' => count($candidateIds),
                        'applied_count' => $appliedCount,
                        'skipped_count' => $skippedCount,
                        'newly_due_today' => $newlyDueCount,
                    ],
                    $snapshotItems
                );
                $snapshotBatchId = $snapshot->batch_id;
            }
        });

        return [
            'success' => true,
            'applied' => true,
            'write_enabled' => true,
            'message' => "已重排 {$appliedCount} 张卡片，其中 {$newlyDueCount} 张今天到期。",
            'applied_count' => $appliedCount,
            'newly_due_today' => $newlyDueCount,
            'total_changed' => $totalChanged,
            'skipped_count' => $skippedCount,
            'snapshot_batch_id' => $snapshotBatchId ?? null,
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
            $goodMemory = $goodState->get_memory();
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
            'new_fsrs_stability' => (float) $goodMemory->get_stability(),
            'new_fsrs_difficulty' => (float) $goodMemory->get_difficulty(),
            'fsrs_last_reviewed_at' => $lastReview->toIso8601String(),
            'is_newly_due_today' => $isNewlyDueToday,
        ];
    }

    private function buildPreviewHash(\Illuminate\Support\Collection $cards, string $language, int $userId, array $activeParams, float $desiredRetention): string
    {
        $sortedCards = $cards->sortBy('review_card_id')->values();
        $cardsArray = $sortedCards->map(function ($card) {
            $dueAt = $card->fsrs_due_at instanceof Carbon
                ? $card->fsrs_due_at->toIso8601String()
                : ($card->fsrs_due_at !== null ? Carbon::parse($card->fsrs_due_at)->toIso8601String() : null);
            $lastReviewedAt = $card->fsrs_last_reviewed_at instanceof Carbon
                ? $card->fsrs_last_reviewed_at->toIso8601String()
                : ($card->fsrs_last_reviewed_at !== null ? Carbon::parse($card->fsrs_last_reviewed_at)->toIso8601String() : null);
            return [
                'review_card_id' => (int) $card->review_card_id,
                'word_sense_id' => (int) $card->word_sense_id,
                'fsrs_due_at' => $dueAt,
                'fsrs_stability' => (float) $card->fsrs_stability,
                'fsrs_difficulty' => (float) $card->fsrs_difficulty,
                'fsrs_last_reviewed_at' => $lastReviewedAt,
                'fsrs_state' => $card->fsrs_state,
                'fsrs_enabled' => (bool) $card->fsrs_enabled,
            ];
        })->toArray();
        $parametersHash = hash('sha256', json_encode(array_values($activeParams), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $payload = [
            'user_id' => $userId,
            'language' => $language,
            'desired_retention' => $desiredRetention,
            'parameters_hash' => $parametersHash,
            'cards' => $cardsArray,
        ];
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function computePreviewData(int $userId, string $language): ?array
    {
        if (!$this->extensionAvailable()) {
            return null;
        }

        $activeParams = $this->fsrsSchedulingService->getActiveFsrsParameters();
        $cards = $this->candidateCardsQuery($userId, $language)->get();
        $desiredRetention = $this->fsrsSchedulingService->desiredRetention();
        $now = Carbon::now();

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

        usort($previewRows, fn ($a, $b) => abs($b['days_change']) <=> abs($a['days_change']));

        $totalCandidates = $cards->count();
        $totalChanged = $willMoveEarlier + $willMoveLater;
        $dueTodayAfter = $currentlyDue + $newlyDueToday;

        return [
            'cards' => $cards,
            'previewRows' => $previewRows,
            'activeParams' => $activeParams,
            'desiredRetention' => $desiredRetention,
            'summary' => [
                'will_move_earlier' => $willMoveEarlier,
                'will_move_later' => $willMoveLater,
                'unchanged' => $unchanged,
                'currently_due' => $currentlyDue,
                'newly_due_today' => $newlyDueToday,
                'due_today_after_reschedule' => $dueTodayAfter,
                'max_earlier_days' => $maxEarlierDays < 0 ? $maxEarlierDays : 0,
                'max_later_days' => $maxLaterDays,
                'skipped_count' => $skippedCount,
                'total_candidates' => $totalCandidates,
                'total_changed' => $totalChanged,
            ],
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
