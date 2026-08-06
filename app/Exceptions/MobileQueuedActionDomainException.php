<?php

namespace App\Exceptions;

use RuntimeException;

class MobileQueuedActionDomainException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfterMs = null,
    ) {
        parent::__construct($message);
    }
}
