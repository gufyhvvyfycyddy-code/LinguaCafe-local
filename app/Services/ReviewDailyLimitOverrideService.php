<?php

namespace App\Services;

use App\Models\ReviewDailyLimitOverride;
use Carbon\Carbon;

class ReviewDailyLimitOverrideService
{
    public function __construct(private ReviewStudyTimezoneService $studyTimezoneService)
    {
    }

    public function current(int $userId, string $language, ?Carbon $now = null): ?ReviewDailyLimitOverride
    {
        $studyDate = $this->studyTimezoneService->localDate($now ?? Carbon::now());

        return ReviewDailyLimitOverride::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('study_date', $studyDate)
            ->first();
    }

    public function save(int $userId, string $language, array $attributes, ?Carbon $now = null): ReviewDailyLimitOverride
    {
        $studyDate = $this->studyTimezoneService->localDate($now ?? Carbon::now());

        return ReviewDailyLimitOverride::query()->updateOrCreate(
            ['user_id' => $userId, 'language_id' => $language, 'study_date' => $studyDate],
            [
                'new_limit_delta' => $attributes['new_limit_delta'],
                'review_limit_delta' => $attributes['review_limit_delta'],
                'pause_new_cards' => $attributes['pause_new_cards'],
            ]
        );
    }

    public function deleteCurrent(int $userId, string $language, ?Carbon $now = null): void
    {
        $override = $this->current($userId, $language, $now);
        $override?->delete();
    }
}
