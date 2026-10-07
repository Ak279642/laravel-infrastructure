# Logging

The package provides structured logging, domains, correlation IDs, context redaction and daily/size-based rotation helpers.

Sensitive keys and common inline credentials are recursively redacted. Logging failures are contained so they do not crash the application.

Configure client/server exception logging, trace inclusion, channel/domain routing, size limits and retention in `laravel-infrastructure.logging`.
