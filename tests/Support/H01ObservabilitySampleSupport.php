<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final class H01ObservabilitySampleSupport
{
    /** @return array{threads_connected:int,threads_running:int,queue_backlog:int,timestamp_ms:int} */
    public static function collect(string $queueConnection, string $queueName): array
    {
        $statusRows = DB::select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected', 'Threads_running')");
        $status = [];
        foreach ($statusRows as $row) {
            $name = $row->Variable_name ?? $row->variable_name ?? null;
            $value = $row->Value ?? $row->value ?? null;
            if (is_string($name) && is_numeric($value)) {
                $status[$name] = (int) $value;
            }
        }
        if (! isset($status['Threads_connected'], $status['Threads_running'])) {
            throw new RuntimeException('H01_MYSQL_STATUS_UNAVAILABLE');
        }

        try {
            $queueBacklog = Queue::connection($queueConnection)->size($queueName);
        } catch (Throwable $error) {
            throw new RuntimeException('H01_QUEUE_BACKLOG_UNAVAILABLE', 0, $error);
        }
        if (! is_int($queueBacklog) && ! is_numeric($queueBacklog)) {
            throw new RuntimeException('H01_QUEUE_BACKLOG_INVALID');
        }

        return [
            'timestamp_ms' => (int) round(microtime(true) * 1000),
            'threads_connected' => $status['Threads_connected'],
            'threads_running' => $status['Threads_running'],
            'queue_backlog' => (int) $queueBacklog,
        ];
    }
}
