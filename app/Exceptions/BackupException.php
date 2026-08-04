<?php

namespace App\Exceptions;

use RuntimeException;

class BackupException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 500,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
