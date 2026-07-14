<?php

namespace App\Http\Controllers;

use App\Exceptions\CustomStudyPreviewPolicyException;
use App\Exceptions\CustomStudySessionException;
use App\Exceptions\CustomStudyValidationException;
use App\Services\CustomStudy\CustomStudySessionService;
use App\Services\ReviewQueueOrderOptions;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Task 2000-22 — Phase 4B Custom Study session HTTP boundary.
 *
 * This Controller is the SINGLE entry point that reads Auth::user() and
 * the user's selected_language. It then delegates all business logic to
 * CustomStudySessionService, passing trusted userId + language.
 *
 * Responsibilities (frozen by §17.2):
 *   - Read Auth::user() → id, selected_language.
 *   - Read Request body (mode, parameters, card_limit / token, rating).
 *   - Load Queue Order settings via SettingsService::getFsrsQueueOrder()
 *     and build ReviewQueueOrderOptions::fromArray(). Read-only: never
 *     persists Queue Order changes.
 *   - Call CustomStudySessionService::openSession / answer / resume.
 *   - Map structured exceptions to HTTP JSON:
 *       CustomStudyValidationException      → 422 (field/reason from exception)
 *       CustomStudyPreviewPolicyException   → 422 (field=rating, reason from exception)
 *       CustomStudySessionException         → 404 (generic message, no leakage)
 *
 * Forbidden in Controller (enforced by CustomStudyBackendVerticalSliceGuard):
 *   - Querying candidate IDs directly.
 *   - Ordering.
 *   - Modifying State.
 *   - Verifying token payload directly.
 *   - Calling PreviewPolicy / EligibilityService / QueryService / SessionOrder.
 *   - Serializing cards directly.
 *   - Writing ReviewLog.
 *   - Modifying FSRS fields.
 *   - Calling AI.
 *
 * 422 frozen payload (§17.3):
 *   {
 *     "success": false,
 *     "message": "...",
 *     "errors": { "field_name": ["..."] },
 *     "error": { "field": "field_name", "reason": "machine_reason" }
 *   }
 *
 * 404 frozen payload:
 *   {
 *     "success": false,
 *     "message": "Custom Study session not found or expired.",
 *     "error": { "reason": "session_not_found" }
 *   }
 *
 * Routing (§18):
 *   POST /custom-study/sessions          -> openSession
 *   POST /custom-study/sessions/answer   -> answer
 *   POST /custom-study/sessions/resume   -> resume
 *
 * Token is ALWAYS in the request body — never in URL or query string.
 * Client-supplied user_id / language in the body are silently dropped by
 * CustomStudyCriteria::fromArray (unknown keys ignored).
 */
class CustomStudyController extends Controller
{
    public function __construct(
        private readonly CustomStudySessionService $sessionService,
        private readonly SettingsService $settingsService,
    ) {
    }

    /**
     * Opens a new Custom Study preview session.
     *
     * Reads mode / parameters / card_limit from the request body.
     * User id and selected_language come from Auth::user() — body
     * supplied user_id / language are ignored.
     */
    public function openSession(Request $request): JsonResponse
    {
        $user = Auth::user();
        $input = is_array($request->input()) ? $request->input() : [];

        try {
            // Queue Order is loaded read-only — fromArray() does NOT persist.
            $queueOptions = ReviewQueueOrderOptions::fromArray(
                $this->settingsService->getFsrsQueueOrder()
            );

            $result = $this->sessionService->openSession(
                $input,
                $user->id,
                $user->selected_language,
                Carbon::now(),
                $queueOptions
            );

            return response()->json($result);
        } catch (CustomStudyValidationException $e) {
            return $this->validationError(
                $e->getField(),
                $e->getReason(),
                $e->getMessage()
            );
        } catch (CustomStudyPreviewPolicyException $e) {
            // §17.3 mapping: PreviewPolicy violations on open are unlikely,
            // but if they occur they map to the rating field by contract.
            return $this->validationError(
                'rating',
                $e->getReason(),
                $e->getMessage()
            );
        }
    }

    /**
     * Applies a rating to the current card in the session.
     *
     * Reads token and rating from the request body. The token is the
     * opaque encrypted string returned by openSession / answer / resume.
     */
    public function answer(Request $request): JsonResponse
    {
        $user = Auth::user();
        $token = (string) $request->input('token', '');
        $rating = (string) $request->input('rating', '');

        try {
            $result = $this->sessionService->answer(
                $token,
                $rating,
                $user->id,
                $user->selected_language,
                Carbon::now()
            );

            return response()->json($result);
        } catch (CustomStudySessionException $e) {
            return $this->notFoundError();
        } catch (CustomStudyValidationException $e) {
            return $this->validationError(
                $e->getField(),
                $e->getReason(),
                $e->getMessage()
            );
        } catch (CustomStudyPreviewPolicyException $e) {
            return $this->validationError(
                'rating',
                $e->getReason(),
                $e->getMessage()
            );
        }
    }

    /**
     * Resumes the session by selecting the next card (or keeping current).
     *
     * Reads only the token from the request body.
     */
    public function resume(Request $request): JsonResponse
    {
        $user = Auth::user();
        $token = (string) $request->input('token', '');

        try {
            $result = $this->sessionService->resume(
                $token,
                $user->id,
                $user->selected_language,
                Carbon::now()
            );

            return response()->json($result);
        } catch (CustomStudySessionException $e) {
            return $this->notFoundError();
        } catch (CustomStudyValidationException $e) {
            return $this->validationError(
                $e->getField(),
                $e->getReason(),
                $e->getMessage()
            );
        }
    }

    // ---------- Private helpers ----------

    /**
     * Builds the frozen 422 payload (§17.3).
     *
     * Never parses exception message to derive field/reason — those come
     * directly from the structured exception.
     */
    private function validationError(string $field, string $reason, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => [
                $field => [$message],
            ],
            'error' => [
                'field' => $field,
                'reason' => $reason,
            ],
        ], 422);
    }

    /**
     * Builds the frozen 404 payload for session_not_found.
     *
     * The message is generic — no internal token verification details
     * are leaked (the reason might be tampered, expired, wrong user,
     * wrong language, or unsupported version, but the response is the
     * same generic session_not_found).
     */
    private function notFoundError(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Custom Study session not found or expired.',
            'error' => [
                'reason' => 'session_not_found',
            ],
        ], 404);
    }
}
