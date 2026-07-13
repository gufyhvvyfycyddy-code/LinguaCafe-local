<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when Custom Study criteria validation fails.
 *
 * Mirrors the structured-payload style of QueueOrderValidationException and
 * DailyLimitsValidationException, but carries a single machine-readable
 * field + reason pair (Criteria validation errors are per-field, not a
 * batched errors map — the validator fails on the first invalid field).
 *
 * The Exception is pure: it does NOT return an HTTP Response, does NOT
 * read the Request, does NOT access Auth, and does NOT query the database.
 * A future Controller will be responsible for converting this exception
 * into a 422 response.
 *
 * Task CS-2 of Custom Study 1A Phase 1 (Task 2000-16).
 */
class CustomStudyValidationException extends Exception
{
    public function __construct(
        private readonly string $field,
        private readonly string $reason,
        string $message = 'Custom Study criteria validation failed.'
    ) {
        parent::__construct($message);
    }

    /**
     * The input field that failed validation (e.g. 'mode', 'chapter_id', 'user_id', 'language').
     */
    public function getField(): string
    {
        return $this->field;
    }

    /**
     * A stable, machine-readable reason code (e.g. 'unknown_mode', 'chapter_not_owned',
     * 'invalid_user_id', 'invalid_language', 'missing_chapter_id', 'invalid_chapter_id',
     * 'missing_sub_mode', 'invalid_sub_mode').
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
