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
- [Full public API: repositories and database queries](#full-public-api-repositories-and-database-queries)
- [Full public API: caching utilities](#full-public-api-caching-utilities)
- [Full public API: validation, services and application context](#full-public-api-validation-services-and-application-context)
- [Full public API: model caching and visibility contracts](#full-public-api-model-caching-and-visibility-contracts)
- [Full public API: file storage and image processing](#full-public-api-file-storage-and-image-processing)
- [Full public API: slugs and schema metadata](#full-public-api-slugs-and-schema-metadata)
- [Full public API: HTTP responses, exceptions and observability](#full-public-api-http-responses-exceptions-and-observability)
- [Full public API: maintenance, backups and configuration](#full-public-api-maintenance-backups-and-configuration)
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

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Validation\ValidationContext;
use App\Models\Product;

class ProductRepository extends BaseRepository
{
    // Bind the abstract BaseRepository model argument to a concrete model.
    // Laravel can now auto-resolve ProductRepository in controllers/services.
    public function __construct(
        Product $model,
        CacheManager $cache,
        ?ValidationContext $context = null,
    ) {
        parent::__construct($model, $cache, $context);
    }

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

The concrete `Product` constructor allows Laravel to auto-resolve the repository in controllers/services. Alternatively, bind `ProductRepository` explicitly in an application service provider. The package registers `CacheManager` and `ValidationContext`; it does **not** guess a repository's model. No application-specific base repository or cache-operation whitelist is required.

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


## Full public API: repositories and database queries

The cookbook above shows typical use; the tables below document the actual callable API so you can use the README without reading the implementation.

### Repository construction and configuration

`BaseRepository` receives an Eloquent model, `CacheManager` and an optional `ValidationContext`. Define a concrete model constructor for dependency injection:

~~~php
public function __construct(
    Product $model,
    \Ak279642\LaravelInfrastructure\Cache\CacheManager $cache,
    ?\Ak279642\LaravelInfrastructure\Validation\ValidationContext $context = null,
) {
    parent::__construct($model, $cache, $context);
}
~~~

| Repository property | Meaning |
| --- | --- |
| `$allowedFilters` | Allowed root-table filter columns |
| `$allowedRelationFilters` | Allowed dotted relation columns such as `category.slug` |
| `$searchable` | Root/relation fields eligible for text search |
| `$allowedSorts` | Allowed sort columns |
| `$allowedRelations` | Allowed eager loads and relation helpers |
| `$allowedScopes` | Allowed local scope names |
| `$defaultRelations` | Relations loaded by `buildQuery()` |
| `$defaultOrder` | Default column → direction map, overridden by explicit `sort` |
| `$strictFilters` | If `true`, throw on disallowed filters, relations and sorts; otherwise skip them |

### Repository method signatures

| Operation | Signature | Result / behavior |
| --- | --- | --- |
| Model | `getModel(): Model` | Bound Eloquent model |
| Builder | `query(): Builder` | Fresh scoped Eloquent builder; not automatically cached |
| Query restriction | `filterQuery(callable $filter): static` | New repository clone with a query restriction |
| All | `all(array $columns = ['*']): Collection` | Cache-aware collection |
| Filtered | `get(array $filters = [], array $columns = ['*']): Collection` | Filtered collection |
| First | `first(array $filters = [], array $columns = ['*']): ?Model` | First matching model or `null` |
| Required first | `firstOrFail(array $filters = [], array $columns = ['*']): Model` | First matching model or 404-style exception |
| Find by key | `find(int\|string $id, array $with = [], array $columns = ['*']): ?Model` | Nullable model with optional eager loading |
| Required key | `findOrFail(int\|string $id, array $with = [], array $columns = ['*']): Model` | Model or not-found exception |
| Create | `create(array $data, bool $refresh = false, array $with = []): Model` | Persist one record |
| Update | `update(int\|string\|Model $id, array $data, bool $refresh = false, array $with = []): Model` | Update one record |
| Upsert-like | `updateOrCreate(array $attributes, array $values = []): Model` | Eloquent update-or-create, not a SQL bulk upsert |
| Delete | `delete(int\|string\|Model $id): bool` | Delete one model |
| Restore | `restore(int\|string\|Model $id): bool` | Restore when supported |
| Force delete | `forceDelete(int\|string\|Model $id): bool` | Permanently delete |
| Truncate | `truncate(): void` | Truncate model table and invalidate cache |
| Bulk update | `bulkUpdate(array $data, array $filters = []): int` | Save matching hydrated models, returning affected count |
| Bulk delete | `bulkDelete(array $filters = []): int` | Delete matching hydrated models |
| Bulk restore | `bulkRestore(array $filters = []): int` | Restore soft-deleted models; `0` for unsupported models |
| Bulk force delete | `bulkForceDelete(array $filters = []): int` | Permanently delete matching models |
| Find with conditions | `findWhere(mixed $id, array $where = [], array $with = []): ?Model` | Key plus validated additional where fields |
| Find batch | `findWhereIn(string $field, array $values, array $where = []): Collection` | Unique matching models in requested identifier order |
| Duplicate | `findDuplicate(array $fields, int\|string\|null $ignore = null, array $where = []): ?Model` | Used by repository-backed uniqueness validation |

Additional helper methods and signatures:

~~~php
$repo->exists(['status' => 'active']);
$repo->doesntExist(['status' => 'archived']);
$repo->count(['status' => 'active']);
$repo->sum('price');
$repo->avg('price');
$repo->min('price');
$repo->max('price');
$repo->pluck('name', 'id');
$repo->groupCount('status');
~~~

These methods accept validated filter arrays when applicable, and aggregates validate their target columns. All aggregate/selection column identifiers are schema-checked. `findWhereIn()` preserves first-seen ID order and normalizes numeric primary keys.

### Query metadata and operator examples

~~~php
$filters = [
    'status' => 'active',
    'price' => ['operator' => 'between', 'value' => [100, 500]],
    'category.slug' => 'electronics',
    'search' => 'wireless headphones',
    'search_columns' => ['name', 'category.name'],
    'with' => ['category'],
    'with_count' => ['orders'],
    'scopes' => ['published'],
    'sort' => ['-created_at', 'price'],
];
$items = $products->get($filters);

// Supported equivalent operator shape:
$items = $products->get(['price' => ['>=', 100]]);

// Alternative sort shape:
$items = $products->get(['sort_by' => 'price', 'sort_order' => 'desc']);
~~~

Search splits terms on whitespace, matches allowed root/relation columns and constrains requested `search_columns` to the configured `$searchable` list. On PostgreSQL, text search/filter comparisons may use `ILIKE`; on other supported drivers the implementation applies its compatible fallback. For relation filters, use the separate `$allowedRelationFilters` list **and** allow the relation itself.

Supported operators: `=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`, `like`, `ilike`, `in`, `in_or_null`, `not_in`, `between`, `not_between`, `null` and `not_null`. Filter operator handling is implemented by the package; it is not a general-purpose raw SQL expression language.

### Sorting, local scopes, relation and streaming helpers

~~~php
$products->orderBy('price', 'desc')->get();
$products->orderByDesc('created_at')->get();
$products->latest('created_at')->get();
$products->oldest('created_at')->get();
$products->scope('published')->paginate(perPage: 20);

$products->with(['category'])->get();
$products->withCount('orders')->get();
$products->withSum('orders', 'total')->get();   // only if orders.total exists
$products->withAvg('orders', 'total')->get();   // only if orders.total exists

$product = $products->findOrFail(12);
$products->load($product, ['category']);
$products->loadMissing($product, ['category']);
~~~

For `withSum()` and `withAvg()`, the related relation and column must exist; unsafe and unsupported nested aggregates are rejected. `scope()` and sorting modifiers change the repository object's query configuration: use a scoped instance when mixing independent query chains. Only allowed scopes/relations/sorts are executed.

~~~php
$products->paginate(filters: [], perPage: 15, columns: ['*'],
    pageName: 'page', page: null, useCache: true, cacheTtl: null);
$products->simplePaginate(perPage: 15);
$products->cursorPaginate(perPage: 15);
$products->chunk(500, function ($rows): void { /* process batch */ });
foreach ($products->lazy(500) as $row) { /* process */ }
foreach ($products->cursor() as $row) { /* process */ }
~~~

## Full public API: caching utilities

These types are injectable services. Normal application repositories do **not** need to use them directly; they are available for reporting, custom SQL and nonrepository workloads.

### CacheManager: storage, tags, locks and atomic operations

~~~php
use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\CacheTtl;
use Ak279642\LaravelInfrastructure\Cache\CacheTag;

$cache = app(CacheManager::class);
$tags = [CacheTag::fromModel(\App\Models\Product::class)];

$cache->put('catalog:version', 4, CacheTtl::HOUR, $tags);
$version = $cache->get('catalog:version', 0, $tags);
$present = $cache->has('catalog:version', $tags);
$absent = $cache->missing('catalog:version', $tags);

$all = $cache->many(['catalog:version', 'catalog:updated'], $tags);
$cache->putMany(['catalog:a' => 1, 'catalog:b' => 2], 300, $tags);
$cache->add('catalog:first-writer', true, 30, $tags);
$cache->increment('catalog:counter', 1, $tags);
$cache->decrement('catalog:counter', 1, $tags);

$cached = $cache->remember('catalog:count', CacheTtl::MINUTES_5,
    fn () => \App\Models\Product::query()->count(), $tags);

$cache->forget('catalog:a', $tags);
$cache->forgetMany(['catalog:b', 'catalog:version'], $tags);
$cache->flushTags($tags);
~~~

Other public storage methods: `forever()`, `putForever()`, `rememberForever()`, `pull()`, `refresh()`, `flushAll()`, `supportsTags()`, `getStore()`, and `lock()`. `flushAll()` affects the **entire configured cache store**; prefer relevant tags. Store a `null` value when appropriate—the cache layer distinguishes a missing entry from cached `null`.

**Prevent cache stampedes** with an atomic lock around population:

~~~php
$value = $cache->rememberLocked(
    key: 'catalog:expensive-query',
    ttl: 120,
    callback: fn () => \App\Models\Product::query()->count(),
    tags: $tags,
    lockSeconds: 10,
    waitSeconds: 3,
);

$lock = $cache->lock('catalog:exclusive-job', 30);
if ($lock !== null && $lock->get()) {
    try {
        // Protected work.
    } finally {
        $lock->release();
    }
}
~~~

The lock-based loader rechecks for values populated by competing workers. Redis/shared lock support is recommended for multiple PHP workers and servers.

### Cache keys, tags, TTL constants and trait helpers

~~~php
use Ak279642\LaravelInfrastructure\Cache\CacheKey;
use Ak279642\LaravelInfrastructure\Cache\CacheTag;
use Ak279642\LaravelInfrastructure\Cache\CacheTtl;

$key = CacheKey::make('metrics', ['tenant' => 7, 'month' => '2026-10']);
$sortedSetKey = CacheKey::make('reports', [
    'cities' => CacheKey::unordered([5, 3, 5, 1]),
]);
$readable = CacheKey::readable('report', ['status' => 'active']);

$modelTag = CacheTag::fromModel(\App\Models\Product::class);
$entityTag = CacheTag::entity('product', 12);
$tags = CacheTag::merge(
    CacheTag::model('product', 12),
    [$modelTag, 'catalog'],
);

$seconds = CacheTtl::SECONDS_30;
$minutes = CacheTtl::MINUTES_10;
$hours = CacheTtl::HOURS_6;
$days = CacheTtl::DAYS_7;
~~~

`CacheTtl` also includes `SECOND`, `SECONDS_5`, `SECONDS_10`, `MINUTE`, `MINUTES_2`, `MINUTES_5`, `MINUTES_15`, `MINUTES_30`, `HOUR`, `HOURS_2`, `HOURS_3`, `HOURS_12`, `DAY`, `DAYS_2`, `DAYS_3`, `DAYS_5`, `DAYS_14`, `MONTH`, `MONTHS_3`, `MONTHS_6`, `YEAR`, and aliases `SHORT` / `MEDIUM` / `LONG` / `WEEK`. All TTL constants are **seconds**.

The `Ak279642\LaravelInfrastructure\Cache\Concerns\Cacheable` trait can be used on application helper classes for protected methods: `cacheGet()`, `cacheMany()`, `cacheHas()`, `cachePut()`, `cacheRemember()`, `cacheRememberForever()`, `cacheForget()`, `cacheInvalidateTags()`, `cacheInvalidateModel()`, `cacheRefresh()`, `cacheLock()`, `cacheManager()` and `cacheInvalidator()`. It is distinct from the Eloquent model's `InteractsWithCache` trait.

~~~php
use Ak279642\LaravelInfrastructure\Cache\Concerns\Cacheable;

class ProductMetrics
{
    use Cacheable;

    public function summary(): array
    {
        return $this->cacheRemember(
            'metrics:products:summary',
            120,
            fn (): array => ['products' => \App\Models\Product::query()->count()],
            [\Ak279642\LaravelInfrastructure\Cache\CacheTag::fromModel(\App\Models\Product::class)],
        );
    }
}
~~~

### CacheInvalidator and transaction-safe invalidation

~~~php
use Ak279642\LaravelInfrastructure\Cache\CacheInvalidator;
use Ak279642\LaravelInfrastructure\Cache\CacheTag;
use Illuminate\Support\Facades\DB;

$invalidator = app(CacheInvalidator::class);
$modelTag = CacheTag::fromModel(\App\Models\Product::class);

DB::transaction(function () use ($invalidator, $modelTag): void {
    DB::table('products')->where('id', 12)->update(['price' => 800]);

    $invalidator->invalidateAfterCommit(
        [$modelTag],
        DB::connection(),
    );
});
~~~

The API also includes `forget()`, `forgetMany()`, `invalidateTags()`, `invalidateModel()`, `invalidateScope()` and `refresh()`. `invalidateAfterCommit()` clears request memoization immediately and schedules persistent invalidation **after commit**; rollback discards it. This is useful for raw/pivot mutations when the SQL watcher is disabled or the mutation's dependent models cannot be inferred.

### Batched and SQL-dependent cached reads

**Batch loading:** one loader call handles only the IDs missing from cache and returns an ID-keyed array.

~~~php
use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\CacheTag;
use Illuminate\Support\Facades\DB;

$cache = app(CacheManager::class);
$ids = [12, 18, 12];

$values = $cache->rememberBatch(
    'product:orders-total', $ids, 600,
    function (array $missingIds): array {
        return DB::table('orders')
            ->whereIn('product_id', $missingIds)
            ->selectRaw('product_id, SUM(total) AS total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id')
            ->all();
    },
    tagsForId: fn (int $id): array => [
        CacheTag::fromModel(\App\Models\Order::class),
        CacheTag::entity('product', $id),
    ],
    connection: DB::connection(),
);
~~~

If you **omit** `tagsForId`, `rememberBatch()` can discover dependency tables itself **only when automatic SQL invalidation is enabled**. When the watcher is off and no tag resolver is provided, it computes without shared caching. Explicit tag resolvers allow caching under a model/table dependency while the watcher is off.

**One custom SQL value with known dependencies:**

~~~php
$value = $cache->rememberWithDependencies(
    'sales:month:2026-10',
    600,
    fn () => DB::table('orders')->whereMonth('created_at', 10)->sum('total'),
    [CacheTag::fromModel(\App\Models\Order::class)],
    DB::connection(),
);
~~~

Related APIs: `getWithDependencies($key, $default, $tags, $connection)` and `putWithDependencies($key, $value, $ttl, $tags, $connection)`. They avoid reading/writing unsafe shared entries during an active transaction and require nonempty dependencies.

**Discover SQL read dependencies automatically:**

~~~php
$report = $cache->rememberObserved(
    key: 'reports:city:5',
    ttl: 300,
    callback: fn () => DB::table('orders')
        ->where('city_id', 5)
        ->sum('total'),
    rootConnection: DB::connection(),
    connection: DB::connection(),
);
~~~

`rememberObserved()` needs `LARAVEL_INFRASTRUCTURE_AUTO_INVALIDATION=true` and a tag-capable store; otherwise it executes the callback. Advanced parameters `baseTags`, `lockSeconds`, `waitSeconds`, `ignoreObservedTags`, `rootTable`, and `rootModelTag` support repository internals. Do not use `ignoreObservedTags` to drop dependencies unless you can **prove** a safe bounded read.

### Request cache and cache identity rules

`RequestReadCache::remember($key, $callback)` memoizes results in the current Laravel request, including `null`; `RequestReadCache::clear()` discards them. It is not a replacement for persistent Redis caching.

Automatic authenticated cache identity recognizes Laravel's authenticated request user, or trusted authentication middleware's `user_type` / `user_id` attributes and optional user/partner models. A partial/mismatched actor model causes the **persistent** cache to be bypassed. The SQL query must still enforce actual user/agent/admin/partner permissions. External permission state requires its own identity dependency or uncached reads.

### Selective SQL partitions and fallback behavior

| Read/write pattern | Package behavior |
| --- | --- |
| Conjunctive root filter, e.g. `orders.user_id = ?` | Can infer a per-owner dependency tag from SQL and bound values |
| Proven grouped OR, e.g. `(orders.user_id = ? OR orders.agent_id = ?)` | Can target only involved owner/agent partitions |
| Shared city/tier readers with bounded predicates | Can target shared city/tier partitions; not necessarily one admin ID |
| Unrestricted OR, negation, unknown SQL, unbounded dashboards | Conservatively retain broad tags |
| Single-row Eloquent old/new owner transfer | Invalidates both prior and new partitions when provable |
| Bulk SQL or raw query-builder writes | Can invalidate broad table/untracked-write tags; cannot infer old owners without additional data |
| Out-of-process SQL write | Must call external table invalidation (or send committed CDC event to it) |
| Unknown third-party API, time expiry or role context never read in observed operation | Cannot be inferred from SQL; use TTL/context/bypass |

The SQL observer does not run extra SELECTs merely to discover dependencies. It records SQL already executed in an observed read. Nested cached dependencies can propagate to parent loaders.



## Full public API: validation, services and application context

### RepositoryValidationRule: every available option

Repository validation runs **after** Laravel's normal FormRequest validation passes, so it does not waste database reads for invalid basic input. Rules use an injectable repository class implementing `RepositoryValidationRepository`.

~~~php
use Ak279642\LaravelInfrastructure\Http\Requests\RepositoryFormRequest;
use Ak279642\LaravelInfrastructure\Validation\RepositoryValidationRule;

class SaveProductRequest extends RepositoryFormRequest
{
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer'],
            'sku' => ['required', 'string'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer'],
        ];
    }

    protected function repositoryValidationRules(): array
    {
        return [
            new RepositoryValidationRule(
                repository: \App\Repositories\CategoryRepository::class,
                exists: ['category_id'],
                resolve: [
                    ['field' => 'category_id', 'as' => 'category', 'with' => ['parent']],
                ],
            ),

            new RepositoryValidationRule(
                repository: \App\Repositories\ProductRepository::class,
                unique: ['sku'],
                ignore: $this->route('product')?->getKey(),
            ),

            new RepositoryValidationRule(
                repository: \App\Repositories\TagRepository::class,
                existsIn: ['field' => 'tag_ids', 'column' => 'id', 'values' => 'tag_ids'],
                resolve: [
                    ['field' => 'tag_ids', 'as' => 'tags'],
                ],
            ),
        ];
    }
}
~~~

The above assumes your `CategoryRepository`, `ProductRepository` and `TagRepository` are concrete, container-resolvable repositories whose models and relations exist.

| Constructor argument | Type | Usage |
| --- | --- | --- |
| `repository` | `string` | Concrete repository class |
| `unique` | `array` | Input fields to check for duplicates |
| `exists` | `array` | Single-ID existence checks; can include per-field `input` and `where` options |
| `where` | `array` | Additional constrained field/value conditions; a value matching an input key can be resolved from input |
| `existsIn` | `array` | Batch existence of an array: `field`, optional `column`, `values`, and `where` |
| `resolve` | `array` | Named model/collection definitions: `field`, optional `as`, `with` |
| `ignore` | `int\|string\|null` | Ignore this model key during uniqueness validation (such as an update) |

~~~php
// In a controller after validation:
$category = $request->resolvedModel('category', \App\Models\Category::class);
$tags = $request->resolvedCollection('tags', \App\Models\Tag::class);
$any = $request->resolved('category');

// A normal repository find can reuse a model already resolved by validation:
$sameCategory = $categories->findOrFail($category->getKey());
~~~

Use `repositoryValidationData(Validator $validator): array` when the repository rules need transformed input. The application can also inject `RepositoryValidationService` to run `validate($rules, $input, resetContext: true)` (throws on failure) or `errors(...)` (returns field-indexed messages) outside a FormRequest.

### ValidationContext: request-local identity map

~~~php
use Ak279642\LaravelInfrastructure\Validation\ValidationContext;

$context = app(ValidationContext::class);
$context->put('product', $product);
$found = $context->get('product');
$typed = $context->requireModel('product', \App\Models\Product::class);
$byKey = $context->findModel(\App\Models\Product::class, $product->getKey());

$context->remember($product);
$models = $context->models(\App\Models\Product::class);
$matching = $context->findMatching(\App\Models\Product::class, ['status' => 'active']);
$context->forgetModel($product);

// Manual snapshot operations are useful for advanced orchestration:
$snapshot = $context->snapshot();
$context->restore($snapshot);
~~~

Other public methods: `getModel()`, `findManyMatching()`, `getCollection()`, `requireCollection()`, `has()`, `forget()`, `clear()` and `all()`. Identity-map mutations respect commit/rollback semantics and are not persistent Redis data.

### BaseService hooks and OperationContext

~~~php
use Ak279642\LaravelInfrastructure\Services\BaseService;

class ProductService extends BaseService
{
    public function __construct(\App\Repositories\ProductRepository $products)
    {
        parent::__construct($products);
    }

    public function create(array $input): \App\Models\Product
    {
        return $this->createRecord($input, refresh: true, with: ['category']);
    }

    public function update(int $id, array $input): \App\Models\Product
    {
        return $this->updateRecord($id, $input, refresh: true);
    }

    public function remove(int $id): bool
    {
        return $this->deleteRecord($id);
    }

    protected function beforeCreate(array $data): array
    {
        $data['name'] = trim($data['name']);
        return $data;
    }

    protected function afterCreate(
        \Illuminate\Database\Eloquent\Model $model,
        array $data,
    ): \Illuminate\Database\Eloquent\Model {
        return $model;
    }
}
~~~

Overridable service hooks also include `beforeUpdate($id, $data)`, `afterUpdate($model, $data)`, `beforeDelete($id)`, and `afterDelete($id, $deleted)`. Service query shortcuts include `findRecord()`, `findRecordOrFail()`, `recordExists()` and `repository()`.

For immutable, typed access to data passed between application operations, use `OperationContext`:

~~~php
use Ak279642\LaravelInfrastructure\Context\OperationContext;

$ctx = new OperationContext(['order_id' => 15, 'warehouse_id' => 4]);

$ctx->has('order_id');          // true
$ctx->get('order_id');          // 15; throws if key is missing
$ctx->getOrNull('not_found');   // null
$ctx->all();                    // all values
~~~

Transaction orchestration uses `BaseAction::transactional(callable $callback, ?int $attempts = null)` and the injectable `TransactionManager` contract. An Action owns the transaction boundary; do not add a second transaction around the same use case unless the application needs nested transactions.

## Full public API: model caching and visibility contracts

`BaseModel` implements `CacheableModel` and includes `InteractsWithCache`, `InteractsWithSlug` and `InteractsWithFiles`. If you already extend Laravel's Eloquent Model directly, you may opt into the cache concern with the corresponding interface:

~~~php
use Ak279642\LaravelInfrastructure\Cache\Concerns\InteractsWithCache;
use Ak279642\LaravelInfrastructure\Contracts\CacheableModel;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return [
            'enabled' => true,
            'scopes' => ['user_id', 'assigned_to'],
            'scope_operator' => 'or',
        ];
    }
}
~~~

**Available `cacheOptions()` keys and behaviors:** `enabled` switches model caching; `scope`/`scopes` and `scope_operator` define ownership restrictions; `visibility.any` expresses additional grouped OR conditions; `visibility_resolver` handles complex query policy; `actor_resolver` customizes resolved actor information. Both normal Laravel global scopes and repository-wide `globalQueryFilter()` can contribute SQL to cache keys.

Model cache methods available from the concern include `usesInfrastructureCache()`, `cacheTag()`, `getCacheTags()`, `getCacheDependencyTags()`, `getCacheInvalidationTags()` and `invalidateCache()`, plus public scope/visibility resolution helpers intended for infrastructure integrations. Most applications should only override `cacheOptions()` and let the observers handle invalidation.

A custom class-based visibility resolver may implement an `apply()` method to change query visibility and optionally both `cacheReadTags(Model $model, ?array $actor): array` and `cacheInvalidationTags(Model $model): array` for precise cache invalidation. Their tags must account for **every** eligible viewer, including shared groups and admin access. If you cannot prove those dependencies, retain the package's conservative model/table fallback.

The SQL-partition inference utility `SqlBoundReadPartitions` supports selected bound equality/IN predicates for `user_id`, `admin_id`, `assigned_admin_id`, `created_by_admin_id`, `agent_id`, `partner_id`, `owner_id`, `tenant_id`, `created_by`, `assigned_to`, `assigned_by`, `city_id`, and `tier_id`. It rejects unsupported or unbounded reads rather than inventing precise partitions.

## Full public API: file storage and image processing

### FileStorage: use outside Eloquent models

Use the injectable `FileStorage` for standalone uploads; if the file is owned by a model attribute, prefer `fileAttributes()` and the model's automatic lifecycle instead.

~~~php
use Ak279642\LaravelInfrastructure\Files\FileStorage;

$storage = app(FileStorage::class);

// All methods accept optional directory/disk/filename arguments.
$path = $storage->store($request->file('avatar'), 'users', 'public');
$raw = $storage->storeContents('hello', 'txt', 'exports', 'local', 'report.txt');
$remote = $storage->storeFromUrl(
    'https://example.com/photo.jpg',
    'imports',
    'public',
);

$exists = $storage->exists($path, 'public');
$url = $storage->url($path, 'public');
$storage->delete($path, 'public');
~~~

Other APIs: `downloadUrl(string $url): UploadedFile` and `copyWithUniqueName(string $oldPath, string $directory, string $filename, string $disk = 'public'): string`. URL imports enforce HTTPS, public host/IP restrictions and bounded downloads; do not pass user-controlled URLs without your application also validating expected origins.

### Model file configuration and lifecycle

~~~php
protected function fileOptions(): array
{
    return [
        'disk' => 'public',
    ];
}

protected function fileAttributes(): array
{
    return [
        'avatar_path' => [
            'disk' => 'public',
            'directory' => 'avatars',
            'auto_upload' => true,
            'delete_on_replace' => true,
            'delete_on_delete' => true,
            'delete_on_soft_delete' => false,
            'audit' => true,
            'filename_from' => ['name'],
            'image' => [
                'driver' => 'gd',
                'format' => 'webp',
                'resize' => 'cover_down',
                'width' => 400,
                'height' => 400,
                'quality' => 80,
                'position' => 'center',
            ],
        ],
    ];
}
~~~

Supported image resize strategies: `none`, `scale`, `scale_down`, `resize`, `resize_down`, `cover` and `cover_down`. Formats: `webp` and `original`. `image => true` enables default processing; omit `image` to save the raw file.

~~~php
$user->avatar_path = $request->file('avatar');
$user->save();

$publicUrl = $user->getFileUrl('avatar_path');
$optionalUrl = $user->fileAssetUrl('avatar_path'); // null when no path
$settings = $user->configuredFileAttributes();
$auditable = $user->auditableFileAttributes();
~~~

Files are stored as **relative paths** in existing model columns; uploads clean up on failure/rollback, and old files are removed after successful committed replacement. Soft-delete cleanup is configurable separately. File names can be derived from already-loaded model relations, but `filename_from` must not trigger new relation queries; preload them first.

### FileReference and FileReferenceCast

~~~php
use Ak279642\LaravelInfrastructure\Files\FileReferenceCast;

protected function casts(): array
{
    return ['avatar_path' => FileReferenceCast::class];
}

// When the model has a stored avatar, the cast returns a FileReference:
$url = $user->avatar_path?->getFileUrl();
$path = (string) $user->avatar_path;
~~~

The cast is **optional**: regular string columns and uploaded-file assignment work without it.

### Public assets, private assets and failure handling

~~~php
// config/laravel-infrastructure.php (relevant parts)
'assets' => [
    'enabled' => true,
    'public_disks' => ['public'],
    'disk_aliases' => ['media' => 'public'],
    'render_error_images' => true,
    'error_images' => [403 => null, 404 => null],
],
~~~

- **Public files:** route `/{alias}/{relative-path}?v=...` (e.g. `/media/products/phone.webp?v=...`) reads the configured disk without Eloquent database queries. Only disks explicitly in `assets.public_disks` can be exposed.
- **Private files:** signed, expiring encrypted tokens use `/_infrastructure/files/{token}`. They intentionally reload the current Eloquent record, verify field/path and require appropriate guard/model authorization. Model hooks such as `authorizesAssetField(string $field)` should enforce user ownership.
- **Asset caching:** public file responses use `Cache-Control: public, max-age=86400`; private responses use `private, no-store, max-age=0`.
- **Error artwork:** by default 403/404 media errors may render built-in WebP artwork with HTTP 200 plus `X-Asset-Error-Status`; set `render_error_images=false` if you require real HTTP 403/404 status codes.
- **Safety:** no public filesystem disk for confidential documents. No legacy unscoped `/uploads` or arbitrary-disk route is registered.

### SEO filename migration, audit and housekeeping

~~~bash
# Preview before changing any existing paths.
php artisan infrastructure:media-rename 'App\Models\Product'
php artisan infrastructure:media-rename 'App\Models\Product' --apply

# Register models in storage_audit.models, then inspect before deleting.
php artisan infrastructure:storage-audit
php artisan infrastructure:storage-audit --model=Product
php artisan infrastructure:storage-audit --delete
~~~

`media-rename` copies existing files to safe SEO names, updates the stored model path conditionally and leaves the old files in place. `storage-audit` identifies unreferenced paths from explicitly registered model-owned directories; deletions are opt-in and should be reviewed first. It uses database reads intentionally.

## Full public API: slugs and schema metadata

### Slug options and lookup

~~~php
protected function slugFields(): array
{
    return [
        'slug' => [
            'source' => 'name',
            'unique' => true,
            'regenerate_on_update' => false,
            'separator' => '-',
            'scope' => ['category_id'],
        ],
        'seo_slug' => [
            'source' => 'seo_title',
            'unique' => true,
            'regenerate_on_update' => true,
        ],
    ];
}
~~~

`slugFields()` enables independently configured slug columns. The legacy `slugOptions()` supports single-field `enabled`, `source`, `column`, `unique`, `regenerate_on_update`, `separator` and `scope`. Manual nonempty slugs can be preserved; database unique indexes remain the concurrency guarantee.

`SlugGenerator::generateFor(Model $model, array $options = []): ?string` is injectable for advanced workflows, and `SlugLookupRepository::matching($column, $baseSlug, $where, $ignoreId)` supports suffix collision lookup. Most applications do not need to call these directly—model save hooks run slug creation automatically.

### Shared database schema registry

~~~php
use Ak279642\LaravelInfrastructure\Database\Schema\SchemaRegistry;
use Illuminate\Support\Facades\DB;

$schema = app(SchemaRegistry::class);
$connection = DB::connection();

$isValid = $schema->has($connection, 'products', 'price');
$columns = $schema->columns($connection, 'products');
$schema->clear();                 // clear in-process registry
~~~

The package caches MySQL/MariaDB column metadata as a database-wide snapshot for **one hour**; SQLite/PostgreSQL use per-table metadata with process-local reuse. After adding/removing database columns:

~~~bash
php artisan cache:clear
php artisan optimize:clear
~~~

Long-running queue/Octane workers may also need restarting so they do not retain old in-memory registry state.

## Full public API: HTTP responses, exceptions and observability

### Three response helpers

~~~php
use Ak279642\LaravelInfrastructure\Http\Responses\ApiResponse;
use Ak279642\LaravelInfrastructure\Http\Responses\MessageResponse;
use Ak279642\LaravelInfrastructure\Http\Responses\ResourceResponse;

return ApiResponse::success(
    data: ['id' => 12],
    message: 'Created.',
    status: 201,
    meta: ['request_type' => 'catalog'],
);

return ApiResponse::error(
    message: 'Product could not be saved.',
    status: 422,
    errors: ['name' => ['Name is required.']],
    errorCode: 'PRODUCT_VALIDATION',
);

return MessageResponse::make('Deleted.', 200);
return ResourceResponse::make(ProductResource::collection($page), 'Loaded.');
~~~

`ApiResponse::success()` includes `success`, `message`, optional `data`, and optional top-level extra metadata. `ApiResponse::error()` includes `success: false`, `message`, optional `errors`, `error_code` and `data`. Debug output only appears with `app.debug` enabled. `ResourceResponse` can append `pagination` metadata for Laravel's length-aware, simple and cursor paginator resource collections.

### Exception classes and rendering

The following public exceptions live under `Ak279642\LaravelInfrastructure\Exceptions`:

| Exception class | Intended application situation |
| --- | --- |
| `UnauthorizedException` | Missing/invalid authentication |
| `AccessForbiddenException` | Insufficient authorization |
| `NotFoundException` | Missing resource |
| `RouteNotFoundException` | Missing application route |
| `HttpMethodNotAllowedException` | Unsupported HTTP method |
| `HttpRequestException` | General HTTP/request failure |
| `ValidationException` | Application validation failure |
| `ConflictException` | State conflict |
| `BusinessLogicException` | Business rule violation |
| `TooManyRequestsException` | Throttled/limited request |
| `ServiceUnavailableException` | Dependency/service unavailable |
| `InternalServerException` | Unexpected server failure |
| `FilterNotAllowedException` | Disallowed repository filter |
| `SortNotAllowedException` | Disallowed sort |
| `RelationNotAllowedException` | Disallowed eager relation |
| `RepositoryValidationConfigurationException` | Invalid repository validation setup |
| `BaseException` | Parent with status, errors, code, metadata and report/render behavior |

~~~php
use Ak279642\LaravelInfrastructure\Exceptions\BusinessLogicException;

throw new BusinessLogicException('The requested product is unavailable.');
~~~

The optional `ApiExceptionRenderer` uses Laravel's exception handler for JSON/API requests, maps known exceptions to the package response envelope, and avoids exposing sensitive internal details in production. Disable it with `responses.exception_renderer_enabled=false` if your application already owns this behavior.

### CustomLog, domains, structured context and correlation IDs

~~~php
use Ak279642\LaravelInfrastructure\Logging\CustomLog;
use Ak279642\LaravelInfrastructure\Logging\LogDomain;
use Ak279642\LaravelInfrastructure\Logging\CorrelationId;

CustomLog::debug('Cache miss.', ['key_type' => 'product'], LogDomain::APPLICATION);
CustomLog::info('Request accepted.', ['resource' => 'products'], LogDomain::API);
CustomLog::warning('Retry scheduled.', ['attempt' => 2], LogDomain::JOBS);
CustomLog::error('Sync failed.', ['operation' => 'sync'], LogDomain::ERRORS);
CustomLog::exception($exception, ['operation' => 'sync']);
$isEnabled = CustomLog::enabled(LogDomain::APPLICATION);

// Inside an HTTP request:
$id = CorrelationId::current();
$header = CorrelationId::headerName(); // defaults to X-Request-ID
~~~

Built-in `LogDomain` enum values: `APPLICATION`, `API`, `WEBHOOK`, `JOBS`, `BUSINESS` and `ERRORS`. Custom string domains and custom string-backed enums work too. `CustomLog::businessException()`, `serverException()` and `exceptionContext()` provide specialized reporting/context paths.

`CorrelationId` has `resolve()`, `current()`, `headerName()`, `set()` and `isValid()`. `RequestCorrelationId` middleware accepts valid incoming IDs if configured or creates a UUID, then adds the correlation response header. Logging recursively redacts credentials and sensitive content, limits payload size/depth, and provides daily/size-based rotation. Logging failures are contained to avoid taking down application requests.

### SecurityHeaders and RejectSensitivePaths

The package registers middleware aliases `infrastructure.security-headers` and `infrastructure.reject-sensitive-paths`, but **does not place them in your application's global stack**. Use them explicitly on routes or register them globally yourself.

`SecurityHeaders` adds `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` and `Referrer-Policy: strict-origin-when-cross-origin`, while removing common disclosure headers.

`RejectSensitivePaths` blocks common exposure probes (environment files, source/config files, traversal paths, sensitive framework directories). It returns JSON responses for API contexts and normal Laravel 404 handling for web contexts. This is complementary to authentication, Laravel policies, web-server hardening and CDN/security configuration—not a replacement.

## Full public API: maintenance, backups and configuration

### All shipped Artisan commands

~~~bash
# Audits registered Eloquent-owned storage directories:
php artisan infrastructure:storage-audit
php artisan infrastructure:storage-audit --model=Product
php artisan infrastructure:storage-audit --delete

# Migration to SEO filenames for publicly routed file fields:
php artisan infrastructure:media-rename 'App\Models\Product'
php artisan infrastructure:media-rename 'App\Models\Product' --apply

# Logical database dumps (MySQL/MariaDB and PostgreSQL):
php artisan infrastructure:database-backup
php artisan infrastructure:database-backup --connection=pgsql

# After a separate process/client commits to the listed physical tables:
php artisan infrastructure:cache:invalidate-external orders order_items
php artisan infrastructure:cache:invalidate-external orders --connection=mysql
~~~

A direct PHP entry point is also available for external CDC/webhook processors **after the external database transaction commits**:

~~~php
use Ak279642\LaravelInfrastructure\Cache\ExternalWriteInvalidator;
use Illuminate\Support\Facades\DB;

$success = app(ExternalWriteInvalidator::class)->invalidateTables(
    ['orders', 'order_items'],
    DB::connection(),
);
~~~

This uses validated physical table identifiers, not model class names. A successful external invalidation does **not** imply that changes are detected automatically across independent processes.

### Database backup configuration

~~~php
// config/laravel-infrastructure.php
'database_backup' => [
    'disk' => 'local',
    'path' => 'backups/database',
    'keep' => 3,
    'compress' => true,
    'exclude_data' => ['jobs', 'failed_jobs'],
    'mysql_dump_binary' => '',
    'pgsql_dump_binary' => '',
],
~~~

Backups use `mysqldump` or `pg_dump` (matching the configured connection). They include table definitions and optionally omit *data* for selected tables, can compress with gzip, write to a configured Laravel filesystem disk, and prune older backups. They do not support SQLite or SQL Server. Keep native dump clients installed and verify actual **restores** in your environment.

### Complete published configuration tree

The following is the package's available top-level configuration structure; values shown are representative defaults. Change only what your application needs:

~~~php
return [
    'auto_invalidation' => [
        'enabled' => false, // env LARAVEL_INFRASTRUCTURE_AUTO_INVALIDATION
    ],
    'transactions' => ['attempts' => 1],
    'files' => [
        'disk' => 'public',
        'image' => ['driver' => 'gd'],
    ],
    'assets' => [
        'enabled' => true,
        'public_disks' => ['public'],
        'disk_aliases' => [],
        'render_error_images' => true,
        'error_images' => [403 => null, 404 => null],
    ],
    'storage_audit' => ['models' => []],
    'database_backup' => [
        'disk' => 'local',
        'path' => 'backups/database',
        'keep' => 3,
        'compress' => true,
        'exclude_data' => [],
        'mysql_dump_binary' => '',
        'pgsql_dump_binary' => '',
    ],
    'responses' => [
        'exception_renderer_enabled' => true,
    ],
    'logging' => [
        'enabled' => true,
        'channel' => null,
        'exception_trace_enabled' => false,
        'log_client_exceptions' => false,
        'log_server_exceptions' => true,
        'correlation_header' => 'X-Request-ID',
        'accept_incoming_correlation_id' => true,
        'domain_enabled' => [],
    ],
];
~~~

Configuration environment overrides and their shipped defaults include:

~~~dotenv
CACHE_STORE=redis
LARAVEL_INFRASTRUCTURE_AUTO_INVALIDATION=false
LARAVEL_INFRASTRUCTURE_TRANSACTION_ATTEMPTS=1
LARAVEL_INFRASTRUCTURE_FILE_DISK=public
LARAVEL_INFRASTRUCTURE_IMAGE_DRIVER=gd
LARAVEL_INFRASTRUCTURE_ASSETS_ENABLED=true
LARAVEL_INFRASTRUCTURE_BACKUP_DISK=local
LARAVEL_INFRASTRUCTURE_BACKUP_PATH=backups/database
LARAVEL_INFRASTRUCTURE_BACKUP_KEEP=3
LARAVEL_INFRASTRUCTURE_BACKUP_COMPRESS=true
LARAVEL_INFRASTRUCTURE_MYSQLDUMP_BINARY=
LARAVEL_INFRASTRUCTURE_PG_DUMP_BINARY=
LARAVEL_INFRASTRUCTURE_EXCEPTION_RENDERER_ENABLED=true
LARAVEL_INFRASTRUCTURE_LOGGING_ENABLED=true
LARAVEL_INFRASTRUCTURE_LOG_CHANNEL=
LARAVEL_INFRASTRUCTURE_EXCEPTION_TRACE=false
~~~

Laravel supplies the actual cache stores, filesystem disks, connections, routing and authentication. Some class-level defaults (such as filename/asset response behavior, log redaction limits and schema caching) intentionally have **no package environment variable**.

### Tests, code style and compatibility

~~~bash
composer validate --strict
composer test
composer test:unit
composer test:architecture
composer lint
composer analyse
~~~

- `composer test` executes PHPUnit; tests include caching, scope invalidation, files, image processing, transaction rollback, validation, responses and security.
- `composer lint` runs Laravel Pint in check mode.
- `composer analyse` runs PHPStan/Larastan analysis.
- CI covers supported PHP versions, including PHP 8.2 and PHP 8.3, and tests tag-based behavior with Redis.
- The package is architecture-scoped: no mandatory `App` model, database migration, RBAC framework, authentication implementation or separate package cache database is shipped.


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
