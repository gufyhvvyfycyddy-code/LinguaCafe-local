<?php

namespace App\Services;

use RuntimeException;

/**
 * Thrown when a lifecycle request fails validation.
 *
 * Maps to HTTP 422. The $reason field distinguishes:
 *   - 'invalid_action'   — action is not a recognized lifecycle action
 *   - 'invalid_timezone' — timezone string is not a valid IANA timezone
 */
class LifecycleValidationException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message ?: $reason, $code, $previous);
    }
}
