<?php

namespace App\Exceptions;

use RuntimeException;

class ReviewCardManualOperationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }
}
