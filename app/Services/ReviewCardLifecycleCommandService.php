<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\ReviewCardStateEvent;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Unified mutation entry point for review card lifecycle transitions (ADR-0010).
 *
 * This is the ONLY service allowed to mutate:
 *   - lifecycle_state
 *   - buried_until
 *   - lifecycle_version
 *   - lifecycle_changed_at
 *
 * It also synchronizes the fsrs_enabled compatibility mirror:
 *   active/buried → true
 *   suspended/archived → false
 *
 * Guarantees:
 *   - No ReviewLog is created.
 *   - FSRS scheduling fields are never modified.
 *   - Same request_id retry is idempotent (returns already_applied=true).
 *   - Stale expected_version returns 409 (LifecycleConflictException).
 *   - Illegal transition returns 409 (LifecycleConflictException).
 *   - Concurrent rating/undo on the same card is safe (lockForUpdate).
 */
class ReviewCardLifecycleCommandService
{
    public function __construct(
        private ReviewCardLifecyclePolicy $policy,
        private ReviewCardLifecycleSnapshotService $snapshotService,
        private ReviewCardBuryTimeService $buryTimeService,
    ) {
    }

    /**
     * Execute a lifecycle action on a card.
     *
     * @param  ReviewCard $card
     * @param  string     $action          bury|unbury|suspend|resume|archive|restore
     * @param  string     $requestId       UUID for idempotency
     * @param  int|null   $expectedVersion optimistic lock check (null = skip)
     * @param  string     $source          UI entry point identifier
     * @param  int        $userId
     * @param  string     $language
     * @param  string     $timezone        user's IANA timezone (for bury)
     * @param  string|null $reason         optional user-supplied reason
     * @return array{review_card_id: int, lifecycle: array, request_id: string, already_applied: bool, event_id: int|null}
     *
     * @throws LifecycleValidationException for invalid action or timezone
     * @throws LifecycleConflictException for version conflict or illegal transition
     */
    public function act(
        ReviewCard $card,
        string $action,
        string $requestId,
        ?int $expectedVersion,
        string $source,
        int $userId,
        string $language,
        string $timezone,
        ?string $reason = null
    ): array {
        $this->validateAction($action);

        if ($action === ReviewCardLifecyclePolicy::ACTION_BURY) {
            if (!$this->buryTimeService->isValidTimezone($timezone)) {
                throw new LifecycleValidationException('invalid_timezone', "Invalid timezone: {$timezone}");
            }
        }

        return DB::transaction(function () use ($card, $action, $requestId, $expectedVersion, $source, $userId, $language, $timezone, $reason) {
            // Lock the card row for the duration of the transaction.
            $locked = ReviewCard::query()
                ->where('id', $card->id)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                abort(404);
            }

            // Validate access: user/language/sense/confirmed.
            $this->validateAccess($locked, $userId, $language);

            // Idempotency: if this request_id already has an event, return it.
            $existingEvent = ReviewCardStateEvent::query()
                ->where('request_id', $requestId)
                ->where('review_card_id', $locked->id)
                ->first();
            if ($existingEvent) {
                return $this->buildIdempotentResponse($locked, $existingEvent, $requestId, $userId, $language, $timezone);
            }

            $now = Carbon::now();

            // Optimistic lock check.
            if ($expectedVersion !== null && (int) $locked->lifecycle_version !== (int) $expectedVersion) {
                throw new LifecycleConflictException('version_conflict', 'Card lifecycle version has changed.');
            }

            // Compute effective state (expired buried → active).
            $effectiveState = $this->policy->effectiveState($locked, $now);

            // Validate transition.
            if (!$this->policy->canTransition($effectiveState, $action)) {
                throw new LifecycleConflictException('illegal_transition', "Cannot {$action} from {$effectiveState}.");
            }

            // Capture previous state.
            $previousState = $this->snapshotService->capture($locked);

            // Apply transition.
            $this->applyTransition($locked, $action, $now, $timezone);

            // Capture new state.
            $newState = $this->snapshotService->capture($locked);

            // Create state event.
            $event = ReviewCardStateEvent::create([
                'user_id' => $userId,
                'language_id' => $language,
                'review_card_id' => $locked->id,
                'action' => $action,
                'previous_state' => $previousState,
                'new_state' => $newState,
                'request_id' => $requestId,
                'source' => $source,
                'metadata' => $reason ? ['reason' => $reason] : null,
                'created_at' => $now,
            ]);

            // Build descriptor for response.
            $descriptor = $this->policy->describe($locked, $now, $timezone);

            return [
                'review_card_id' => $locked->id,
                'lifecycle' => $descriptor,
                'request_id' => $requestId,
                'already_applied' => false,
                'event_id' => $event->id,
            ];
        });
    }

    /**
     * Apply a transition to the card model (in memory; caller saves).
     */
    private function applyTransition(ReviewCard $card, string $action, Carbon $now, string $timezone): void
    {
        $newState = $this->policy->transitionTo(
            $this->policy->effectiveState($card, $now),
            $action
        );

        if ($newState === null) {
            throw new LifecycleConflictException('illegal_transition', "Cannot {$action}.");
        }

        $card->lifecycle_state = $newState;
        $card->lifecycle_version = (int) $card->lifecycle_version + 1;
        $card->lifecycle_changed_at = $now;

        // Compute buried_until.
        if ($action === ReviewCardLifecyclePolicy::ACTION_BURY) {
            $card->buried_until = $this->buryTimeService->buryUntil($timezone, $now);
        } else {
            // All other actions clear buried_until.
            $card->buried_until = null;
        }

        // Synchronize fsrs_enabled mirror.
        $card->fsrs_enabled = $this->mirrorFsrsEnabled($newState);

        $card->save();
    }

    /**
     * fsrs_enabled mirror invariant:
     *   active/buried → true
     *   suspended/archived → false
     */
    private function mirrorFsrsEnabled(string $lifecycleState): bool
    {
        return in_array($lifecycleState, [
            ReviewCardLifecyclePolicy::STATE_ACTIVE,
            ReviewCardLifecyclePolicy::STATE_BURIED,
        ], true);
    }

    /**
     * Validate that the action is a recognized lifecycle action.
     */
    private function validateAction(string $action): void
    {
        if (!in_array($action, ReviewCardLifecyclePolicy::actions(), true)) {
            throw new LifecycleValidationException('invalid_action', "Unknown lifecycle action: {$action}");
        }
    }

    /**
     * Validate user/language/sense/confirmed access.
     *
     * This mirrors ReviewCardManageAccessService but operates on an already-
     * loaded card inside a transaction. We don't call the access service
     * directly because it uses abort(404) which would break the transaction.
     */
    private function validateAccess(ReviewCard $card, int $userId, string $language): void
    {
        if ((int) $card->user_id !== $userId) {
            abort(404);
        }
        if ((string) $card->language_id !== (string) $language) {
            abort(404);
        }
        if ($card->target_type !== ReviewCard::TARGET_SENSE) {
            abort(404);
        }

        $sense = WordSense::query()
            ->where('id', $card->target_id)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', WordSense::STATUS_CONFIRMED)
            ->first();

        if (!$sense) {
            abort(404);
        }
    }

    /**
     * Build an idempotent response for a retried request_id.
     */
    private function buildIdempotentResponse(ReviewCard $card, ReviewCardStateEvent $event, string $requestId, int $userId, string $language, string $timezone): array
    {
        $now = Carbon::now();
        $descriptor = $this->policy->describe($card, $now, $timezone);

        return [
            'review_card_id' => $card->id,
            'lifecycle' => $descriptor,
            'request_id' => $requestId,
            'already_applied' => true,
            'event_id' => $event->id,
        ];
    }

    /**
     * Bulk execute a lifecycle action on multiple cards.
     *
     * Each card is processed in its own transaction. Partial failure does
     * not roll back successful items.
     *
     * @param  array<int> $cardIds
     * @return array{results: array<int, array>}
     */
    public function bulkAct(
        array $cardIds,
        string $action,
        string $source,
        int $userId,
        string $language,
        string $timezone
    ): array {
        $this->validateAction($action);

        $results = [];
        foreach ($cardIds as $cardId) {
            $results[] = $this->bulkActSingle(
                (int) $cardId,
                $action,
                $source,
                $userId,
                $language,
                $timezone
            );
        }
        return ['results' => $results];
    }

    /**
     * Process a single card in a bulk operation.
     */
    private function bulkActSingle(
        int $cardId,
        string $action,
        string $source,
        int $userId,
        string $language,
        string $timezone
    ): array {
        $requestId = Str::uuid()->toString();

        try {
            $card = ReviewCard::find($cardId);
            if (!$card) {
                return ['id' => $cardId, 'not_found' => true];
            }

            $result = $this->act(
                $card,
                $action,
                $requestId,
                null, // bulk does not use optimistic lock
                $source,
                $userId,
                $language,
                $timezone
            );

            return [
                'id' => $cardId,
                'success' => true,
                'event_id' => $result['event_id'],
                'already_applied' => $result['already_applied'],
            ];
        } catch (LifecycleConflictException $e) {
            return ['id' => $cardId, 'conflict' => $e->reason];
        } catch (LifecycleValidationException $e) {
            return ['id' => $cardId, 'error' => $e->reason];
        } catch (\Exception $e) {
            if (app()->environment('testing') && str_contains((string) $e->getMessage(), '404')) {
                return ['id' => $cardId, 'forbidden' => true];
            }
            // In non-testing environments, abort(404) throws HttpException
            // which we should treat as forbidden/not_found.
            $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
            if ($status === 404) {
                return ['id' => $cardId, 'forbidden' => true];
            }
            return ['id' => $cardId, 'error' => 'server_error'];
        }
    }
}
