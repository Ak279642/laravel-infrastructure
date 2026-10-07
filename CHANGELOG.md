# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added
- Added `infrastructure:database-backup` for MySQL/MariaDB and PostgreSQL logical backups with optional gzip compression, schema-only exclusions, Laravel filesystem storage, and retention pruning.
- Added opt-in `SecurityHeaders` and `RejectSensitivePaths` middleware with package middleware aliases.

### Fixed
- Added compensating cleanup for model uploads when a create/update database write fails.
- Added transaction rollback cleanup for newly uploaded model files while preserving the previously committed file until successful replacement commit.
- Fixed `bulkRestore()` on non-`SoftDeletes` models so it returns `0` instead of calling an unavailable `restore()` method.
- Fixed `bulkForceDelete()` on non-`SoftDeletes` models so it falls back to normal model deletion, matching the single-record API.
- Bulk mutations now invalidate repository cache state even when the model does not use the cache observer concern.
- Relation sum/average helpers now reject unknown aggregate columns explicitly and reuse `SchemaRegistry` metadata instead of repeatedly introspecting table schemas.
- Nested dotted relation aggregates now fail early with an actionable exception instead of falling through to framework-specific runtime behavior.
- `findWhereIn()` now normalizes primary-key identifiers before deduplication and ordering so integer/string aliases, duplicates, missing IDs, UUIDs, and requested order behave deterministically.
- Deferred validation-context create/update/delete/restore mutations until transaction commit so rollbacks cannot leave phantom or stale identity-map models.
- Made bulk update/delete/restore/force-delete keep ValidationContext coherent using the same transaction-aware rules as single-record repository writes.
- Deferred model file replacement/delete cleanup until database commit so rolled-back writes never restore rows that reference already-deleted files.
- Rejected custom upload filenames containing path separators or hidden dotfile names.
- Rejected unsafe storage-audit directories and referenced paths before any deletion scan is allowed to proceed.

### Notes
- Newly uploaded files are still written before the database save so their paths can be persisted. If a surrounding database transaction later rolls back, the new file can remain orphaned; infrastructure:storage-audit is the supported cleanup path.

## [1.1.0] - 2026-10-07

### Added
- Reusable Eloquent repository infrastructure with CRUD, filtering, searching, sorting, relations, pagination, aggregates, scopes, streaming, duplicate/existence helpers, and bulk operations.
- Explicit repository query allow-lists for filters, relation filters, sorts, relations, search columns, and request-driven model scopes.
- Deterministic tag-aware repository caching with per-query TTL controls, cache bypassing, forever caching, dependency tags, and stampede-protected population.
- Automatic after-commit Eloquent cache invalidation for create, update, delete, restore, and force-delete lifecycle events.
- Repository-backed validation, `RepositoryFormRequest`, `RepositoryValidationRule`, and scoped `ValidationContext`.
- Automatic validation-context identity-map reuse: existing models/collections are reused first, only missing records are queried, missing relations are loaded onto the same instance, and queried records are remembered automatically.
- Validation-context snapshot/restore so failed additive validation restores aliases and automatically remembered models atomically.
- Reusable `BaseAction`, `BaseService`, transaction manager abstraction, configurable transaction retries, and operation context primitives.
- Generic API response helpers, normalized JSON exception rendering, correlation IDs, request-correlation middleware, structured/domain logging, redaction, and log rotation helpers.
- Optional package `BaseModel` with cache, slug, and file lifecycle concerns.
- Single and multiple model slug fields through `slugOptions()` and `slugFields()`, including scoped uniqueness and independent regeneration policies.
- Generic `FileStorage` helpers plus model-level automatic `UploadedFile` persistence with per-field disk, directory, filename, lifecycle, and audit options.
- Model-scoped `infrastructure:storage-audit` command with dry-run default, explicit `--delete`, model filtering, shared-directory reference aggregation, and chunked database reads.
- Pint and Larastan development tooling plus transaction, caching, query-security, validation-context, files, slugs, API, logging, and provider regression coverage.
- Focused documentation under `docs/` for architecture, repositories, filtering/security, caching, validation, transactions, files, logging, responses, exceptions, slugs, testing, and security.

### Changed
- Repository cache enablement, default TTL, lock duration, and lock wait duration are centrally configurable while preserving backwards-compatible defaults.
- Repository reads automatically bypass cache inside open database transactions so rolled-back uncommitted data cannot become cached.
- Repository reads bypass unsafe tagged-cache behavior on stores that cannot reliably support tag invalidation.
- `withoutCache()` is isolated to a cloned repository operation instead of mutating shared repository state.
- Cache keys include repository/model identity and deterministic query normalization.
- Cache invalidation now runs explicitly after database commit.
- Schema metadata caching is isolated by logical connection and physical database identity for tenant/database switching and long-running workers.
- Repository selected, aggregate, validation-helper, relation-filter, and relation-aggregate columns are validated before query construction.
- PostgreSQL uses native `ILIKE`; MySQL/MariaDB and SQLite use portable case-insensitive behavior without emitting invalid `ILIKE` SQL.
- File storage rejects traversal, absolute paths, null bytes, control characters, unsafe filenames, and malformed upload extensions across store/read/delete/URL operations.
- Slug uniqueness checks include soft-deleted rows when the model uses `SoftDeletes`.
- Create/update/restore refresh remembered validation-context models; delete/force-delete evict them.
- JSON API exceptions use correct 4xx/5xx mappings while normal HTML exception rendering remains application-controlled.
- Expected client exceptions are quiet by default; unexpected server failures are redacted and logged without exposing internal details.

### Fixed
- Arbitrary dotted filter keys can no longer become relation filters without explicit authorization.
- Unknown or malformed relation/filter/sort/search/scope identifiers can no longer affect repository queries.
- Aggregate, selected-column, duplicate-check, and repository-validation helper APIs reject unsafe or nonexistent model columns.
- `groupCount()` no longer interpolates caller-supplied column names into raw SQL.
- Legacy operator arrays such as `['>=', 18]` remain compatible.
- Failed repository validation no longer leaves partial aliases or remembered models in context.
- Nullable validation-scope values are no longer treated as literal input-field names.
- Replacing a validation alias no longer leaves stale models in the class/id identity map.
- Soft-deleted rows are visible to `bulkRestore()` and `bulkForceDelete()`.
- Repository caching remains correct across rolled-back transactions.
- Production 500 responses do not expose exception messages, SQL, or stack traces.
- File replacement/deletion lifecycle handling avoids deleting previous files before a successful model write.
- Storage auditing never falls back to globally scanning unmanaged upload directories.

### Security
- Request-controlled query identifiers are allow-listed and schema-validated where appropriate; query values remain parameter-bound through Laravel.
- Relation filters require both an allowed dotted filter and an allowed relation.
- Storage cleanup is restricted to explicitly registered models and explicitly model-owned directories.
- Logging recursively redacts passwords, tokens, API keys, authorization headers, cookies, secrets, and nested sensitive values.
- Correlation IDs are bounded and validated before reuse.
- File-system paths cannot escape configured storage locations.

### Compatibility verified
- PHP 8.2 / Laravel 10.
- PHP 8.2 and 8.3 / Laravel 11.
- PHP 8.3 and 8.4 / Laravel 12.
- PHP 8.4 / Laravel 13.
- SQLite test suite.
- MySQL 8.4 filter compatibility.
- PostgreSQL 17 filter compatibility.
- `composer validate --strict`, PHPUnit, Laravel Pint, and Larastan level 5.
