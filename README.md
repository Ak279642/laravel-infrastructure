# Laravel Infrastructure

Reusable, application-agnostic infrastructure for **Laravel 10–13**: Eloquent repositories, SQL-aware caching and selective invalidation, scoped visibility, validation, service/action layers, transactions, slug and file lifecycles, API responses, logging, security middleware, and maintenance commands.

Use the package for infrastructure; keep your business models, migrations, authorization policies, routes, and domain logic inside your application.

**Requirements:** PHP 8.2+ · Laravel 10, 11, 12, or 13 · Intervention Image 3.x or 4.1+ · GD or Imagick extension when processing images. **License:** MIT.

## Contents

- [What is included?](#what-is-included)
- [Installation and configuration](#installation-and-configuration)
- [Quick start: model to API](#quick-start-model-to-api)
- [Repository query and write cookbook](#repository-query-and-write-cookbook)
- [Caching, authentication, and automatic invalidation](#caching-authentication-and-automatic-invalidation)
- [Visibility, tenancy, and authorization](#visibility-tenancy-and-authorization)
- [Repository validation and identity reuse](#repository-validation-and-identity-reuse)
- [Services, actions, and transactions](#services-actions-and-transactions)
- [Slugs](#slugs)
- [File uploads, images, and protected media](#file-uploads-images-and-protected-media)
- [API responses and exceptions](#api-responses-and-exceptions)
- [Logging, correlation IDs, and security](#logging-correlation-ids-and-security)
- [Artisan commands and operations](#artisan-commands-and-operations)
- [Configuration reference](#configuration-reference)
- [Troubleshooting and limitations](#troubleshooting-and-limitations)
- [Complete guides and testing](#complete-guides-and-testing)

## What is included?

| Area | Capabilities | Detailed guide |
| --- | --- | --- |
| Repositories | CRUD, allow-listed filters and relations, search, sorting, scopes, aggregates, pagination, streaming, bulk writes | [Repositories](docs/repositories.md) · [Filtering](docs/filtering.md) |
| Caching | Tagged Redis caching, automatic cache keys, request-only reads, SQL dependency tracking, after-commit and scoped invalidation | [Caching](docs/caching.md) |
| Visibility | User/tenant/owner scopes, AND/OR ownership, custom visibility resolvers, actor-isolated keys | [Caching: ownership](docs/caching.md#ownership-global-scopes-and-query-builder-filters) |
| Validation | FormRequest validation, repository existence rules, named model resolution, request identity map | [Validation](docs/validation.md) |
| Application layer | Repository-backed services and transactional use-case actions | [Transactions](docs/transactions.md) · [Complete example](docs/complete-example.md) |
| Slugs | Multiple slug attributes, uniqueness, configurable update behavior | [Slugs](docs/slugs.md) |
| Media | Model-backed upload fields, images, SEO filenames, signed private assets, transactional cleanup, storage audit | [Files](docs/files.md) · [Assets](docs/asset-responses.md) |
| HTTP | Consistent JSON response envelopes, resource pagination metadata, exception rendering | [Responses](docs/responses.md) · [Exceptions](docs/exceptions.md) |
| Logs and security | Domain logs, redaction, correlation IDs, optional security middleware | [Logging](docs/logging.md) · [Security](docs/security.md) |
| Operations | MySQL/PostgreSQL backups, media rename, storage audit, external-write invalidation | [Database backups](docs/database-backups.md) · [Caching](docs/caching.md) |

## Installation and configuration

```bash
composer require ak279642/laravel-infrastructure

# Optional: publish config/laravel-infrastructure.php
php artisan vendor:publish --tag=laravel-infrastructure-config
```

Laravel discovers `LaravelInfrastructureServiceProvider` automatically. The package creates **no application tables** and does not provide application auth, roles, policies, or migrations. Configure those as normal Laravel code.

For repository caching, use Laravel's **existing** cache store. Redis is strongly recommended because tagged invalidation needs a tag-capable driver. There is **no second package cache store**.

```dotenv
CACHE_STORE=redis

# Optional: also observe Laravel query-builder/raw-SQL and pivot writes.
LARAVEL_INFRASTRUCTURE_AUTO_INVALIDATION=true

# Other commonly used settings:
LARAVEL_INFRASTRUCTURE_TRANSACTION_ATTEMPTS=1
LARAVEL_INFRASTRUCTURE_FILE_DISK=public
LARAVEL_INFRASTRUCTURE_IMAGE_DRIVER=gd
LARAVEL_INFRASTRUCTURE_BACKUP_DISK=local
LARAVEL_INFRASTRUCTURE_LOGGING_ENABLED=true
```

**Important:** `auto_invalidation` is **off by default**. Turn it on when you need SQL read-table discovery and invalidation for raw/query-builder writes executed through Laravel's observed connections. Eloquent model-write cache invalidation already works through cache-aware model observers.

## Quick start: model to API

### 1. Define a model

Use the package's `BaseModel` when you want cache events, slug lifecycle, and file lifecycle together. Your application owns the actual database schema and relationships.

```php
<?php

namespace App\Models;

use Ak279642\LaravelInfrastructure\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends BaseModel
{
    protected $fillable = [
        'category_id', 'name', 'slug', 'status', 'price', 'image_path',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    protected function slugFields(): array
    {
        return ['slug' => ['source' => 'name', 'unique' => true]];
    }

    protected function fileAttributes(): array
    {
        return [
            'image_path' => [
                'directory' => 'products',
                'filename_from' => 'name',
                'image' => [
                    'format' => 'webp',
                    'resize' => 'scale_down',
                    'width' => 720,
                    'height' => 720,
                ],
            ],
        ];
    }
}
```

Create the matching `products` table, including the `slug` and `image_path` string columns, using an application migration. Add a database unique index to fields whose uniqueness must survive concurrent writes.

### 2. Define a repository

Child repositories inherit the package's reads, writes, caching, and invalidation. Configure only the query features you want callers to use.

```php
<?php

namespace App\Repositories;

use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;

class ProductRepository extends BaseRepository
{
    protected array $allowedFilters = ['category_id', 'status', 'price'];
    protected array $allowedRelationFilters = ['category.slug'];
    protected array $searchable = ['name', 'category.name'];
    protected array $allowedSorts = ['name', 'price', 'created_at'];
    protected array $allowedRelations = ['category'];
    protected array $allowedScopes = ['published'];
    protected array $defaultOrder = ['created_at' => 'desc'];
    protected bool $strictFilters = true;
}
```

The repository is resolved by Laravel's container with its `Product` model and package-provided cache manager. No application-specific base repository or cache-operation whitelist is required.

### 3. Inject and use it

```php
<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Repositories\ProductRepository;
use Ak279642\LaravelInfrastructure\Http\Responses\ResourceResponse;
use Illuminate\Http\JsonResponse;

class ProductController
{
    public function index(ProductRepository $products): JsonResponse
    {
        $page = $products->paginate(
            filters: ['status' => 'published', 'sort' => ['-created_at'], 'with' => ['category']],
            perPage: 20,
        );

        return ResourceResponse::make(
            ProductResource::collection($page),
            'Products loaded.',
        );
    }
}
```

Your application's `ProductResource` is a normal Laravel JSON resource. For the full production-style model → repository → service → action → request → controller → resource flow, see [Complete Product example](docs/complete-example.md).

## Repository query and write cookbook

All examples below assume `$products` is an injected `ProductRepository`.

### CRUD and lookups

```php
$all = $products->all();
$product = $products->find(12, ['category']);        // nullable
$product = $products->findOrFail(12, ['category']);  // throws if missing
$first = $products->first(['status' => 'published']);
$required = $products->firstOrFail(['status' => 'published']);

$created = $products->create([
    'category_id' => 1, 'name' => 'Phone', 'status' => 'draft', 'price' => 500,
]);
$updated = $products->update($created->getKey(), ['status' => 'published']);
$upserted = $products->updateOrCreate(
    ['slug' => 'phone'],
    ['name' => 'Phone', 'category_id' => 1, 'status' => 'published', 'price' => 500],
);
$products->delete($updated->getKey());
```

`restore()` and `forceDelete()` are available for relevant models, including soft-delete use cases. `truncate()` clears the table and the relevant model cache; use it with care.

### Filtering, searching, relations, and scopes

```php
$items = $products->get([
    'status' => 'published',
    'price' => ['operator' => 'between', 'value' => [100, 1000]],
    'category.slug' => 'electronics',
    'search' => 'phone',
    'scopes' => ['published'],
    'with' => ['category'],
    'sort' => ['-created_at', 'price'],
]);

$published = $products->scope('published')->paginate(perPage: 20);

$open = $products->filterQuery(
    fn (\Illuminate\Database\Eloquent\Builder $query) =>
        $query->where('status', 'published'),
);
$open->get();

$withCategory = $products->with('category')->get();
```

`filterQuery()` returns a clone, so the original repository is not changed. You can also override `globalQueryFilter(Builder $query): Builder` for a consistent repository-wide filter. Query-builder SQL and bindings contribute to cache identity.

Request-facing filters, sorts, scopes, and relations must be allow-listed. Supported comparison operators are `=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`, `like`, `ilike`, `in`, `in_or_null`, `not_in`, `between`, `not_between`, `null`, and `not_null`. See [Filtering](docs/filtering.md) for security rules.

### Counts, sums, averages, and other read helpers

```php
$exists = $products->exists(['status' => 'published']);
$notFound = $products->doesntExist(['status' => 'archived']);
$count = $products->count(['status' => 'published']);
$totalPrice = $products->sum('price');
$averagePrice = $products->avg('price');
$minimumPrice = $products->min('price');
$maximumPrice = $products->max('price');
$names = $products->pluck('name', 'id');
$perStatus = $products->groupCount('status');

$withCounts = $products->withCount('category')->get();
$withCategorySum = $products->withSum('category', 'id')->get();
$withCategoryAverage = $products->withAvg('category', 'id')->get();
```

Aggregate columns and selected fields are checked against the model schema. Relation aggregate methods require valid allow-listed relationships and supported related columns.

For validation-oriented lookups, use `findWhere()`, `findWhereIn()`, and `findDuplicate()`. The package's request-scoped identity map can reuse previously resolved models.

### Pagination and streaming

```php
$page = $products->paginate(filters: ['status' => 'published'], perPage: 20);
$freshPage = $products->paginate(perPage: 20, useCache: false);
$shortPage = $products->paginate(perPage: 20, cacheTtl: 30);

$simple = $products->simplePaginate(perPage: 20);
$cursorPage = $products->cursorPaginate(perPage: 20);

$products->chunk(500, function ($rows): void {
    foreach ($rows as $row) {
        // Process each model.
    }
});
foreach ($products->lazy(500) as $row) {
    // Stream in chunks.
}
foreach ($products->cursor() as $row) {
    // Stream one model at a time.
}
```

`paginate()` is cached by default, with keys incorporating the page, filters, sort, path, SQL scopes, and TTL. `simplePaginate()`, `cursorPaginate()`, `chunk()`, `lazy()`, and `cursor()` use their uncached paths.

### Bulk mutations

```php
$affected = $products->bulkUpdate(
    ['status' => 'archived'],
    ['status' => 'draft'],
);
$deleted = $products->bulkDelete(['status' => 'archived']);
$restored = $products->bulkRestore(['status' => 'archived']);
$removed = $products->bulkForceDelete(['status' => 'archived']);
```

These inherited bulk methods use model lifecycle semantics and cache invalidation. They are **not** the same as a single direct SQL `UPDATE`; for exceptionally large sets, see [efficient set-based SQL and pivot mutations](docs/caching.md#efficient-set-based-sql-and-pivot-mutations).

## Caching, authentication, and automatic invalidation

### How the default repository cache works

- `get()`, `find()`, `first()`, `paginate()`, aggregates, and custom `cacheRemember()` reads use the repository's cache policy by default.
- Default TTL: **5 minutes** (unless a repository or call overrides it). No command is required after each ordinary Eloquent write.
- Cache keys include the repository/model, operation, input parameters, effective SQL/bindings, and database identity.
- In the authenticated-cache-isolation package update, keys also incorporate verified Laravel request/guard actor identity. Actor-model attributes are hashed into the identity when available; partner context is included where present.
- Authentication **does not** authorize a query. Laravel policies, global scopes and application visibility rules still decide which rows are returned.
- Entries use Laravel's configured cache store. Non-taggable stores bypass repository shared read caching rather than rely on unsafe invalidation.
- Reads inside open database transactions bypass shared caching. Completed cache-aware Eloquent writes invalidate after commit, not on rollback.

No `authenticatedPersistentCacheOperations` or `authenticatedPersistentCacheWithoutVisibility` arrays are needed in backend repositories. A custom `cacheReadPolicy()` is optional only for genuinely special read policies, not for enumerating method names.

#### TTL and bypass controls

```php
$products->cacheTtl(60)->get();               // 60-second repository TTL
$products->withCache(600)->findOrFail(12);   // enable cache, 600-second TTL
$products->withoutCache()->findOrFail(12);  // bypass this one read
$products->rememberForever()->get();        // avoid for changing data
$products->paginate(perPage: 20, cacheTtl: 30);
$products->paginate(perPage: 20, useCache: false);
$products->clearCache();                     // explicit invalidation
```

For a repository-wide default:

```php
use Ak279642\LaravelInfrastructure\Cache\CacheTtl;

protected function defaultCacheTtl(): int
{
    return CacheTtl::MINUTES_10;
}
```

Disable cache for a time-sensitive model (for example, an OTP delivery):

```php
protected function cacheOptions(): array
{
    return ['enabled' => false];
}
```

### Enable SQL-observed dependency tracking and invalidation

```dotenv
CACHE_STORE=redis
LARAVEL_INFRASTRUCTURE_AUTO_INVALIDATION=true
```

With this option on, the package records **SQL SELECT dependencies** during repository cache loading (including joins, eligible subqueries, relations, and observed policy/permission lookups) and invalidates their table tags when Laravel executes successful SQL writes. No per-repository `cacheTags()` arrays or explicit table-dependency lists are needed for tables visible to the observer.

It also recognizes safe ownership predicates in some read queries and can target per-user/agent/admin or city/tier partitions. When ownership cannot be proven, it conservatively invalidates a wider table/model dependency to avoid stale results.

**What happens when something changes?**

| Write path | Behavior |
| --- | --- |
| `$model->save()` / `update()` / `delete()` on a cache-aware Eloquent model | Observer-driven invalidation after commit; safe known-owner groups can be targeted |
| Laravel `DB::table(...)->update()`, INSERT/DELETE, pivot writes | Automatically observed when `auto_invalidation` is enabled; table-level fallback may evict more entries |
| SQL outside Laravel's observed database connection/process | Not observed; trigger the external invalidation command or integrate a change feed |
| Role, membership, or permission data queried **during** the observed cache operation | SQL dependencies can be captured and invalidated on relevant Laravel writes |
| Permission/visibility state precomputed elsewhere, external service responses, expiry-by-time | Not automatically discoverable; use explicit policy context, a short TTL, request-only mode, or bypass |

No system can safely infer arbitrary external permissions or time expiry from a cached SQL result. Test your application's user, agent, partner, and admin access patterns.

### Custom repository reads — no manual tag list for observed SQL

A custom method can cache its result with the same package mechanism. Include **every argument affecting the result** in `params`:

```php
use Illuminate\Database\Eloquent\Collection;

// Inside ProductRepository:
public function featured(int $limit = 10): Collection
{
    $limit = max(1, min(100, $limit));

    return $this->cacheRemember(
        'featured',
        fn (): Collection => $this->query()
            ->where('status', 'published')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(),
        ['limit' => $limit],
    );
}
```

`cacheRemember()` works with custom repository operations, but **calling `query()` or raw Eloquent builders does not itself turn those queries into cached reads**. When the watcher is enabled, dependencies from SQL actually executed in the loader are discovered automatically.

Advanced APIs: `CacheManager::rememberObserved()` for standalone SQL reads, `rememberBatch()` for batched per-actor metrics, `rememberWithDependencies()` for explicitly tagged custom reads, and `Repository::loadMissing()` for cached relation graphs when automatic invalidation is on. See [Caching](docs/caching.md) for contracts, transactions, fallbacks, and examples.

### Request-only or uncached exceptions

Override `cacheReadPolicy(string $operation, array $params): ?array` **only when required**:

```php
protected function cacheReadPolicy(string $operation, array $params): ?array
{
    // Application-specific example: handle independently expiring data.
    if ($operation === 'externalTokenStatus') {
        return ['mode' => 'none'];
    }

    return null; // package's default persistent policy
}
```

Supported modes: `persistent`, `request` (memoize inside the current request), and `none` (always execute). Optional persistent `params` can carry authorization context that is **not** expressed by the actor, SQL, or tracked SQL dependencies.

The request-scoped cache is not Redis. Model writes clear its memoized values immediately. See [Caching: read policies](docs/caching.md#request-only-caching-and-application-cache-policies).

### External database changes

If another service or a database console changes a table, it cannot dispatch Laravel query events in this application process. After such writes, explicitly invalidate affected tables:

```bash
php artisan infrastructure:cache:invalidate-external service_requests
```

Prefer integrating the command or corresponding invalidation mechanism into the external writer's deployment/CDC workflow. Refer to [external database write guidance](docs/caching.md#changes-written-outside-the-laravel-application) for the command's exact arguments, limits, and connection handling.

## Visibility, tenancy, and authorization

The package can **apply** model-declared ownership restrictions through `cacheOptions()`, or respect existing Eloquent global scopes. Do not confuse a cache partition with a security policy.

### One actor / tenant boundary

```php
protected function cacheOptions(): array
{
    return [
        'scope' => [
            'column' => 'tenant_id',
            'guard' => 'web',
            'attribute' => 'tenant_id',
        ],
    ];
}
```

When using an authenticated user's ID directly, short owner columns are simpler:

```php
protected function cacheOptions(): array
{
    return [
        'scopes' => ['user_id', 'assigned_to', 'assigned_by'],
        'scope_operator' => 'or',
    ];
}
```

### Tenant AND multiple owner alternatives

```php
protected function cacheOptions(): array
{
    return [
        'scope' => ['tenant_id' => 'auth.tenant_id'],
        'visibility' => [
            'any' => ['user_id', 'assigned_to', 'assigned_by'],
        ],
    ];
}
```

This groups the intended filter as `tenant_id = ? AND (user_id = ? OR assigned_to = ? OR assigned_by = ?)` so the OR clauses cannot bypass the tenant boundary.

### Existing global scope or complex authorization

If Laravel already applies a tenant global scope, it affects SQL/cache keys without any duplicate visibility configuration. A column-only marker such as `['scope' => 'tenant_id']` is **only a cache-partition declaration**. It does not add a SQL `WHERE` and must only be used when an existing global scope strictly enforces the partition.

Complex business visibility may use a `visibility_resolver` class. A custom resolver may also implement `cacheReadTags()` and `cacheInvalidationTags()` if it can prove precisely which viewers are affected. Unknown admin city/tier visibility and shared cross-tenant policies must use the safe broader fallback when precise partitions cannot be proven.

See [Caching: ownership, global scopes, visibility resolvers](docs/caching.md#ownership-global-scopes-and-query-builder-filters).

## Repository validation and identity reuse

`RepositoryFormRequest` validates inputs using normal Laravel rules first, then supports repository-backed existence checks and resolved models:

```php
use Ak279642\LaravelInfrastructure\Http\Requests\RepositoryFormRequest;
use Ak279642\LaravelInfrastructure\Validation\RepositoryValidationRule;

class StoreProductRequest extends RepositoryFormRequest
{
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer'],
            'name' => ['required', 'string'],
        ];
    }

    protected function repositoryValidationRules(): array
    {
        return [
            new RepositoryValidationRule(
                repository: CategoryRepository::class,
                exists: ['category_id'],
                resolve: [
                    ['field' => 'category_id', 'as' => 'category'],
                ],
            ),
        ];
    }
}

// Later in your controller:
$category = $request->resolvedModel('category', Category::class);
```

`ValidationContext` is a scoped identity map: it reuses matching models across lookups during the request and avoids redundant fetches where safe. Its write updates are transaction-aware; rollbacks do not leave mutated identity-map state. See [Validation](docs/validation.md).

## Services, actions, and transactions

Use `BaseService` for reusable single-entity business operations and hooks; use `BaseAction` for use cases coordinating multiple writes in **one transaction**.

```php
use Ak279642\LaravelInfrastructure\Services\BaseService;

class ProductService extends BaseService
{
    public function __construct(ProductRepository $products)
    {
        parent::__construct($products);
    }

    public function createProduct(array $data): Product
    {
        return $this->createRecord($data);
    }

    protected function beforeCreate(array $data): array
    {
        $data['name'] = trim($data['name']);
        return $data;
    }
}
```

`BaseService` hooks: `beforeCreate` / `afterCreate`, `beforeUpdate` / `afterUpdate`, and `beforeDelete` / `afterDelete`.

```php
use Ak279642\LaravelInfrastructure\Actions\BaseAction;
use Ak279642\LaravelInfrastructure\Contracts\TransactionManager;

class CreateProductAction extends BaseAction
{
    public function __construct(
        TransactionManager $transactions,
        private ProductService $products,
        private InventoryRepository $inventory,
    ) {
        parent::__construct($transactions);
    }

    public function execute(array $data): Product
    {
        return $this->transactional(function () use ($data): Product {
            $product = $this->products->createProduct($data);
            $this->inventory->create([
                'product_id' => $product->getKey(),
                'stock' => 0,
            ]);
            return $product;
        });
    }
}
```

The package binds `TransactionManager` to its Laravel implementation. Set `LARAVEL_INFRASTRUCTURE_TRANSACTION_ATTEMPTS` to configure retry attempts (default `1`). Eloquent cache invalidation is deferred until a successful commit; rolled-back transactions do not invalidate committed cache entries.

See [Transactions](docs/transactions.md) and [Complete example](docs/complete-example.md).

## Slugs

Model-defined slugs support multiple columns and independent sources:

```php
protected function slugFields(): array
{
    return [
        'slug' => [
            'source' => 'name',
            'unique' => true,
            'regenerate_on_update' => false,
        ],
        'seo_slug' => [
            'source' => 'seo_title',
            'unique' => true,
            'regenerate_on_update' => true,
        ],
    ];
}
```

Existing hand-set, nonempty slug values are preserved where applicable. A legacy single-field `slugOptions()` API is also supported. **Use a database unique index** for concurrency guarantees. See [Slugs](docs/slugs.md).

## File uploads, images, and protected media

The model stores **relative file paths** in your normal columns. You define the disk, location, processing, filename, and access policy on each field.

```php
protected function fileAttributes(): array
{
    return [
        'image_path' => [
            'disk' => 'public',
            'directory' => 'products',
            'filename_from' => ['name'],  // use name in physical filename
            'image' => [
                'format' => 'webp',
                'resize' => 'scale_down',
                'width' => 720,
                'height' => 720,
                'quality' => 80,
            ],
        ],
        'invoice_path' => [
            'disk' => 'private',
            'directory' => 'invoices',
            'access' => ['signed' => true, 'guard' => 'web'],
        ],
    ];
}
```

```php
$product->image_path = $request->file('image');
$product->invoice_path = $request->file('invoice');
$product->save();

$publicUrl = $product->getFileUrl('image_path');
$privateUrl = $product->fileAssetUrl(
    'invoice_path',
    now()->addMinutes(5),
);
```

- **Public assets:** map a public disk alias, e.g. `'assets' => ['disk_aliases' => ['media' => 'public']]`. A stored path like `products/phone.webp` becomes `/media/products/phone.webp?v=...`, with no database query for public delivery. Aliases must refer to `assets.public_disks`.
- **Private assets:** use a nonpublic filesystem disk and expiring encrypted signed URLs. Delivery checks the current model/file and guard; implement `authorizesAssetField(string $field)` to enforce ownership where required. Never store sensitive files on a public disk.
- **Images:** GD/Imagick processing supports WebP/original format, scaling, resizing, covers, dimensions, and quality.
- **SEO filenames:** `filename_from` can use model attributes and **already-loaded** related attributes; filename collisions get numeric suffixes. Preload relationships before upload; the filename builder does not run hidden relationship queries.
- **URLs:** a public HTTPS source can also be imported through `FileStorage::storeFromUrl()`; URL imports have validation and size restrictions.
- **Lifecycle:** replacements and deletes are cleaned up after commit; failed uploads or rollbacks clean up newly created files, preserving committed previous files.
- **Optional object interface:** `FileReferenceCast` gives file fields `getFileUrl()` while keeping ordinary string columns as the default.

See [Files and media](docs/files.md), [Asset responses](docs/asset-responses.md), and the [expanded Product walkthrough](docs/complete-example.md).

## API responses and exceptions

```php
use Ak279642\LaravelInfrastructure\Http\Responses\MessageResponse;
use Ak279642\LaravelInfrastructure\Http\Responses\ResourceResponse;

return ResourceResponse::make(
    ProductResource::collection($products->paginate(perPage: 20)),
    'Products loaded.',
);

// For a message-only endpoint:
return MessageResponse::make('Product deleted.');
```

`ResourceResponse` puts a consistent JSON envelope around resource data and includes pagination metadata for Laravel paginator-backed resource collections. The package's optional JSON exception renderer uses an API envelope while preserving normal Laravel HTML error handling for non-API requests.

```php
use Ak279642\LaravelInfrastructure\Exceptions\BusinessLogicException;

throw new BusinessLogicException('Insufficient stock.');
```

Additional typed exceptions cover common unauthorized/forbidden/not-found/conflict, validation and service errors. See [Responses](docs/responses.md) and [Exceptions](docs/exceptions.md).

## Logging, correlation IDs, and security

### Structured logging

```php
use Ak279642\LaravelInfrastructure\Logging\CustomLog;

CustomLog::info('Product created.', ['product_id' => $product->getKey()], 'catalog');
CustomLog::warning('Webhook failed.', ['operation' => 'sync'], 'integrations');
CustomLog::error('Job failed.', ['job' => 'rebuild'], 'jobs');
```

Logging supports custom string-backed enum domains, recursively redacted sensitive data, correlation IDs, configurable channels/domains, and file rotation. Logging errors are contained rather than crashing the application. See [Logging](docs/logging.md).

### Optional HTTP middleware

```php
use Ak279642\LaravelInfrastructure\Http\Middleware\RequestCorrelationId;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'infrastructure.reject-sensitive-paths',
    'infrastructure.security-headers',
    RequestCorrelationId::class,
])->group(function (): void {
    // Your API/web routes
});
```

The security middleware are **opt-in**. They reject common sensitive source/configuration path probes and add headers such as `X-Content-Type-Options`, `X-Frame-Options`, and `Referrer-Policy`. Application authentication, authorization, and other security measures remain your responsibility. See [Security](docs/security.md).

## Artisan commands and operations

| Command | What it does |
| --- | --- |
| `php artisan infrastructure:storage-audit` | Review orphaned files in explicitly registered model-owned directories |
| `php artisan infrastructure:storage-audit --delete` | Delete identified orphans; review dry-run output first |
| `php artisan infrastructure:media-rename 'App\Models\Product'` | Dry-run SEO filename migration for existing assets |
| `php artisan infrastructure:media-rename 'App\Models\Product' --apply` | Apply safe path/filename updates; old assets are retained |
| `php artisan infrastructure:database-backup` | Create a MySQL/MariaDB or PostgreSQL logical backup |
| `php artisan infrastructure:database-backup --connection=mysql` | Back up a chosen configured database connection |
| `php artisan infrastructure:cache:invalidate-external service_requests` | Invalidate cache dependencies after out-of-process table writes |
| `php artisan cache:clear` | Refresh shared cached schema metadata after migrations |

**Storage audit:** register models under `storage_audit.models` in `config/laravel-infrastructure.php`, and review the output before deleting. **Backups:** require `mysqldump` or `pg_dump` on the host, support optional gzip, retention, excluded table data, and Laravel disks; SQLite backup is not supported. Always test restores.

See [Files](docs/files.md), [Database backups](docs/database-backups.md), [Repositories](docs/repositories.md), and [Caching](docs/caching.md).

## Configuration reference

Publish `config/laravel-infrastructure.php` only when you need overrides. The following are the main sections, not a replacement for Laravel's cache or filesystem configurations.

| Key / environment setting | Purpose | Default |
| --- | --- | --- |
| `CACHE_STORE` (Laravel) | Redis or another configured Laravel cache store | Application setting |
| `auto_invalidation.enabled` / `LARAVEL_INFRASTRUCTURE_AUTO_INVALIDATION` | Observe SQL reads and Laravel SQL writes | `false` |
| `transactions.attempts` / `LARAVEL_INFRASTRUCTURE_TRANSACTION_ATTEMPTS` | Transaction retry count | `1` |
| `files.disk` / `LARAVEL_INFRASTRUCTURE_FILE_DISK` | Default model-upload disk | `public` |
| `files.image.driver` / `LARAVEL_INFRASTRUCTURE_IMAGE_DRIVER` | Image processor | `gd` |
| `assets.enabled` / `LARAVEL_INFRASTRUCTURE_ASSETS_ENABLED` | Register media routes | `true` |
| `assets.public_disks` | Allow-list of publicly routable disks | `['public']` |
| `assets.disk_aliases` | Optional route alias → disk mapping | `[]` |
| `storage_audit.models` | Model list for storage auditing | `[]` |
| `database_backup.disk` / `LARAVEL_INFRASTRUCTURE_BACKUP_DISK` | Backup target disk | `local` |
| `database_backup.path` / `LARAVEL_INFRASTRUCTURE_BACKUP_PATH` | Backup directory | `backups/database` |
| `database_backup.keep` / `LARAVEL_INFRASTRUCTURE_BACKUP_KEEP` | Retained backup count | `3` |
| `database_backup.compress` / `LARAVEL_INFRASTRUCTURE_BACKUP_COMPRESS` | Compress SQL backups | `true` |
| `responses.exception_renderer_enabled` / `LARAVEL_INFRASTRUCTURE_EXCEPTION_RENDERER_ENABLED` | API JSON exception renderer | `true` |
| `logging.enabled` / `LARAVEL_INFRASTRUCTURE_LOGGING_ENABLED` | Enable structured package logs | `true` |
| `logging.channel` / `LARAVEL_INFRASTRUCTURE_LOG_CHANNEL` | Optional Laravel logging channel | Application default |

Other file, logging and database-backup options are documented in their individual guides.

## Troubleshooting and limitations

- **Repository results are not cached:** verify a tag-capable Laravel cache store (Redis recommended), enabled model caching, a positive TTL, and no active transaction or explicit `withoutCache()`/`useCache: false`. A request with incomplete/mismatched authenticated actor attributes deliberately bypasses shared caching.
- **Stale results after direct SQL:** enable `LARAVEL_INFRASTRUCTURE_AUTO_INVALIDATION=true` for Laravel `DB::table`/pivot writes. Updates outside the Laravel process require explicit external invalidation.
- **Stale or incorrectly shared permissions:** ensure queries enforce your actual visibility policy; keep actor data and permission dependencies in cache identity or within observed SQL, or opt out of persistent caching. **Cached data does not grant access by itself, but caching an unauthorized result is still a security bug.**
- **Too many cache misses:** actor attribute fingerprints and conservative fallback dependencies can lower hit rates. Test and measure before widening persistent caching for complex dashboard/permission reads.
- **Time-only changes (OTP expiry, token expiration, external API state):** no SQL write means no automatic invalidation. Disable shared caching, use request-only mode, or use short/time-aware validity.
- **New columns not recognized:** clear Laravel's schema metadata cache after migrations and restart long-running workers if necessary.
- **Public assets show access denied:** check the configured public disk allow-list, route alias and stored relative path. Protected documents must use a private disk and authorization.
- **Missing signed private asset:** check URL expiry, guard, model/field ownership policy and whether the stored path changed.
- **Unexpected database requests:** public file delivery avoids DB reads, but protected delivery intentionally reloads models to authorize access.
- **Backups fail:** verify the matching native dump client is installed, filesystem disk is writable, and database connection settings are correct.

## Complete guides and testing

| Guide | Focus |
| --- | --- |
| [Complete example](docs/complete-example.md) | Full model, repository, service, action, request, controller and resource |
| [Repositories](docs/repositories.md) | CRUD and repository edge cases |
| [Filtering](docs/filtering.md) | Operator and identifier safety |
| [Caching](docs/caching.md) | Cache isolation, SQL observation, targeted invalidation, batch APIs, external changes |
| [Validation](docs/validation.md) | Repository rules, resolved context and model reuse |
| [Transactions](docs/transactions.md) | Actions and commit/rollback semantics |
| [Slugs](docs/slugs.md) | Slug fields, uniqueness and compatibility |
| [Files](docs/files.md) | Uploads, conversions, filenames, signed assets and cleanup |
| [Asset responses](docs/asset-responses.md) | Public/private URL security, cache headers and error imagery |
| [Responses](docs/responses.md) · [Exceptions](docs/exceptions.md) | API envelopes and error rendering |
| [Logging](docs/logging.md) · [Security](docs/security.md) | Log domains, redaction, middleware |
| [Database backups](docs/database-backups.md) | Logical dumps and retention |
| [Architecture](docs/architecture.md) · [Testing](docs/testing.md) | Package boundaries and quality gates |

Run quality checks from the package repository:

```bash
composer validate --strict
composer test
composer lint
composer analyse
```

The package supports PHP 8.2+ and the Laravel versions declared in [composer.json](composer.json). Image operations require GD or Imagick. See [GitHub issues](https://github.com/Ak279642/laravel-infrastructure/issues) for bugs or feature requests.

**Design boundary:** the package supplies reusable infrastructure, **not** your app's migrations, models, business workflows, authentication framework, RBAC policies, or cross-process change feed.
