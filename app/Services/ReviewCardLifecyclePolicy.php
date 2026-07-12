<?php

namespace App\Services;

use App\Models\ReviewCard;
use Carbon\Carbon;

/**
 * Pure lifecycle state machine policy for review cards (ADR-0010).
 *
 * This service has NO database access and NO side effects. It only:
 *   - describes the current effective state of a card
 *   - validates whether a transition is legal
 *   - lists the lifecycle actions available in a given state
 *
 * The frontend never replicates this state machine — it consumes the
 * descriptor produced by describe() and rendered by the backend.
 */
class ReviewCardLifecyclePolicy
{
    public const STATE_ACTIVE = 'active';
    public const STATE_BURIED = 'buried';
    public const STATE_SUSPENDED = 'suspended';
    public const STATE_ARCHIVED = 'archived';

    public const ACTION_BURY = 'bury';
    public const ACTION_UNBURY = 'unbury';
    public const ACTION_SUSPEND = 'suspend';
    public const ACTION_RESUME = 'resume';
    public const ACTION_ARCHIVE = 'archive';
    public const ACTION_RESTORE = 'restore';

    public const REASON_SUSPENDED = 'suspended';
    public const REASON_ARCHIVED = 'archived';
    public const REASON_TEMPORARILY_BURIED = 'temporarily_buried';

    /**
     * All persistent lifecycle states.
     */
    public static function states(): array
    {
        return [
            self::STATE_ACTIVE,
            self::STATE_BURIED,
            self::STATE_SUSPENDED,
            self::STATE_ARCHIVED,
        ];
    }

    /**
     * All lifecycle actions (excluding reset/delete, which are not states).
     */
    public static function actions(): array
    {
        return [
            self::ACTION_BURY,
            self::ACTION_UNBURY,
            self::ACTION_SUSPEND,
            self::ACTION_RESUME,
            self::ACTION_ARCHIVE,
            self::ACTION_RESTORE,
        ];
    }

    /**
     * Legal transitions map: [from_effective_state => [action => to_state]].
     *
     * Note: 'buried' only appears as a source when the bury is still in
     * effect (not expired). Expired buried is treated as 'active' by
     * describe(), so canTransition() receives 'active' in that case.
     */
    public static function transitionTable(): array
    {
        return [
            self::STATE_ACTIVE => [
                self::ACTION_BURY => self::STATE_BURIED,
                self::ACTION_SUSPEND => self::STATE_SUSPENDED,
                self::ACTION_ARCHIVE => self::STATE_ARCHIVED,
            ],
            self::STATE_BURIED => [
                self::ACTION_UNBURY => self::STATE_ACTIVE,
            ],
            self::STATE_SUSPENDED => [
                self::ACTION_RESUME => self::STATE_ACTIVE,
                self::ACTION_ARCHIVE => self::STATE_ARCHIVED,
            ],
            self::STATE_ARCHIVED => [
                self::ACTION_RESTORE => self::STATE_ACTIVE,
            ],
        ];
    }

    /**
     * Whether a transition from $from (effective state) via $action is legal.
     *
     * @param  string $from  effective state (active|buried|suspended|archived)
     * @param  string $action lifecycle action
     */
    public function canTransition(string $from, string $action): bool
    {
        $table = self::transitionTable();
        return isset($table[$from][$action]);
    }

    /**
     * The resulting state after applying $action to $from.
     *
     * @return string|null null if the transition is illegal
     */
    public function transitionTo(string $from, string $action): ?string
    {
        $table = self::transitionTable();
        return $table[$from][$action] ?? null;
    }

    /**
     * Whether a buried card's temporary hide is still in effect.
     *
     * True only when lifecycle_state='buried' AND buried_until is set
     * AND buried_until is in the future relative to $now.
     */
    public function isTemporarilyBuried(ReviewCard $card, Carbon $now): bool
    {
        if ($card->lifecycle_state !== self::STATE_BURIED) {
            return false;
        }
        if (!$card->buried_until) {
            return false;
        }
        return $card->buried_until->isFuture();
    }

    /**
     * Compute the effective state of a card.
     *
     * - buried + not expired → 'buried'
     * - buried + expired     → 'active' (auto-revert by query semantics)
     * - other states         → as stored
     */
    public function effectiveState(ReviewCard $card, Carbon $now): string
    {
        if ($card->lifecycle_state === self::STATE_BURIED && !$this->isTemporarilyBuried($card, $now)) {
            return self::STATE_ACTIVE;
        }
        return $card->lifecycle_state;
    }

    /**
     * Produce a complete descriptor of the card's current lifecycle state.
     *
     * Returns:
     *   persistent_state   — the stored lifecycle_state
     *   temporarily_buried — true only if buried AND not expired
     *   effective_state    — active|buried|suspended|archived (expired buried → active)
     *   queue_eligible     — true if the card can appear in the review queue
     *   blocked_reason     — why not queue eligible (null if eligible)
     *   buried_until       — ISO string or null
     *   available_actions  — lifecycle actions legal from the effective state
     *   version            — lifecycle_version
     */
    public function describe(ReviewCard $card, Carbon $now, string $timezone = 'UTC'): array
    {
        $persistent = $card->lifecycle_state ?? self::STATE_ACTIVE;
        $temporarilyBuried = $this->isTemporarilyBuried($card, $now);
        $effective = $this->effectiveState($card, $now);

        $queueEligible = false;
        $blockedReason = null;

        if ($effective === self::STATE_ACTIVE) {
            $queueEligible = true;
        } elseif ($effective === self::STATE_BURIED) {
            $queueEligible = false;
            $blockedReason = self::REASON_TEMPORARILY_BURIED;
        } elseif ($effective === self::STATE_SUSPENDED) {
            $queueEligible = false;
            $blockedReason = self::REASON_SUSPENDED;
        } elseif ($effective === self::STATE_ARCHIVED) {
            $queueEligible = false;
            $blockedReason = self::REASON_ARCHIVED;
        }

        return [
            'persistent_state' => $persistent,
            'temporarily_buried' => $temporarilyBuried,
            'effective_state' => $effective,
            'queue_eligible' => $queueEligible,
            'blocked_reason' => $blockedReason,
            'buried_until' => $card->buried_until ? $card->buried_until->toIso8601String() : null,
            'available_actions' => $this->availableActionsForState($effective),
            'version' => (int) ($card->lifecycle_version ?? 0),
            'changed_at' => $card->lifecycle_changed_at ? $card->lifecycle_changed_at->toIso8601String() : null,
            'timezone' => $timezone,
        ];
    }

    /**
     * Lifecycle actions available from a given effective state.
     *
     * Reset and delete are NOT included — they are not lifecycle actions.
     */
    public function availableActionsForState(string $effectiveState): array
    {
        $table = self::transitionTable();
        $actions = array_keys($table[$effectiveState] ?? []);
        return $actions;
    }

    /**
     * Convenience wrapper: available actions for a card's effective state.
     */
    public function availableActions(ReviewCard $card, Carbon $now): array
    {
        return $this->availableActionsForState($this->effectiveState($card, $now));
    }

    /**
     * Whether the card is in a terminal (non-active) persistent state.
     *
     * Used by the undo policy to decide if undo should be blocked.
     * Buried is NOT terminal — it auto-reverts.
     */
    public function isTerminalState(ReviewCard $card): bool
    {
        return in_array($card->lifecycle_state, [self::STATE_SUSPENDED, self::STATE_ARCHIVED], true);
    }
}
