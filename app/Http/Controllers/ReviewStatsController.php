<?php

namespace App\Http\Controllers;

use App\Services\ReviewStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReviewStatsController extends Controller
{
    public function __construct(
        private ReviewStatsService $reviewStatsService,
    ) {
    }

    /**
     * GET /review-cards/stats
     *
     * Returns aggregate FSRS statistics for the current user's selected
     * language, scoped to confirmed sense review cards only.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        return response()->json(
            $this->reviewStatsService->all($userId, $language)
        );
    }
}
