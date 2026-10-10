<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Laravel Infrastructure
|--------------------------------------------------------------------------
|
| Only package-specific settings live here. Caching uses Laravel's own
| config/cache.php and CACHE_STORE; this config only controls optional\n| invalidation tracking, never a second cache store.
| Override only the sections your application actually needs.
|
*/

return [
    // Optional automatic invalidation for successful query-builder/raw SQL
    // mutations, including pivot sync and set-based UPDATE. Uses Laravel's
    // existing cache store; Redis tags are recommended.
    'cache' => [
        'auto_invalidation' => [
            'enabled' => (bool) env('LARAVEL_INFRASTRUCTURE_AUTO_INVALIDATION', false),
            // Pivot/visibility tables can affect caches for other models.
            // Keys are physical table names; values are dependent model classes.
            'table_dependencies' => [],
        ],
    ],

    // Database transaction retry count.
    'transactions' => [
        'attempts' => max(1, (int) env('LARAVEL_INFRASTRUCTURE_TRANSACTION_ATTEMPTS', 1)),
    ],

    // Default upload disk and image processor. Models may override per field.
    'files' => [
        'disk' => env('LARAVEL_INFRASTRUCTURE_FILE_DISK', 'public'),
        'image' => [
            'driver' => env('LARAVEL_INFRASTRUCTURE_IMAGE_DRIVER', 'gd'),
        ],
    ],

    // Public file URL aliases; a missing alias falls back to the disk name.
    // Only explicitly listed public disks can ever be routed publicly.
    'assets' => [
        'enabled' => env('LARAVEL_INFRASTRUCTURE_ASSETS_ENABLED', true),
        'public_disks' => ['public'],
        'disk_aliases' => [],
        'render_error_images' => true,
        'error_images' => [403 => null, 404 => null],
    ],

    // Models whose stored files should be checked by the storage audit command.
    'storage_audit' => [
        'models' => [],
    ],

    // Optional database backups; uses Laravel filesystem disks.
    'database_backup' => [
        'disk' => env('LARAVEL_INFRASTRUCTURE_BACKUP_DISK', 'local'),
        'path' => env('LARAVEL_INFRASTRUCTURE_BACKUP_PATH', 'backups/database'),
        'keep' => max(1, (int) env('LARAVEL_INFRASTRUCTURE_BACKUP_KEEP', 3)),
        'compress' => (bool) env('LARAVEL_INFRASTRUCTURE_BACKUP_COMPRESS', true),
        'exclude_data' => [],
        'mysql_dump_binary' => env('LARAVEL_INFRASTRUCTURE_MYSQLDUMP_BINARY', ''),
        'pgsql_dump_binary' => env('LARAVEL_INFRASTRUCTURE_PG_DUMP_BINARY', ''),
    ],

    // API exception handling.
    'responses' => [
        'exception_renderer_enabled' => env('LARAVEL_INFRASTRUCTURE_EXCEPTION_RENDERER_ENABLED', true),
    ],

    // Structured logs; channel falls back to Laravel's logging.default.
    'logging' => [
        'enabled' => env('LARAVEL_INFRASTRUCTURE_LOGGING_ENABLED', true),
        'channel' => env('LARAVEL_INFRASTRUCTURE_LOG_CHANNEL'),
        'exception_trace_enabled' => env('LARAVEL_INFRASTRUCTURE_EXCEPTION_TRACE', false),
        'log_client_exceptions' => false,
        'log_server_exceptions' => true,
        'correlation_header' => 'X-Request-ID',
        'accept_incoming_correlation_id' => true,
        'domain_enabled' => [],
    ],
];
