# Logging

The package provides structured logging, domains, correlation IDs, recursive redaction and daily/size-based rotation.

Published config provides automatic per-domain directories with daily and size-based rotation:

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
    'directory' => storage_path('logs'),
    'level' => 'debug',
    'max_file_mb' => 10,
    'retention_days' => 14,
],
```

Redaction depth and string/array truncation retain safe defaults in code. Size rotation and retention are configurable through the published config or environment variables `LARAVEL_INFRASTRUCTURE_LOG_MAX_FILE_MB`, `LARAVEL_INFRASTRUCTURE_LOG_RETENTION_DAYS`, `LARAVEL_INFRASTRUCTURE_LOG_DIRECTORY`, and `LARAVEL_INFRASTRUCTURE_LOG_LEVEL`.

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

## Automatic domain folders and same-day size rotation

Without configuring channels in the host application's `config/logging.php`, each domain has its own automatically created folder:

```text
storage/logs/
  application/application-2026-10-12-001.log
  application/application-2026-10-12-002.log
  errors/errors-2026-10-12-001.log
  business/business-2026-10-12-001.log
  api/api-2026-10-12-001.log
  ivr/ivr-2026-10-12-001.log
```

Each date starts at `001`. Before writing a record that would exceed `max_file_mb` (default **10 MB**) in a nonempty segment, the handler starts the next numbered file **on the same day**. An unusually large single record is written unsplit. A per-domain file lock serializes selection and writing across PHP workers, avoiding mixed or corrupted entries. The date is based on the server timezone. Logs older than `retention_days` (default **14**) are pruned when a new segment is opened. A hidden `.domain.rotation.lock` file remains in each domain folder to coordinate writers.

Set the options in `config/laravel-infrastructure.php`:

```php
'logging' => [
    'enabled' => true,
    'domain_enabled' => [],
    'domain_channels' => [],
    'directory' => storage_path('logs'),
    'level' => 'debug',
    'max_file_mb' => 5,
    'retention_days' => 30,
],
```

### Optional host-defined channels

Existing Laravel channels keep precedence over automatic files. Define one only when you need a custom handler, log destination or different rotation policy:

Define a separate Laravel channel in your application's `config/logging.php`:

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

`CustomLog` chooses `logging.domain_channels` explicit mappings first, then an existing host channel named `domain_<name>`, then `logging.channel` when specified, and finally the automatic size-rotating domain files. A host channel keeps its own rotation and destination configuration. Laravel framework log calls still use the application's `LOG_CHANNEL`.
