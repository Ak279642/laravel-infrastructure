<?php

declare(strict_types=1);

return [
    'cache' => [
        // Preserve historical behavior: repository caching is enabled by default.
        'enabled' => env('LARAVEL_INFRASTRUCTURE_CACHE_ENABLED', true),
        // Null uses Laravel's default cache store.
        'store' => env('LARAVEL_INFRASTRUCTURE_CACHE_STORE'),
        'lock_seconds' => max(1, (int) env('LARAVEL_INFRASTRUCTURE_CACHE_LOCK_SECONDS', 10)),
        'lock_wait_seconds' => max(0, (int) env('LARAVEL_INFRASTRUCTURE_CACHE_LOCK_WAIT_SECONDS', 3)),
    ],

    'transactions' => [
        // Deadlock/serialization retry attempts used by BaseAction by default.
        'attempts' => max(1, (int) env('LARAVEL_INFRASTRUCTURE_TRANSACTION_ATTEMPTS', 1)),
    ],

    'files' => [
        'disk' => env('LARAVEL_INFRASTRUCTURE_FILE_DISK', 'public'),
        'delete_on_replace' => true,
        'delete_on_delete' => true,
        'delete_on_soft_delete' => false,
        'auto_upload' => true,

        // Infrastructure-level driver only.
        // A model field enables image processing by declaring "image".
        'image' => [
            'driver' => env(
                'LARAVEL_INFRASTRUCTURE_IMAGE_DRIVER',
                'gd',
            ),
        ],
    ],

    'assets' => [
        'enabled' => env(
            'LARAVEL_INFRASTRUCTURE_ASSETS_ENABLED',
            true,
        ),
        'prefix' => env(
            'LARAVEL_INFRASTRUCTURE_ASSETS_PREFIX',
            'infrastructure/assets',
        ),
        'signed' => env(
            'LARAVEL_INFRASTRUCTURE_ASSETS_SIGNED',
            true,
        ),
        'url_ttl_minutes' => max(
            1,
            (int) env(
                'LARAVEL_INFRASTRUCTURE_ASSETS_URL_TTL',
                15,
            ),
        ),

        // Short model aliases used by model-aware asset URLs.
        // Configure in the host app after publishing config:
        //
        // 'resources' => [
        //     'product' => App\\Models\\Product::class,
        // ],
        //
        // Generated model-asset URLs never expose PHP namespaces,
        // filesystem disks, or stored file paths.
        'resources' => [],

        // Only these filesystem disks can ever be served.
        'allowed_disks' => ['public'],

        // Optional disk/folder fallback rules.
        // Rules merge "*" -> parent folder -> most-specific folder.
        // Model fileAttributes()['field']['access'] overrides these values.
        'folder_access' => [
            'public' => [
                '*' => [
                    'enabled' => true,
                    'signed' => true,
                    'guard' => null,
                ],
            ],
        ],
    ],

    'storage_audit' => [
        // Explicit opt-in only. The command never discovers/scans App models.
        'models' => [],
        'chunk_size' => 500,
    ],

    'database_backup' => [
        'disk' => env('LARAVEL_INFRASTRUCTURE_BACKUP_DISK', 'local'),
        'path' => env(
            'LARAVEL_INFRASTRUCTURE_BACKUP_PATH',
            'backups/database',
        ),
        'keep' => max(
            1,
            (int) env('LARAVEL_INFRASTRUCTURE_BACKUP_KEEP', 3),
        ),
        'compress' => (bool) env(
            'LARAVEL_INFRASTRUCTURE_BACKUP_COMPRESS',
            true,
        ),
        // Tables listed here keep their schema but omit row data.
        'exclude_data' => [],
        'mysql_dump_binary' => env(
            'LARAVEL_INFRASTRUCTURE_MYSQLDUMP_BINARY',
            '',
        ),
        'pgsql_dump_binary' => env(
            'LARAVEL_INFRASTRUCTURE_PG_DUMP_BINARY',
            '',
        ),
    ],

    'responses' => [
        // Normalize exceptions only for requests that explicitly expect JSON.
        'exception_renderer_enabled' => env(
            'LARAVEL_INFRASTRUCTURE_EXCEPTION_RENDERER_ENABLED',
            true,
        ),
    ],

    'logging' => [
        'enabled' => env('LARAVEL_INFRASTRUCTURE_LOGGING_ENABLED', true),
        'channel' => env('LARAVEL_INFRASTRUCTURE_LOG_CHANNEL'),
        'exception_trace_enabled' => env('LARAVEL_INFRASTRUCTURE_EXCEPTION_TRACE', false),
        'log_client_exceptions' => env('LARAVEL_INFRASTRUCTURE_LOG_CLIENT_EXCEPTIONS', false),
        'log_server_exceptions' => env('LARAVEL_INFRASTRUCTURE_LOG_SERVER_EXCEPTIONS', true),
        'correlation_header' => env('LARAVEL_INFRASTRUCTURE_CORRELATION_HEADER', 'X-Request-ID'),
        'accept_incoming_correlation_id' => env('LARAVEL_INFRASTRUCTURE_ACCEPT_CORRELATION_ID', true),
        'max_depth' => 6,
        'max_string_length' => 4096,
        'max_array_items' => 100,
        'max_file_mb' => 100,
        'retention_days' => 14,
        'domain_enabled' => [],
        'domain_channels' => [],
    ],
];
