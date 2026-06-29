<?php

namespace App\Exceptions;

use Exception;

class DailyLimitsValidationException extends Exception
{
    /** @var array<string, string> */
    protected array $errors;

    public function __construct(array $errors, string $message = '每日上限设置无效。')
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
