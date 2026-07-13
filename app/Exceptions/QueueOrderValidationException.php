<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when Queue Order settings validation fails.
 *
 * Mirrors DailyLimitsValidationException: structured 422 payload
 * with field-keyed errors, no partial save.
 */
class QueueOrderValidationException extends Exception
{
    /** @var array<string, string> */
    protected array $errors;

    public function __construct(array $errors, string $message = '复习显示顺序设置无效。')
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    /**
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
