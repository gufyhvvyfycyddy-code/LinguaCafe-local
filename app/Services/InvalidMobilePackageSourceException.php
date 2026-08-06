<?php

namespace App\Services;

use RuntimeException;

class InvalidMobilePackageSourceException extends RuntimeException
{
    public function __construct(
        string $message = 'The package source data is invalid.',
        public readonly string $errorCode = 'INVALID_PACKAGE_SOURCE',
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }
}
