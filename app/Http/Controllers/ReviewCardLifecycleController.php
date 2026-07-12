<?php

namespace App\Http\Controllers;

use App\Models\ReviewCard;
use App\Models\ReviewCardStateEvent;
use App\Services\LifecycleConflictException;
use App\Services\LifecycleValidationException;
use App\Services\ReviewCardLifecycleCommandService;
use App\Services\ReviewCardLifecyclePolicy;
use App\Services\ReviewCardManageAccessService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * HTTP controller for review card lifecycle state machine (ADR-0010).
 *
 * Endpoints:
 *   GET  /review-cards/{reviewCard}/lifecycle
 *   POST /review-cards/{reviewCard}/lifecycle-actions
 *   GET  /review-cards/{reviewCard}/lifecycle-events
 *   POST /review-cards/manage/bulk-lifecycle
 *
 * Reset and Delete continue to use their own endpoints and are NOT routed
 * through this controller.
 */
class ReviewCardLifecycleController extends Controller
{
    public function __construct(
        private ReviewCardManageAccessService $accessService,
        private ReviewCardLifecycleCommandService $commandService,
        private ReviewCardLifecyclePolicy $policy,
    ) {
    }

    /**
     * GET /review-cards/{reviewCard}/lifecycle
     * Return the current lifecycle descriptor for a card.
     */
    public function show(int $reviewCard): JsonResponse
    {
        [$card, $sense] = $this->accessService->findManageableSenseCardOrFail(
            $reviewCard, Auth::user()->id, Auth::user()->selected_language
        );

        $timezone = $this->resolveTimezone();
        $descriptor = $this->policy->describe($card, Carbon::now(), $timezone);

        return response()->json([
            'review_card_id' => $card->id,
            'lifecycle' => $descriptor,
        ]);
    }

    /**
     * POST /review-cards/{reviewCard}/lifecycle-actions
     * Execute a lifecycle action.
     *
     * Body: { action, request_id, expected_version?, source, reason? }
     */
    public function act(Request $request, int $reviewCard): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string'],
            'request_id' => ['required', 'string'],
            'expected_version' => ['nullable', 'integer'],
            'source' => ['nullable', 'string'],
            'reason' => ['nullable', 'string'],
        ]);

        [$card, $sense] = $this->accessService->findManageableSenseCardOrFail(
            $reviewCard, Auth::user()->id, Auth::user()->selected_language
        );

        $timezone = $this->resolveTimezone();
        $source = $validated['source'] ?? 'unknown';
        $reason = $validated['reason'] ?? null;
        $expectedVersion = isset($validated['expected_version']) ? (int) $validated['expected_version'] : null;

        try {
            $result = $this->commandService->act(
                $card,
                $validated['action'],
                $validated['request_id'],
                $expectedVersion,
                $source,
                Auth::user()->id,
                Auth::user()->selected_language,
                $timezone,
                $reason
            );

            return response()->json($result, $result['already_applied'] ? 200 : 200);
        } catch (LifecycleConflictException $e) {
            return response()->json([
                'error' => $e->reason,
                'message' => $e->getMessage(),
                'review_card_id' => $reviewCard,
            ], 409);
        } catch (LifecycleValidationException $e) {
            return response()->json([
                'error' => $e->reason,
                'message' => $e->getMessage(),
                'review_card_id' => $reviewCard,
            ], 422);
        }
    }

    /**
     * GET /review-cards/{reviewCard}/lifecycle-events
     * Return the last 20 state events for a card.
     */
    public function events(int $reviewCard): JsonResponse
    {
        [$card, $sense] = $this->accessService->findManageableSenseCardOrFail(
            $reviewCard, Auth::user()->id, Auth::user()->selected_language
        );

        $events = ReviewCardStateEvent::query()
            ->where('review_card_id', $card->id)
            ->where('user_id', Auth::user()->id)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(fn (ReviewCardStateEvent $e) => [
                'id' => $e->id,
                'action' => $e->action,
                'previous_state' => $e->previous_state,
                'new_state' => $e->new_state,
                'source' => $e->source,
                'created_at' => optional($e->created_at)->toISOString(),
                'request_id_prefix' => $e->request_id ? substr($e->request_id, 0, 8) : null,
            ]);

        return response()->json([
            'items' => $events,
        ]);
    }

    /**
     * POST /review-cards/manage/bulk-lifecycle
     * Bulk execute a lifecycle action on multiple cards.
     *
     * Body: { ids: int[], action, source?, reason? }
     */
    public function bulkAct(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'action' => ['required', 'string'],
            'source' => ['nullable', 'string'],
            'reason' => ['nullable', 'string'],
        ]);

        $ids = array_map('intval', $validated['ids']);
        if (empty($ids)) {
            return response()->json(['message' => '请选择至少一张复习卡。'], 422);
        }

        $source = $validated['source'] ?? 'review_card_manage_bulk';
        $timezone = $this->resolveTimezone();

        $result = $this->commandService->bulkAct(
            $ids,
            $validated['action'],
            $source,
            Auth::user()->id,
            Auth::user()->selected_language,
            $timezone
        );

        return response()->json($result);
    }

    /**
     * Resolve the user's timezone for bury-time computation.
     *
     * Defaults to the application timezone. The request may override via
     * the 'timezone' field (e.g., 'Asia/Shanghai').
     */
    private function resolveTimezone(): string
    {
        return config('app.timezone', 'UTC');
    }
}
