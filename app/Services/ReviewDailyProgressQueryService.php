<?php

namespace App\Services;

use App\Models\ReviewCard;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReviewDailyProgressQueryService
{
    public function __construct(
        private SenseReviewQueryService $senseReviewQueryService,
        private ReviewStudyTimezoneService $studyTimezoneService,
    ) {
    }

    public function counts(int $userId, string $language, ?Carbon $now = null): array
    {
        $bounds = $this->studyTimezoneService->dayBounds($now ?? Carbon::now());
        $start = $bounds['day_start'];
        $end = $bounds['next_day_start'];

        $reviewed = $this->senseReviewQueryService
            ->nonResetSenseReviewLogQuery($userId, $language, $start)
            ->where('review_logs.reviewed_at', '<', $end)
            ->count('review_logs.id');

        $introduced = DB::table('review_logs as candidate')
            ->join('review_cards as cards', 'cards.id', '=', 'candidate.review_card_id')
            ->where('candidate.user_id', $userId)
            ->where('candidate.language_id', $language)
            ->where('cards.user_id', $userId)
            ->where('cards.language_id', $language)
            ->where('cards.target_type', ReviewCard::TARGET_SENSE)
            ->where('candidate.source', 'sense_review')
            ->where('candidate.rating', '!=', 'reset')
            ->whereNull('candidate.undone_at')
            ->where('candidate.previous_state', 'new')
            ->where('candidate.reviewed_at', '>=', $start)
            ->where('candidate.reviewed_at', '<', $end)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('review_logs as earlier')
                    ->whereColumn('earlier.review_card_id', 'candidate.review_card_id')
                    ->whereColumn('earlier.id', '<', 'candidate.id')
                    ->where('earlier.source', 'sense_review')
                    ->where('earlier.rating', '!=', 'reset')
                    ->whereNull('earlier.undone_at');
            })
            ->distinct()
            ->count('candidate.review_card_id');

        return [
            'reviewed_today_count' => $reviewed,
            'introduced_today_count' => $introduced,
        ];
    }
}
