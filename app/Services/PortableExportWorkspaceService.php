<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class PortableExportWorkspaceService
{
    public const SCHEMA_VERSION = 1;
    public const KIND_ANKI_EXPORT = 'anki-export';
    public const KIND_PORTABLE_EXPORT = 'portable-export';
    public const MARKER_FILE = '.linguacafe-export-workspace.json';

    private string $root;

    public function __construct(?string $root = null)
    {
        $root ??= storage_path('app/temp');
        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('Unable to create the portable export temp root.');
        }

        $resolved = realpath($root);
        if ($resolved === false) {
            throw new RuntimeException('Unable to resolve the portable export temp root.');
        }

        $this->root = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public function create(string $kind): string
    {
        $prefix = $this->prefixForKind($kind);
        $workspaceId = (string) Str::uuid();
        $directory = $this->root.DIRECTORY_SEPARATOR.$prefix.'-'.$workspaceId;

        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the portable export workspace.');
        }

        $marker = [
            'schema_version' => self::SCHEMA_VERSION,
            'kind' => $kind,
            'created_at' => CarbonImmutable::now('UTC')->toISOString(),
            'workspace_id' => $workspaceId,
        ];
        $temporaryMarker = $directory.DIRECTORY_SEPARATOR.'.marker-'.bin2hex(random_bytes(8)).'.tmp';
        $markerPath = $directory.DIRECTORY_SEPARATOR.self::MARKER_FILE;

        try {
            $bytes = json_encode(
                $marker,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
            if (file_put_contents($temporaryMarker, $bytes, LOCK_EX) !== strlen($bytes)
                || ! rename($temporaryMarker, $markerPath)) {
                throw new RuntimeException('Unable to publish the portable export workspace marker.');
            }
        } catch (Throwable $exception) {
            @unlink($temporaryMarker);
            try {
                $this->removeTree($directory);
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }
            throw $exception;
        }

        return $directory;
    }

    public function cleanup(string $directory): bool
    {
        if (! $this->isTopLevelCandidatePath($directory)) {
            return false;
        }
        if (! file_exists($directory) && ! is_link($directory)) {
            return true;
        }
        if (is_link($directory) || ! is_dir($directory) || $this->readOwnedMarker($directory) === null) {
            return false;
        }

        $this->removeTree($directory);

        return ! file_exists($directory) && ! is_link($directory);
    }

    /** @return array{scanned:int,owned:int,stale:int,deleted:int,skipped:int,errors:int} */
    public function cleanupStale(int $minimumAgeHours = 6, bool $apply = false): array
    {
        if ($minimumAgeHours < 1) {
            throw new \InvalidArgumentException('The minimum age must be at least one hour.');
        }

        $counts = [
            'scanned' => 0,
            'owned' => 0,
            'stale' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];
        $cutoff = CarbonImmutable::now('UTC')->subHours($minimumAgeHours);

        foreach (array_diff(scandir($this->root) ?: [], ['.', '..']) as $entry) {
            if (! str_starts_with($entry, 'anki-') && ! str_starts_with($entry, 'portable-')) {
                continue;
            }

            $counts['scanned']++;
            $directory = $this->root.DIRECTORY_SEPARATOR.$entry;
            if (is_link($directory) || ! is_dir($directory)) {
                $counts['skipped']++;
                continue;
            }

            try {
                $marker = $this->readOwnedMarker($directory);
                if ($marker === null) {
                    $counts['skipped']++;
                    continue;
                }
                $counts['owned']++;

                $createdAt = CarbonImmutable::parse($marker['created_at'])->utc();
                if ($createdAt->greaterThan($cutoff)) {
                    $counts['skipped']++;
                    continue;
                }
                $counts['stale']++;

                if (! $apply) {
                    $counts['skipped']++;
                    continue;
                }

                if ($this->cleanup($directory)) {
                    $counts['deleted']++;
                } else {
                    $counts['errors']++;
                    $counts['skipped']++;
                }
            } catch (Throwable) {
                $counts['errors']++;
                $counts['skipped']++;
            }
        }

        return $counts;
    }

    /** @return array{schema_version:int,kind:string,created_at:string,workspace_id:string}|null */
    private function readOwnedMarker(string $directory): ?array
    {
        if (! $this->isTopLevelCandidatePath($directory)) {
            return null;
        }

        $markerPath = $directory.DIRECTORY_SEPARATOR.self::MARKER_FILE;
        if (is_link($markerPath) || ! is_file($markerPath)) {
            return null;
        }

        try {
            $marker = json_decode((string) file_get_contents($markerPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($marker)
            || array_keys($marker) !== ['schema_version', 'kind', 'created_at', 'workspace_id']
            || ($marker['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ! is_string($marker['kind'] ?? null)
            || ! is_string($marker['created_at'] ?? null)
            || preg_match('/Z\z/', $marker['created_at']) !== 1
            || ! is_string($marker['workspace_id'] ?? null)) {
            return null;
        }

        $entry = basename($directory);
        $prefix = $this->prefixForKindOrNull($marker['kind']);
        if ($prefix === null
            || $entry !== $prefix.'-'.$marker['workspace_id']
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $marker['workspace_id']) !== 1) {
            return null;
        }

        try {
            CarbonImmutable::parse($marker['created_at']);
        } catch (Throwable) {
            return null;
        }

        return $marker;
    }

    private function isTopLevelCandidatePath(string $directory): bool
    {
        $normalizedRoot = $this->normalizePath($this->root);
        $normalizedDirectory = $this->normalizePath($directory);
        $parent = $this->normalizePath(dirname($directory));
        $entry = basename($directory);

        return $parent === $normalizedRoot
            && $normalizedDirectory !== $normalizedRoot
            && preg_match(
                '/^(anki|portable)-[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $entry,
            ) === 1;
    }

    private function prefixForKind(string $kind): string
    {
        return $this->prefixForKindOrNull($kind)
            ?? throw new \InvalidArgumentException('Unsupported portable export workspace kind.');
    }

    private function prefixForKindOrNull(string $kind): ?string
    {
        return match ($kind) {
            self::KIND_ANKI_EXPORT => 'anki',
            self::KIND_PORTABLE_EXPORT => 'portable',
            default => null,
        };
    }

    private function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', rtrim($path, "\\/"));

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path)) {
            $removed = is_dir($path) ? @rmdir($path) : @unlink($path);
            if (! $removed) {
                throw new RuntimeException('Unable to remove a portable export workspace link.');
            }
            return;
        }
        if (is_file($path)) {
            if (! @unlink($path)) {
                throw new RuntimeException('Unable to remove a portable export workspace file.');
            }
            return;
        }
        if (! is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path.DIRECTORY_SEPARATOR.$entry);
        }
        if (! @rmdir($path) && is_dir($path)) {
            throw new RuntimeException('Unable to remove the portable export workspace.');
        }
    }
}
