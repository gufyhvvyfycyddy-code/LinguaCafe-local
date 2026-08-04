<?php

namespace App\Services;

use App\Exceptions\BackupException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

class DatabaseDumpProcess
{
    public function dump(string $outputPath): void
    {
        $connectionName = config('database.default');
        $connection = config("database.connections.{$connectionName}", []);

        if (($connection['driver'] ?? null) !== 'mysql') {
            throw new BackupException(
                'BACKUP_DRIVER_UNSUPPORTED',
                'Only MySQL backups are supported.',
                422,
            );
        }

        $database = $this->requiredString($connection, 'database');
        $username = $this->requiredString($connection, 'username');
        $host = $this->requiredString($connection, 'host');
        $port = (string) ($connection['port'] ?? 3306);
        $password = (string) ($connection['password'] ?? '');
        $binary = (string) config('backup.mysqldump_binary', 'mysqldump');
        $timeout = max(1, (int) config('backup.process_timeout_seconds', 900));
        $handle = fopen($outputPath, 'wb');

        if ($handle === false) {
            throw new BackupException(
                'BACKUP_TEMPORARY_FILE_FAILED',
                'The temporary backup file could not be created.',
            );
        }

        $command = [
            $binary,
            '--no-tablespaces',
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--skip-add-locks',
            '--skip-disable-keys',
            '--skip-triggers',
            '--skip-comments',
            '--hex-blob',
            '--default-character-set=utf8mb4',
            "--host={$host}",
            "--port={$port}",
            "--user={$username}",
            $database,
        ];

        try {
            $result = Process::timeout($timeout)
                ->env($this->processEnvironment($password))
                ->run($command, function (string $type, string $output) use ($handle) {
                    if ($type === SymfonyProcess::OUT && fwrite($handle, $output) === false) {
                        throw new BackupException(
                            'BACKUP_TEMPORARY_FILE_FAILED',
                            'The database dump could not be written.',
                        );
                    }
                });
        } finally {
            fclose($handle);
        }

        if ($result->failed()) {
            throw new BackupException(
                'BACKUP_DATABASE_DUMP_FAILED',
                'The database backup process failed.',
            );
        }
    }

    private function processEnvironment(string $password): array
    {
        $environment = ['MYSQL_PWD' => $password];

        if (PHP_OS_FAMILY !== 'Windows') {
            return $environment;
        }

        foreach (['SystemRoot', 'WINDIR', 'ComSpec'] as $key) {
            $value = getenv($key);

            if (is_string($value) && $value !== '') {
                $environment[$key] = $value;
            }
        }

        return $environment;
    }

    private function requiredString(array $connection, string $key): string
    {
        $value = $connection[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new BackupException(
                'BACKUP_CONFIGURATION_INVALID',
                'The database backup configuration is incomplete.',
                503,
            );
        }

        return $value;
    }
}
