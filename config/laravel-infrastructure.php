<?php

declare(strict_types=1);

return [
    'cache' => [
        'store' => env('LARAVEL_INFRASTRUCTURE_CACHE_STORE'),
        'default_ttl' => (int) env('LARAVEL_INFRASTRUCTURE_CACHE_TTL', 300),
        'key_prefix' => env('LARAVEL_INFRASTRUCTURE_CACHE_PREFIX', 'laravel-infrastructure'),

        'lock' => [
            'enabled' => env('LARAVEL_INFRASTRUCTURE_CACHE_LOCKS', true),
            'seconds' => (int) env('LARAVEL_INFRASTRUCTURE_CACHE_LOCK_SECONDS', 10),
            'wait_seconds' => (int) env('LARAVEL_INFRASTRUCTURE_CACHE_LOCK_WAIT_SECONDS', 3),
        ],

        'events' => [
            'enabled' => env('LARAVEL_INFRASTRUCTURE_CACHE_EVENTS', false),
        ],
    ],

    'slug' => [
        'enabled' => false,
        'source' => 'name',
        'column' => 'slug',
        'unique' => true,
        'regenerate_on_update' => false,
        'separator' => '-',
        'scope' => [],
    ],

    'files' => [
        'disk' => env('LARAVEL_INFRASTRUCTURE_FILE_DISK', 'public'),
        'directory' => env('LARAVEL_INFRASTRUCTURE_FILE_DIRECTORY', 'uploads'),
        'delete_on_replace' => true,
        'delete_on_delete' => true,
        'delete_on_soft_delete' => false,

        // Cleanup happens after a successful DB commit. By default a storage
        // cleanup failure is logged instead of corrupting the persisted row.
        'throw_on_cleanup_failure' => false,
    ],

    'logging' => [
        'enabled' => env('LARAVEL_INFRASTRUCTURE_LOGGING_ENABLED', true),
        'channel' => env('LARAVEL_INFRASTRUCTURE_LOG_CHANNEL'),
        'exception_trace_enabled' => env('LARAVEL_INFRASTRUCTURE_EXCEPTION_TRACE', false),
        'max_depth' => 6,
        'max_string_length' => 4096,
        'max_array_items' => 100,
        'max_file_mb' => 100,
        'retention_days' => 14,
        'domain_enabled' => [],
        'domain_channels' => [],
    ],
];
