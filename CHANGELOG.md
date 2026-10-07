# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [1.1.0] - 2026-10-07

### Added
- Explicit repository query allow-lists with optional strict filter/sort/relation exceptions.
- Reusable `BaseAction` and `BaseService` application infrastructure.
- Repository-validation contract and atomic/scoped `ValidationContext` reuse.
- Locked cache population for stampede protection.
- Nested relation cache-dependency tags.
- Uniform API response envelopes and JSON exception normalization.
- Correlation IDs and reusable request-correlation middleware.
- Recursive structured-log redaction for sensitive keys and common inline credentials.
- Regression coverage for caching, validation, transactions, responses, logging, provider lifetimes, and long-running-worker isolation.

### Changed
- Repository caching now honors `LARAVEL_INFRASTRUCTURE_CACHE_TTL` by default while preserving explicit repository TTL overrides.
- `withoutCache()` is an isolated one-operation clone instead of shared mutable repository state.
- Repository cache keys include repository/model identity and deterministic query normalization.
- Repository reads bypass cache on stores that cannot safely support tag invalidation.
- Schema metadata caching is isolated by logical connection and physical database identity for tenant/database switching in long-running workers.
- JSON API exceptions use correct 4xx/5xx status mappings while normal HTML exception rendering remains application-controlled.
- Expected 4xx logs are quiet by default; unexpected 5xx logs are redacted and emitted once.
- Composer metadata and architecture checks were strengthened for package distribution.

### Fixed
- Arbitrary filters, sorts, relations, relation counts, and search columns can no longer bypass repository allow-lists.
- Legacy repository operator arrays such as `['>=', 18]` remain compatible.
- Failed repository validation no longer leaves partially resolved models in context.
- Nullable scoped validation values are no longer mistaken for literal input field names.
- Replacing a validation alias no longer leaves a stale model in the class/id index.
- Production 500 responses no longer expose raw exception details.
- Direct database writes and their manual cache-invalidation requirements are documented explicitly.

## [1.0.0] - 2026-10-07

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
