<?php

namespace App\Exceptions;

use RuntimeException;

final class DictionaryReadException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $publicMessage,
        public readonly int $httpStatus,
    ) {
        parent::__construct($publicMessage);
    }

    public static function notFound(): self
    {
        return new self(
            'DICTIONARY_NOT_FOUND',
            'The requested dictionary was not found.',
            404,
        );
    }

    public static function lookupUnavailable(): self
    {
        return new self(
            'DICTIONARY_LOOKUP_UNAVAILABLE',
            'Dictionary lookup is temporarily unavailable.',
            503,
        );
    }

    public static function recordCountNotAllowed(): self
    {
        return new self(
            'DICTIONARY_RECORD_COUNT_NOT_ALLOWED',
            'Record count is not available for this target.',
            422,
        );
    }
}
