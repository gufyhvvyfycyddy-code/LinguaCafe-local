<?php

namespace App\Services\CustomStudy;

use App\Exceptions\CustomStudySessionException;
use App\Exceptions\CustomStudyValidationException;
use App\Models\ReviewCard;
use App\Services\ReviewQueueOrderOptions;
use App\Services\SenseReviewCardSerializerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Stateless orchestrator for Custom Study preview sessions.
 *
 * Three public methods:
 * - openSession: validate → query → order → truncate → create state →
 *   eligibility recheck → issue token → serialize current.
 * - answer: verify token → apply rating → eligibility recheck →
 *   issue new token → serialize current.
 * - resume: verify token → resume → eligibility recheck →
 *   issue new token → serialize current.
 *
 * Architecture constraints (enforced by CustomStudyBackendVerticalSliceGuard):
 * - Does NOT access Auth / Request / Session / Settings facades.
 * - Does NOT call Crypt directly (TokenService is the only encrypt/decrypt boundary).
 * - Does NOT write review logs / FSRS / lifecycle (preview-only session).
 * - Does NOT call AI.
 * - The caller (Controller) passes trusted userId, language, and cardLimit.
 *
 * Task 2000-22 — Phase 4B.
 */
class CustomStudySessionService
{
    private const DEFAULT_CARD_LIMIT = 100;
    private const MIN_CARD_LIMIT = 1;
    private const MAX_CARD_LIMIT = 500;
    private const TTL_SECONDS = 14400;

    /** @var array{again_secs: int, hard_secs: int, good_secs: int, easy_secs: int} */
    private const PREVIEW_DELAY_CONFIG = [
        'again_secs' => 60,
        'hard_secs' => 600,
        'good_secs' => 0,
        'easy_secs' => 0,
    ];

    public function __construct(
        private readonly CustomStudyCriteriaValidator $criteriaValidator,
        private readonly CustomStudyQueryService $queryService,
        private readonly CustomStudySessionOrder $sessionOrder,
        private readonly CustomStudySessionTokenService $tokenService,
        private readonly CustomStudyPreviewPolicy $previewPolicy,
        private readonly CustomStudySessionEligibilityService $eligibilityService,
        private readonly SenseReviewCardSerializerService $serializerService,
    ) {
    }

    /**
     * Opens a new Custom Study preview session.
     *
     * @param array<string, mixed> $input Raw client input (mode, parameters, card_limit).
     * @param int $userId Trusted current user id (from caller, NOT from input).
     * @param string $language Trusted current language (from caller, NOT from input).
     * @param Carbon $now Current time.
     * @param ReviewQueueOrderOptions $queueOptions Queue Order settings (read-only).
     * @return array<string, mixed> Response payload (token, session_id, current_card, summary, expires_at).
     * @throws CustomStudyValidationException If criteria or card_limit is invalid.
     */
    public function openSession(
        array $input,
        int $userId,
        string $language,
        Carbon $now,
        ReviewQueueOrderOptions $queueOptions
    ): array {
        // 1. Validate criteria (mode + parameters + chapter ownership).
        $criteria = $this->criteriaValidator->validate($input, $userId, $language);

        // 2. Validate card_limit (strict integer, 1-500).
        $cardLimit = $this->validateCardLimit($input);

        // 3. Query candidate IDs for the selected mode.
        $candidateIds = $this->queryService->candidateIds(
            $criteria,
            $userId,
            $language,
            $now
        );

        // 4. Order the FULL candidate set (before truncation).
        $orderedIds = $this->sessionOrder->order(
            $candidateIds,
            $criteria,
            $userId,
            $language,
            $now,
            $queueOptions
        );

        // 5. total_candidates = count of full ordered set (pre-truncation).
        $totalCandidates = count($orderedIds);

        // 6. Truncate to card_limit (AFTER ordering, never before).
        $truncatedIds = array_slice($orderedIds, 0, $cardLimit);

        // 7. Generate session identity and timestamps.
        $sessionId = (string) Str::uuid();
        $issuedAt = $now->getTimestamp();
        $expiresAt = $issuedAt + self::TTL_SECONDS;

        // 8. Create initial state (availableCandidateCount = pre-truncation total).
        $state = CustomStudySessionState::createInitial(
            CustomStudySessionState::VERSION,
            $userId,
            $language,
            $criteria,
            $sessionId,
            $issuedAt,
            $expiresAt,
            $truncatedIds,
            self::PREVIEW_DELAY_CONFIG,
            $totalCandidates
        );

        // 9. Eligibility recheck (race guard: cards may have been
        //    suspended/archived between query and state creation).
        $ineligibleIds = $this->eligibilityService->findIneligibleCardIds($state, $now);
        $state = $this->previewPolicy->resolveEligibility($state, $ineligibleIds);

        // 10. Issue encrypted token.
        $token = $this->tokenService->issue($state);

        // 11. Serialize only the current card.
        $currentCard = $this->serializeCurrentCard($state);

        // 12. Build response.
        return [
            'token' => $token,
            'session_id' => $state->sessionId(),
            'current_card' => $currentCard,
            'summary' => $this->buildSummary($state),
            'expires_at' => $this->toUtcIso8601($state->expiresAt()),
        ];
    }

    /**
     * Applies a rating to the current card and returns the next state.
     *
     * @param string $token Encrypted session token.
     * @param string $rating One of: again, hard, good, easy.
     * @param int $userId Trusted current user id.
     * @param string $language Trusted current language.
     * @param Carbon $now Current time.
     * @return array<string, mixed> Response payload (refreshed_token, current_card, summary, wait_until, completed).
     * @throws CustomStudySessionException If token verification fails.
     * @throws \App\Exceptions\CustomStudyPreviewPolicyException If rating is invalid or no current card.
     */
    public function answer(
        string $token,
        string $rating,
        int $userId,
        string $language,
        Carbon $now
    ): array {
        // 1. Verify token.
        $state = $this->tokenService->verify($token, $userId, $language, $now);
        if ($state === null) {
            throw new CustomStudySessionException(
                CustomStudySessionException::REASON_SESSION_NOT_FOUND,
                'Custom Study session not found or expired.'
            );
        }

        // 2. Apply rating (throws PreviewPolicyException on invalid rating
        //    or no current card).
        $ratedState = $this->previewPolicy->applyRating($state, $rating, $now);

        // 3. Eligibility recheck on the new state.
        $ineligibleIds = $this->eligibilityService->findIneligibleCardIds($ratedState, $now);
        $resolvedState = $this->previewPolicy->resolveEligibility($ratedState, $ineligibleIds);

        // 4. Issue refreshed token.
        $newToken = $this->tokenService->issue($resolvedState);

        // 5. Serialize current card.
        $currentCard = $this->serializeCurrentCard($resolvedState);

        // 6. Build response.
        return [
            'refreshed_token' => $newToken,
            'current_card' => $currentCard,
            'summary' => $this->buildSummary($resolvedState),
            'wait_until' => $resolvedState->waitUntil() !== null
                ? $this->toUtcIso8601($resolvedState->waitUntil())
                : null,
            'completed' => $resolvedState->isCompleted(),
        ];
    }

    /**
     * Resumes the session by selecting the next card (or keeping current).
     *
     * @param string $token Encrypted session token.
     * @param int $userId Trusted current user id.
     * @param string $language Trusted current language.
     * @param Carbon $now Current time.
     * @return array<string, mixed> Response payload (refreshed_token, current_card, summary, wait_until, completed).
     * @throws CustomStudySessionException If token verification fails.
     */
    public function resume(
        string $token,
        int $userId,
        string $language,
        Carbon $now
    ): array {
        // 1. Verify token.
        $state = $this->tokenService->verify($token, $userId, $language, $now);
        if ($state === null) {
            throw new CustomStudySessionException(
                CustomStudySessionException::REASON_SESSION_NOT_FOUND,
                'Custom Study session not found or expired.'
            );
        }

        // 2. Resume (picks next card or keeps current).
        $resumedState = $this->previewPolicy->resume($state, $now);

        // 3. Eligibility recheck on the new state.
        $ineligibleIds = $this->eligibilityService->findIneligibleCardIds($resumedState, $now);
        $resolvedState = $this->previewPolicy->resolveEligibility($resumedState, $ineligibleIds);

        // 4. Issue refreshed token.
        $newToken = $this->tokenService->issue($resolvedState);

        // 5. Serialize current card.
        $currentCard = $this->serializeCurrentCard($resolvedState);

        // 6. Build response.
        return [
            'refreshed_token' => $newToken,
            'current_card' => $currentCard,
            'summary' => $this->buildSummary($resolvedState),
            'wait_until' => $resolvedState->waitUntil() !== null
                ? $this->toUtcIso8601($resolvedState->waitUntil())
                : null,
            'completed' => $resolvedState->isCompleted(),
        ];
    }

    // ---------- Helpers ----------

    /**
     * Validates card_limit from the input array.
     *
     * Rules (frozen by Task 2000-22 §13.1):
     * - Missing key → default 100.
     * - Must be a true PHP integer (rejects string, float, bool, array, null).
     * - Must be 1-500.
     *
     * @param array<string, mixed> $input
     * @throws CustomStudyValidationException On invalid type or out-of-range.
     */
    private function validateCardLimit(array $input): int
    {
        if (!array_key_exists('card_limit', $input)) {
            return self::DEFAULT_CARD_LIMIT;
        }

        $cardLimit = $input['card_limit'];

        if (!is_int($cardLimit)) {
            throw new CustomStudyValidationException(
                'card_limit',
                'invalid_type',
                'Custom Study card_limit must be an integer.'
            );
        }

        if ($cardLimit < self::MIN_CARD_LIMIT) {
            throw new CustomStudyValidationException(
                'card_limit',
                'below_minimum',
                'Custom Study card_limit must be at least ' . self::MIN_CARD_LIMIT . '.'
            );
        }

        if ($cardLimit > self::MAX_CARD_LIMIT) {
            throw new CustomStudyValidationException(
                'card_limit',
                'above_maximum',
                'Custom Study card_limit must not exceed ' . self::MAX_CARD_LIMIT . '.'
            );
        }

        return $cardLimit;
    }

    /**
     * Builds the summary sub-object for the response.
     *
     * @return array<string, mixed>
     */
    private function buildSummary(CustomStudySessionState $state): array
    {
        $skippedCount = count($state->skippedIneligibleIds());
        return [
            'total_candidates' => $state->availableCandidateCount(),
            'total_count' => $state->totalCount(),
            'completed_count' => $state->completedCount(),
            'skipped_ineligible_count' => $skippedCount,
            'remaining_count' => $state->totalCount() - $state->completedCount() - $skippedCount,
            'mode' => $state->mode(),
            'step' => $state->step(),
        ];
    }

    /**
     * Serializes the current card for the response, or null if no current.
     *
     * Loads the ReviewCard with its sense relationship in a single query.
     * Scoped by user/language for defense in depth.
     */
    private function serializeCurrentCard(CustomStudySessionState $state): ?array
    {
        $currentCardId = $state->currentCardId();
        if ($currentCardId === null) {
            return null;
        }

        $card = ReviewCard::with('sense')
            ->where('user_id', $state->userId())
            ->where('language', $state->language())
            ->find($currentCardId);

        if ($card === null) {
            return null;
        }

        return $this->serializerService->serialize($card);
    }

    /**
     * Converts a Unix timestamp to a UTC ISO-8601 string.
     */
    private function toUtcIso8601(int $timestamp): string
    {
        return Carbon::createFromTimestamp($timestamp)->setTimezone('UTC')->toIso8601String();
    }
}
