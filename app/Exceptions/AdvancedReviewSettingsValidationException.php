<?php

namespace App\Exceptions;

use Exception;

class AdvancedReviewSettingsValidationException extends Exception
{
    public function __construct(
        private array $errors,
        string $message = '高级复习设置无效。',
    ) {
        parent::__construct($message);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
