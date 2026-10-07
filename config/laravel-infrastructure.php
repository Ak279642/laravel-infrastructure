<?php

declare(strict_types=1);

return [
    'cache' => [
        // Null uses Laravel's default cache store.
        'store' => env('LARAVEL_INFRASTRUCTURE_CACHE_STORE'),
        'default_ttl' => (int) env('LARAVEL_INFRASTRUCTURE_CACHE_TTL', 300),
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
