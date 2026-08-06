<?php

namespace App\Services;

use App\Models\ReviewCard;
use Carbon\Carbon;
use InvalidArgumentException;

class ReviewCardOperationSnapshotService
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private ReviewCardFsrsSnapshotService $fsrsSnapshots,
        private ReviewCardLifecycleSnapshotService $lifecycleSnapshots,
    ) {}

    public function capture(ReviewCard $card): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'fsrs' => $this->fsrsSnapshots->capture($card),
            'lifecycle' => $this->lifecycleSnapshots->capture($card),
        ];
    }

    public function fingerprint(array $snapshot): string
    {
        $this->validate($snapshot);

        return hash('sha256', json_encode(
            $snapshot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    public function matches(ReviewCard $card, array $snapshot): bool
    {
        try {
            $this->validate($snapshot);
        } catch (InvalidArgumentException) {
            return false;
        }

        return hash_equals(
            $this->fingerprint($snapshot),
            $this->fingerprint($this->capture($card)),
        );
    }

    public function restore(ReviewCard $card, array $snapshot): void
    {
        $this->validate($snapshot);
        $this->fsrsSnapshots->restore($card, $snapshot['fsrs']);

        $lifecycle = $snapshot['lifecycle'];
        $card->lifecycle_state = $lifecycle['lifecycle_state'];
        $card->buried_until = $lifecycle['buried_until']
            ? Carbon::parse($lifecycle['buried_until'])
            : null;
        $card->lifecycle_version = (int) $lifecycle['lifecycle_version'];
        $card->lifecycle_changed_at = $lifecycle['lifecycle_changed_at']
            ? Carbon::parse($lifecycle['lifecycle_changed_at'])
            : null;
        $card->fsrs_enabled = (bool) $lifecycle['fsrs_enabled'];
    }

    public function validate(array $snapshot): void
    {
        if (($snapshot['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Unsupported operation snapshot schema.');
        }
        if (! is_array($snapshot['fsrs'] ?? null)
            || ! is_array($snapshot['lifecycle'] ?? null)) {
            throw new InvalidArgumentException('Operation snapshot sections are required.');
        }

        $this->fsrsSnapshots->validate($snapshot['fsrs']);
        $lifecycle = $snapshot['lifecycle'];
        foreach ([
            'lifecycle_state',
            'buried_until',
            'lifecycle_version',
            'lifecycle_changed_at',
            'fsrs_enabled',
        ] as $field) {
            if (! array_key_exists($field, $lifecycle)) {
                throw new InvalidArgumentException("Lifecycle snapshot missing field: {$field}");
            }
        }
        if (! in_array($lifecycle['lifecycle_state'], [
            ReviewCard::LIFECYCLE_ACTIVE,
            ReviewCard::LIFECYCLE_BURIED,
            ReviewCard::LIFECYCLE_SUSPENDED,
            ReviewCard::LIFECYCLE_ARCHIVED,
        ], true)) {
            throw new InvalidArgumentException('Invalid lifecycle state.');
        }
        if ($lifecycle['buried_until'] !== null && ! is_string($lifecycle['buried_until'])) {
            throw new InvalidArgumentException('buried_until must be a string or null.');
        }
        if (! is_int($lifecycle['lifecycle_version'])) {
            throw new InvalidArgumentException('lifecycle_version must be an integer.');
        }
        if ($lifecycle['lifecycle_changed_at'] !== null
            && ! is_string($lifecycle['lifecycle_changed_at'])) {
            throw new InvalidArgumentException('lifecycle_changed_at must be a string or null.');
        }
        if (! is_bool($lifecycle['fsrs_enabled'])) {
            throw new InvalidArgumentException('fsrs_enabled must be a boolean.');
        }
    }
}
