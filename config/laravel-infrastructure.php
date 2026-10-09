<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Laravel Infrastructure
|--------------------------------------------------------------------------
|
| Only package-specific settings live here. Caching uses Laravel's own
| config/cache.php and CACHE_STORE; no separate package cache settings.
| Override only the sections your application actually needs.
|
*/

return [
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

    // The package registers and handles its own asset URLs and access rules.
    'assets' => [
        'enabled' => env('LARAVEL_INFRASTRUCTURE_ASSETS_ENABLED', true),
        'prefix' => env('LARAVEL_INFRASTRUCTURE_ASSETS_PREFIX', 'infrastructure/assets'),

        // Map a URL alias to your Eloquent model class (e.g. Product::class).
        'resources' => [],

        // Only these storage disks can be requested through asset URLs.
        'allowed_disks' => ['public'],

        // Optional per-folder rules: 'public' => ['private' => ['guard' => 'admin']].
        'folder_access' => [],

        // Optional regex allowlists for generic (non-model) file URLs.
        'generic_path_patterns' => [],

        // Enable only if existing clients still use /uploads/{file}.
        'legacy_uploads' => [
            'enabled' => false,
            'prefix' => 'uploads',
            'disk' => 'public',
        ],

        // null = the included optimized WebP. Override with an absolute file path.
        'error_images' => [
            403 => null,
            404 => null,
        ],
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
        'domain_channels' => [],
    ],
];
