<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\MobileApiResponse;
use App\Services\ReviewDailyProgressQueryService;
use App\Services\SenseReviewQueryService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MobileSummaryController extends Controller
{
    public function __construct(
        private ReviewDailyProgressQueryService $dailyProgress,
        private SenseReviewQueryService $senseReviewQuery,
    ) {
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $now = Carbon::now();
        $base = $this->senseReviewQuery
            ->confirmedSenseCardQuery($user->id, $user->selected_language)
            ->senseReviewEligible($user->id, $user->selected_language, $now);

        return MobileApiResponse::success([
            'today' => $this->dailyProgress->counts(
                $user->id,
                $user->selected_language,
                $now,
            ),
            'active_card_count' => (clone $base)->count('review_cards.id'),
            'due_now_count' => (clone $base)
                ->where('review_cards.fsrs_due_at', '<=', $now)
                ->count('review_cards.id'),
            'generated_at' => $now->utc()->toIso8601String(),
            'read_only' => true,
        ]);
    }
}
