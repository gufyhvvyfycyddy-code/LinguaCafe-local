<?php

namespace App\Exceptions;

use RuntimeException;

class LanguageSelectionException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}
