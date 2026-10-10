# Laravel Infrastructure

Reusable Laravel infrastructure for repository-driven applications: Eloquent repositories, cache-aware visibility, validation, services/actions, files, slugs, logging, responses and maintenance commands.

**Requirements:** PHP 8.2+ · Laravel 10–13 · Intervention Image 3/4 (image processing requires PHP `ext-gd` or `ext-imagick`).

## Installation

```bash
composer require ak279642/laravel-infrastructure
php artisan vendor:publish --tag=laravel-infrastructure-config # optional
```

Laravel auto-discovers the service provider. Published options live in `config/laravel-infrastructure.php`. **Use Laravel's existing `CACHE_STORE`** (Redis is recommended for tag-based invalidation); no separate package cache store is configured.

## Quick start: model and repository

```php
use Ak279642\LaravelInfrastructure\Models\BaseModel;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Builder;

class Product extends BaseModel
{
    protected $fillable = ['category_id', 'name', 'slug', 'status', 'price', 'image_path'];

    protected function slugFields(): array
    {
        return ['slug' => ['source' => 'name']];
    }

    protected function fileAttributes(): array
    {
        return ['image_path' => [
            'directory' => 'products',
            'image' => ['format' => 'webp', 'width' => 720, 'height' => 720],
        ]];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }
}

class ProductRepository extends BaseRepository
{
    protected array $allowedFilters = ['category_id', 'status', 'price'];
    protected array $allowedRelationFilters = ['category.slug'];
    protected array $searchable = ['name', 'category.name'];
    protected array $allowedSorts = ['price', 'created_at'];
    protected array $allowedRelations = ['category', 'orders'];
    protected array $allowedScopes = ['published'];
    protected array $defaultOrder = ['created_at' => 'desc'];
    protected bool $strictFilters = true;
}
```

Your application defines the `category()` and `orders()` relationships and its own migrations. Inject `ProductRepository` into a controller or service:

```php
$products->findOrFail($id, ['category']);
$products->create(['name' => 'Phone', 'status' => 'draft', 'price' => 500]);
$products->update($id, ['status' => 'published']);
$products->delete($id);

$page = $products->paginate(
    filters: ['status' => 'published', 'sort' => ['-created_at']],
    perPage: 20,
);
```

See the [complete Product walkthrough](docs/complete-example.md) for the service, transaction action, validated request, controller and API resource wired together.

## Queries and bulk operations

Repository input is allow-listed; unknown input can throw when `strictFilters` is enabled. Filters, relation filters, search, local scopes, sorting, eager loading and counts work together:

```php
$rows = $products->get([
    'status' => 'published',
    'price' => ['operator' => 'between', 'value' => [100, 1000]],
    'category.slug' => 'electronics',
    'search' => 'phone',
    'scopes' => ['published'],
    'with' => ['category'],
    'with_count' => ['orders'],
    'sort' => ['-created_at', 'price'],
]);

$products->count(['status' => 'published']);
$products->sum('price');
$products->groupCount('status');
$products->withCount('orders')->get();
$products->withSum('orders', 'total')->withAvg('orders', 'total')->get();

$products->bulkUpdate(['status' => 'archived'], ['status' => 'draft']);
$products->bulkDelete(['status' => 'archived']);
$products->bulkRestore(['status' => 'archived']);     // SoftDeletes models
$products->bulkForceDelete(['status' => 'archived']);
```

Supported filter operators: `=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`, `like`, `ilike`, `in`, `in_or_null`, `not_in`, `between`, `not_between`, `null` and `not_null`.

Other helpers include `findWhere()`, `findWhereIn()`, `findDuplicate()`, `updateOrCreate()`, `exists()`, `first()` and `firstOrFail()`.

For large datasets use `chunk(500, $callback)`, `lazy(500)` or `cursor()`. `paginate()` is cached by default; `simplePaginate()` and `cursorPaginate()` keep their uncached behavior. See [Repositories](docs/repositories.md) and [Filtering](docs/filtering.md).

### Query-builder and local-scope filters

Apply a reusable query restriction without subclassing a repository:

```php
$open = $products->filterQuery(fn (Builder $q) => $q->where('status', 'published'));
$open->paginate(perPage: 20);
```

`filterQuery()` returns a repository clone, so the original remains unchanged. Alternatively override `protected function globalQueryFilter(Builder $query): Builder` in your repository, or use an allow-listed `$products->scope('published')`. Effective SQL and bindings contribute to cache keys. Custom reads can use `cacheRemember()` with a unique operation name and all relevant parameters:

```php
// Inside a repository method:
return $this->cacheRemember(
    'featured',
    fn () => $this->query()->where('status', 'published')->limit($limit)->get(),
    ['limit' => $limit],
);
```

See [Caching](docs/caching.md) for complete key, tag and dependency behavior.

## Scope-aware caching and visibility

Repository caching uses Laravel's tag-capable cache store, scoped keys and automatic **after-commit invalidation** for cache-aware Eloquent models. The default repository TTL is five minutes; override `defaultCacheTtl()` on a repository.

```php
$products->withCache(600)->findOrFail($id);    // override TTL
$products->cacheTtl(60)->findOrFail($id);       // seconds
$products->withoutCache()->findOrFail($id);
$products->rememberForever()->findOrFail($id);
$products->paginate(perPage: 20, cacheTtl: 30);
$products->paginate(perPage: 20, useCache: false);
$products->clearCache();
```

Disable caching on one model with `cacheOptions(): ['enabled' => false]`.

**Single owner or tenant:** configure only the column and guard/attribute when needed.

```php
protected function cacheOptions(): array
{
    return ['scope' => ['column' => 'tenant_id', 'guard' => 'web', 'attribute' => 'tenant_id']];
}
```

**Creator / assignee / assigner:** a short column list resolves each value to the authenticated user's ID; `or` means any owner may see the record.

```php
protected function cacheOptions(): array
{
    return [
        'scopes' => ['user_id', 'assigned_to', 'assigned_by'],
        'scope_operator' => 'or', // default: and
    ];
}
```

**Tenant AND one of several owners:** express the tenant boundary separately. The package groups the OR conditions so they cannot bypass the tenant restriction.

```php
protected function cacheOptions(): array
{
    return [
        'scope' => ['tenant_id' => 'auth.tenant_id'],
        'visibility' => ['any' => ['user_id', 'assigned_to', 'assigned_by']],
    ];
}
```

**Existing Eloquent global scope:** Laravel's scope already affects cache identity; no duplicate visibility query is needed. To enable targeted tenant invalidation, optionally declare **only a cache-partition marker**:

```php
protected function cacheOptions(): array
{
    return ['scope' => 'tenant_id']; // assumes the existing global scope enforces tenant_id
}
```

This marker adds **no SQL restriction**; do not use it unless the existing global scope guarantees that partition. Known ownership dimensions invalidate affected old/new owner and tenant groups after writes. Unknown global visibility, custom or unbounded policies safely fall back to model-wide invalidation.

**Complex policies:** set `'visibility_resolver' => TaskVisibility::class` in `cacheOptions()`; class resolvers can optionally supply `cacheReadTags()` and `cacheInvalidationTags()` for precise invalidation. Existing `actor_resolver`, named guards, legacy ownership declarations and AND/OR configurations remain supported. See [Caching](docs/caching.md) for resolver signatures, scope safety and limitations.

> Cache isolation does not replace authorization. Use Eloquent model writes for automatic invalidation; raw SQL writes and independent membership/permission changes require explicit invalidation. Cache stores without tags bypass repository read caching for correctness.

## Validation, services and transactions

`RepositoryFormRequest` performs Laravel validation first, then optional repository-backed existence/uniqueness checks. A named resolver loads a validated model once for reuse:

```php
use Ak279642\LaravelInfrastructure\Http\Requests\RepositoryFormRequest;
use Ak279642\LaravelInfrastructure\Validation\RepositoryValidationRule;

class StoreProductRequest extends RepositoryFormRequest
{
    public function rules(): array
    {
        return ['category_id' => ['required', 'integer'], 'name' => ['required', 'string']];
    }

    protected function repositoryValidationRules(): array
    {
        return [new RepositoryValidationRule(
            repository: CategoryRepository::class,
            exists: ['category_id'],
            resolve: [['field' => 'category_id', 'as' => 'category']],
        )];
    }
}

$category = $request->resolvedModel('category', Category::class);
```

`BaseService` provides `createRecord()`, `updateRecord()` and `deleteRecord()` with before/after hooks; `BaseAction` wraps multi-write use cases in the injected `TransactionManager`:

```php
use Ak279642\LaravelInfrastructure\Actions\BaseAction;
use Ak279642\LaravelInfrastructure\Contracts\TransactionManager;

class CreateProductAction extends BaseAction
{
    public function __construct(
        TransactionManager $transactions,
        private ProductRepository $products,
        private InventoryRepository $inventory,
    ) {
        parent::__construct($transactions);
    }

    public function execute(array $data): Product
    {
        return $this->transactional(function () use ($data) {
            $product = $this->products->create($data);
            $this->inventory->create(['product_id' => $product->getKey()]);
            return $product;
        });
    }
}
```

Actions own transaction boundaries; ordinary single-record operations need no extra action. Retry attempts use `LARAVEL_INFRASTRUCTURE_TRANSACTION_ATTEMPTS` (default `1`). See [Validation](docs/validation.md), [Transactions](docs/transactions.md) and the [complete example](docs/complete-example.md).

## Model slugs, files and media

`BaseModel` supports multiple independent slug fields via `slugFields()` (including `source`, `unique` and `regenerate_on_update`), and backwards-compatible `slugOptions()`.

`fileAttributes()` declares uploads on existing model columns. For example:

```php
protected function fileAttributes(): array
{
    return [
        'image_path' => [
            'directory' => 'products',
            'filename_from' => 'name',
            'image' => ['format' => 'webp', 'resize' => 'scale_down', 'width' => 720, 'height' => 720],
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
$product->image_path = $request->file('image'); // or a public HTTPS image URL
$product->save();

$publicUrl = $product->getFileUrl('image_path');
$privateUrl = $product->fileAssetUrl('invoice_path', now()->addMinutes(5));
```

Public URLs can use `'assets' => ['disk_aliases' => ['media' => 'public']]` in package config. `FileStorage::storeFromUrl()` supports direct HTTPS imports; `FileReferenceCast` is an optional field-object interface. File cleanup handles replacements, deletes, errors and transaction rollbacks. Private media uses signed, guarded/model-authorized URLs—**never store sensitive files on a public disk**. See [Files](docs/files.md), [Slugs](docs/slugs.md) and [Asset responses](docs/asset-responses.md).

## HTTP responses, logging and security

```php
use Ak279642\LaravelInfrastructure\Http\Responses\MessageResponse;
use Ak279642\LaravelInfrastructure\Http\Responses\ResourceResponse;

return ResourceResponse::make(ProductResource::collection($page), 'Products loaded.');
// Or: return MessageResponse::make('Deleted.');
```

`ResourceResponse` includes pagination metadata for resource collections. The optional API exception renderer normalizes JSON requests while preserving normal Laravel web rendering.

```php
use Ak279642\LaravelInfrastructure\Logging\CustomLog;
use Ak279642\LaravelInfrastructure\Exceptions\BusinessLogicException;

CustomLog::warning('Webhook failed.', ['operation' => 'webhook'], 'integrations');
throw new BusinessLogicException('Insufficient stock.');
```

Logs support domains, redaction, correlation IDs and rotation. Opt in to HTTP security middleware:

```php
use Ak279642\LaravelInfrastructure\Http\Middleware\RequestCorrelationId;

Route::middleware([
    'infrastructure.reject-sensitive-paths',
    'infrastructure.security-headers',
    RequestCorrelationId::class,
])->group(function () {
    // Application routes
});
```

See [Responses](docs/responses.md), [Exceptions](docs/exceptions.md), [Logging](docs/logging.md) and [Security](docs/security.md).

## Maintenance and configuration

```bash
php artisan infrastructure:storage-audit             # audit model-owned files
php artisan infrastructure:storage-audit --delete    # remove orphans
php artisan infrastructure:media-rename 'App\Models\Product'
php artisan infrastructure:media-rename 'App\Models\Product' --apply

php artisan infrastructure:database-backup
php artisan infrastructure:database-backup --connection=mysql
```

Storage audit models and database backup settings are configured in `config/laravel-infrastructure.php`. Backups support MySQL/MariaDB and PostgreSQL via their native dump tools, optional gzip, filesystem disks and retention; SQLite backups are not supported.

Common environment variables (other options are documented with each feature):

```dotenv
CACHE_STORE=redis
LARAVEL_INFRASTRUCTURE_TRANSACTION_ATTEMPTS=1
LARAVEL_INFRASTRUCTURE_FILE_DISK=public
LARAVEL_INFRASTRUCTURE_IMAGE_DRIVER=gd
LARAVEL_INFRASTRUCTURE_BACKUP_DISK=local
LARAVEL_INFRASTRUCTURE_LOGGING_ENABLED=true
```

See [Database backups](docs/database-backups.md) and [Files](docs/files.md).

## Guides and quality checks

| Area | Detailed guide |
| --- | --- |
| End-to-end model, service, action, request, controller | [Complete example](docs/complete-example.md) |
| Repository queries, filters, and pagination | [Repositories](docs/repositories.md) · [Filtering](docs/filtering.md) |
| Scoped caching and visibility resolvers | [Caching](docs/caching.md) |
| Model validation and transactions | [Validation](docs/validation.md) · [Transactions](docs/transactions.md) |
| Slugs and uploads | [Slugs](docs/slugs.md) · [Files](docs/files.md) · [Asset responses](docs/asset-responses.md) |
| API, middleware, logging | [Responses](docs/responses.md) · [Exceptions](docs/exceptions.md) · [Security](docs/security.md) · [Logging](docs/logging.md) |
| Maintenance and architecture | [Database backups](docs/database-backups.md) · [Architecture](docs/architecture.md) · [Testing](docs/testing.md) |

```bash
composer validate --strict
composer test
composer lint
composer analyse
```

**Scope:** infrastructure only—no application business models, migrations, authentication implementation, RBAC framework or dependency on the host application's `App` namespace.
