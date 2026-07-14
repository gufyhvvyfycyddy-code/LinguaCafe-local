<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when Custom Study session state construction or validation fails.
 *
 * Mirrors the structured-payload style of CustomStudyValidationException,
 * but carries a single machine-readable reason code + human-readable message
 * for session-state invariant violations (State errors are single-reason per
 * throw site — the State validator fails on the first invalid invariant).
 *
 * The Exception is pure: it does NOT return an HTTP Response, does NOT
 * read the Request, does NOT access Auth, and does NOT query the database.
 * A future Controller / SessionService will be responsible for converting
 * this exception into an appropriate response (e.g. 422 for caller errors,
 * 500 for invariant violations, or silently dropping tampered tokens).
 *
 * Task 2000-19 — Custom Study 1A Phase 3A.
 */
class CustomStudySessionStateException extends Exception
{
    public function __construct(
        private readonly string $reason,
        string $message = 'Custom Study session state validation failed.'
    ) {
        parent::__construct($message);
    }

    /**
     * A stable, machine-readable reason code identifying the invariant that
     * was violated (e.g. 'invalid_version', 'unknown_mode',
     * 'invalid_session_id', 'current_overlap', 'lost_ordered_id',
     * 'completed_count_mismatch', 'invalid_delay_config').
     *
     * Callers MUST NOT parse the human-readable message text; they must
     * branch on `reason` if any programmatic decision is needed.
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
