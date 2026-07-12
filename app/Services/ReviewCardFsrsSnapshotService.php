<?php

namespace App\Services;

use App\Models\ReviewCard;
use Carbon\Carbon;

/**
 * ReviewCardFsrsSnapshotService
 *
 * Captures and restores the complete FSRS state of a ReviewCard
 * for the undo ledger. Each snapshot is a plain associative array
 * containing exactly 8 fields — nothing more, nothing less.
 *
 * Snapshot fields:
 *   fsrs_state            string
 *   fsrs_due_at           ISO 8601 string (normalized from Carbon)
 *   fsrs_stability        float (normalized to 6 decimal places)
 *   fsrs_difficulty       float (normalized to 6 decimal places)
 *   fsrs_last_reviewed_at ISO 8601 string or null
 *   fsrs_reps             integer
 *   fsrs_lapses           integer
 *   fsrs_enabled          boolean
 *
 * Design rules (frozen in ADR-0009):
 *   - capture() does NOT query the database and does NOT save.
 *   - restore() sets attributes on the model but does NOT save.
 *   - matches() does NOT query the database.
 *   - validate() throws on missing or malformed fields.
 *   - Partial restore is forbidden — validate() runs first.
 *   - Datetime values use Carbon::toIso8601String() for stability.
 *   - Float values use round($v, 6) for fingerprint stability.
 *   - The snapshot never includes id, user_id, language_id,
 *     target_type, target_id, created_at, or updated_at.
 */
class ReviewCardFsrsSnapshotService
{
    /**
     * The exact fields captured in every snapshot.
     */
    public const SNAPSHOT_FIELDS = [
        'fsrs_state',
        'fsrs_due_at',
        'fsrs_stability',
        'fsrs_difficulty',
        'fsrs_last_reviewed_at',
        'fsrs_reps',
        'fsrs_lapses',
        'fsrs_enabled',
    ];

    /**
     * Capture the complete FSRS state of a ReviewCard.
     *
     * Pure: does not query the database, does not save, does not
     * modify the model.
     */
    public function capture(ReviewCard $card): array
    {
        return [
            'fsrs_state' => $card->fsrs_state,
            'fsrs_due_at' => $card->fsrs_due_at
                ? Carbon::parse($card->fsrs_due_at)->toIso8601String()
                : null,
            'fsrs_stability' => $card->fsrs_stability !== null
                ? round((float) $card->fsrs_stability, 6)
                : null,
            'fsrs_difficulty' => $card->fsrs_difficulty !== null
                ? round((float) $card->fsrs_difficulty, 6)
                : null,
            'fsrs_last_reviewed_at' => $card->fsrs_last_reviewed_at
                ? Carbon::parse($card->fsrs_last_reviewed_at)->toIso8601String()
                : null,
            'fsrs_reps' => (int) $card->fsrs_reps,
            'fsrs_lapses' => (int) $card->fsrs_lapses,
            'fsrs_enabled' => (bool) $card->fsrs_enabled,
        ];
    }

    /**
     * Restore a complete FSRS snapshot onto a ReviewCard model.
     *
     * Sets FSRS scheduling attributes on the model but does NOT call save().
     * The caller is responsible for persisting the model within the
     * same transaction.
     *
     * ADR-0010: fsrs_enabled is NOT restored. It is now a mirror of
     * lifecycle_state, which is owned exclusively by
     * ReviewCardLifecycleCommandService. The undo service checks
     * lifecycle state separately via SenseReviewUndoPolicy.
     *
     * @param  array  $snapshot  Must pass validate() first.
     */
    public function restore(ReviewCard $card, array $snapshot): void
    {
        $this->validate($snapshot);

        $card->fsrs_state = $snapshot['fsrs_state'];
        $card->fsrs_due_at = $snapshot['fsrs_due_at']
            ? Carbon::parse($snapshot['fsrs_due_at'])
            : null;
        $card->fsrs_stability = $snapshot['fsrs_stability'] !== null
            ? (float) $snapshot['fsrs_stability']
            : null;
        $card->fsrs_difficulty = $snapshot['fsrs_difficulty'] !== null
            ? (float) $snapshot['fsrs_difficulty']
            : null;
        $card->fsrs_last_reviewed_at = $snapshot['fsrs_last_reviewed_at']
            ? Carbon::parse($snapshot['fsrs_last_reviewed_at'])
            : null;
        $card->fsrs_reps = (int) $snapshot['fsrs_reps'];
        $card->fsrs_lapses = (int) $snapshot['fsrs_lapses'];
        // ADR-0010: fsrs_enabled is NOT restored — it is a lifecycle mirror.
        // $card->fsrs_enabled = (bool) $snapshot['fsrs_enabled'];
    }

    /**
     * Check whether the current FSRS state of a ReviewCard matches
     * a snapshot.
     *
     * Pure: does not query the database. Compares each field with
     * the same normalization as capture().
     */
    public function matches(ReviewCard $card, array $snapshot): bool
    {
        try {
            $this->validate($snapshot);
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        $current = $this->capture($card);

        // Compare each field with type-aware normalization.
        foreach (self::SNAPSHOT_FIELDS as $field) {
            if (!$this->fieldMatches($current[$field], $snapshot[$field], $field)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compute a stable fingerprint for a snapshot.
     *
     * Used for quick comparison and logging. The fingerprint is
     * deterministic: the same snapshot always produces the same
     * fingerprint.
     */
    public function fingerprint(array $snapshot): string
    {
        $this->validate($snapshot);

        $parts = [];
        foreach (self::SNAPSHOT_FIELDS as $field) {
            $value = $snapshot[$field];
            if ($value === null) {
                $parts[] = $field . ':null';
            } elseif (is_bool($value)) {
                $parts[] = $field . ':' . ($value ? 'true' : 'false');
            } elseif (is_float($value) || is_int($value)) {
                $parts[] = $field . ':' . number_format((float) $value, 6, '.', '');
            } else {
                $parts[] = $field . ':' . (string) $value;
            }
        }

        return md5(implode('|', $parts));
    }

    /**
     * Validate that a snapshot contains all required fields with
     * correct types.
     *
     * @throws \InvalidArgumentException if any field is missing or
     *         has an incorrect type.
     */
    public function validate(array $snapshot): void
    {
        foreach (self::SNAPSHOT_FIELDS as $field) {
            if (!array_key_exists($field, $snapshot)) {
                throw new \InvalidArgumentException(
                    "Snapshot missing required field: {$field}"
                );
            }
        }

        // Type checks (null is allowed for nullable fields).
        if (!is_string($snapshot['fsrs_state'])) {
            throw new \InvalidArgumentException('fsrs_state must be a string');
        }
        if ($snapshot['fsrs_due_at'] !== null && !is_string($snapshot['fsrs_due_at'])) {
            throw new \InvalidArgumentException('fsrs_due_at must be a string or null');
        }
        if ($snapshot['fsrs_stability'] !== null && !is_numeric($snapshot['fsrs_stability'])) {
            throw new \InvalidArgumentException('fsrs_stability must be numeric or null');
        }
        if ($snapshot['fsrs_difficulty'] !== null && !is_numeric($snapshot['fsrs_difficulty'])) {
            throw new \InvalidArgumentException('fsrs_difficulty must be numeric or null');
        }
        if ($snapshot['fsrs_last_reviewed_at'] !== null && !is_string($snapshot['fsrs_last_reviewed_at'])) {
            throw new \InvalidArgumentException('fsrs_last_reviewed_at must be a string or null');
        }
        if (!is_int($snapshot['fsrs_reps']) && !is_numeric($snapshot['fsrs_reps'])) {
            throw new \InvalidArgumentException('fsrs_reps must be an integer');
        }
        if (!is_int($snapshot['fsrs_lapses']) && !is_numeric($snapshot['fsrs_lapses'])) {
            throw new \InvalidArgumentException('fsrs_lapses must be an integer');
        }
        if (!is_bool($snapshot['fsrs_enabled'])) {
            throw new \InvalidArgumentException('fsrs_enabled must be a boolean');
        }
    }

    /**
     * Compare a single field between current state and snapshot.
     */
    private function fieldMatches($current, $snapshot, string $field): bool
    {
        if ($field === 'fsrs_stability' || $field === 'fsrs_difficulty') {
            if ($current === null && $snapshot === null) {
                return true;
            }
            if ($current === null || $snapshot === null) {
                return false;
            }
            return abs((float) $current - (float) $snapshot) < 0.000001;
        }

        if ($field === 'fsrs_due_at' || $field === 'fsrs_last_reviewed_at') {
            if ($current === null && $snapshot === null) {
                return true;
            }
            if ($current === null || $snapshot === null) {
                return false;
            }
            // Compare as ISO 8601 strings (both normalized by capture()).
            return $current === $snapshot;
        }

        if ($field === 'fsrs_enabled') {
            return (bool) $current === (bool) $snapshot;
        }

        if ($field === 'fsrs_reps' || $field === 'fsrs_lapses') {
            return (int) $current === (int) $snapshot;
        }

        // String comparison for fsrs_state.
        return (string) $current === (string) $snapshot;
    }
}
