<?php

namespace App\Exceptions;

use RuntimeException;

class ReviewSettingsPresetException extends RuntimeException
{
    public function __construct(
        string $message,
        private int $status = 422,
        private array $errors = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('Preset 不存在。', 404);
    }

    public static function validation(string $message, string $field = 'name'): self
    {
        return new self($message, 422, [$field => [$message]]);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function response(): array
    {
        return array_filter([
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ], fn (mixed $value): bool => $value !== []);
    }
}
