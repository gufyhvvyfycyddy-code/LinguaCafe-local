<?php

namespace App\Services;

use RuntimeException;

/**
 * Thrown when the browser search grammar parser detects an invalid,
 * unsupported, or conflicting token.
 *
 * ADR-0012: The controller catches this and returns a structured 422
 * JSON response with per-token error details so the frontend can show
 * specific guidance (not a generic "load failed" message).
 *
 * The exception carries:
 *  - message: human-readable summary (Chinese)
 *  - errors: list of {token, reason, example} for each invalid token
 */
class InvalidBrowserSearchException extends RuntimeException
{
    /** @var list<array{token: string, reason: string, example: string}> */
    private array $errors;

    /**
     * @param string $message
     * @param list<array{token: string, reason: string, example: string}> $errors
     */
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    /**
     * @return list<array{token: string, reason: string, example: string}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Convert to the JSON response structure expected by the frontend.
     *
     * @return array{message: string, code: string, errors: list<array{token: string, reason: string, example: string}>}
     */
    public function toResponseArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => 'invalid_browser_search',
            'errors' => $this->errors,
        ];
    }
}
