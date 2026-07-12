<?php

namespace App\Http\Controllers;

use App\Models\ReviewCard;
use App\Services\ReviewCardLifecyclePolicy;
use App\Services\ReviewCardManageAccessService;
use App\Services\SenseReviewLeechQueryService;
use App\Services\SenseReviewLeechRewritePackageService;
use App\Services\SenseReviewLearningFeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * HTTP controller for sense leech governance (ADR-0011).
 *
 * Endpoints:
 *   GET  /reviews/senses/{reviewCard}/leech
 *   POST /reviews/senses/{reviewCard}/leech/rewrite-package
 *   GET  /review-cards/manage/leech-summary
 *   POST /review-cards/manage/bulk-leech-rewrite-packages
 *
 * Safety invariants:
 *  - Does NOT call AI providers.
 *  - Does NOT create WordSense / ReviewCard / ReviewLog.
 *  - Does NOT modify lifecycle state (suspend goes through lifecycle endpoint).
 *  - Read-only classification + rewrite package generation.
 */
class SenseReviewLeechController extends Controller
{
    public function __construct(
        private ReviewCardManageAccessService $accessService,
        private SenseReviewLeechQueryService $leechQuery,
        private SenseReviewLeechRewritePackageService $rewriteService,
        private SenseReviewLearningFeedbackService $feedbackService,
        private ReviewCardLifecyclePolicy $lifecyclePolicy,
    ) {
    }

    /**
     * GET /reviews/senses/{reviewCard}/leech
     * Return the leech descriptor for a single card.
     */
    public function show(int $reviewCard): JsonResponse
    {
        [$card, $sense] = $this->accessService->findManageableSenseCardOrFail(
            $reviewCard, Auth::id(), Auth::user()->selected_language
        );

        $timezone = $this->resolveTimezone();
        $descriptor = $this->leechQuery->describeForCard($card, Carbon::now(), $timezone);

        return response()->json([
            'review_card_id' => $card->id,
            'leech' => $descriptor,
        ]);
    }

    /**
     * POST /reviews/senses/{reviewCard}/leech/rewrite-package
     * Generate a rewrite prompt package for a leech card.
     *
     * Does NOT call any AI provider. Returns JSON + Markdown for the user
     * to copy to an external AI manually.
     */
    public function rewritePackage(int $reviewCard): JsonResponse
    {
        [$card, $sense] = $this->accessService->findManageableSenseCardOrFail(
            $reviewCard, Auth::id(), Auth::user()->selected_language
        );

        $timezone = $this->resolveTimezone();
        $now = Carbon::now();

        $feedback = $this->feedbackService->buildForCard($card->id);
        $lifecycleDescriptor = $this->lifecyclePolicy->describe($card, $now, $timezone);
        $leechDescriptor = $this->leechQuery->describeForCard($card, $now, $timezone);

        $package = $this->rewriteService->buildPackage(
            $card,
            $feedback,
            $leechDescriptor,
            $lifecycleDescriptor,
            $now
        );

        return response()->json($package);
    }

    /**
     * GET /review-cards/manage/leech-summary
     * Return leech status counts for the management page.
     */
    public function summary(): JsonResponse
    {
        $userId = Auth::id();
        $language = Auth::user()->selected_language;

        $summary = $this->leechQuery->summary($userId, $language, Carbon::now());

        return response()->json([
            'counts' => $summary['counts'],
            'leech_card_ids' => $summary['leech_card_ids'],
            'struggling_card_ids' => $summary['struggling_card_ids'],
        ]);
    }

    /**
     * POST /review-cards/manage/bulk-leech-rewrite-packages
     * Generate rewrite packages for multiple cards.
     *
     * Request body: { ids: int[] }
     * Returns: { packages: [...], failed: [...], provider_called: false, ... }
     */
    public function bulkRewritePackages(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:50',
            'ids.*' => 'integer|min:1',
        ]);

        $userId = Auth::id();
        $language = Auth::user()->selected_language;
        $timezone = $this->resolveTimezone();
        $now = Carbon::now();
        $ids = array_values(array_unique(array_map('intval', $validated['ids'])));

        // Load all cards in one query.
        $cards = ReviewCard::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        // Batch build feedback (1 ReviewLog query).
        $feedbackMap = $this->feedbackService->buildForCards($ids);

        $cardsData = [];
        $failed = [];

        foreach ($ids as $id) {
            $card = $cards->get($id);
            if (!$card) {
                $failed[] = ['card_id' => $id, 'error' => 'Card not found or not accessible.'];
                continue;
            }
            $feedback = $feedbackMap[$id] ?? [];
            $lifecycleDescriptor = $this->lifecyclePolicy->describe($card, $now, $timezone);
            $leechDescriptor = $this->leechQuery->describeForCard($card, $now, $timezone);
            $cardsData[] = [
                'card' => $card,
                'feedback' => $feedback,
                'leechDescriptor' => $leechDescriptor,
                'lifecycleDescriptor' => $lifecycleDescriptor,
            ];
        }

        $result = $this->rewriteService->buildPackagesBatch($cardsData, $now);

        // Merge access failures with generation failures.
        $result['failed'] = array_merge($failed, $result['failed']);

        return response()->json($result);
    }

    private function resolveTimezone(): string
    {
        return Auth::user()->timezone ?? 'UTC';
    }
}
