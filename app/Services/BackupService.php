<?php

namespace App\Services;

use App\Exceptions\BackupException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class BackupService
{
    private const FORMAT = 'linguacafe-backup';

    private const FORMAT_VERSION = 1;

    public function __construct(
        private DatabaseDumpProcess $dumpProcess,
    ) {}

    public function createBackup(array $protectedBackupIds = []): array
    {
        return $this->withExclusiveOperation(
            fn (callable $create) => $create($protectedBackupIds),
        );
    }

    public function withExclusiveOperation(
        callable $operation,
        ?int $lockSeconds = null,
    ): mixed
    {
        $maxBackups = $this->maxBackups();
        try {
            $lock = $this->coordinationStore()->lock('backup:create', max(
                1,
                $lockSeconds ?? (int) config('backup.lock_seconds', 1800),
            ));
            $result = $lock->get(fn () => $operation(
                function (array $protectedBackupIds = []) use ($maxBackups) {
                    $this->validateProtectedBackupIds($protectedBackupIds);

                    return $this->createLocked($maxBackups, $protectedBackupIds);
                },
            ));
        } catch (BackupException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new BackupException(
                'BACKUP_UNAVAILABLE',
                'The backup service is unavailable.',
                503,
            );
        }

        if ($result === false) {
            throw new BackupException(
                'BACKUP_ALREADY_RUNNING',
                'Another backup is already running.',
                409,
            );
        }

        return $result;
    }

    public function listBackups(): array
    {
        $disk = $this->disk();
        $backups = [];

        foreach ($disk->files() as $file) {
            if (! preg_match('/^linguacafe_[0-9]{8}_[0-9]{6}_[0-9a-f-]{36}\.json$/', $file)) {
                continue;
            }

            try {
                $manifest = json_decode(
                    $disk->get($file),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
            } catch (Throwable) {
                continue;
            }

            if (! $this->validManifest($manifest, $file, $disk)) {
                continue;
            }

            $backups[] = $manifest;
        }

        usort(
            $backups,
            fn (array $left, array $right) => strcmp(
                $right['created_at'],
                $left['created_at'],
            ),
        );

        return $backups;
    }

    /**
     * @return array{manifest: array, manifest_sha256: string, payload_path: string}
     */
    public function inspectBackup(string $backupId): array
    {
        if (! Str::isUuid($backupId)) {
            throw new BackupException(
                'BACKUP_NOT_FOUND',
                'The requested backup was not found.',
                404,
            );
        }

        foreach ($this->listBackups() as $manifest) {
            if (! hash_equals($manifest['backup_id'], $backupId)) {
                continue;
            }

            $disk = $this->disk();
            $payloadPath = $disk->path($manifest['payload_file']);
            $manifestFile = Str::beforeLast($manifest['payload_file'], '.sql.gz') . '.json';
            $manifestPath = $disk->path($manifestFile);
            $root = realpath($disk->path(''));
            $realPayloadPath = realpath($payloadPath);
            $realManifestPath = realpath($manifestPath);

            if ($root === false
                || $realPayloadPath === false
                || $realManifestPath === false
                || ! $this->pathIsContained($root, $realPayloadPath)
                || ! $this->pathIsContained($root, $realManifestPath)) {
                break;
            }

            return [
                'manifest' => $manifest,
                'manifest_sha256' => hash_file('sha256', $realManifestPath),
                'payload_path' => $realPayloadPath,
            ];
        }

        throw new BackupException(
            'BACKUP_NOT_FOUND',
            'The requested backup was not found.',
            404,
        );
    }

    private function createLocked(int $maxBackups, array $protectedBackupIds): array
    {
        $disk = $this->disk();
        $backupId = (string) Str::uuid();
        $createdAt = Carbon::now('UTC');
        $baseName = sprintf(
            'linguacafe_%s_%s',
            $createdAt->format('Ymd_His'),
            $backupId,
        );
        $temporaryDirectory = ".tmp/{$backupId}";
        $dumpFile = "{$temporaryDirectory}/database.sql";
        $compressedFile = "{$temporaryDirectory}/{$baseName}.sql.gz";
        $manifestFile = "{$temporaryDirectory}/{$baseName}.json";
        $publishedPayload = "{$baseName}.sql.gz";
        $publishedManifest = "{$baseName}.json";

        try {
            if (! $disk->makeDirectory($temporaryDirectory)) {
                throw new BackupException(
                    'BACKUP_TEMPORARY_FILE_FAILED',
                    'The temporary backup directory could not be created.',
                );
            }

            $this->dumpProcess->dump($disk->path($dumpFile));
            $this->assertNonEmpty($disk, $dumpFile);
            $this->compress($disk->path($dumpFile), $disk->path($compressedFile));
            $this->assertNonEmpty($disk, $compressedFile);

            $payloadPath = $disk->path($compressedFile);
            $manifest = [
                'backup_id' => $backupId,
                'format' => self::FORMAT,
                'format_version' => self::FORMAT_VERSION,
                'created_at' => $createdAt->toIso8601String(),
                'application_version' => (string) config('backup.application_version', 'unknown'),
                'database_driver' => (string) config(
                    'database.connections.' . config('database.default') . '.driver',
                ),
                'database_name_fingerprint' => hash(
                    'sha256',
                    (string) config('database.connections.' . config('database.default') . '.database'),
                ),
                'included_scopes' => [
                    'database',
                    'articles',
                    'article_structure',
                ],
                'payload_file' => $publishedPayload,
                'size_bytes' => filesize($payloadPath),
                'sha256' => hash_file('sha256', $payloadPath),
                'status' => 'successful',
            ];
            $encodedManifest = json_encode(
                $manifest,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );

            if (! $disk->put($manifestFile, $encodedManifest)
                || ! $disk->move($compressedFile, $publishedPayload)
                || ! $disk->move($manifestFile, $publishedManifest)) {
                throw new BackupException(
                    'BACKUP_PUBLISH_FAILED',
                    'The completed backup could not be published.',
                );
            }

            try {
                $this->enforceRetention($maxBackups, $protectedBackupIds);
            } catch (Throwable $exception) {
                report($exception);
            }

            return $manifest;
        } catch (BackupException $exception) {
            $disk->delete([$publishedPayload, $publishedManifest]);
            throw $exception;
        } catch (Throwable $exception) {
            $disk->delete([$publishedPayload, $publishedManifest]);
            report($exception);

            throw new BackupException(
                'BACKUP_FAILED',
                'The backup could not be created.',
            );
        } finally {
            $disk->deleteDirectory($temporaryDirectory);
        }
    }

    private function compress(string $sourcePath, string $destinationPath): void
    {
        $source = fopen($sourcePath, 'rb');
        $destination = gzopen($destinationPath, 'wb9');

        if ($source === false || $destination === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination)) {
                gzclose($destination);
            }

            throw new BackupException(
                'BACKUP_COMPRESSION_FAILED',
                'The database backup could not be compressed.',
            );
        }

        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);

                if ($chunk === false || gzwrite($destination, $chunk) === false) {
                    throw new BackupException(
                        'BACKUP_COMPRESSION_FAILED',
                        'The database backup could not be compressed.',
                    );
                }
            }
        } finally {
            fclose($source);
            gzclose($destination);
        }
    }

    private function assertNonEmpty(FilesystemAdapter $disk, string $path): void
    {
        if (! $disk->exists($path) || $disk->size($path) < 1) {
            throw new BackupException(
                'BACKUP_EMPTY',
                'The database backup was empty.',
            );
        }
    }

    private function validManifest(
        mixed $manifest,
        string $manifestFile,
        FilesystemAdapter $disk,
    ): bool
    {
        $expectedPayload = Str::beforeLast($manifestFile, '.json') . '.sql.gz';

        if (! is_array($manifest)
            || ($manifest['format'] ?? null) !== self::FORMAT
            || ($manifest['format_version'] ?? null) !== self::FORMAT_VERSION
            || ($manifest['status'] ?? null) !== 'successful'
            || ! is_string($manifest['backup_id'] ?? null)
            || ! Str::isUuid($manifest['backup_id'])
            || ! is_string($manifest['created_at'] ?? null)
            || ! is_string($manifest['payload_file'] ?? null)
            || basename($manifest['payload_file']) !== $manifest['payload_file']
            || $manifest['payload_file'] !== $expectedPayload
            || ! str_ends_with(
                Str::beforeLast($manifest['payload_file'], '.sql.gz'),
                $manifest['backup_id'],
            )
            || ! preg_match('/^linguacafe_[0-9]{8}_[0-9]{6}_[0-9a-f-]{36}\.sql\.gz$/', $manifest['payload_file'])
            || ! is_int($manifest['size_bytes'] ?? null)
            || $manifest['size_bytes'] < 1
            || ! is_string($manifest['sha256'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/', $manifest['sha256'])
            || ! $disk->exists($manifest['payload_file'])) {
            return false;
        }

        try {
            Carbon::parse($manifest['created_at']);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function enforceRetention(int $maxBackups, array $protectedBackupIds): void
    {
        $disk = $this->disk();

        foreach (array_slice($this->listBackups(), $maxBackups) as $backup) {
            if (in_array($backup['backup_id'], $protectedBackupIds, true)) {
                continue;
            }

            $baseName = Str::beforeLast($backup['payload_file'], '.sql.gz');
            $disk->delete([
                $backup['payload_file'],
                "{$baseName}.json",
            ]);
        }
    }

    private function maxBackups(): int
    {
        $maxBackups = config('backup.max_backups');

        if (! is_int($maxBackups) || $maxBackups < 1 || $maxBackups > 1000) {
            throw new BackupException(
                'BACKUP_CONFIGURATION_INVALID',
                'The backup retention configuration is invalid.',
                503,
            );
        }

        return $maxBackups;
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk((string) config('backup.disk', 'backup'));
    }

    private function pathIsContained(string $root, string $path): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $root);
    }

    private function coordinationStore()
    {
        $store = (string) config('backup.restore_coordination_store', 'file');
        $driver = config("cache.stores.{$store}.driver");

        if (! is_string($driver)
            || $driver === 'database'
            || ($driver === 'array' && ! app()->environment('testing'))) {
            throw new BackupException(
                'BACKUP_CONFIGURATION_INVALID',
                'The backup coordination store must be outside the application database.',
                503,
            );
        }

        return Cache::store($store);
    }

    private function validateProtectedBackupIds(array $protectedBackupIds): void
    {
        foreach ($protectedBackupIds as $protectedBackupId) {
            if (! is_string($protectedBackupId) || ! Str::isUuid($protectedBackupId)) {
                throw new BackupException(
                    'BACKUP_CONFIGURATION_INVALID',
                    'The protected backup list is invalid.',
                    503,
                );
            }
        }
    }
}
