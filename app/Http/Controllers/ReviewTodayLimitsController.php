<?php

namespace App\Http\Controllers;

use App\Services\EffectiveReviewLimitsService;
use App\Services\ReviewDailyLimitOverrideService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReviewTodayLimitsController extends Controller
{
    public function __construct(
        private EffectiveReviewLimitsService $effectiveLimitsService,
        private ReviewDailyLimitOverrideService $overrideService,
    ) {
    }

    public function show(Request $request)
    {
        return response()->json($this->limits($request, Carbon::now()));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'new_limit_delta' => ['required', 'integer', 'between:0,999'],
            'review_limit_delta' => ['required', 'integer', 'between:0,9999'],
            'pause_new_cards' => ['required', 'boolean'],
        ]);
        $user = $request->user();
        $now = Carbon::now();
        $this->overrideService->save($user->id, $user->selected_language, $validated, $now);

        return response()->json($this->limits($request, $now));
    }

    public function destroy(Request $request)
    {
        $user = $request->user();
        $now = Carbon::now();
        $this->overrideService->deleteCurrent($user->id, $user->selected_language, $now);

        return response()->json($this->limits($request, $now));
    }

    private function limits(Request $request, Carbon $now): array
    {
        $user = $request->user();

        return $this->effectiveLimitsService->resolve($user->id, $user->selected_language, $now);
    }
}
