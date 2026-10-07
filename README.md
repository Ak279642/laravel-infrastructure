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

LARAVEL_INFRASTRUCTURE_LOGGING_ENABLED=true
LARAVEL_INFRASTRUCTURE_LOG_CHANNEL=
LARAVEL_INFRASTRUCTURE_EXCEPTION_TRACE=false
```

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
        'avatar',

        'document_path' => [
            'disk' => 'private',
            'delete_on_replace' => true,
            'delete_on_delete' => true,
            'delete_on_soft_delete' => false,
        ],
    ];
}
```

A short string entry uses shared defaults from `fileOptions()` / package configuration. A keyed entry overrides behavior for that one attribute.

When `delete_on_replace` is true, the old path is deleted only **after the database update succeeds**. With soft deletes, files are retained by default until force-delete because `delete_on_soft_delete` defaults to false.

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

Default repository TTL:

```text
5 minutes
```

You can change cache behavior per repository call chain.

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

Paginated Laravel resource collections receive a separate `pagination` object automatically.

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

## Logging

Use `CustomLog` for application/domain-aware logging with sensitive-value sanitization.

```php
use Ak279642\LaravelInfrastructure\Logging\CustomLog;
use Ak279642\LaravelInfrastructure\Logging\LogDomain;

CustomLog::info(
    'Customer created.',
    [
        'customer_id' => $customer->id,
        'email' => $customer->email,
    ],
    LogDomain::APPLICATION,
);
```

Exceptions:

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

Sensitive keys such as passwords, tokens, API keys, secrets and authorization headers are redacted recursively.

Logging can be disabled globally or per domain through the package configuration.

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

The GitHub Actions compatibility matrix tests supported PHP/Laravel combinations and includes an architecture test that prevents accidental application `App\` dependencies.

## License

MIT. See [LICENSE](LICENSE).
