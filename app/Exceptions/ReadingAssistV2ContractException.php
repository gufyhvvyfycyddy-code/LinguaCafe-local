<?php

namespace App\Exceptions;

use InvalidArgumentException;

class ReadingAssistV2ContractException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
