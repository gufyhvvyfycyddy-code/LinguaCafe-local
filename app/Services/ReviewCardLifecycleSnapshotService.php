<?php

namespace App\Services;

use App\Models\ReviewCard;

/**
 * Captures lifecycle state snapshots for audit and undo (ADR-0010).
 *
 * This service is distinct from ReviewCardFsrsSnapshotService (ADR-0009),
 * which captures FSRS scheduling fields. This service captures only the
 * lifecycle fields:
 *   - lifecycle_state
 *   - buried_until
 *   - lifecycle_version
 *   - lifecycle_changed_at
 *   - fsrs_enabled (compatibility mirror)
 *
 * Snapshots are stored in review_card_state_events.previous_state and
 * new_state as JSON.
 */
class ReviewCardLifecycleSnapshotService
{
    /**
     * Capture the current lifecycle state of a card.
     *
     * @return array<string, mixed>
     */
    public function capture(ReviewCard $card): array
    {
        return [
            'lifecycle_state' => $card->lifecycle_state ?? ReviewCard::LIFECYCLE_ACTIVE,
            'buried_until' => $card->buried_until ? $card->buried_until->toIso8601String() : null,
            'lifecycle_version' => (int) ($card->lifecycle_version ?? 0),
            'lifecycle_changed_at' => $card->lifecycle_changed_at ? $card->lifecycle_changed_at->toIso8601String() : null,
            'fsrs_enabled' => (bool) $card->fsrs_enabled,
        ];
    }

    /**
     * Whether two snapshots are equivalent (ignoring timestamp formatting).
     */
    public function matches(array $a, array $b): bool
    {
        return ($a['lifecycle_state'] ?? null) === ($b['lifecycle_state'] ?? null)
            && (bool) ($a['fsrs_enabled'] ?? false) === (bool) ($b['fsrs_enabled'] ?? false)
            && (int) ($a['lifecycle_version'] ?? 0) === (int) ($b['lifecycle_version'] ?? 0);
    }
}
