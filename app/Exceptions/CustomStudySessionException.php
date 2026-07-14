<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a Custom Study preview session cannot be found or verified.
 *
 * Pure exception — does NOT construct an HTTP response, does NOT access
 * the request, does NOT access Auth, does NOT query the database.
 *
 * The Controller is responsible for mapping this exception to a 404
 * JSON response with a generic message (no internal reason leakage).
 *
 * Known reasons:
 * - session_not_found: token verification failed (tampered, expired,
 *   wrong user, wrong language, unsupported version, or corrupted).
 *
 * Task 2000-22 — Phase 4B.
 */
class CustomStudySessionException extends Exception
{
    public const REASON_SESSION_NOT_FOUND = 'session_not_found';

    public function __construct(
        private readonly string $reason,
        string $message = 'Custom Study session error.'
    ) {
        parent::__construct($message);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
