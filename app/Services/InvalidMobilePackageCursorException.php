<?php

namespace App\Services;

use RuntimeException;

class InvalidMobilePackageCursorException extends RuntimeException
{
    public function __construct(
        string $message = 'The package cursor is invalid.',
        public readonly string $errorCode = 'INVALID_PACKAGE_CURSOR',
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
