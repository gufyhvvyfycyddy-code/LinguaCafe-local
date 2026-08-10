<?php

namespace App\Services;

use App\Models\ReadingSession;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class HomeStudySummaryQueryService
{
    // Query history in chunks only; keep walking backward until the first inactive day.
    private const STREAK_BATCH_DAYS = 31;

    public function __construct(
        private ReviewDailyProgressQueryService $dailyProgress,
        private SenseReviewQueryService $senseReviewQuery,
        private ReviewStudyTimezoneService $studyTimezone,
        private ReadingChapterTextService $chapterText,
    ) {
    }

    public function build(int $userId, string $language, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $bounds = $this->studyTimezone->dayBounds($now);
        $reviewProgress = $this->dailyProgress->counts($userId, $language, $now);
        $reviewedCount = (int) $reviewProgress['reviewed_today_count'];
        $readingCompletedCount = $this->readingCompletionQuery($userId, $language)
            ->where('reading_sessions.completed_at', '>=', $bounds['day_start'])
            ->where('reading_sessions.completed_at', '<', $bounds['next_day_start'])
            ->count('reading_session_completions.id');
        $reviewDueCount = $this->senseReviewQuery
            ->confirmedSenseCardQuery($userId, $language)
            ->senseReviewEligible($userId, $language, $now)
            ->where('review_cards.fsrs_due_at', '<=', $now)
            ->count('review_cards.id');
        $checkedIn = $reviewedCount > 0 || $readingCompletedCount > 0;

        return [
            'study_date' => $bounds['study_date'],
            'timezone' => $bounds['timezone'],
            'streak_days' => $this->streakDays($userId, $language, $bounds, $checkedIn),
            'today' => [
                'reviewed_count' => $reviewedCount,
                'reading_completed_count' => $readingCompletedCount,
                'review_due_count' => $reviewDueCount,
                'checked_in' => $checkedIn,
            ],
            'continue_learning' => $this->continueLearning($userId, $language, $reviewDueCount),
            'generated_at' => $now->copy()->utc()->toIso8601String(),
        ];
    }

    private function streakDays(int $userId, string $language, array $todayBounds, bool $todayActive): int
    {
        $anchor = $todayBounds['day_start']->copy();
        if (!$todayActive) {
            $anchor->subDay()->startOfDay();
        }

        $streak = 0;

        while (true) {
            $blockStart = $anchor->copy()->subDays(self::STREAK_BATCH_DAYS - 1)->startOfDay();
            $blockEnd = $anchor->copy()->addDay()->startOfDay();
            $activityDates = $this->activityDatesForRange(
                $userId,
                $language,
                $blockStart,
                $blockEnd,
                $todayBounds['timezone'],
            );

            for ($offset = 0; $offset < self::STREAK_BATCH_DAYS; $offset++) {
                $date = $anchor->copy()->subDays($offset)->format('Y-m-d');
                if (!isset($activityDates[$date])) {
                    return $streak;
                }

                $streak++;
            }

            $anchor = $blockStart->copy()->subDay()->startOfDay();
        }
    }

    /** @return array<string, true> */
    private function activityDatesForRange(
        int $userId,
        string $language,
        Carbon $start,
        Carbon $end,
        string $timezone,
    ): array {
        $dates = [];

        $reviewLogs = $this->senseReviewQuery
            ->nonResetSenseReviewLogQuery($userId, $language, $start)
            ->where('review_logs.reviewed_at', '<', $end)
            ->get(['review_logs.reviewed_at']);
        foreach ($reviewLogs as $reviewLog) {
            if ($reviewLog->reviewed_at) {
                $dates[$reviewLog->reviewed_at->copy()->tz($timezone)->format('Y-m-d')] = true;
            }
        }

        $readingSessions = $this->readingCompletionQuery($userId, $language)
            ->where('reading_sessions.completed_at', '>=', $start)
            ->where('reading_sessions.completed_at', '<', $end)
            ->get(['reading_sessions.completed_at']);
        foreach ($readingSessions as $session) {
            if ($session->completed_at) {
                $dates[$session->completed_at->copy()->tz($timezone)->format('Y-m-d')] = true;
            }
        }

        return $dates;
    }

    private function readingCompletionQuery(int $userId, string $language): Builder
    {
        return ReadingSession::query()
            ->join(
                'reading_session_completions',
                'reading_session_completions.reading_session_id',
                '=',
                'reading_sessions.id',
            )
            ->where('reading_sessions.user_id', $userId)
            ->where('reading_sessions.language_id', $language)
            ->where('reading_session_completions.user_id', $userId)
            ->where('reading_session_completions.language_id', $language)
            ->whereNotNull('reading_sessions.completed_at');
    }

    /** @return array{kind:string, href:string} */
    private function continueLearning(int $userId, string $language, int $reviewDueCount): array
    {
        if ($reviewDueCount > 0) {
            return [
                'kind' => 'review',
                'href' => '/reviews/senses',
            ];
        }

        foreach (ReadingSession::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', ReadingSession::STATUS_ACTIVE)
            ->whereNotNull('started_at')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->cursor() as $session) {
            try {
                $chapter = $this->chapterText->chapterForUser(
                    $userId,
                    $language,
                    (int) $session->chapter_id,
                );
            } catch (\InvalidArgumentException) {
                continue;
            }

            $currentRevision = $this->chapterText->sourceRevision($chapter);
            if (hash_equals((string) $session->source_revision, $currentRevision)) {
                return [
                    'kind' => 'reading',
                    'href' => '/chapters/read/' . (int) $session->chapter_id,
                ];
            }
        }

        return [
            'kind' => 'library',
            'href' => '/books',
        ];
    }
}
