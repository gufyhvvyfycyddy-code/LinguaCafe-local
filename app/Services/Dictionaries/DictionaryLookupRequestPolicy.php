<?php

namespace App\Services\Dictionaries;

use InvalidArgumentException;

final class DictionaryLookupRequestPolicy
{
    public const MAX_TERM_LENGTH = 100;

    /** @return array<int, mixed> */
    public function validationRules(): array
    {
        return [
            'bail',
            'required',
            'string',
            'max:'.self::MAX_TERM_LENGTH,
            function (string $attribute, mixed $value, callable $fail): void {
                try {
                    $this->normalize((string) $value);
                } catch (InvalidArgumentException $exception) {
                    $fail($exception->getMessage());
                }
            },
        ];
    }

    public function normalize(string $term): string
    {
        $normalized = trim($term);

        if ($normalized === '') {
            throw new InvalidArgumentException('The dictionary lookup term is required.');
        }

        if (mb_strlen($normalized, 'UTF-8') > self::MAX_TERM_LENGTH) {
            throw new InvalidArgumentException('The dictionary lookup term may not exceed 100 characters.');
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $normalized) === 1) {
            throw new InvalidArgumentException('The dictionary lookup term contains unsupported control characters.');
        }

        return $normalized;
    }
}
