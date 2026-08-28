<?php

return [
    'enabled' => env('BACKUP_ENABLED', true),
    'schedule' => env('BACKUP_INTERVAL', '0 2 * * *'),
    'disk' => env('BACKUP_DISK', 'backup'),
    'max_backups' => (int) env('MAX_SAVED_BACKUPS', 14),
    'mysqldump_binary' => env('MYSQLDUMP_BINARY', 'mysqldump'),
    'process_timeout_seconds' => (int) env('BACKUP_PROCESS_TIMEOUT_SECONDS', 900),
    'lock_seconds' => (int) env('BACKUP_LOCK_SECONDS', 1800),
    'application_version' => env('APP_VERSION', 'unknown'),
    'mysql_binary' => env('MYSQL_BINARY', 'mysql'),
    'restore_preview_ttl_seconds' => (int) env('BACKUP_RESTORE_PREVIEW_TTL_SECONDS', 600),
    'restore_operation_ttl_seconds' => (int) env(
        'BACKUP_RESTORE_OPERATION_TTL_SECONDS',
        604800,
    ),
    'restore_coordination_store' => env('BACKUP_RESTORE_COORDINATION_STORE', 'file'),
    'restore_queue_connection' => env('BACKUP_RESTORE_QUEUE_CONNECTION', 'redis-restore'),
    'restore_queue' => env('BACKUP_RESTORE_QUEUE', 'maintenance'),
    'restore_lock_seconds' => (int) env('BACKUP_RESTORE_LOCK_SECONDS', 25200),
    'restore_max_uncompressed_bytes' => (int) env(
        'BACKUP_RESTORE_MAX_UNCOMPRESSED_BYTES',
        2 * 1024 * 1024 * 1024,
    ),
    'restore_disk_headroom_multiplier' => (float) env(
        'BACKUP_RESTORE_DISK_HEADROOM_MULTIPLIER',
        2.5,
    ),
    'restore_quiesce_timeout_seconds' => (int) env(
        'BACKUP_RESTORE_QUIESCE_TIMEOUT_SECONDS',
        30,
    ),
    'restore_quiesce_stable_seconds' => (int) env(
        'BACKUP_RESTORE_QUIESCE_STABLE_SECONDS',
        2,
    ),
    'restore_max_statement_bytes' => (int) env(
        'BACKUP_RESTORE_MAX_STATEMENT_BYTES',
        64 * 1024 * 1024,
    ),
    'restore_required_tables' => [
        'migrations',
        'users',
        'books',
        'chapters',
        'encountered_words',
        'word_senses',
        'review_cards',
        'review_logs',
    ],
    'restore_temporary_database_prefix' => env(
        'BACKUP_RESTORE_TEMPORARY_DATABASE_PREFIX',
        'linguacafe_restore_test_',
    ),
    'restore_validation_host' => env('BACKUP_RESTORE_VALIDATION_HOST'),
    'restore_validation_port' => env('BACKUP_RESTORE_VALIDATION_PORT', 3306),
    'restore_validation_username' => env('BACKUP_RESTORE_VALIDATION_USERNAME'),
    'restore_validation_password' => env('BACKUP_RESTORE_VALIDATION_PASSWORD'),
];
