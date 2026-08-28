<?php

namespace App\Services;

use App\Exceptions\BackupException;

class SqlDumpInspector
{
    /**
     * @return array{uncompressed_size_bytes: int, tables: list<string>, schema_fingerprint: string}
     */
    public function inspect(string $path, array $requiredTables = []): array
    {
        $maximumBytes = max(
            1,
            (int) config('backup.restore_max_uncompressed_bytes', 2 * 1024 * 1024 * 1024),
        );
        $maximumStatementBytes = max(
            1024,
            (int) config('backup.restore_max_statement_bytes', 64 * 1024 * 1024),
        );
        $handle = str_ends_with(strtolower($path), '.gz')
            ? gzopen($path, 'rb')
            : fopen($path, 'rb');

        if ($handle === false) {
            throw $this->invalid('The backup payload could not be opened.');
        }

        $tables = [];
        $bytes = 0;
        $statement = '';
        $state = 'normal';
        $escaped = false;
        $versioned = false;
        $versionedBody = '';
        $previous = '';
        $pendingCharacter = '';

        try {
            while (! $this->endOfFile($handle, $path)) {
                $chunk = $this->read($handle, $path);
                if ($chunk === false) {
                    throw $this->invalid('The backup payload could not be read.');
                }

                $bytes += strlen($chunk);
                if ($bytes > $maximumBytes) {
                    throw new BackupException(
                        'BACKUP_RESTORE_ARCHIVE_TOO_LARGE',
                        'The expanded backup exceeds the configured safety limit.',
                        422,
                    );
                }

                $chunk = $pendingCharacter . $chunk;
                $pendingCharacter = '';
                if (! $this->endOfFile($handle, $path) && $chunk !== '') {
                    $pendingCharacter = substr($chunk, -1);
                    $chunk = substr($chunk, 0, -1);
                }

                $length = strlen($chunk);
                for ($index = 0; $index < $length; $index++) {
                    $character = $chunk[$index];
                    $next = $index + 1 < $length ? $chunk[$index + 1] : '';

                    if ($state === 'line_comment') {
                        if ($character === "\n" || $character === "\r") {
                            $state = 'normal';
                        }
                        continue;
                    }

                    if ($state === 'block_comment') {
                        if ($versioned) {
                            $versionedBody .= $character;
                        }
                        if ($previous === '*' && $character === '/') {
                            if ($versioned) {
                                $versionedBody = substr($versionedBody, 0, -2);
                                $this->inspectVersionedComment($versionedBody);
                            }
                            $state = 'normal';
                            $versioned = false;
                            $versionedBody = '';
                        }
                        $previous = $character;
                        continue;
                    }

                    if ($state !== 'normal') {
                        $statement .= $character;
                        if ($escaped) {
                            $escaped = false;
                        } elseif ($character === '\\' && $state !== 'backtick') {
                            $escaped = true;
                        } elseif (($state === 'single' && $character === "'")
                            || ($state === 'double' && $character === '"')
                            || ($state === 'backtick' && $character === '`')) {
                            if ($next === $character) {
                                $statement .= $next;
                                $index++;
                            } else {
                                $state = 'normal';
                            }
                        }
                        $this->assertStatementSize($statement, $maximumStatementBytes);
                        continue;
                    }

                    if ($character === '-' && $next === '-'
                        && ($index + 2 >= $length || ctype_space($chunk[$index + 2]))) {
                        $state = 'line_comment';
                        $index++;
                        continue;
                    }
                    if ($character === '#') {
                        $state = 'line_comment';
                        continue;
                    }
                    if ($character === '/' && $next === '*') {
                        $state = 'block_comment';
                        $versioned = ($index + 2 < $length && $chunk[$index + 2] === '!');
                        if ($versioned) {
                            $index += 2;
                            $versionedBody = '!';
                        } else {
                            $index++;
                        }
                        $previous = '';
                        continue;
                    }
                    if ($character === "'") {
                        $state = 'single';
                    } elseif ($character === '"') {
                        $state = 'double';
                    } elseif ($character === '`') {
                        $state = 'backtick';
                    } elseif ($character === ';') {
                        $this->inspectStatement($statement, $tables);
                        $statement = '';
                        continue;
                    }

                    $statement .= $character;
                    $this->assertStatementSize($statement, $maximumStatementBytes);
                }
            }

            if ($state !== 'normal' && $state !== 'line_comment') {
                throw $this->invalid('The backup contains an unterminated SQL token.');
            }
            $this->inspectStatement($statement, $tables);
        } finally {
            str_ends_with(strtolower($path), '.gz') ? gzclose($handle) : fclose($handle);
        }

        if ($bytes < 1 || $tables === []) {
            throw $this->invalid('The backup does not contain a usable table inventory.');
        }

        $tables = array_keys($tables);
        sort($tables, SORT_STRING);
        $requiredTables = array_values(array_unique(array_map('strval', $requiredTables)));
        if (array_diff($requiredTables, $tables) !== []) {
            throw new BackupException(
                'BACKUP_RESTORE_REQUIRED_TABLE_MISSING',
                'The backup is missing required application tables.',
                422,
            );
        }

        return [
            'uncompressed_size_bytes' => $bytes,
            'tables' => $tables,
            'schema_fingerprint' => hash('sha256', implode("\n", $tables)),
        ];
    }

    private function inspectStatement(string $statement, array &$tables): void
    {
        $statement = trim($statement);
        if ($statement === '') {
            return;
        }

        if (preg_match('/^CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`([A-Za-z0-9_]+)`\s*\(/is', $statement, $matches)) {
            $this->assertSafeCreateTable($statement);
            $tables[$matches[1]] = true;

            return;
        }

        if (preg_match('/^DROP\s+TABLE\s+IF\s+EXISTS\s+`[A-Za-z0-9_]+`$/i', $statement)
            || preg_match(
                '/^SET\s+NAMES\s+[A-Za-z0-9_]+(?:\s+COLLATE\s+[A-Za-z0-9_]+)?$/i',
                $statement,
            )) {
            return;
        }

        if (preg_match(
            '/^INSERT\s+INTO\s+`[A-Za-z0-9_]+`\s+VALUES\s*(.+)$/is',
            $statement,
            $matches,
        )) {
            $this->assertSafeInsertValues($matches[1]);

            return;
        }

        throw new BackupException(
            'BACKUP_RESTORE_STATEMENT_UNSUPPORTED',
            'The backup contains a SQL statement outside the supported dump grammar.',
            422,
        );
    }

    private function inspectVersionedComment(string $body): void
    {
        if (! preg_match('/^!(\d{5})\s+(.+)$/s', trim($body), $matches)) {
            throw $this->unsupportedComment();
        }

        $statement = trim($matches[2]);
        $allowed = [
            '/^SET\s+@OLD_CHARACTER_SET_CLIENT\s*=\s*@@CHARACTER_SET_CLIENT$/i',
            '/^SET\s+@saved_cs_client\s*=\s*@@character_set_client$/i',
            '/^SET\s+character_set_client\s*=\s*[A-Za-z0-9_]+$/i',
            '/^SET\s+character_set_client\s*=\s*@saved_cs_client$/i',
            '/^SET\s+@OLD_CHARACTER_SET_RESULTS\s*=\s*@@CHARACTER_SET_RESULTS$/i',
            '/^SET\s+@OLD_COLLATION_CONNECTION\s*=\s*@@COLLATION_CONNECTION$/i',
            '/^SET\s+NAMES\s+[A-Za-z0-9_]+$/i',
            '/^SET\s+@OLD_TIME_ZONE\s*=\s*@@TIME_ZONE$/i',
            '/^SET\s+TIME_ZONE\s*=\s*([\'"])\+00:00\1$/i',
            '/^SET\s+@OLD_UNIQUE_CHECKS\s*=\s*@@UNIQUE_CHECKS\s*,\s*UNIQUE_CHECKS\s*=\s*0$/i',
            '/^SET\s+@OLD_FOREIGN_KEY_CHECKS\s*=\s*@@FOREIGN_KEY_CHECKS\s*,\s*FOREIGN_KEY_CHECKS\s*=\s*0$/i',
            '/^SET\s+@OLD_SQL_MODE\s*=\s*@@SQL_MODE\s*,\s*SQL_MODE\s*=\s*([\'"])NO_AUTO_VALUE_ON_ZERO\1$/i',
            '/^SET\s+@OLD_SQL_NOTES\s*=\s*@@SQL_NOTES\s*,\s*SQL_NOTES\s*=\s*0$/i',
            '/^SET\s+TIME_ZONE\s*=\s*@OLD_TIME_ZONE$/i',
            '/^SET\s+SQL_MODE\s*=\s*@OLD_SQL_MODE$/i',
            '/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*@OLD_FOREIGN_KEY_CHECKS$/i',
            '/^SET\s+UNIQUE_CHECKS\s*=\s*@OLD_UNIQUE_CHECKS$/i',
            '/^SET\s+CHARACTER_SET_CLIENT\s*=\s*@OLD_CHARACTER_SET_CLIENT$/i',
            '/^SET\s+CHARACTER_SET_RESULTS\s*=\s*@OLD_CHARACTER_SET_RESULTS$/i',
            '/^SET\s+COLLATION_CONNECTION\s*=\s*@OLD_COLLATION_CONNECTION$/i',
            '/^SET\s+SQL_NOTES\s*=\s*@OLD_SQL_NOTES$/i',
        ];
        foreach ($allowed as $pattern) {
            if (preg_match($pattern, $statement)) {
                return;
            }
        }

        throw $this->unsupportedComment();
    }

    private function assertSafeCreateTable(string $statement): void
    {
        if (preg_match('/`[A-Za-z0-9_]+`\s*\.\s*`[A-Za-z0-9_]+`/i', $statement)) {
            throw $this->unsupportedStatement();
        }

        $unquoted = $this->withoutQuotedValues($statement);
        if (! is_string($unquoted)
            || preg_match(
                '/\b[A-Za-z_][A-Za-z0-9_]*\s*\.\s*[A-Za-z_][A-Za-z0-9_]*\b/',
                $unquoted,
            )
            || preg_match(
                '/\b(?:DATA\s+DIRECTORY|INDEX\s+DIRECTORY|TABLESPACE|CONNECTION|SELECT)\b/i',
                $unquoted,
            )
            || preg_match('/\bENGINE\s*=\s*FEDERATED\b/i', $unquoted)) {
            throw $this->unsupportedStatement();
        }
    }

    private function assertSafeInsertValues(string $values): void
    {
        $unquoted = $this->withoutQuotedValues($values);
        if (! is_string($unquoted)
            || ! preg_match('/^\s*\(.+\)\s*$/s', $unquoted)
            || preg_match('/[^0-9A-Fa-fEeXxNnUuLlBb\s(),.+\-]/', $unquoted)) {
            throw $this->unsupportedStatement();
        }
    }

    private function withoutQuotedValues(string $sql): ?string
    {
        return preg_replace(
            [
                "/'(?:\\\\.|''|[^'])*'/s",
                '/"(?:\\\\.|""|[^"])*"/s',
                '/`(?:``|[^`])*`/s',
            ],
            ' ',
            $sql,
        );
    }

    private function assertStatementSize(string $statement, int $maximum): void
    {
        if (strlen($statement) > $maximum) {
            throw new BackupException(
                'BACKUP_RESTORE_STATEMENT_TOO_LARGE',
                'The backup contains an SQL statement above the configured safety limit.',
                422,
            );
        }
    }

    private function unsupportedComment(): BackupException
    {
        return new BackupException(
            'BACKUP_RESTORE_STATEMENT_UNSUPPORTED',
            'The backup contains an unsupported executable comment.',
            422,
        );
    }

    private function unsupportedStatement(): BackupException
    {
        return new BackupException(
            'BACKUP_RESTORE_STATEMENT_UNSUPPORTED',
            'The backup contains a SQL statement outside the supported dump grammar.',
            422,
        );
    }

    private function invalid(string $message): BackupException
    {
        return new BackupException('BACKUP_RESTORE_ARCHIVE_INVALID', $message, 422);
    }

    private function endOfFile($handle, string $path): bool
    {
        return str_ends_with(strtolower($path), '.gz') ? gzeof($handle) : feof($handle);
    }

    private function read($handle, string $path): string|false
    {
        return str_ends_with(strtolower($path), '.gz')
            ? gzread($handle, 1024 * 1024)
            : fread($handle, 1024 * 1024);
    }
}
