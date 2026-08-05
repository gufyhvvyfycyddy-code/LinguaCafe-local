<?php

namespace App\Services;

use App\Exceptions\BackupException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class RestoreWriteFence
{
    private const KEY = 'backup:restore-write-fence';

    public function activate(string $operationId): void
    {
        $record = [
            'operation_id' => $operationId,
            'activated_at' => now('UTC')->toIso8601String(),
        ];

        if (! $this->store()->add(self::KEY, $record, now()->addDays(7))) {
            $current = $this->store()->get(self::KEY);
            if (! is_array($current)
                || ($current['operation_id'] ?? null) !== $operationId) {
                throw new BackupException(
                    'BACKUP_RESTORE_FENCE_ACTIVE',
                    'Another restore write fence is already active.',
                    409,
                );
            }
        }
    }

    public function deactivate(string $operationId): void
    {
        $current = $this->store()->get(self::KEY);
        if (is_array($current) && ($current['operation_id'] ?? null) === $operationId) {
            $this->store()->forget(self::KEY);
        }
    }

    public function active(): bool
    {
        return is_array($this->store()->get(self::KEY));
    }

    public function assertQueryAllowed(string $query): void
    {
        if ($this->isReadOnlyQuery($query) || ! $this->active()) {
            return;
        }

        throw new BackupException(
            'BACKUP_RESTORE_WRITE_FENCE_ACTIVE',
            'Database writes are temporarily unavailable during restore.',
            503,
        );
    }

    private function isReadOnlyQuery(string $query): bool
    {
        if (! preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $query, $matches)) {
            return false;
        }

        if (strtoupper($matches[1]) !== 'SELECT') {
            return true;
        }

        return ! preg_match(
            '/\b(?:FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i',
            $query,
        );
    }

    private function store(): Repository
    {
        $name = (string) config('backup.restore_coordination_store', 'file');
        $driver = config("cache.stores.{$name}.driver");
        if (! is_string($driver)
            || $driver === 'database'
            || ($driver === 'array' && ! app()->environment('testing'))) {
            throw new BackupException(
                'BACKUP_CONFIGURATION_INVALID',
                'The restore coordination store is not durable and external to the target database.',
                503,
            );
        }

        return Cache::store($name);
    }
}
