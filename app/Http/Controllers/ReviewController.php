<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

// services
use App\Services\ReviewService;
use App\Services\GoalService;
use App\Services\ReviewCardService;

// request classes
use App\Http\Requests\Review\GetReviewItemsRequest;
use App\Http\Requests\Review\UpdateReviewGoalRequest;
use App\Http\Requests\Review\RateReviewCardRequest;

class ReviewController extends Controller {

    private $reviewService;
    private $goalService;
    private $reviewCardService;

    public function __construct(ReviewService $reviewService, GoalService $goalService, ReviewCardService $reviewCardService) {
        $this->reviewService = $reviewService;
        $this->goalService = $goalService;
        $this->reviewCardService = $reviewCardService;
    }
    
    public function getReviewItems(GetReviewItemsRequest $request) {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $practiceMode = $request->post('practiceMode');
        $chapterId = $request->post('chapterId');
        $bookId = $request->post('bookId');
        $languagesWithoutSpaces = config('linguacafe.languages.languages_without_spaces');
        
        try {
            $reviews = $this->reviewService->getReviewItems($userId, $language, $bookId, $chapterId, $practiceMode, $languagesWithoutSpaces);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        $reviewData = new \stdClass();
        $reviewData->reviews = $reviews;
        $reviewData->language = $language;
        $reviewData->languageSpaces = !in_array($language, $languagesWithoutSpaces, true);

        return response()->json($reviewData, 200);
    }

    public function updateReadWordsGoal(UpdateReviewGoalRequest $request) {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $readWords = $request->post('readWords');

        try {
            $this->goalService->updateGoalAchievement($userId, $language, 'read_words', $readWords);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json('Review goal has been updated successfully.', 200);
    }

    public function rateReviewCard(RateReviewCardRequest $request) {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $reviewCardId = $request->post('reviewCardId');
        $rating = $request->post('rating');

        try {
            $card = $this->reviewCardService->recordReview($userId, $language, $reviewCardId, $rating);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json($card, 200);
    }
}
