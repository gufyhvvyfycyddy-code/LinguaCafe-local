<?php

namespace App\Http\Controllers;

use App\Models\ReviewCard;
use App\Services\ReviewCardService;
use App\Services\SenseReviewCardSerializerService;
use App\Services\SenseReviewDailyReportService;
use App\Services\SenseReviewService;
use App\Services\SenseReviewSevenDayTrendService;
use App\Services\SenseReviewTodaySummaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SenseReviewController extends Controller
{
    public function __construct(
        private SenseReviewService $senseReviewService,
        private ReviewCardService $reviewCardService,
        private HomeController $homeController,
        private SenseReviewCardSerializerService $senseReviewCardSerializerService,
        private SenseReviewTodaySummaryService $senseReviewTodaySummaryService,
        private SenseReviewDailyReportService $senseReviewDailyReportService,
        private SenseReviewSevenDayTrendService $senseReviewSevenDayTrendService,
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

        // SenseReview-BatchFeedback-1000-1: serialize the queue with a single
        // batch ReviewLog query instead of N per-card queries. The serializer
        // loads all feedback in one go via buildForCards(); payload shape is
        // unchanged.
        return response()->json([
            'cards' => $this->senseReviewCardSerializerService->serializeMany($result['cards']),
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
            'reviewed_card' => $this->senseReviewCardSerializerService->serialize($updatedCard->load('sense')),
            'next_card' => $nextCard ? $this->senseReviewCardSerializerService->serialize($nextCard) : null,
            'summary' => $result['summary'],
        ]);
    }

    /**
     * SenseReview-TodaySummary-1000-1
     *
     * Read-only daily sense review summary. Aggregates ALL of today's real
     * sense-card ratings across multiple page sessions (unlike the ephemeral
     * session summary which resets on page reload). Source of truth: ReviewLog.
     *
     * Controller stays thin: read current user + language, delegate to
     * SenseReviewTodaySummaryService, return JSON. No writes, no FSRS changes.
     */
    public function todaySummary(Request $request)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $summary = $this->senseReviewTodaySummaryService->build($userId, $language);

        return response()->json($summary);
    }

    /**
     * SenseReview-DailyReport-1000-1
     *
     * Read-only "今日学习日报" — richer four-block daily report (overview,
     * quality, focus_senses, progress_senses). Distinct from the simpler
     * today-summary. Source of truth: ReviewLog. No writes, no FSRS changes.
     */
    public function dailyReport(Request $request)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $report = $this->senseReviewDailyReportService->build($userId, $language);

        return response()->json($report);
    }

    /**
     * SenseReview-SevenDayTrend-1000-1
     *
     * Read-only "近 7 天学习趋势" — fixed rolling 7-day window (today +
     * previous 6 natural days, NOT a natural week). Source of truth:
     * ReviewLog. Sense-review only, reset excluded. No writes, no FSRS.
     */
    public function sevenDayTrend(Request $request)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $trend = $this->senseReviewSevenDayTrendService->build($userId, $language);

        return response()->json($trend);
    }
}
