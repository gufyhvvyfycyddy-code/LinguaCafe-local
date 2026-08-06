<?php

namespace App\Exceptions;

use RuntimeException;

class SpecialStudyException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }
}
