<?php

declare(strict_types=1);

return [
    'cache' => [
        'enabled' => env('LARAVEL_INFRASTRUCTURE_CACHE_ENABLED', true),
        'store' => env('LARAVEL_INFRASTRUCTURE_CACHE_STORE'),
    ],
    'transactions' => [
        'attempts' => max(1, (int) env('LARAVEL_INFRASTRUCTURE_TRANSACTION_ATTEMPTS', 1)),
    ],
    'files' => [
        'disk' => env('LARAVEL_INFRASTRUCTURE_FILE_DISK', 'public'),
        'image' => [
            'driver' => env('LARAVEL_INFRASTRUCTURE_IMAGE_DRIVER', 'gd'),
        ],
    ],
    'assets' => [
        'enabled' => env('LARAVEL_INFRASTRUCTURE_ASSETS_ENABLED', true),
        'prefix' => env('LARAVEL_INFRASTRUCTURE_ASSETS_PREFIX', 'infrastructure/assets'),
        // Short alias => model class. These are set by each application.
        'resources' => [],
        'allowed_disks' => ['public'],
        'folder_access' => [],
        'generic_path_patterns' => [],
        // Optional legacy /uploads/{file} alias (off by default).
        'legacy_uploads' => [
            'enabled' => false,
            'prefix' => 'uploads',
            'disk' => 'public',
        ],
        // One global image per HTTP error. Local absolute paths are supported;
        // null uses the optimized WebP images bundled with this package.
        'error_images' => [
            403 => null,
            404 => null,
        ],
        'cache' => [
            'enabled' => true,
            // Only publicly accessible images may use shared browser/CDN caches.
            'public_max_age' => 86400,
            // Zero by default: signed/authenticated images must not remain
            // available in the browser's cache after access is revoked.
            'private_max_age' => 0,
            // ETag is metadata-based; no file contents are loaded into memory.
            'etag' => true,
        ],
    ],
    'storage_audit' => ['models' => []],
    'database_backup' => [
        'disk' => env('LARAVEL_INFRASTRUCTURE_BACKUP_DISK', 'local'),
        'path' => env('LARAVEL_INFRASTRUCTURE_BACKUP_PATH', 'backups/database'),
        'keep' => max(1, (int) env('LARAVEL_INFRASTRUCTURE_BACKUP_KEEP', 3)),
        'compress' => (bool) env('LARAVEL_INFRASTRUCTURE_BACKUP_COMPRESS', true),
        'exclude_data' => [],
        'mysql_dump_binary' => env('LARAVEL_INFRASTRUCTURE_MYSQLDUMP_BINARY', ''),
        'pgsql_dump_binary' => env('LARAVEL_INFRASTRUCTURE_PG_DUMP_BINARY', ''),
    ],
    'responses' => [
        'exception_renderer_enabled' => env('LARAVEL_INFRASTRUCTURE_EXCEPTION_RENDERER_ENABLED', true),
    ],
    'logging' => [
        'enabled' => env('LARAVEL_INFRASTRUCTURE_LOGGING_ENABLED', true),
        'channel' => env('LARAVEL_INFRASTRUCTURE_LOG_CHANNEL'),
        'exception_trace_enabled' => env('LARAVEL_INFRASTRUCTURE_EXCEPTION_TRACE', false),
        'log_client_exceptions' => false,
        'log_server_exceptions' => true,
        'correlation_header' => 'X-Request-ID',
        'accept_incoming_correlation_id' => true,
        'domain_enabled' => [],
        'domain_channels' => [],
    ],
];
