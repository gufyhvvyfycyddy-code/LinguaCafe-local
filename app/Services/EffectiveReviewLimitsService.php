<?php

namespace App\Services;

use Carbon\Carbon;

class EffectiveReviewLimitsService
{
    public function __construct(
        private SettingsService $settingsService,
        private ReviewDailyLimitOverrideService $overrideService,
        private ReviewDailyProgressQueryService $progressQueryService,
        private ReviewStudyTimezoneService $studyTimezoneService,
    ) {
    }

    public function resolve(int $userId, string $language, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $permanent = $this->settingsService->getFsrsDailyLimits();
        $override = $this->overrideService->current($userId, $language, $now);
        $progress = $this->progressQueryService->counts($userId, $language, $now);

        $permanentNew = (int) ($permanent['daily_new_limit'] ?? 20);
        $permanentReview = (int) ($permanent['daily_review_limit'] ?? 200);
        // ponytail: legacy rows may hold negative deltas (pre-contract schema); normalize on read,
        // never write back. Clients receive canonical non-negative values; next save overwrites.
        $newDelta = max(0, (int) ($override?->new_limit_delta ?? 0));
        $reviewDelta = max(0, (int) ($override?->review_limit_delta ?? 0));
        $paused = (bool) ($override?->pause_new_cards ?? false);
        $effectiveNew = $paused ? 0 : $permanentNew + $newDelta;
        $effectiveReview = $permanentReview + $reviewDelta;

        return [
            'study_date' => $this->studyTimezoneService->localDate($now),
            'timezone' => $this->studyTimezoneService->getStudyTimezone(),
            'permanent_new_limit' => $permanentNew,
            'permanent_review_limit' => $permanentReview,
            'permanent_new_limit_enabled' => (bool) ($permanent['daily_new_limit_enabled'] ?? true),
            'permanent_review_limit_enabled' => (bool) ($permanent['daily_review_limit_enabled'] ?? true),
            'new_cards_ignore_review_limit' => (bool) ($permanent['new_cards_ignore_review_limit'] ?? false),
            'override' => $override ? [
                'new_limit_delta' => $newDelta,
                'review_limit_delta' => $reviewDelta,
                'pause_new_cards' => $paused,
            ] : null,
            'pause_new_cards' => $paused,
            'effective_new_limit' => $effectiveNew,
            'effective_review_limit' => $effectiveReview,
            'effective_new_limit_enabled' => $paused || (bool) ($permanent['daily_new_limit_enabled'] ?? true),
            'effective_review_limit_enabled' => (bool) ($permanent['daily_review_limit_enabled'] ?? true),
            'reviewed_today_count' => $progress['reviewed_today_count'],
            'introduced_today_count' => $progress['introduced_today_count'],
            'remaining_new' => max(0, $effectiveNew - $progress['introduced_today_count']),
            'remaining_review' => max(0, $effectiveReview - $progress['reviewed_today_count']),
        ];
    }
}
