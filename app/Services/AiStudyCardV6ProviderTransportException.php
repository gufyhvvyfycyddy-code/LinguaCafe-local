<?php

namespace App\Services;

use RuntimeException;
use Throwable;

class AiStudyCardV6ProviderTransportException extends RuntimeException
{
    public function __construct(
        private string $failureCode,
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
    )
    {
        parent::__construct($message, $code, $previous);
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }
}
