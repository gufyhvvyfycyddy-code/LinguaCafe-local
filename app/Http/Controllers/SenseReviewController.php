<?php

namespace App\Http\Controllers;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Services\ReviewCardService;
use App\Services\SenseReviewCardSerializerService;
use App\Services\SenseReviewDailyReportService;
use App\Services\SenseReviewIntervalPreviewService;
use App\Services\SenseReviewRatingContract;
use App\Services\SenseReviewService;
use App\Services\SenseReviewSessionActionService;
use App\Services\SenseReviewSevenDayTrendService;
use App\Services\SenseReviewThirtyDayCalendarService;
use App\Services\SenseReviewUndoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SenseReviewController extends Controller
{
    public function __construct(
        private SenseReviewService $senseReviewService,
        private ReviewCardService $reviewCardService,
        private HomeController $homeController,
        private SenseReviewCardSerializerService $senseReviewCardSerializerService,
        private SenseReviewDailyReportService $senseReviewDailyReportService,
        private SenseReviewSevenDayTrendService $senseReviewSevenDayTrendService,
        private SenseReviewThirtyDayCalendarService $senseReviewThirtyDayCalendarService,
        private SenseReviewIntervalPreviewService $senseReviewIntervalPreviewService,
        private SenseReviewSessionActionService $sessionActionService,
        private SenseReviewUndoService $undoService,
        private SenseReviewRatingContract $ratingContract,
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
            'review_session_id' => ['nullable', 'string', 'uuid'],
        ]);

        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $ignoreDailyLimits = $request->input('ignoreDailyLimits', $request->input('ignore_daily_limits', false));
        $reviewSessionId = $request->post('review_session_id');

        $card = ReviewCard::where('id', $reviewCardId)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->first();

        if (!$card) {
            abort(404, 'Sense review card does not exist.');
        }

        $rating = $request->post('rating');
        $updatedCard = $this->reviewCardService->recordReview(
            $userId,
            $language,
            $card->id,
            $rating,
            'sense_review',
            $reviewSessionId,
        );

        // Build action metadata for the undo ledger (ADR-0009).
        $latestLog = ReviewLog::where('review_card_id', $card->id)
            ->where('user_id', $userId)
            ->orderBy('id', 'desc')
            ->first();

        $action = null;
        if ($latestLog) {
            $action = [
                'review_log_id' => $latestLog->id,
                'review_session_id' => $latestLog->review_session_id,
                'rating' => $latestLog->rating,
                'rating_label' => $this->ratingContract->labelFor($latestLog->rating),
                'reviewed_at' => $latestLog->reviewed_at?->toIso8601String(),
                'undoable' => $latestLog->before_card_snapshot !== null
                    && $latestLog->review_session_id !== null
                    && $latestLog->undone_at === null,
            ];
        }

        // Use limit-aware next and summary
        $result = $this->senseReviewService->dueCardsWithLimits($userId, $language, $ignoreDailyLimits);
        $nextCard = $result['cards']->first();

        return response()->json([
            'reviewed_card' => $this->senseReviewCardSerializerService->serialize($updatedCard->load('sense')),
            'next_card' => $nextCard ? $this->senseReviewCardSerializerService->serialize($nextCard) : null,
            'summary' => $result['summary'],
            'action' => $action,
        ]);
    }

    /**
     * SenseReview-DailyReport-1000-1
     *
     * Read-only "今日学习日报" — consolidated five-block daily report
     * (overview, quality, focus_senses, progress_senses, recent_reviews).
     * This is the SINGLE formal today-report endpoint — the former
     * today-summary endpoint was merged here in task
     * GLM-SenseReview-DailyReportConsolidation-AndMergedProduct-1000-3.
     * Source of truth: ReviewLog. No writes, no FSRS changes.
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

    /**
     * SenseReview-ThirtyDayCalendar-1000-1
     *
     * Read-only "近 30 天复习日历" — fixed rolling 30-day window (today +
     * previous 29 natural days, NOT a natural month). Source of truth:
     * ReviewLog. Sense-review only, reset excluded. No writes, no FSRS.
     */
    public function thirtyDayCalendar(Request $request)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $calendar = $this->senseReviewThirtyDayCalendarService->build($userId, $language);

        return response()->json($calendar);
    }

    /**
     * ADR-0008: Read-only interval preview for the four rating buttons.
     *
     * Returns the projected due_at, interval_seconds, and next_state for
     * each of again/hard/good/easy. Does NOT write ReviewLog, does NOT
     * modify FSRS, does NOT change the queue.
     *
     * Access: current user, current language, target_type=sense,
     * fsrs_enabled=true, WordSense status=confirmed. Any failure → 404.
     */
    public function intervalPreview(int $reviewCardId, Request $request)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $preview = $this->senseReviewIntervalPreviewService->preview($reviewCardId, $userId, $language);

        if (!$preview) {
            abort(404, 'Sense review card does not exist or is not previewable.');
        }

        return response()->json($preview);
    }

    /**
     * ADR-0009: Session action timeline.
     *
     * Returns the most recent 20 review actions for the current
     * user, language, and session. Includes undone actions (for
     * audit) with undoable/blocked_reason metadata. Read-only.
     */
    public function sessionActions(Request $request)
    {
        $request->validate([
            'review_session_id' => ['required', 'string', 'uuid'],
        ]);

        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $actions = $this->sessionActionService->timeline(
            $userId,
            $language,
            $request->query('review_session_id'),
        );

        return response()->json([
            'review_session_id' => $request->query('review_session_id'),
            'actions' => $actions,
        ]);
    }

    /**
     * ADR-0009: Stack-based undo of a review action.
     *
     * Restores the card's FSRS state to the before-snapshot and marks
     * the ReviewLog as undone. Idempotent: same undo_request_id returns
     * 200 with already_applied=true. Returns 409 for conflicts (stale
     * snapshot, not-latest-action, different request ID on already-undone).
     */
    public function undo(int $reviewLog, Request $request)
    {
        $request->validate([
            'review_session_id' => ['required', 'string', 'uuid'],
            'undo_request_id' => ['required', 'string', 'uuid'],
            'source' => ['required', 'in:sense_review_snackbar,sense_review_history,sense_review_hotkey'],
        ]);

        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $result = $this->undoService->undo(
            $reviewLog,
            $userId,
            $language,
            $request->post('review_session_id'),
            $request->post('undo_request_id'),
            $request->post('source'),
        );

        if (!$result['success']) {
            $status = $result['status'] ?? 409;
            return response()->json([
                'success' => false,
                'blocked_reason' => $result['blocked_reason'] ?? null,
                'message' => $result['message'] ?? 'Undo failed.',
            ], $status);
        }

        return response()->json($result);
    }
}
