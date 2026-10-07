# Laravel Infrastructure

Reusable Laravel infrastructure for repository-driven applications.

The package provides a reusable repository layer, deterministic and tag-aware caching, automatic Eloquent cache invalidation, query filtering/search/sorting, relation loading, repository-backed validation, operation contexts, generic API responses/exceptions, logging helpers, and transaction boundaries.

[![Tests](https://github.com/Ak279642/laravel-infrastructure/actions/workflows/tests.yml/badge.svg)](https://github.com/Ak279642/laravel-infrastructure/actions/workflows/tests.yml)

## Requirements

- PHP 8.2+
- Laravel 10, 11, 12 or 13
- A taggable Laravel cache store such as Redis or Memcached is recommended when repository caching is enabled

## Installation

```bash
composer require ak279642/laravel-infrastructure
```

Laravel package discovery registers the service provider automatically.

Publish the optional configuration file when you want to customize package settings:

```bash
php artisan vendor:publish --tag=laravel-infrastructure-config
```

The published file is:

```text
config/laravel-infrastructure.php
```

Useful environment variables include:

```dotenv
LARAVEL_INFRASTRUCTURE_CACHE_STORE=redis
LARAVEL_INFRASTRUCTURE_CACHE_TTL=300
LARAVEL_INFRASTRUCTURE_TRANSACTION_ATTEMPTS=1

LARAVEL_INFRASTRUCTURE_EXCEPTION_RENDERER_ENABLED=true

LARAVEL_INFRASTRUCTURE_LOGGING_ENABLED=true
LARAVEL_INFRASTRUCTURE_LOG_CHANNEL=
LARAVEL_INFRASTRUCTURE_EXCEPTION_TRACE=false
LARAVEL_INFRASTRUCTURE_LOG_CLIENT_EXCEPTIONS=false
LARAVEL_INFRASTRUCTURE_LOG_SERVER_EXCEPTIONS=true
LARAVEL_INFRASTRUCTURE_CORRELATION_HEADER=X-Request-ID
LARAVEL_INFRASTRUCTURE_ACCEPT_CORRELATION_ID=true
```

## Complete feature catalog: usage and benefits

This package is intended to provide a reusable, production-oriented Laravel application infrastructure layer. The points below summarize every major capability, how to use it, and the practical benefit it provides. Detailed examples for each feature are available in the sections that follow.

### 1. Repository pattern with a reusable `BaseRepository`

**What it provides**

- Standard CRUD operations.
- Common read helpers such as `all()`, `find()`, `findOrFail()`, `get()`, `first()`, `firstOrFail()`, `exists()`, `doesntExist()`, `count()`, `pluck()`, `sum()`, `avg()`, `min()`, `max()`, and `groupCount()`.
- Standard write helpers such as `create()`, `update()`, `updateOrCreate()`, `delete()`, `forceDelete()`, and `restore()`.

**Usage**

Create one repository per model and extend `BaseRepository`:

```php
final class CustomerRepository extends BaseRepository
{
    public function __construct(
        Customer $model,
        CacheManager $cache,
        ?ValidationContext $validationContext = null,
    ) {
        parent::__construct($model, $cache, $validationContext);
    }
}
```

Then use the repository instead of duplicating Eloquent query/persistence code across controllers and services.

**Benefits**

- Removes repetitive CRUD boilerplate.
- Centralizes model querying and persistence.
- Gives every project the same repository conventions.
- Makes business logic easier to test and maintain.
- Keeps controllers thin.

### 2. Query filtering with explicit allow-lists

**What it provides**

Repositories can expose only approved filter fields through `$allowedFilters`.

Supported operators include:

```text
=
!=
<>
>
>=
<
<=
like
ilike
in
in_or_null
not_in
between
not_between
null
not_null
```

Nested relation filtering such as `country.code` is also supported.

**Usage**

```php
protected array $allowedFilters = [
    'status',
    'country_id',
    'created_at',
];

$customers = $repository->get([
    'status' => 'active',
    'created_at' => [
        'operator' => 'between',
        'value' => ['2026-01-01', '2026-12-31'],
    ],
]);
```

**Benefits**

- Prevents arbitrary request fields from becoming database filters.
- Reduces accidental exposure of internal columns.
- Keeps API filtering consistent across modules.
- Avoids rewriting common `where`, `whereIn`, `whereBetween`, and null-filter logic.

### 3. Search across model and relation fields

**What it provides**

A repository can define searchable columns, including relation columns.

**Usage**

```php
protected array $searchable = [
    'name',
    'email',
    'country.name',
];

$customers = $repository->get([
    'search' => 'Acme India',
]);
```

You can also supply a narrower `search_columns` list per call.

**Benefits**

- Gives APIs a consistent search implementation.
- Supports relation-aware search without rebuilding query logic.
- Keeps searchable fields controlled by the repository.

### 4. Safe sorting

**What it provides**

Repositories can explicitly define sortable columns with `$allowedSorts` and a default order with `$defaultOrder`.

**Usage**

```php
protected array $allowedSorts = [
    'name',
    'email',
    'created_at',
];

protected array $defaultOrder = [
    'created_at' => 'desc',
];

$repository->get([
    'sort' => ['name', '-created_at'],
]);
```

**Benefits**

- Prevents clients from sorting by arbitrary database expressions or columns.
- Produces consistent ordering behavior.
- Keeps sorting rules close to the repository.

### 5. Controlled eager loading and relation helpers

**What it provides**

- `$allowedRelations`
- `$defaultRelations`
- `load()`
- `loadMissing()`
- `withCount()`
- `withSum()`
- `withAvg()`

**Usage**

```php
protected array $allowedRelations = [
    'country',
    'orders',
];

$customer = $repository->findOrFail(
    id: 10,
    with: ['country', 'orders'],
);

$repository
    ->withCount('orders')
    ->withSum('orders', 'total');
```

**Benefits**

- Prevents unrestricted relation loading from API input.
- Helps control N+1 queries.
- Standardizes relation counts and aggregates.
- Makes repository responses predictable.

### 6. Eloquent model scope support

**What it provides**

Existing Eloquent scopes can be invoked through repository filters or repository query helpers.

**Usage**

```php
public function scopeActive($query)
{
    return $query->where('status', 'active');
}

$customers = $repository->get([
    'scopes' => ['active'],
]);

$repository->scope('active');
```

**Benefits**

- Reuses model-level query rules.
- Keeps domain-specific query logic out of controllers.
- Lets repository queries and normal Eloquent scopes work together.

### 7. Pagination and memory-safe iteration

**What it provides**

- `paginate()`
- `simplePaginate()`
- `cursorPaginate()`
- `chunk()`
- `lazy()`
- `cursor()`

**Usage**

```php
$repository->paginate(perPage: 25);

$repository->chunk(500, function ($customers) {
    // Process one batch at a time.
});

foreach ($repository->lazy(1000) as $customer) {
    // Low-memory processing.
}
```

**Benefits**

- Avoids loading large tables into memory.
- Helps commands, exports, migrations, and background jobs scale better.
- Reduces memory spikes and long-running worker pressure.

### 8. Bulk repository operations

**What it provides**

Repository-level bulk operations for cases where processing records one-by-one would be unnecessarily expensive.

**Usage**

Use the bulk helpers documented later in this README when updating, inserting, deleting, or processing many rows.

**Benefits**

- Reduces query counts.
- Helps eliminate N+1 write patterns.
- Improves command/job performance.
- Makes high-volume operations more predictable.

### 9. Duplicate and existence helpers

**What it provides**

Reusable checks for record existence and duplicate detection through the repository layer.

**Usage**

Use repository existence/duplicate helpers instead of manually repeating query checks throughout validation and service code.

**Benefits**

- Keeps duplicate rules centralized.
- Reduces repeated query code.
- Works naturally with repository-backed validation.

### 10. Repository caching

**What it provides**

Read methods are cache-aware, with configurable TTLs, optional cache bypassing, forever caching, and additional tags.

**Usage**

```php
$customers = $repository
    ->cacheTtl(CacheTtl::MINUTES_10)
    ->get(['status' => 'active']);

$customers = $repository
    ->withoutCache()
    ->get();

$customers = $repository
    ->rememberForever()
    ->get();

$repository->clearCache();
```

**Benefits**

- Reduces repeated database reads.
- Centralizes caching behavior instead of spreading `Cache::remember()` throughout the application.
- Allows per-query cache decisions.
- Makes cache invalidation easier to reason about.

### 11. Deterministic cache keys

**What it provides**

Stable cache-key generation for repository queries, including normalized parameters.

**Usage**

Use repository methods normally; the package generates deterministic keys internally. Advanced custom repository methods can also use the package cache-key helpers.

**Benefits**

- Equivalent queries resolve to the same cache entry.
- Reduces accidental duplicate cache entries.
- Makes caching custom repository methods safer.

### 12. Cache tags and targeted invalidation

**What it provides**

Model/repository cache tags and optional custom tags.

**Usage**

```php
$repository
    ->cacheTags([
        'tenant:10',
        'customer-directory',
    ])
    ->get();
```

**Benefits**

- Clears related data without flushing the entire cache.
- Supports tenant/domain-specific invalidation strategies.
- Keeps cache invalidation focused and efficient.

### 13. Cache TTL constants

**What it provides**

Reusable `CacheTtl` constants for common cache durations.

**Usage**

```php
$repository
    ->cacheTtl(CacheTtl::MINUTES_10)
    ->get();
```

**Benefits**

- Avoids magic TTL numbers.
- Makes cache policy easier to read and standardize.

### 14. Taggable and non-taggable cache-store safety

**What it provides**

The package handles cache-store capability differences instead of assuming every store supports tags.

**Usage**

Configure the desired Laravel cache store. Redis or Memcached is recommended when repository cache tags are required.

**Benefits**

- Avoids runtime surprises when a cache driver does not support tags.
- Makes local/test environments safer.
- Keeps production cache behavior explicit.

### 15. Automatic Eloquent cache invalidation

**What it provides**

Models implementing `CacheableModel` and using `InteractsWithCache` automatically invalidate related repository cache after create, update, delete, restore, and force-delete events.

**Usage**

```php
final class Customer extends Model implements CacheableModel
{
    use InteractsWithCache;
}
```

**Benefits**

- Prevents stale repository data after writes.
- Removes repeated manual `clearCache()` calls.
- Keeps cache lifecycle tied to model lifecycle.

### 16. Manual invalidation for direct database writes

**What it provides**

Manual invalidation remains available when an application intentionally bypasses repository/model events.

**Usage**

After raw query-builder or direct database writes, explicitly clear the relevant repository/model cache using the package invalidation helpers documented below.

**Benefits**

- Supports performance-sensitive direct writes without silently accepting stale cache.
- Makes exceptional write paths explicit.

### 17. Custom cached repository methods

**What it provides**

Custom repository methods can participate in the same cache-key/tag/TTL infrastructure as built-in reads.

**Usage**

Use the custom cache helpers shown later in this README for:

- simple custom reads;
- custom reads with relations;
- aggregates;
- unordered parameters;
- custom keys and tags.

**Benefits**

- Keeps application-specific queries consistent with built-in repository caching.
- Avoids ad-hoc cache implementations.
- Makes invalidation predictable.

### 18. Repository-backed validation

**What it provides**

Validation rules can query through repositories instead of bypassing application data-access conventions.

**Usage**

Use `RepositoryValidationService`, `RepositoryValidationRule`, and the repository-validation contract described later in the README.

**Benefits**

- Reuses repository query behavior in validation.
- Prevents the validation layer from becoming a second data-access architecture.
- Supports centralized existence and uniqueness checks.

### 19. Repository-aware `FormRequest`

**What it provides**

`RepositoryFormRequest` can validate input and resolve referenced models/collections into a reusable validation context.

**Usage**

Extend the package request base class and declare repository-backed validation/resolution rules.

**Benefits**

- Avoids validating an ID and then querying the same model again in the service.
- Reduces duplicate database queries.
- Gives services already-resolved domain objects.

### 20. `ValidationContext`

**What it provides**

A scoped context that stores models/collections resolved during validation so repositories/services can reuse them.

**Usage**

Inject or access `ValidationContext` as shown in the validation examples below.

**Benefits**

- Eliminates repeated lookups in one request/use case.
- Reduces query count.
- Preserves the exact records that were validated.

### 21. Operation context

**What it provides**

A reusable per-operation context for sharing state across coordinated application work.

**Usage**

Use the package operation-context API in actions/services when multiple components need the same operation-scoped values.

**Benefits**

- Reduces passing the same metadata through many method signatures.
- Keeps temporary request/use-case state scoped correctly.
- Helps long-running worker safety by avoiding global mutable state.

### 22. Reusable `BaseService`

**What it provides**

A consistent base for application service classes and shared service-level infrastructure.

**Usage**

Extend `BaseService` for domain/business services where the package-provided conventions are useful.

**Benefits**

- Gives services a common structure.
- Encourages business logic to stay outside controllers.
- Makes cross-project service design consistent.

### 23. Reusable `BaseAction`

**What it provides**

An action/orchestrator layer designed to coordinate complete use cases and transaction boundaries.

**Usage**

Put multi-step application use cases in an Action and call services/repositories from it.

**Benefits**

- Separates orchestration from domain logic.
- Creates a clear place for transaction boundaries.
- Makes complex use cases easier to test.

### 24. Repository-validation contract

**What it provides**

Actions/services can declare their repository-validation dependency in a consistent way.

**Usage**

Follow the repository-validation contract shown later in this README when an action/service relies on data resolved during request validation.

**Benefits**

- Makes validation dependencies explicit.
- Reduces accidental re-querying.
- Improves consistency between HTTP validation and business execution.

### 25. Transaction management

**What it provides**

A `TransactionManager` abstraction backed by Laravel database transactions, plus configurable retry attempts for deadlocks/serialization failures.

**Usage**

Use transaction-aware Actions or inject the transaction manager for explicit transaction boundaries.

Configure attempts with:

```dotenv
LARAVEL_INFRASTRUCTURE_TRANSACTION_ATTEMPTS=1
```

**Benefits**

- Keeps transaction ownership at the use-case boundary.
- Avoids transaction code scattered across repositories/controllers.
- Supports retry strategies for concurrency failures.

### 26. Optional infrastructure `BaseModel`

**What it provides**

One Eloquent base model that bundles cache, slug, and file lifecycle capabilities.

**Usage**

```php
abstract class BaseModel extends InfrastructureBaseModel
{
}
```

Then let application models extend your own `App\Models\BaseModel`.

**Benefits**

- One opt-in place for shared model infrastructure.
- Reduces trait setup across every model.
- Remains optional: normal Laravel `Model` classes can use only the concerns they need.

### 27. Single-field slug generation

**What it provides**

Automatic slug generation from one or more fallback source columns.

**Usage**

```php
protected function slugOptions(): array
{
    return [
        'enabled' => true,
        'source' => ['name', 'title'],
        'column' => 'slug',
        'unique' => true,
        'regenerate_on_update' => true,
        'separator' => '-',
    ];
}
```

**Benefits**

- Removes repeated slug-generation code.
- Supports fallback sources.
- Preserves manually supplied slugs.
- Supports optional regeneration on source updates.

### 28. Multiple independent slug fields per model

**What it provides**

A model can own multiple differently configured slug columns.

**Usage**

```php
protected function slugFields(): array
{
    return [
        'slug' => [
            'source' => 'name',
            'unique' => true,
            'regenerate_on_update' => true,
        ],
        'seo_slug' => [
            'source' => 'seo_title',
            'unique' => true,
            'regenerate_on_update' => false,
        ],
    ];
}
```

**Benefits**

- Supports public URLs, SEO URLs, category slugs, localized/alternate slug columns, or other model-specific slug purposes.
- Lets each slug have its own lifecycle.
- Keeps backward compatibility with the original single-slug API.

### 29. Scoped unique slugs

**What it provides**

Slug uniqueness can be scoped by model attributes such as `organization_id`.

**Usage**

```php
protected function slugOptions(): array
{
    return [
        'enabled' => true,
        'source' => 'name',
        'scope' => ['organization_id'],
    ];
}
```

**Benefits**

- Ideal for multi-tenant systems.
- Allows the same slug in different organizations while preventing duplicates inside one scope.
- Avoids global slug constraints when the domain does not require them.

### 30. `FileStorage` service

**What it provides**

Reusable file storage helpers for:

- storing uploads;
- generating safe UUID filenames;
- custom filenames;
- checking existence;
- deleting files;
- generating URLs.

**Usage**

```php
$path = $files->store(
    file: $request->file('avatar'),
    directory: 'customers/avatars',
    disk: 'public',
);

$files->exists($path, 'public');
$files->delete($path, 'public');
$url = $files->url($path, 'public');
```

**Benefits**

- Centralizes filesystem operations.
- Avoids repeated upload boilerplate.
- Keeps disk/directory decisions explicit.
- Does not force a third-party image library.

### 31. Model-level automatic file uploads

**What it provides**

An `UploadedFile` assigned directly to a configured model attribute is stored automatically before persistence.

**Usage**

```php
protected function fileAttributes(): array
{
    return [
        'avatar_path' => [
            'disk' => 'public',
            'directory' => 'customers/avatars',
            'auto_upload' => true,
        ],
    ];
}

Customer::query()->create([
    'name' => 'Acme Ltd',
    'avatar_path' => $request->file('avatar'),
]);
```

**Benefits**

- Removes repetitive controller/service upload code for simple model-owned files.
- Stores only the resulting path in the database.
- Allows different disks and directories per model field.

### 32. Per-field file configuration

**What it provides**

Each configured file column can control:

- disk;
- directory;
- automatic upload;
- custom filename/callback;
- audit participation;
- delete on replacement;
- delete on model deletion;
- delete on soft deletion.

**Usage**

```php
'contract_path' => [
    'disk' => 'private',
    'directory' => 'customers/contracts',
    'auto_upload' => true,
    'audit' => true,
    'delete_on_replace' => true,
    'delete_on_delete' => true,
    'delete_on_soft_delete' => false,
],
```

**Benefits**

- Makes file ownership declarative at model level.
- Supports different storage policies for avatars, documents, contracts, etc.
- Reduces accidental file leaks.

### 33. Automatic old-file cleanup on replacement

**What it provides**

When a configured file is replaced, the previous file can be removed after the database update succeeds.

**Usage**

Set:

```php
'delete_on_replace' => true,
```

**Benefits**

- Prevents obsolete files from accumulating.
- Avoids deleting the previous file before a failed database write.
- Keeps database and storage lifecycle aligned.

### 34. File cleanup on delete, soft delete, restore, and force delete policies

**What it provides**

Configurable behavior for normal deletes and soft deletes.

**Usage**

```php
'delete_on_delete' => true,
'delete_on_soft_delete' => false,
```

**Benefits**

- Lets soft-deleted records retain recoverable files.
- Allows permanent cleanup when records are permanently removed.
- Makes deletion policy explicit per file field.

### 35. Storage audit command

**What it provides**

An Artisan command compares files in explicitly model-owned directories against database references.

**Usage**

Register models:

```php
'storage_audit' => [
    'models' => [
        App\Models\Customer::class,
        App\Models\Invoice::class,
    ],
    'chunk_size' => 500,
],
```

Dry run:

```bash
php artisan infrastructure:storage-audit
```

Delete orphaned files:

```bash
php artisan infrastructure:storage-audit --delete
```

Audit one registered model:

```bash
php artisan infrastructure:storage-audit --model=Customer
```

**Benefits**

- Finds files that remain on disk after their database references disappear.
- Reclaims storage safely.
- Uses chunked database reads for large tables.
- Defaults to dry-run mode.

### 36. Storage-audit safety boundaries

**What it provides**

The audit intentionally:

- scans only explicitly registered models;
- scans only explicitly model-owned directories;
- never falls back to blindly scanning the global upload directory;
- includes references from every registered model sharing a directory;
- includes soft-deleted rows;
- aborts deletion if reference collection fails;
- requires `--delete` before touching orphaned files.

**Benefits**

- Greatly reduces the risk of deleting unrelated application files.
- Makes storage cleanup suitable for production use.
- Allows shared directories without treating another model's file as orphaned.

### 37. Standard API response helpers

**What it provides**

Reusable JSON response classes including:

- `MessageResponse`;
- `ResourceResponse`;
- common success/error envelopes.

**Usage**

Return package response helpers from controllers instead of manually rebuilding JSON structures.

**Benefits**

- Standardizes API shape across endpoints.
- Reduces controller boilerplate.
- Makes frontend/client handling more predictable.

### 38. Exception-to-JSON normalization

**What it provides**

A package exception renderer can normalize exceptions for requests that explicitly expect JSON.

**Usage**

Configure with:

```dotenv
LARAVEL_INFRASTRUCTURE_EXCEPTION_RENDERER_ENABLED=true
```

**Benefits**

- Produces consistent API errors.
- Avoids changing normal web/HTML exception behavior.
- Centralizes exception response policy.

### 39. Structured exception hierarchy

**What it provides**

Reusable infrastructure exceptions and consistent error handling for API/application failures.

**Usage**

Throw the relevant package/application exception and let the configured renderer convert it into the standard error envelope when appropriate.

**Benefits**

- Reduces ad-hoc `response()->json(...)` exception handling.
- Separates exceptional control flow from HTTP formatting.
- Makes error contracts easier to document.

### 40. Correlation IDs

**What it provides**

Per-request correlation IDs that can accept a trusted incoming ID or generate one for tracing.

**Usage**

Configure:

```dotenv
LARAVEL_INFRASTRUCTURE_CORRELATION_HEADER=X-Request-ID
LARAVEL_INFRASTRUCTURE_ACCEPT_CORRELATION_ID=true
```

**Benefits**

- Makes one request traceable across logs.
- Simplifies debugging distributed/API workflows.
- Helps support teams correlate client reports with backend logs.

### 41. Structured logging through `CustomLog`

**What it provides**

Centralized logging helpers with package-level configuration.

**Usage**

Enable/configure:

```dotenv
LARAVEL_INFRASTRUCTURE_LOGGING_ENABLED=true
LARAVEL_INFRASTRUCTURE_LOG_CHANNEL=
```

Use the logging helpers documented below from application services/actions where structured operational events are useful.

**Benefits**

- Creates consistent log structure.
- Avoids different logging conventions across modules.
- Makes operational analysis easier.

### 42. Sensitive log-context redaction

**What it provides**

`LogContextRedactor` sanitizes/redacts sensitive values and limits oversized nested data.

**Usage**

Use package logging/context handling instead of dumping raw request/domain arrays directly to logs.

**Benefits**

- Reduces risk of leaking secrets or credentials.
- Keeps logs smaller and more useful.
- Protects against accidentally logging huge nested payloads.

### 43. Domain-specific logging

**What it provides**

- `LogDomain`
- `DomainLoggerFactory`
- per-domain enablement/channel configuration.

**Usage**

Configure domain logging in `laravel-infrastructure.logging.domain_enabled` and `domain_channels`.

**Benefits**

- Separates logs for different application areas.
- Makes high-volume systems easier to operate.
- Allows selective logging without disabling logging globally.

### 44. Daily + size-based log rotation

**What it provides**

`DailySizeRotatingFileHandler` supports log rotation policies based on both date and file size, with retention settings.

**Usage**

Configure maximum file size and retention through package logging configuration.

**Benefits**

- Prevents a single log file from growing without bound.
- Keeps disk usage controlled.
- Makes production log retention predictable.

### 45. Configurable client/server exception logging

**What it provides**

Separate control over logging client-side and server-side exceptions.

**Usage**

```dotenv
LARAVEL_INFRASTRUCTURE_LOG_CLIENT_EXCEPTIONS=false
LARAVEL_INFRASTRUCTURE_LOG_SERVER_EXCEPTIONS=true
LARAVEL_INFRASTRUCTURE_EXCEPTION_TRACE=false
```

**Benefits**

- Reduces noisy logs from expected validation/client errors.
- Keeps server failures visible.
- Lets production environments avoid unnecessary stack traces.

### 46. Long-running worker / Octane-aware service lifetimes

**What it provides**

The service provider is designed so request/operation-scoped infrastructure does not accidentally become stale global state in long-running workers.

**Usage**

Use the package through its registered container bindings rather than manually storing request-specific objects in global/static state.

**Benefits**

- Safer Laravel Octane and long-running queue-worker behavior.
- Reduces state leaking between requests/jobs.
- Makes dependency lifetimes more predictable.

### 47. Package auto-discovery and publishable configuration

**What it provides**

Laravel discovers the package service provider automatically.

**Usage**

Install:

```bash
composer require ak279642/laravel-infrastructure
```

Publish configuration when customization is needed:

```bash
php artisan vendor:publish --tag=laravel-infrastructure-config
```

**Benefits**

- Minimal setup.
- Central configuration for cache, transactions, slug behavior, files, storage audit, responses, and logging.
- Easy reuse across multiple Laravel applications.

### 48. Laravel 10, 11, 12, and 13 support

**What it provides**

The package currently targets:

- PHP 8.2+;
- Laravel 10;
- Laravel 11;
- Laravel 12;
- Laravel 13.

**Benefits**

- One infrastructure package can be reused across multiple supported Laravel generations.
- Easier framework upgrades because common application infrastructure remains centralized.

### 49. Architecture boundary tests

**What it provides**

The test suite includes architecture checks that keep the package independent from a consuming application's own classes.

**Usage**

Run:

```bash
composer test:architecture
```

**Benefits**

- Helps prevent accidental coupling to one application.
- Keeps the package reusable.
- Protects package boundaries as features are added.

### 50. Unit and feature test coverage

**What it provides**

Tests cover repository/cache hardening, query security, validation/actions/services, API responses, exception logging, model infrastructure, slug/file behavior, storage auditing, and service-provider behavior.

**Usage**

```bash
composer test
composer test:unit
composer test:architecture
```

**Benefits**

- Makes package upgrades safer.
- Protects infrastructure behavior shared by many projects.
- Reduces regression risk when extending the package.

### Why use this package instead of rebuilding these pieces in every Laravel project?

1. **Less boilerplate** — common repository, query, cache, validation, transaction, response, logging, slug, and file code is already implemented.
2. **Consistent architecture** — teams can use the same Controller → Action → Service → Repository → Model flow across projects.
3. **Safer queries** — filters, sorts, searches, and relations are allow-listed instead of being blindly accepted from request input.
4. **Fewer database queries** — repository caching, validation-context reuse, eager loading, chunking, and bulk operations reduce unnecessary work.
5. **Safer caching** — deterministic keys, tags, automatic invalidation, and explicit direct-write handling reduce stale-cache bugs.
6. **Better performance** — caching, cursor/chunk APIs, bulk operations, and controlled relation loading make large applications easier to scale.
7. **Cleaner business logic** — controllers focus on HTTP, Actions orchestrate, Services own business rules, and Repositories own persistence/querying.
8. **Safer file lifecycle** — uploads, replacement cleanup, delete policies, and orphan-file auditing are handled consistently.
9. **Better multi-tenant support** — scoped slug uniqueness and cache tags can follow tenant/application boundaries.
10. **Consistent APIs** — response and exception helpers produce predictable JSON contracts.
11. **Better observability** — correlation IDs, structured/domain logging, context redaction, and rotation make production debugging easier.
12. **Long-running worker safety** — package services are designed with worker/Octane lifecycle concerns in mind.
13. **Lower maintenance cost** — infrastructure fixes are made once in the package and reused across consuming applications.
14. **Faster project setup** — new Laravel projects can begin with proven infrastructure instead of recreating foundational patterns.
15. **Incremental adoption** — the package does not require using everything. Applications can use only repositories, only caching, only slug/file concerns, or the complete architecture.

## Recommended application architecture

The package is designed for this application flow:

```text
Controller
    |
    v
Action / Orchestrator  <-- transaction boundary
    |
    v
Service                <-- complete business logic
    |
    v
Repository             <-- querying, persistence and cache-aware reads
    |
    v
Eloquent Model
```

The package does not force this structure, but it is the intended use:

- Controllers handle HTTP concerns.
- Actions coordinate a use case and own transaction boundaries.
- Services implement complete business operations.
- Repositories own database querying and persistence.
- Models describe Eloquent state/relations and may opt into automatic cache invalidation.

## Quick start

### 1. Make the model cache-aware

Your application models do **not** need to extend a package base model.

Implement `CacheableModel` and use `InteractsWithCache` only on models that should participate in automatic cache invalidation.

```php
<?php

namespace App\Models;

use Ak279642\LaravelInfrastructure\Cache\Concerns\InteractsWithCache;
use Ak279642\LaravelInfrastructure\Contracts\CacheableModel;
use Illuminate\Database\Eloquent\Model;

final class Customer extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $fillable = [
        'name',
        'email',
        'status',
        'country_id',
    ];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
```

The model concern registers the package cache observer automatically.

When a cache-aware model is created, updated, deleted, restored, or force-deleted, related repository cache tags are invalidated.

### 2. Create a repository

```php
<?php

namespace App\Repositories;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Validation\ValidationContext;
use App\Models\Customer;

final class CustomerRepository extends BaseRepository
{
    protected array $searchable = [
        'name',
        'email',
        'country.name',
    ];

    protected array $allowedFilters = [
        'status',
        'country_id',
        'created_at',
    ];

    // Dotted relation filters are opt-in separately.
    protected array $allowedRelationFilters = [
        'country.code',
    ];

    // Request-driven model scopes are also explicit.
    protected array $allowedScopes = [
        'active',
    ];

    protected array $allowedSorts = [
        'name',
        'email',
        'created_at',
    ];

    protected array $allowedRelations = [
        'country',
        'orders',
    ];

    protected array $defaultRelations = [
        'country',
    ];

    protected array $defaultOrder = [
        'created_at' => 'desc',
    ];

    public function __construct(
        Customer $model,
        CacheManager $cache,
        ?ValidationContext $validationContext = null,
    ) {
        parent::__construct($model, $cache, $validationContext);
    }
}
```

The repository now has the package's standard query, persistence and cache behavior.

## Optional package BaseModel

If you want cache, slug and file lifecycle behavior available from one application base model, extend the package base model:

```php
<?php

namespace App\Models;

use Ak279642\LaravelInfrastructure\Models\BaseModel as InfrastructureBaseModel;

abstract class BaseModel extends InfrastructureBaseModel
{
    protected function slugOptions(): array
    {
        return [
            'enabled' => true,
            'source' => ['name', 'title'],
            'column' => 'slug',
            'unique' => true,
            'regenerate_on_update' => false,
            'separator' => '-',
        ];
    }

    protected function fileOptions(): array
    {
        return [
            'disk' => 'public',
            'delete_on_replace' => true,
            'delete_on_delete' => true,
            'delete_on_soft_delete' => false,
        ];
    }
}
```

Then application models can extend your own `App\Models\BaseModel`.

A copyable example is available at [`examples/Models/BaseModel.php`](examples/Models/BaseModel.php).

The package base model is optional. You can still extend Laravel's normal `Model` and use only the concerns you need.

## Slug handling

Slug generation is opt-in.

Global defaults live in `config/laravel-infrastructure.php`:

```php
'slug' => [
    'enabled' => false,
    'source' => 'name',
    'column' => 'slug',
    'unique' => true,
    'regenerate_on_update' => false,
    'separator' => '-',
    'scope' => [],
],
```

Enable/configure it on your application base model or a specific model:

```php
protected function slugOptions(): array
{
    return [
        'enabled' => true,
        'source' => ['name', 'title'],
        'column' => 'slug',
        'unique' => true,
        'regenerate_on_update' => true,
        'separator' => '-',
    ];
}
```

The source can be one column or a fallback list. The first non-empty source value is used.

### Multiple slug fields on one model

For models that need different slugs for different purposes, declare `slugFields()`. Each slug column has its own source, uniqueness, regeneration and scope settings:

```php
protected function slugFields(): array
{
    return [
        'slug' => [
            'source' => 'name',
            'unique' => true,
            'regenerate_on_update' => true,
        ],

        'seo_slug' => [
            'source' => 'seo_title',
            'unique' => true,
            'regenerate_on_update' => false,
        ],
    ];
}
```

Declaring a field in `slugFields()` enables that field automatically unless its own `enabled` option is set to `false`. The existing single-field `slugOptions()` API remains supported for backward compatibility.


### Scoped unique slugs

For tenant/organization-specific uniqueness:

```php
protected function slugOptions(): array
{
    return array_replace(parent::slugOptions(), [
        'scope' => ['organization_id'],
    ]);
}
```

Now two organizations can both have `acme-limited`, while duplicate names inside one organization become `acme-limited`, `acme-limited-1`, `acme-limited-2`.

The slug uniqueness lookup goes through the package repository layer and intentionally bypasses cache so multiple writes in the same operation cannot reuse a stale slug lookup.

A manually supplied non-empty slug is never overwritten. If `regenerate_on_update` is `false`, the existing slug remains stable when the source changes. If it is `true`, changing a configured source regenerates the slug unless you explicitly supplied a slug yourself.

For final race-condition protection, add an appropriate database unique index.

## File handling

The package provides `FileStorage` for storing/deleting files and model lifecycle handling for deleting replaced or removed file paths.

It intentionally does not force an image library. Generic Laravel uploads work without Intervention Image or Livewire.

### Store an uploaded file

```php
use Ak279642\LaravelInfrastructure\Files\FileStorage;

$path = $files->store(
    file: $request->file('avatar'),
    directory: 'customers/avatars',
    disk: 'public',
);
```

A safe UUID filename is generated by default while preserving the extension.

Custom filename:

```php
$path = $files->store(
    file: $request->file('contract'),
    directory: 'contracts',
    disk: 'private',
    filename: 'customer-100-contract.pdf',
);
```

Other helpers:

```php
$files->exists($path, 'public');
$files->delete($path, 'public');
$url = $files->url($path, 'public');
```

### Configure model file attributes

```php
protected function fileAttributes(): array
{
    return [
        'avatar_path' => [
            'disk' => 'public',
            'directory' => 'customers/avatars',
            'auto_upload' => true,
            'audit' => true,
            'delete_on_replace' => true,
            'delete_on_delete' => true,
            'delete_on_soft_delete' => false,
        ],

        'contract_path' => [
            'disk' => 'private',
            'directory' => 'customers/contracts',
            'auto_upload' => true,
            'audit' => true,
        ],
    ];
}
```

With `auto_upload=true`, an `UploadedFile` assigned directly to a configured attribute is stored automatically before the model is persisted:

```php
$customer = Customer::query()->create([
    'name' => 'Acme Ltd',
    'avatar_path' => $request->file('avatar'),
    'contract_path' => $request->file('contract'),
]);
```

The database receives the stored path, for example `customers/avatars/<uuid>.jpg`. A custom `filename` string or callable can also be configured per field.

A short string entry still uses shared defaults from `fileOptions()` / package configuration, so existing models remain compatible. For new auto-upload/audit fields, declaring the directory explicitly on the model is recommended.

When `delete_on_replace` is true, the old path is deleted only **after the database update succeeds**. With soft deletes, files are retained by default until force-delete because `delete_on_soft_delete` defaults to false.

### Model-scoped storage audit

Register only the models whose owned storage directories should be audited:

```php
// config/laravel-infrastructure.php
'storage_audit' => [
    'models' => [
        App\Models\Customer::class,
        App\Models\Invoice::class,
    ],
    'chunk_size' => 500,
],
```

Dry-run audit:

```bash
php artisan infrastructure:storage-audit
```

Delete confirmed orphaned files:

```bash
php artisan infrastructure:storage-audit --delete
```

Audit a subset of registered models:

```bash
php artisan infrastructure:storage-audit --model=Customer
```

The audit has a deliberately strict safety boundary:

- it only considers models explicitly listed in `storage_audit.models`;
- it only scans directories explicitly declared by those models in `fileOptions()` or `fileAttributes()`;
- it **never falls back to the global package upload directory for scanning**;
- it aggregates references from every registered model that shares a directory before classifying files as orphaned;
- it reads database references in chunks and includes soft-deleted rows;
- if any database reference scan fails, deletion is aborted before storage is touched;
- dry-run is the default and physical deletion requires `--delete`.

Files in unrelated directories, global upload directories not explicitly owned by a model, and unmanaged storage paths are ignored.

### Recommended file + repository flow

Keep upload/storage work in the service, then let the repository persist the path:

```php
final class UpdateCustomerAvatarService
{
    public function __construct(
        private FileStorage $files,
        private CustomerRepository $customers,
    ) {}

    public function update(Customer $customer, UploadedFile $avatar): Customer
    {
        $path = $this->files->store(
            file: $avatar,
            directory: 'customers/avatars',
            disk: 'public',
        );

        return $this->customers->update(
            id: $customer,
            data: ['avatar' => $path],
            refresh: true,
        );
    }
}
```

The repository handles persistence; the model file concern removes the previous avatar after the successful update.

See [`examples/Services/UpdateCustomerAvatarService.php`](examples/Services/UpdateCustomerAvatarService.php).

## Built-in repository methods

Common read methods are cache-aware automatically:

```php
$repository->all();

$repository->find($id);
$repository->findOrFail($id);

$repository->get([
    'status' => 'active',
]);

$repository->first([
    'email' => 'customer@example.com',
]);

$repository->firstOrFail([
    'email' => 'customer@example.com',
]);

$repository->exists([
    'email' => 'customer@example.com',
]);

$repository->doesntExist([
    'email' => 'customer@example.com',
]);

$repository->count([
    'status' => 'active',
]);

$repository->sum('credit_limit', [
    'status' => 'active',
]);

$repository->avg('credit_limit');
$repository->min('credit_limit');
$repository->max('credit_limit');

$repository->pluck('name', 'id');

$repository->groupCount('status');
```

Pagination and streaming methods are also available:

```php
$repository->paginate(
    filters: ['status' => 'active'],
    perPage: 25,
);

$repository->simplePaginate(perPage: 25);

$repository->cursorPaginate(perPage: 100);

$repository->chunk(500, function ($customers) {
    // Process each chunk.
});

foreach ($repository->lazy(1000) as $customer) {
    // Low-memory iteration.
}

foreach ($repository->cursor() as $customer) {
    // Cursor iteration.
}
```

Standard write methods:

```php
$customer = $repository->create($data);

$customer = $repository->update(
    id: $customerId,
    data: $data,
);

$customer = $repository->updateOrCreate(
    ['email' => $email],
    ['name' => $name],
);

$repository->delete($customerId);
$repository->forceDelete($customerId);
$repository->restore($customerId);
```

Repository write methods clear the repository cache after persistence.

## Filtering

Only fields listed in `$allowedFilters` are intended for normal application filtering.

Simple equality:

```php
$customers = $repository->get([
    'status' => 'active',
    'country_id' => 10,
]);
```

Operator filters:

```php
$customers = $repository->get([
    'created_at' => [
        'operator' => 'between',
        'value' => ['2026-01-01', '2026-12-31'],
    ],

    'country_id' => [
        'operator' => 'in',
        'value' => [10, 20, 30],
    ],

    'email' => [
        'operator' => 'like',
        'value' => '@example.com',
    ],
]);
```

Supported operators include:

```text
=
!=
<>
>
>=
<
<=
like
ilike
in
in_or_null
not_in
between
not_between
null
not_null
```

Relation filters must be explicitly listed in `$allowedRelationFilters` (legacy dotted entries already present in `$allowedFilters` remain supported for backwards compatibility). The relation itself must also be present in `$allowedRelations`, and the related column must exist in the related model table.

Nested relation filtering is also supported:

```php
$customers = $repository->get([
    'country.code' => 'IN',
]);
```

## Search

Configure searchable fields:

```php
protected array $searchable = [
    'name',
    'email',
    'country.name',
];
```

Then:

```php
$customers = $repository->get([
    'search' => 'Acme India',
]);
```

Search terms are split on spaces and applied to the configured columns.

You can also provide search columns for a particular repository call:

```php
$customers = $repository->get([
    'search' => 'Acme',
    'search_columns' => ['name', 'email'],
]);
```

## Sorting

Configure allowed sort columns:

```php
protected array $allowedSorts = [
    'name',
    'email',
    'created_at',
];
```

Then:

```php
$repository->get([
    'sort' => ['name', '-created_at'],
]);
```

or:

```php
$repository->get([
    'sort' => [
        'name' => 'asc',
        'created_at' => 'desc',
    ],
]);
```

A leading `-` means descending order.

Default ordering can be configured on the repository:

```php
protected array $defaultOrder = [
    'created_at' => 'desc',
];
```

## Relations

Configure relations that callers are allowed to request:

```php
protected array $allowedRelations = [
    'country',
    'orders',
];
```

Read with relations:

```php
$customer = $repository->findOrFail(
    id: 10,
    with: ['country', 'orders'],
);
```

or:

```php
$customers = $repository->get([
    'with' => ['country'],
]);
```

Default relations:

```php
protected array $defaultRelations = [
    'country',
];
```

Load after retrieval:

```php
$repository->load($customer, ['country', 'orders']);

$repository->loadMissing($customer, 'country');
```

Relation counts/aggregates are also available through repository relation helpers:

```php
$repository
    ->withCount('orders')
    ->withSum('orders', 'total')
    ->withAvg('orders', 'total');
```

## Model scopes

Given an Eloquent model scope:

```php
public function scopeActive($query)
{
    return $query->where('status', 'active');
}
```

Only scopes listed in `$allowedScopes` can be invoked through repository input:

```php
protected array $allowedScopes = [
    'active',
];
```

Use it through filters:

```php
$customers = $repository->get([
    'scopes' => ['active'],
]);
```

or through the repository query helper:

```php
$repository->scope('active');
```

Unknown scopes are ignored.

# Repository caching

Repository caching is enabled by default.

Default repository TTL is read from `laravel-infrastructure.cache.default_ttl`, which defaults to **300 seconds (5 minutes)** and is configurable with:

```dotenv
LARAVEL_INFRASTRUCTURE_CACHE_TTL=300
```

A repository may still override the default with its protected `$cacheTtl` property, and you can change cache behavior per call chain.

### Change the TTL

```php
use Ak279642\LaravelInfrastructure\Cache\CacheTtl;

$customers = $repository
    ->cacheTtl(CacheTtl::MINUTES_10)
    ->get(['status' => 'active']);
```

or:

```php
$customers = $repository
    ->withCache(CacheTtl::HOUR)
    ->get();
```

### Disable cache

```php
$customers = $repository
    ->withoutCache()
    ->get(['status' => 'active']);
```

### Re-enable cache

```php
$customers = $repository
    ->withCache()
    ->get();
```

### Cache forever

```php
$customers = $repository
    ->rememberForever()
    ->get(['status' => 'active']);
```

Use forever caching only for data whose invalidation path is reliable.

### Add extra cache tags

```php
$customers = $repository
    ->cacheTags([
        'tenant:10',
        'customer-directory',
    ])
    ->get();
```

### Clear a repository's cache

```php
$repository->clearCache();
```

This flushes the model tag used by that repository.

## Custom repository methods with cache

A complete copyable example is available at [`examples/Repositories/CustomerRepository.php`](examples/Repositories/CustomerRepository.php).

This is the recommended way to cache application-specific repository queries.

**Do not use Laravel's `Cache` facade directly inside the repository unless you intentionally want to bypass the package's repository key/tag conventions.**

A subclass of `BaseRepository` can call the protected `cacheRemember()` method.

### Example: simple custom cached method

```php
<?php

namespace App\Repositories;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\CacheTtl;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection;

final class CustomerRepository extends BaseRepository
{
    protected array $allowedRelations = [
        'country',
        'orders',
    ];

    public function __construct(
        Customer $model,
        CacheManager $cache,
    ) {
        parent::__construct($model, $cache);
    }

    public function activeForCountry(int $countryId): Collection
    {
        return $this
            ->cacheTtl(CacheTtl::MINUTES_10)
            ->cacheRemember(
                operation: 'activeForCountry',
                callback: fn () => $this->query()
                    ->where('country_id', $countryId)
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->get(),
                params: [
                    'country_id' => $countryId,
                    'status' => 'active',
                ],
            );
    }
}
```

Usage:

```php
$customers = $customerRepository->activeForCountry(10);
```

The generated cache key is deterministic and includes the custom operation plus parameters.

Equivalent calls with the same parameters reuse the same cache entry.

Different parameters generate different entries:

```php
$customerRepository->activeForCountry(10); // one cache entry
$customerRepository->activeForCountry(20); // another cache entry
```

### Example: custom cached method with relations

When your custom query eager-loads relations, pass the relation names in the `params` array using the `with` key.

This lets the package include cache dependency tags for cache-aware related models.

```php
public function findWithDashboardData(int $customerId): Customer
{
    return $this->cacheRemember(
        operation: 'findWithDashboardData',
        callback: fn () => $this->query()
            ->with([
                'country',
                'orders',
            ])
            ->withCount('orders')
            ->findOrFail($customerId),
        params: [
            'id' => $customerId,
            'with' => [
                'country',
                'orders',
            ],
        ],
    );
}
```

If `Country` and `Order` also implement `CacheableModel`, their dependency tags can participate in invalidation.

### Example: cached aggregate custom method

```php
public function activeCreditTotal(int $countryId): float
{
    return (float) $this->cacheRemember(
        operation: 'activeCreditTotal',
        callback: fn () => $this->query()
            ->where('country_id', $countryId)
            ->where('status', 'active')
            ->sum('credit_limit'),
        params: [
            'country_id' => $countryId,
            'status' => 'active',
        ],
    );
}
```

### Example: custom method with unordered parameters

If order should not change the meaning of an array parameter, wrap the values with `CacheKey::unordered()`.

```php
use Ak279642\LaravelInfrastructure\Cache\CacheKey;

public function byIds(array $ids): Collection
{
    return $this->cacheRemember(
        operation: 'byIds',
        callback: fn () => $this->query()
            ->whereIn('id', $ids)
            ->get(),
        params: [
            'ids' => CacheKey::unordered($ids),
        ],
    );
}
```

These calls now resolve to the same cache key:

```php
$repository->byIds([1, 2, 3]);
$repository->byIds([3, 1, 2]);
```

### Custom write methods and cache invalidation

If the custom method uses the repository's normal write methods, cache clearing is already handled:

```php
public function activate(int $customerId): Customer
{
    return $this->update(
        id: $customerId,
        data: ['status' => 'active'],
    );
}
```

If you implement a completely custom write query, invalidate the repository cache after the write:

```php
public function markCountryCustomersInactive(int $countryId): int
{
    $affected = $this->query()
        ->where('country_id', $countryId)
        ->update([
            'status' => 'inactive',
        ]);

    if ($affected > 0) {
        $this->clearCache();
    }

    return $affected;
}
```

For model-by-model saves on models using `InteractsWithCache`, the model observer also invalidates cache tags. Calling `clearCache()` explicitly for a custom bulk/database write is still the safest repository pattern.

### Advanced: completely custom cache keys and tags

For unusual repository requirements, subclasses can access the package cache manager through `getCacheManager()`.

```php
use Ak279642\LaravelInfrastructure\Cache\CacheKey;
use Ak279642\LaravelInfrastructure\Cache\CacheTag;
use Ak279642\LaravelInfrastructure\Cache\CacheTtl;

public function dashboardSummary(int $countryId): array
{
    $key = CacheKey::make('customers.dashboard-summary', [
        'country_id' => $countryId,
    ]);

    $tags = CacheTag::tags(
        Customer::cacheTag(),
        'customer-dashboard',
        'country:'.$countryId,
    );

    return $this->getCacheManager()->remember(
        key: $key,
        ttl: CacheTtl::MINUTES_5,
        callback: fn () => [
            'active' => $this->query()
                ->where('country_id', $countryId)
                ->where('status', 'active')
                ->count(),

            'inactive' => $this->query()
                ->where('country_id', $countryId)
                ->where('status', 'inactive')
                ->count(),
        ],
        tags: $tags,
    );
}
```

Prefer `cacheRemember()` for normal repository methods because it automatically follows the package's repository key and tag strategy.

Use direct `CacheManager` access only when you actually need a separate cache namespace/tag design.

## Cache keys

Use `CacheKey::make()` to generate deterministic cache keys.

```php
use Ak279642\LaravelInfrastructure\Cache\CacheKey;

$key = CacheKey::make('customers.list', [
    'status' => 'active',
    'country_id' => 10,
]);
```

Parameter ordering does not change the resulting key.

```php
CacheKey::make('customers.list', [
    'status' => 'active',
    'country_id' => 10,
]);

CacheKey::make('customers.list', [
    'country_id' => 10,
    'status' => 'active',
]);
```

Both generate the same deterministic key.

Use a readable key when debugging is more important than compactness:

```php
$key = CacheKey::readable('customers.list', [
    'status' => 'active',
]);
```

## Cache tags

Useful helpers:

```php
use Ak279642\LaravelInfrastructure\Cache\CacheTag;

$modelTag = CacheTag::fromModel(Customer::class);

$entityTags = CacheTag::model(
    Customer::cacheTag(),
    10,
);

$tags = CacheTag::tags(
    'customers',
    'tenant:10',
    ['directory', 'active'],
);

$merged = CacheTag::merge(
    ['customers'],
    ['tenant:10'],
);
```

## Cache TTL constants

```php
use Ak279642\LaravelInfrastructure\Cache\CacheTtl;

CacheTtl::SECONDS_30;
CacheTtl::MINUTE;
CacheTtl::MINUTES_5;
CacheTtl::MINUTES_10;
CacheTtl::MINUTES_30;
CacheTtl::HOUR;
CacheTtl::HOURS_6;
CacheTtl::DAY;
CacheTtl::DAYS_7;
CacheTtl::MONTH;
CacheTtl::YEAR;

CacheTtl::SHORT;  // 5 minutes
CacheTtl::MEDIUM; // 1 hour
CacheTtl::LONG;   // 6 hours
CacheTtl::WEEK;   // 7 days
```

## Cache-store safety

Repository invalidation is tag-based.

Laravel cache stores do not all support tags.

When a repository read resolves cache tags but the configured store does not support tags, the package intentionally bypasses repository caching rather than create entries that cannot be invalidated reliably.

For applications that depend on repository caching, use a taggable cache store such as Redis or Memcached.

Example:

```dotenv
CACHE_STORE=redis
LARAVEL_INFRASTRUCTURE_CACHE_STORE=redis
```

Correctness is preferred over stale cache.

## Direct database writes and manual invalidation

Repository cache invalidation is driven by repository writes and Eloquent model lifecycle events.

A direct database/query-builder write does **not** dispatch Eloquent model events:

```php
DB::table('customers')->where('id', $id)->update([
    'status' => 'inactive',
]);
```

If cached repository reads may be affected, invalidate them explicitly afterward:

```php
$customerRepository->clearCache();
```

For a one-operation fresh read, use the returned repository chain:

```php
$customers = $customerRepository
    ->withoutCache()
    ->get();
```

`withoutCache()` does not disable caching on the original repository instance and its bypass is consumed by that read operation. This prevents request-specific cache state from leaking through long-running workers or shared repository instances.

## Bulk repository operations

The package provides model-aware bulk methods:

```php
$repository->bulkUpdate(
    data: ['status' => 'inactive'],
    filters: ['country_id' => 10],
);

$repository->bulkDelete([
    'status' => 'inactive',
]);

$repository->bulkRestore([
    'country_id' => 10,
]);

$repository->bulkForceDelete([
    'status' => 'deleted',
]);
```

These methods process models individually so normal Eloquent lifecycle hooks/observers can run.

## Duplicate and existence helpers

Useful repository methods for business validation:

```php
$duplicate = $repository->findDuplicate([
    'email' => $email,
]);

$duplicate = $repository->findDuplicate(
    fields: [
        'email' => $email,
        'phone' => $phone,
    ],
    ignore: $customerId,
    where: [
        'tenant_id' => $tenantId,
    ],
);

$customer = $repository->findWhere(
    id: $customerId,
    where: [
        'tenant_id' => $tenantId,
    ],
    with: ['country'],
);

$customers = $repository->findWhereIn(
    field: 'id',
    values: [1, 2, 3],
    where: [
        'tenant_id' => $tenantId,
    ],
);
```

## Repository-backed validation

The package can perform repository-level uniqueness/existence checks and reuse resolved models through `ValidationContext`.

Example:

```php
use Ak279642\LaravelInfrastructure\Validation\RepositoryValidationRule;
use Ak279642\LaravelInfrastructure\Validation\RepositoryValidationService;

final class CreateCustomerService
{
    public function __construct(
        private RepositoryValidationService $validation,
    ) {}

    public function create(array $data): void
    {
        $this->validation->validate([
            new RepositoryValidationRule(
                repository: CustomerRepository::class,
                unique: ['email'],
            ),

            new RepositoryValidationRule(
                repository: CountryRepository::class,
                exists: ['country_id'],
                resolve: [
                    [
                        'field' => 'country_id',
                        'with' => [],
                    ],
                ],
            ),
        ], $data);

        // Continue the complete business operation...
    }
}
```

Validation failures throw the package `ValidationException`.

## Repository-aware FormRequest validation

Use `RepositoryFormRequest` when normal Laravel validation must also verify data through repositories and reuse the loaded models later in the same request.

The flow is:

```text
FormRequest rules()
        |
        v
normal Laravel validation
        |
        | only if successful
        v
repositoryValidationRules()
        |
        v
RepositoryValidationService
        |
        +--> repository existence/uniqueness checks
        +--> optional relation loading
        +--> ValidationContext aliases
        |
        v
Controller / Action / Service
```

Repository queries are not executed if normal FormRequest validation already failed.

### Example FormRequest

```php
use Ak279642\LaravelInfrastructure\Http\Requests\RepositoryFormRequest;
use Ak279642\LaravelInfrastructure\Validation\RepositoryValidationRule;

final class StoreInvoiceRequest extends RepositoryFormRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'integer'],
            'customer_id' => ['required', 'integer'],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
        ];
    }

    protected function repositoryValidationRules(): array
    {
        return [
            new RepositoryValidationRule(
                repository: CustomerRepository::class,
                exists: [
                    'customer_id' => [
                        'where' => [
                            'organization_id' => 'organization_id',
                        ],
                    ],
                ],
                resolve: [
                    [
                        'field' => 'customer_id',
                        'as' => 'customer',
                        'with' => ['organization'],
                    ],
                ],
            ),

            new RepositoryValidationRule(
                repository: ProductRepository::class,
                existsIn: [
                    'field' => 'product_ids',
                    'column' => 'id',
                    'values' => 'product_ids',
                    'where' => [
                        'organization_id' => 'organization_id',
                    ],
                ],
                resolve: [
                    [
                        'field' => 'product_ids',
                        'as' => 'products',
                        'with' => ['tax'],
                    ],
                ],
            ),
        ];
    }
}
```

See [`examples/Http/Requests/StoreInvoiceRequest.php`](examples/Http/Requests/StoreInvoiceRequest.php).

### Repository uniqueness validation

```php
new RepositoryValidationRule(
    repository: CustomerRepository::class,
    unique: ['email'],
);
```

For update validation:

```php
new RepositoryValidationRule(
    repository: CustomerRepository::class,
    unique: ['email'],
    ignore: $this->route('customer')->id,
);
```

Scoped uniqueness:

```php
new RepositoryValidationRule(
    repository: CustomerRepository::class,
    unique: ['email'],
    where: [
        'organization_id' => 'organization_id',
    ],
);
```

A string value in `where` is resolved from request data when that request field exists.

### Resolve a validated model

```php
resolve: [
    [
        'field' => 'customer_id',
        'as' => 'customer',
        'with' => ['organization'],
    ],
],
```

After validation:

```php
$customer = $request->resolvedModel(
    'customer',
    Customer::class,
);
```

### Resolve a collection

```php
existsIn: [
    'field' => 'product_ids',
    'column' => 'id',
    'values' => 'product_ids',
],
resolve: [
    [
        'field' => 'product_ids',
        'as' => 'products',
        'with' => ['tax'],
    ],
],
```

Then:

```php
$products = $request->resolvedCollection(
    'products',
    Product::class,
);
```

Invalid IDs are returned as normal FormRequest validation errors with HTTP 422 behavior.

### Use resolved data in a Service

Inject the scoped `ValidationContext`:

```php
final class CreateInvoiceService
{
    public function __construct(
        private ValidationContext $validationContext,
        private CustomerRepository $customers,
    ) {}

    public function create(array $data): void
    {
        $customer = $this->validationContext->requireModel(
            'customer',
            Customer::class,
        );

        $products = $this->validationContext->requireCollection(
            'products',
            Product::class,
        );

        // Continue business logic without repeating validation queries.
    }
}
```

### Automatic ValidationContext identity map

Repository-backed validation and repository ID lookups share the scoped `ValidationContext` automatically.

- If a matching model is already in context, the repository reuses the same instance.
- If requested relations are missing, they are loaded onto that existing instance with `loadMissing()`.
- `findWhereIn()` reuses matching context models and queries only missing values.
- Newly queried validation models are remembered automatically even when no explicit alias is configured.
- Create/update/restore refresh the context identity map.
- Delete/force-delete evict removed models so a stale in-memory model cannot be returned later in the same request.
- Failed validation restores the previous context and removes partially resolved data.

This means a request can validate an ID, resolve/load its relations, pass through a Service, and later call the Repository without paying for the same lookup twice.

### Repository automatically reuses resolved models

A service can also continue using its repository normally:

```php
$customer = $this->customers->findOrFail(
    $data['customer_id'],
);
```

`BaseRepository` first searches the scoped validation context by **model class + primary key**. If the same customer was resolved during FormRequest validation, the repository returns that existing model instance instead of querying the database again.

This changes the common pattern:

```text
FormRequest checks customer exists   -> query 1
Service loads customer               -> query 2
Repository loads customer again      -> query 3
```

into one repository lookup during validation, with the loaded data reused afterward.

See [`examples/Services/CreateInvoiceService.php`](examples/Services/CreateInvoiceService.php).

### ValidationContext API

```php
$context->get('customer');

$context->getModel('customer');
$context->requireModel('customer', Customer::class);

$context->getCollection('products');
$context->requireCollection('products', Product::class);

$context->findModel(Customer::class, $customerId);

$context->has('customer');
$context->all();
```

Aliases also handle multiple instances of the same model class cleanly, such as `billing_address` and `shipping_address`, while the internal class+ID index still lets repositories reuse each resolved model.

## Operation context

`OperationContext` is an immutable key/value context for passing already-known operation data without introducing global state.

```php
use Ak279642\LaravelInfrastructure\Context\OperationContext;

$context = new OperationContext([
    'tenant_id' => 10,
    'requested_by' => $user->id,
]);

$tenantId = $context->get('tenant_id');

$userId = $context->getOrNull('requested_by');

$all = $context->all();
```

Calling `get()` for a missing key throws an `InvalidArgumentException`.

Use `getOrNull()` for optional values.

## Reusable Services and Actions

The package provides optional base classes for keeping application layers consistent without moving business logic into repositories.

`BaseService` accepts a primary `RepositoryInterface` and provides protected create, update, find, delete and existence helpers plus customization hooks. A service remains responsible for the complete business operation and may inject additional repositories as normal.

```php
use Ak279642\LaravelInfrastructure\Services\BaseService;

final class CreateCustomerService extends BaseService
{
    public function __construct(CustomerRepository $customers)
    {
        parent::__construct($customers);
    }

    public function create(array $data): Customer
    {
        return $this->createRecord($data);
    }

    protected function beforeCreate(array $data): array
    {
        $data['email'] = strtolower(trim($data['email']));

        return $data;
    }
}
```

`BaseAction` provides the transaction boundary. It intentionally does not force an `execute()` signature, so application actions keep strongly typed entry points.

```php
use Ak279642\LaravelInfrastructure\Actions\BaseAction;

final class CreateCustomerAction extends BaseAction
{
    public function __construct(
        TransactionManager $transactions,
        private CreateCustomerService $service,
    ) {
        parent::__construct($transactions);
    }

    public function execute(array $data): Customer
    {
        return $this->transactional(
            fn () => $this->service->create($data),
        );
    }
}
```

The default retry count is configured by `LARAVEL_INFRASTRUCTURE_TRANSACTION_ATTEMPTS` and may be overridden per action with `transactionAttempts()` or per call with the second `transactional()` argument.

Repositories and services should not open competing transaction boundaries for the same use case.

### Repository-validation contract

Repositories used by `RepositoryValidationService` must implement `RepositoryValidationRepository`. `BaseRepository` implements this contract automatically, so normal package repositories require no extra work.

Successful validation stores resolved models and collections in the scoped `ValidationContext`, allowing later services and repositories to reuse the same loaded instances. Failed validation clears partially resolved context so invalid request state cannot leak into later business logic.

## Transaction boundaries

The package exposes `TransactionManager`.

Transactions are intended to live in actions/orchestrators rather than repositories or business services.

```php
use Ak279642\LaravelInfrastructure\Contracts\TransactionManager;

final class CreateInvoiceAction
{
    public function __construct(
        private CreateInvoiceService $service,
        private TransactionManager $transactions,
    ) {}

    public function execute(array $data): Invoice
    {
        return $this->transactions->run(
            fn () => $this->service->create($data),
        );
    }
}
```

That keeps this flow clear:

```text
Action owns transaction
Service owns business logic
Repository owns data access
```

## API responses

The response helpers use one stable JSON envelope and preserve the existing `MessageResponse` and `ResourceResponse` APIs.

### Message response

```php
use Ak279642\LaravelInfrastructure\Http\Responses\MessageResponse;

return MessageResponse::make('Customer deleted.');
```

Response:

```json
{
    "success": true,
    "message": "Customer deleted."
}
```

### Resource response

```php
use Ak279642\LaravelInfrastructure\Http\Responses\ResourceResponse;

return ResourceResponse::make(
    resource: new CustomerResource($customer),
    message: 'Customer loaded.',
);
```

Response:

```json
{
    "success": true,
    "message": "Customer loaded.",
    "data": {
        "id": 1,
        "name": "Example"
    }
}
```

Length-aware, simple and cursor-paginated Laravel resource collections keep `data` separate from a top-level `pagination` object. Length-aware pagination contains `total`, `per_page`, `current_page`, `last_page`, `from`, and `to`.

### Error envelope

Package exceptions and normalized Laravel JSON exceptions use:

```json
{
    "success": false,
    "message": "Validation failed.",
    "errors": {
        "email": [
            "The email field is required."
        ]
    },
    "error_code": "VALIDATION_ERROR"
}
```

Optional `errors` and `data` fields are only emitted when populated. Debug exception details are emitted only when `APP_DEBUG=true`; production 5xx responses use a generic message and do not expose exception messages, files, traces, SQL details, tokens, or secrets.

## Exceptions

Generic package exceptions include:

```text
AccessForbiddenException
BusinessLogicException
ConflictException
HttpMethodNotAllowedException
HttpRequestException
InternalServerException
NotFoundException
RouteNotFoundException
ServiceUnavailableException
TooManyRequestsException
UnauthorizedException
ValidationException
```

Example:

```php
use Ak279642\LaravelInfrastructure\Exceptions\BusinessLogicException;

throw new BusinessLogicException(
    message: 'Customer cannot be deactivated while invoices are pending.',
);
```

For requests that explicitly expect JSON, the package service provider also normalizes common Laravel/Symfony exceptions without replacing the host application's exception handler:

| Failure | HTTP status | Error code |
| --- | ---: | --- |
| Authentication | 401 | `UNAUTHORIZED` |
| Authorization | 403 | `FORBIDDEN` |
| Missing model / route | 404 | `NOT_FOUND` |
| Method not allowed | 405 | `METHOD_NOT_ALLOWED` |
| Conflict | 409 | `CONFLICT` |
| Laravel validation | 422 | `VALIDATION_ERROR` |
| Rate limiting | 429 | `TOO_MANY_REQUESTS` |
| Service unavailable | 503 | `SERVICE_UNAVAILABLE` |
| Unexpected server error | 500 | `INTERNAL_ERROR` |

Automatic JSON exception normalization is enabled by default and can be disabled with:

```dotenv
LARAVEL_INFRASTRUCTURE_EXCEPTION_RENDERER_ENABLED=false
```

HTML/web requests continue through the application's normal Laravel exception rendering.

## Correlation IDs

Package API responses and normalized API errors include a correlation header. The default header is `X-Request-ID`.

A safe incoming ID is reused when it is at most 128 characters and contains only letters, numbers, `.`, `_`, `:`, or `-`. Missing or unsafe IDs are replaced with a generated UUID.

When you also want the correlation header added to arbitrary application responses, register the reusable middleware:

```php
use Ak279642\LaravelInfrastructure\Http\Middleware\RequestCorrelationId;

Route::middleware(RequestCorrelationId::class)->group(function () {
    // API routes...
});
```

Configure the behavior with:

```dotenv
LARAVEL_INFRASTRUCTURE_CORRELATION_HEADER=X-Request-ID
LARAVEL_INFRASTRUCTURE_ACCEPT_CORRELATION_ID=true
```

The same ID is added to structured log context as `request.request_id`, allowing an API failure and its logs to be correlated.

## Logging

Use `CustomLog` for domain-aware structured logging.

```php
use Ak279642\LaravelInfrastructure\Logging\CustomLog;
use Ak279642\LaravelInfrastructure\Logging\LogDomain;

CustomLog::info(
    'Customer created.',
    [
        'customer_id' => $customer->id,
        'status' => $customer->status,
    ],
    LogDomain::APPLICATION,
);
```

Exception logging:

```php
try {
    // Operation...
} catch (Throwable $e) {
    CustomLog::exception(
        exception: $e,
        context: [
            'customer_id' => $customerId,
        ],
    );

    throw $e;
}
```

Sensitive keys are recursively redacted, including passwords, authorization/cookie values, tokens, API keys, secrets, client secrets, private keys, sessions, CSRF tokens, JWTs, and signatures. Common inline forms such as `Bearer <token>`, `password=...`, `token=...`, and `api_key=...` are also scrubbed.

Request logging records query **key names**, not query values or request bodies.

Expected client-side API exceptions (4xx) are not logged by default to reduce noise. Unexpected 5xx API exceptions are logged once through the redacted structured logger, and duplicate raw framework logging is suppressed for normalized JSON requests.

Configure logging with:

```dotenv
LARAVEL_INFRASTRUCTURE_LOGGING_ENABLED=true
LARAVEL_INFRASTRUCTURE_LOG_CLIENT_EXCEPTIONS=false
LARAVEL_INFRASTRUCTURE_LOG_SERVER_EXCEPTIONS=true
LARAVEL_INFRASTRUCTURE_EXCEPTION_TRACE=false
```

Enable exception traces only when appropriate for the deployment environment because traces can contain operational details.

## Service provider and long-running workers

Laravel package discovery registers `LaravelInfrastructureServiceProvider` automatically.

The provider intentionally uses these lifetimes:

| Service | Lifetime | Reason |
| --- | --- | --- |
| `CacheManager` | singleton | cache store/configuration service with no request-specific state |
| `CacheInvalidator` | singleton | stateless cache invalidation coordinator |
| `CacheObserver` | singleton | stateless Eloquent observer |
| `SchemaRegistry` | singleton | process-level schema metadata cache, isolated by connection + physical database identity |
| `FileStorage` | singleton | stateless filesystem adapter |
| `SlugGenerator` | singleton | stateless generator backed by cache/schema services |
| `ApiExceptionRenderer` | singleton | stateless JSON exception mapper |
| `ValidationContext` | scoped | request/job-specific resolved validation models must never leak between operations |
| `TransactionManager` | transient binding | lightweight wrapper around Laravel's database manager |

This makes the package safe for long-running workers such as Laravel Octane and queue workers as long as application repositories/services are not manually registered as unsafe global singletons.

The schema registry includes driver, host, port, database, schema/search path, and table prefix in its in-memory identity. Reusing the same Laravel connection name for another tenant/database therefore does not reuse stale schema metadata.

`ValidationContext` is a Laravel scoped binding, so Octane/request/job scope resets discard previously resolved models.

## Package boundaries

This package intentionally does **not** provide application/domain features such as:

- Admin/RBAC implementation
- User/business models
- Livewire screens
- application routes
- business migrations
- image transformation/resizing workflows (generic file storage/lifecycle handling is included)
- domain seeders
- application-specific authentication
- request-log UI

It also has no dependency on the host application's `App\` namespace.

The goal is reusable infrastructure, not a starter application.

## Testing

Run the package tests:

```bash
composer test
```

Validate the Composer package:

```bash
composer validate --strict
```

The GitHub Actions compatibility matrix runs the complete test suite across PHP 8.2-8.4 and Laravel 10-13. It also includes an architecture suite that scans runtime package source/config for accidental host-application `App\` dependencies.

Run only the architecture guard with:

```bash
composer test:architecture
```

## License

MIT. See [LICENSE](LICENSE).
