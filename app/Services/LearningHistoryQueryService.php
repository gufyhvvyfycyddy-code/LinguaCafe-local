<?php

namespace App\Services;

use App\Models\WordSense;
use Carbon\Carbon;

class LearningHistoryQueryService
{
    public function __construct(private ReviewStudyTimezoneService $timezoneService)
    {
    }

    public function countReadingSensesStartedToday(
        int $userId,
        string $language,
        ?Carbon $now = null,
    ): int {
        $bounds = $this->timezoneService->dayBounds($now ?: Carbon::now());

        return WordSense::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('learning_started_origin', WordSense::LEARNING_ORIGIN_READING)
            ->where('learning_started_at', '>=', $bounds['day_start'])
            ->where('learning_started_at', '<', $bounds['next_day_start'])
            ->count();
    }
}
