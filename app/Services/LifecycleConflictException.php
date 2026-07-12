<?php

namespace App\Services;

use RuntimeException;

/**
 * Thrown when a lifecycle transition conflicts with the current card state.
 *
 * Maps to HTTP 409. The $reason field distinguishes:
 *   - 'version_conflict'   — expected_version does not match
 *   - 'illegal_transition' — canTransition() returned false
 */
class LifecycleConflictException extends RuntimeException
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
