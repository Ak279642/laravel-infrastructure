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
