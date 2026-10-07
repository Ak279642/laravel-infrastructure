<?php

declare(strict_types=1);

return [
    'cache' => [
        // Null uses Laravel's default cache store.
        'store' => env('LARAVEL_INFRASTRUCTURE_CACHE_STORE'),
        'default_ttl' => (int) env('LARAVEL_INFRASTRUCTURE_CACHE_TTL', 300),
    ],

    'transactions' => [
        // Deadlock/serialization retry attempts used by BaseAction by default.
        'attempts' => max(1, (int) env('LARAVEL_INFRASTRUCTURE_TRANSACTION_ATTEMPTS', 1)),
    ],

    'slug' => [
        // Disabled globally by default. Enable per model with slugOptions().
        'enabled' => false,
        'source' => 'name',
        'column' => 'slug',
        'unique' => true,
        'regenerate_on_update' => false,
        'separator' => '-',
        // List of model attributes that scope uniqueness, e.g. ['organization_id'].
        'scope' => [],
    ],

    'files' => [
        'disk' => env('LARAVEL_INFRASTRUCTURE_FILE_DISK', 'public'),
        'directory' => env('LARAVEL_INFRASTRUCTURE_FILE_DIRECTORY', 'uploads'),
        'delete_on_replace' => true,
        'delete_on_delete' => true,
        'delete_on_soft_delete' => false,
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
