<?php

namespace App\Services\Dictionaries;

final class DictionaryLookupResultPolicy
{
    public const DEFINITION_LIMIT = 10;

    /** @return string[] */
    public function splitImportedDefinitions(string $definitions): array
    {
        $parts = array_map(
            static fn (string $definition): string => trim($definition),
            explode(';', $definitions),
        );

        return array_values(array_filter(
            $parts,
            static fn (string $definition): bool => $definition !== '',
        ));
    }

    /** @param iterable<mixed> $definitions
     *  @return string[]
     */
    public function dedupeAndCap(iterable $definitions, int $limit = self::DEFINITION_LIMIT): array
    {
        $result = [];
        $seen = [];

        foreach ($definitions as $definition) {
            if (! is_string($definition)) {
                continue;
            }

            $normalized = trim($definition);
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $result[] = $normalized;

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }
}
