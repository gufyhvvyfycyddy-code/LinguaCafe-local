<?php

namespace App\Services;

use App\Exceptions\BackupException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

class DatabaseRestoreProcess
{
    public function waitForQuiescence(): void
    {
        $timeout = (int) config('backup.restore_quiesce_timeout_seconds', 30);
        if ($timeout < 1 || $timeout > 300) {
            throw new BackupException(
                'BACKUP_CONFIGURATION_INVALID',
                'The restore quiescence timeout is invalid.',
                503,
            );
        }
        $stableSeconds = (int) config('backup.restore_quiesce_stable_seconds', 2);
        if ($stableSeconds < 1 || $stableSeconds > 30 || $stableSeconds >= $timeout) {
            throw new BackupException(
                'BACKUP_CONFIGURATION_INVALID',
                'The restore quiescence stable window is invalid.',
                503,
            );
        }

        $monitorConnection = $this->validationConnection();
        $deadline = microtime(true) + $timeout;
        $stableSince = null;
        do {
            $result = $this->run([
                ...$this->baseCommand($monitorConnection),
                '--batch',
                '--skip-column-names',
                '--execute=' . $this->activeWriterCountQuery(),
            ], null, $monitorConnection);

            $writerCount = trim($result->output());
            if (! preg_match('/^-?\d+$/', $writerCount) || $writerCount === '-1') {
                throw new BackupException(
                    'BACKUP_CONFIGURATION_INVALID',
                    'Restore quiescence monitoring is unavailable.',
                    503,
                );
            }

            if ($writerCount === '0') {
                $stableSince ??= microtime(true);
                if (microtime(true) - $stableSince >= $stableSeconds) {
                    return;
                }
            } else {
                $stableSince = null;
            }

            usleep(250000);
        } while (microtime(true) < $deadline);

        throw new BackupException(
            'BACKUP_RESTORE_WRITERS_ACTIVE',
            'Application database writers did not quiesce before restore.',
            409,
        );
    }

    public function assertActiveInventory(array $expectedTables): void
    {
        $result = $this->run([
            ...$this->baseCommand(),
            '--batch',
            '--skip-column-names',
            $this->connection()['database'],
            '--execute=SHOW TABLES',
        ]);
        $actualTables = preg_split('/\r\n|\n|\r/', trim($result->output())) ?: [];
        $actualTables = array_values(array_filter(array_map('trim', $actualTables)));
        sort($actualTables, SORT_STRING);
        $expectedTables = array_values(array_unique(array_map('strval', $expectedTables)));
        sort($expectedTables, SORT_STRING);

        if ($actualTables !== $expectedTables) {
            throw new BackupException(
                'BACKUP_RESTORE_HEALTH_CHECK_FAILED',
                'The restored database table inventory did not match the preview.',
            );
        }

        $databaseHex = bin2hex($this->connection()['database']);
        if ($this->schemaObjects(
            "SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = CONVERT(0x{$databaseHex} USING utf8mb4)",
        ) !== []
            || $this->schemaObjects(
                "SELECT EVENT_NAME, 'EVENT' FROM information_schema.EVENTS WHERE EVENT_SCHEMA = CONVERT(0x{$databaseHex} USING utf8mb4)",
            ) !== []) {
            throw new BackupException(
                'BACKUP_RESTORE_HEALTH_CHECK_FAILED',
                'The restored database contains unexpected executable schema objects.',
            );
        }
    }

    public function validateIsolated(string $payloadPath, array $expectedTables): void
    {
        $validationConnection = $this->validationConnection();
        $temporaryDatabase = $this->temporaryDatabaseName();
        $created = false;
        $failure = null;

        try {
            $this->run([
                ...$this->baseCommand($validationConnection),
                "--execute=CREATE DATABASE `{$temporaryDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
            ], null, $validationConnection);
            $created = true;
            $this->import($payloadPath, $temporaryDatabase, $validationConnection);
            $result = $this->run([
                ...$this->baseCommand($validationConnection),
                '--batch',
                '--skip-column-names',
                $temporaryDatabase,
                '--execute=SHOW TABLES',
            ], null, $validationConnection);
            $actualTables = preg_split('/\r\n|\n|\r/', trim($result->output())) ?: [];
            $actualTables = array_values(array_filter(array_map('trim', $actualTables)));
            sort($actualTables, SORT_STRING);
            $expectedTables = array_values(array_unique(array_map('strval', $expectedTables)));
            sort($expectedTables, SORT_STRING);

            if ($actualTables !== $expectedTables) {
                throw new BackupException(
                    'BACKUP_RESTORE_ISOLATED_VALIDATION_FAILED',
                    'The isolated restore table inventory did not match the preview.',
                    422,
                );
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        if ($created) {
            try {
                $this->run([
                    ...$this->baseCommand($validationConnection),
                    "--execute=DROP DATABASE IF EXISTS `{$temporaryDatabase}`",
                ], null, $validationConnection);
            } catch (Throwable $cleanupFailure) {
                report($cleanupFailure);
                if ($failure === null) {
                    throw new BackupException(
                        'BACKUP_RESTORE_TEMPORARY_CLEANUP_FAILED',
                        'The isolated restore database could not be removed.',
                    );
                }
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function replaceActive(string $payloadPath): void
    {
        $this->resetActiveSchema();
        $this->import($payloadPath, $this->connection()['database']);
    }

    private function resetActiveSchema(): void
    {
        $connection = $this->connection();
        $databaseHex = bin2hex($connection['database']);
        $result = $this->run([
            ...$this->baseCommand(),
            '--batch',
            '--skip-column-names',
            "--execute=SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = CONVERT(0x{$databaseHex} USING utf8mb4)",
        ]);
        $tables = [];
        $views = [];
        $routines = $this->schemaObjects(
            "SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = CONVERT(0x{$databaseHex} USING utf8mb4)",
        );
        $events = $this->schemaObjects(
            "SELECT EVENT_NAME, 'EVENT' FROM information_schema.EVENTS WHERE EVENT_SCHEMA = CONVERT(0x{$databaseHex} USING utf8mb4)",
        );

        foreach (preg_split('/\r\n|\n|\r/', trim($result->output())) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            [$name, $type] = array_pad(explode("\t", $line, 2), 2, '');
            if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                throw new BackupException(
                    'BACKUP_RESTORE_SCHEMA_RESET_FAILED',
                    'The active database contains an unsafe object name.',
                );
            }
            if ($type === 'VIEW') {
                $views[] = "`{$name}`";
            } else {
                $tables[] = "`{$name}`";
            }
        }

        $statements = ['SET FOREIGN_KEY_CHECKS=0'];
        if ($views !== []) {
            $statements[] = 'DROP VIEW IF EXISTS ' . implode(', ', $views);
        }
        if ($tables !== []) {
            $statements[] = 'DROP TABLE IF EXISTS ' . implode(', ', $tables);
        }
        foreach ([...$routines, ...$events] as [$name, $type]) {
            if (! in_array($type, ['PROCEDURE', 'FUNCTION', 'EVENT'], true)) {
                throw new BackupException(
                    'BACKUP_RESTORE_SCHEMA_RESET_FAILED',
                    'The active database contains an unsupported schema object.',
                );
            }
            $statements[] = "DROP {$type} IF EXISTS `{$name}`";
        }
        $statements[] = 'SET FOREIGN_KEY_CHECKS=1';
        $this->run([
            ...$this->baseCommand(),
            $connection['database'],
            '--execute=' . implode('; ', $statements),
        ]);
    }

    private function schemaObjects(string $query): array
    {
        $result = $this->run([
            ...$this->baseCommand(),
            '--batch',
            '--skip-column-names',
            "--execute={$query}",
        ]);
        $objects = [];
        foreach (preg_split('/\r\n|\n|\r/', trim($result->output())) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            [$name, $type] = array_pad(explode("\t", $line, 2), 2, '');
            if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                throw new BackupException(
                    'BACKUP_RESTORE_SCHEMA_RESET_FAILED',
                    'The active database contains an unsafe object name.',
                );
            }
            $objects[] = [$name, strtoupper($type)];
        }

        return $objects;
    }

    private function import(
        string $payloadPath,
        string $database,
        ?array $connection = null,
    ): void
    {
        $input = str_ends_with(strtolower($payloadPath), '.gz')
            ? gzopen($payloadPath, 'rb')
            : fopen($payloadPath, 'rb');

        if ($input === false) {
            throw new BackupException(
                'BACKUP_RESTORE_ARCHIVE_INVALID',
                'The backup payload could not be opened.',
                422,
            );
        }

        try {
            $this->run([
                ...$this->baseCommand($connection),
                '--default-character-set=utf8mb4',
                $database,
            ], $input, $connection);
        } finally {
            str_ends_with(strtolower($payloadPath), '.gz')
                ? gzclose($input)
                : fclose($input);
        }
    }

    /**
     * @param  resource|null  $input
     */
    private function run(
        array $command,
        $input = null,
        ?array $connection = null,
    ): ProcessResult
    {
        $connection ??= $this->connection();

        try {
            $pending = Process::timeout(max(
                1,
                (int) config('backup.process_timeout_seconds', 900),
            ))->env(['MYSQL_PWD' => $connection['password']]);

            if ($input !== null) {
                $pending->input($input);
            }

            $result = $pending->run($command);
        } catch (BackupException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new BackupException(
                'BACKUP_RESTORE_PROCESS_FAILED',
                'The database restore process failed.',
            );
        }

        if ($result->failed()) {
            throw new BackupException(
                'BACKUP_RESTORE_PROCESS_FAILED',
                'The database restore process failed.',
            );
        }

        return $result;
    }

    private function baseCommand(?array $connection = null): array
    {
        $connection ??= $this->connection();

        return [
            (string) config('backup.mysql_binary', 'mysql'),
            "--host={$connection['host']}",
            "--port={$connection['port']}",
            "--user={$connection['username']}",
        ];
    }

    /**
     * @return array{database: string, username: string, password: string, host: string, port: string}
     */
    private function connection(): array
    {
        $connectionName = config('database.default');
        $connection = config("database.connections.{$connectionName}", []);

        if (($connection['driver'] ?? null) !== 'mysql') {
            throw new BackupException(
                'BACKUP_DRIVER_UNSUPPORTED',
                'Only MySQL restores are supported.',
                422,
            );
        }

        return [
            'database' => $this->requiredString($connection, 'database'),
            'username' => $this->requiredString($connection, 'username'),
            'password' => (string) ($connection['password'] ?? ''),
            'host' => $this->requiredString($connection, 'host'),
            'port' => (string) ($connection['port'] ?? 3306),
        ];
    }

    private function validationConnection(): array
    {
        $active = $this->connection();
        $connection = [
            'database' => '',
            'username' => (string) config('backup.restore_validation_username', ''),
            'password' => (string) config('backup.restore_validation_password', ''),
            'host' => (string) config('backup.restore_validation_host', ''),
            'port' => (string) config('backup.restore_validation_port', 3306),
        ];

        if (trim($connection['username']) === '' || trim($connection['host']) === '') {
            throw new BackupException(
                'BACKUP_CONFIGURATION_INVALID',
                'Dedicated isolated-restore database credentials are required.',
                503,
            );
        }
        if (app()->environment() !== 'testing'
            && hash_equals($active['username'], $connection['username'])
            && hash_equals($active['host'], $connection['host'])) {
            throw new BackupException(
                'BACKUP_CONFIGURATION_INVALID',
                'Isolated restore must not use the active application database identity.',
                503,
            );
        }

        return $connection;
    }

    private function temporaryDatabaseName(): string
    {
        $prefix = (string) config(
            'backup.restore_temporary_database_prefix',
            'linguacafe_restore_test_',
        );

        if (! preg_match('/^[a-z][a-z0-9_]{0,38}$/', $prefix)) {
            throw new BackupException(
                'BACKUP_CONFIGURATION_INVALID',
                'The restore database prefix is invalid.',
                503,
            );
        }

        return $prefix . Str::lower(Str::random(24));
    }

    private function activeWriterCountQuery(): string
    {
        $databaseHex = bin2hex($this->connection()['database']);

        return "SELECT IF("
            . "(SELECT ENABLED FROM performance_schema.setup_consumers "
            . "WHERE NAME = 'events_transactions_current') = 'YES' "
            . "AND (SELECT ENABLED FROM performance_schema.setup_instruments "
            . "WHERE NAME = 'transaction') = 'YES', "
            . "(SELECT COUNT(*) FROM performance_schema.threads "
            . "WHERE PROCESSLIST_DB = CONVERT(0x{$databaseHex} USING utf8mb4) "
            . "AND TYPE = 'FOREGROUND' "
            . "AND COALESCE(PROCESSLIST_COMMAND, '') <> 'Sleep') + "
            . "(SELECT COUNT(*) FROM performance_schema.events_transactions_current "
            . "WHERE STATE = 'ACTIVE' AND ACCESS_MODE = 'READ WRITE'), -1)";
    }

    private function requiredString(array $connection, string $key): string
    {
        $value = $connection[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new BackupException(
                'BACKUP_CONFIGURATION_INVALID',
                'The database restore configuration is incomplete.',
                503,
            );
        }

        return $value;
    }
}
