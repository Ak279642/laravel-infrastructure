# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added
- Standalone Laravel infrastructure package extracted from the Ledger foundation.
- Eloquent repository abstraction with filters, sorting, relations, scopes, pagination, aggregates, and bulk operations.
- Safe tag-aware repository caching and cache invalidation.
- Opt-in model cache invalidation through `CacheableModel` and `InteractsWithCache`.
- Validation and operation context primitives.
- Generic JSON response and application exception helpers.
- Transaction manager abstraction for action/orchestration boundaries.
- Package logging utilities with sensitive-value redaction.
- Laravel package auto-discovery and publishable configuration.
- Optional package `BaseModel` with configurable slug generation and file lifecycle handling.
- `FileStorage` service for generic Laravel uploads without image-library coupling.
- Scoped unique slug generation through the repository layer.
- Repository-aware `FormRequest` validation with aliased resolved models/collections.
- Validation-context reuse inside services and automatic `BaseRepository` class+ID reuse.
- Database-portable schema inspection for MySQL, PostgreSQL, SQLite, and SQL Server.
