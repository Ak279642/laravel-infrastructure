# Logging

The package provides structured logging, domains, correlation IDs, recursive redaction and daily/size-based rotation.

Published config intentionally keeps only application-level logging switches:

```php
'logging' => [
    'enabled' => true,
    'channel' => null,
    'exception_trace_enabled' => false,
    'log_client_exceptions' => false,
    'log_server_exceptions' => true,
    'correlation_header' => 'X-Request-ID',
    'accept_incoming_correlation_id' => true,
    'domain_enabled' => [],
    'domain_channels' => [],
],
```

Safe limits such as redaction depth, string/array truncation, file-size rotation and retention keep package defaults in code instead of expanding the published config.

Sensitive keys and common inline credentials are recursively redacted. Logging failures are contained so they do not crash the application.


## Custom log domains

Pass any string-backed enum as a domain; the enum's backing value is used for the `domain` context and the `domain_enabled` / `domain_channels` config keys.

```php
enum AppLogDomain: string
{
    case IVR = 'ivr';
}

CustomLog::warning(
    'Webhook could not be matched.',
    ['operation' => 'webhook_match'],
    AppLogDomain::IVR,
);
```

The package's `LogDomain` enum and string domains remain supported.

Define separate Laravel channels in the host application's `config/logging.php`:

```php
'channels' => [
    'domain_meta' => [
        'driver' => 'daily',
        'path' => storage_path('logs/meta/meta.log'),
        'level' => 'info',
        'days' => 14,
    ],
],
```

`CustomLog` automatically selects `domain_meta` for the `meta` domain. If a channel has another name, map it explicitly using `laravel-infrastructure.logging.domain_channels` (e.g. `['meta' => 'marketing_meta']`). Explicit mapping takes precedence; otherwise `domain_<name>` is used when defined, and the configured fallback channel is used only when no domain channel exists. **A channel name alone does not create a directory:** configure a distinct `path` for each domain. Ordinary Laravel framework logs use the application's `LOG_CHANNEL` independently.
