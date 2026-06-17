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
        $cards = $this->senseReviewService->dueCards($userId, $language);

        return response()->json([
            'cards' => $cards->map(fn (ReviewCard $card) => $this->senseReviewService->serializeCard($card))->values(),
            'summary' => [
                'due_count' => $cards->count(),
            ],
        ]);
    }

    public function rate(int $reviewCardId, Request $request)
    {
        $request->validate([
            'rating' => ['required', 'in:again,hard,good,easy'],
        ]);

        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $card = ReviewCard::where('id', $reviewCardId)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->first();

        if (!$card) {
            abort(404, 'Sense review card does not exist.');
        }

        $updatedCard = $this->reviewCardService->recordReview($userId, $language, $card->id, $request->post('rating'), 'sense_review');

        return response()->json([
            'reviewed_card' => $this->senseReviewService->serializeCard($updatedCard->load('sense')),
            'next_card' => $this->senseReviewService->nextDueCard($userId, $language),
            'summary' => $this->senseReviewService->summary($userId, $language),
        ]);
    }
}
