<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by CustomStudyPreviewPolicy when a rating or resume operation violates
 * the frozen Phase 3B contract.
 *
 * The `reason` field is the machine protocol — callers MUST NOT parse the
 * human `message` text. Known reasons:
 * - `invalid_rating`  : rating is not one of again/hard/good/easy (lowercase).
 * - `no_current_card` : applyRating called when current_card_id is null.
 *
 * Task 2000-20 — Custom Study 1A Phase 3B.
 */
class CustomStudyPreviewPolicyException extends Exception
{
    public function __construct(
        private readonly string $reason,
        string $message = 'Custom Study preview policy validation failed.'
    ) {
        parent::__construct($message);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
