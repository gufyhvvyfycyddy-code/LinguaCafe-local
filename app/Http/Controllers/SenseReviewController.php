<?php

namespace App\Http\Controllers;

use App\Models\ReviewCard;
use App\Services\ReviewCardService;
use App\Services\SenseReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SenseReviewController extends Controller
{
    public function __construct(
        private SenseReviewService $senseReviewService,
        private ReviewCardService $reviewCardService,
        private HomeController $homeController,
    ) {
    }

    public function index(Request $request)
    {
        if (!$request->expectsJson() && !$request->ajax()) {
            return $this->homeController->index();
        }

        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $ignoreDailyLimits = $request->input('ignoreDailyLimits', $request->input('ignore_daily_limits', false));
        $result = $this->senseReviewService->dueCardsWithLimits($userId, $language, $ignoreDailyLimits);

        return response()->json([
            'cards' => $result['cards']->map(fn (ReviewCard $card) => $this->senseReviewService->serializeCard($card))->values(),
            'summary' => $result['summary'],
        ]);
    }

    public function rate(int $reviewCardId, Request $request)
    {
        $request->validate([
            'rating' => ['required', 'in:again,hard,good,easy'],
        ]);

        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $ignoreDailyLimits = $request->input('ignoreDailyLimits', $request->input('ignore_daily_limits', false));

        $card = ReviewCard::where('id', $reviewCardId)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->first();

        if (!$card) {
            abort(404, 'Sense review card does not exist.');
        }

        $updatedCard = $this->reviewCardService->recordReview($userId, $language, $card->id, $request->post('rating'), 'sense_review');

        // Use limit-aware next and summary
        $result = $this->senseReviewService->dueCardsWithLimits($userId, $language, $ignoreDailyLimits);
        $nextCard = $result['cards']->first();

        return response()->json([
            'reviewed_card' => $this->senseReviewService->serializeCard($updatedCard->load('sense')),
            'next_card' => $nextCard ? $this->senseReviewService->serializeCard($nextCard) : null,
            'summary' => $result['summary'],
        ]);
    }
}
