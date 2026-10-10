# Laravel Infrastructure

Reusable Laravel infrastructure for repository-driven Laravel applications.

**Requires:** PHP 8.2+ · Laravel 10–13

For image processing, enable either PHP `ext-gd` or `ext-imagick`. The package supports Intervention Image v3 and v4; PHP 8.2 resolves v3, while PHP 8.3+ projects can use v4.

## Install

```bash
composer require ak279642/laravel-infrastructure
```

Optional config:

```bash
php artisan vendor:publish --tag=laravel-infrastructure-config
```

The published config contains only package-specific options, organized by feature. Laravel's `config/cache.php` selects the cache store; repository cache TTLs belong in repositories, and image HTTP caching is automatic.

## Features

```text
Repository CRUD, filters, search, sorting, scopes
Relations, counts, sum, average
Pagination, chunk, lazy, cursor
Bulk update/delete/restore/force-delete
Repository cache + model cache switch + per-operation cache
Repository validation + automatic model reuse + named resolvers
BaseService + BaseAction + transaction retries
Multiple slugs per model
Automatic file upload + WebP/resize processing + rollback/failure cleanup
Per-file guard visibility
Signed AssetsController URLs
Storage orphan audit
Database backup
Structured logging + redaction
API response helpers + exception normalization
Security middleware + request correlation
```

# Complete example

One real flow:

```text
Product Model
    ↓
ProductRepository
    ↓
ProductService
    ↓
CreateProductAction
    ↓
StoreProductRequest
    ↓
ProductController
    ↓
ProductResource / ResourceResponse
```

## 1. Product model

```php
use Ak279642\LaravelInfrastructure\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Product extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'category_id',
        'name',
        'seo_title',
        'slug',
        'seo_slug',
        'status',
        'price',
        'image_path',
        'document_path',
    ];

    // Cache can be controlled at model level.
    protected function cacheOptions(): array
    {
        return [
            'enabled' => true,
        ];
    }

    // Slugs are model-local; there is no global slug config.
    // Multiple slug columns can use different sources.
    protected function slugFields(): array
    {
        return [
            'slug' => [
                'source' => 'name',
                'regenerate_on_update' => false, // default: false
            ],

            'seo_slug' => [
                'source' => 'seo_title',
                'regenerate_on_update' => true,
            ],
        ];
    }

    // File storage + visibility are defined on the same field.
    protected function fileAttributes(): array
    {
        return [
            'image_path' => [
                'disk' => 'public',
                // default: config files.disk -> "public"

                'directory' => 'products/images',
                // required for model-owned uploads
                // may also be shared through model fileOptions()

                'auto_upload' => true,
                // default: true

                'delete_on_replace' => true,
                // default: true

                'delete_on_delete' => true,
                // default: true

                'delete_on_soft_delete' => false,
                // default: false

                'audit' => true,
                // default: true

                // 'filename' => null,
                // default: UUID + uploaded extension
                // may also be a string/callable

                'image' => [
                    // Presence of this "image" block enables processing.

                    'driver' => 'gd',
                    // default: gd
                    // supported: gd, imagick

                    'format' => 'webp',
                    // supported: webp, original

                    'resize' => 'scale_down',
                    // supported:
                    // none, scale, scale_down,
                    // resize, resize_down,
                    // cover, cover_down

                    'width' => 720,
                    'height' => 720,

                    'quality' => 80,
                    // 0-100

                    'position' => 'center',
                    // used by cover / cover_down
                ],

                'access' => [
                    'enabled' => true,
                    // default: true

                    'signed' => false,
                    // default: assets.signed -> true

                    'guard' => null,
                    // default: null
                    // no specific guard required
                ],
            ],

            'document_path' => [
                'disk' => 'private',
                'directory' => 'products/documents',

                'access' => [
                    'enabled' => true,
                    'signed' => true,

                    // A user authenticated on either guard can access this field.
                    'guard' => ['admin', 'web'],
                ],
            ],
        ];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }
}
```

Upload:

```php
$product->image_path = $request->file('image');
$product->document_path = $request->file('document');
$product->save();
```

You can also upload from a public HTTPS URL using the same model file attributes and existing image processing, naming, disk, and cleanup settings:

```php
$product->image_path = 'https://example.com/images/product.jpg';
$product->save();
```

Or store a URL directly using `FileStorage`:

```php
use Ak279642\LaravelInfrastructure\Files\FileStorage;

$path = app(FileStorage::class)->storeFromUrl(
    'https://example.com/images/product.jpg',
    'products',
);
```

URL uploads require HTTPS and a publicly resolving hostname. Redirects and restricted IP addresses are rejected, and downloads are limited to 20 MB.


For `image_path` above the package will:

```text
read uploaded image
    -> scale down inside 720x720
    -> keep aspect ratio
    -> encode WebP at quality 80
    -> store .webp path in image_path
```

Use `cover_down` for fixed-size thumbnails:

```php
'image' => [
    'format' => 'webp',
    'resize' => 'cover_down',
    'width' => 300,
    'height' => 300,
    'quality' => 80,
],
```

Use original format but still resize:

```php
'image' => [
    'format' => 'original',
    'resize' => 'scale_down',
    'width' => 1200,
    'height' => 1200,
    'quality' => 85,
],
```

`image => true` is also supported and uses the package image defaults. Without an `image` key, the file is stored normally.

Automatic file lifecycle:

```text
save success        -> keep new file
replace success     -> delete old file after commit
DB save failure     -> delete new uploaded file
transaction rollback-> delete new uploaded file, keep old committed file
delete/force-delete -> cleanup according to file options
```

Generate asset URLs from the model field:

```php
$imageUrl = $product->fileAssetUrl('image_path');

$documentUrl = $product->fileAssetUrl(
    'document_path',
    now()->addMinutes(5),
);
```

The package now builds public links from the stored relative file path, without a model lookup, disk metadata query, or `resources` mapping. Configure a public URL alias in the application's package config:

```php
'assets' => [
    'disk_aliases' => ['media' => 'public'],
],
```

With `image_path = products/images/iphone.webp`, `$product->getFileUrl('image_path')` returns `/media/products/images/iphone.webp?v=...`. Without an alias, the URL begins with `/public/`. The public disk must be considered public: move sensitive files to a private disk.

For SEO-friendly physical names at upload time, add `'filename_from' => 'name'` or `'filename_from' => ['name', 'category.slug']` to the model's file attribute. Related models must already be loaded (or supplied with `setRelation()`) before saving. A custom `filename` callback remains supported. Conflicts receive `-2`, `-3`, etc. See [Files](docs/files.md).

Private files use an encrypted and expiring route such as `/_infrastructure/files/{token}?expires=...&signature=...`. A guard and/or model authorization hook is required. Unlike public media, a private request intentionally reloads the model to enforce current authorization. The URL hides the model, path, field and disk. **Never store a guarded file on the public disk.**

## 2. ProductRepository

```php
use Ak279642\LaravelInfrastructure\Cache\CacheTtl;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

final class ProductRepository extends BaseRepository
{
    // Repository-specific cache TTL.
    // No global 300-second TTL exists.
    protected function defaultCacheTtl(): int
    {
        return CacheTtl::MINUTES_10;
    }

    protected array $allowedFilters = [
        'id',
        'category_id',
        'status',
        'price',
        'created_at',
    ];

    protected array $allowedRelationFilters = [
        'category.slug',
    ];

    protected array $searchable = [
        'name',
        'seo_title',
        'category.name',
    ];

    protected array $allowedSorts = [
        'name',
        'price',
        'created_at',
    ];

    protected array $allowedRelations = [
        'category',
        'orders',
    ];

    protected array $allowedScopes = [
        'published',
    ];

    protected array $defaultRelations = [
        'category',
    ];

    protected array $defaultOrder = [
        'created_at' => 'desc',
    ];

    // Unknown filter/sort/relation input throws.
    protected bool $strictFilters = true;

    // Real custom method using the same repository cache layer.
    public function featured(int $limit = 10): Collection
    {
        $limit = max(1, min($limit, 100));

        return $this->cacheRemember(
            operation: 'featured',

            callback: fn () => $this->query()
                ->where('status', 'published')
                ->where('is_featured', true)
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get(),

            // Included in the cache key.
            params: [
                'limit' => $limit,
            ],
        );
    }
}
```

## 3. ProductService

Use a Service for reusable business rules.

```php
use Ak279642\LaravelInfrastructure\Services\BaseService;

final class ProductService extends BaseService
{
    public function __construct(
        ProductRepository $products,
    ) {
        parent::__construct($products);
    }

    public function createProduct(array $data): Product
    {
        /** @var Product $product */
        $product = $this->createRecord(
            $data,
            refresh: true,
            with: ['category'],
        );

        return $product;
    }

    public function updateProduct(
        Product $product,
        array $data,
    ): Product {
        /** @var Product $product */
        $product = $this->updateRecord(
            $product,
            $data,
            refresh: true,
            with: ['category'],
        );

        return $product;
    }

    public function deleteProduct(Product $product): bool
    {
        return $this->deleteRecord($product);
    }

    protected function beforeCreate(array $data): array
    {
        // Example business normalization.
        $data['name'] = trim($data['name']);

        return $data;
    }
}
```

Available hooks:

```text
beforeCreate / afterCreate
beforeUpdate / afterUpdate
beforeDelete / afterDelete
```

## 4. CreateProductAction

Use an Action when one use case contains multiple writes that must commit or roll back together.

```php
use Ak279642\LaravelInfrastructure\Actions\BaseAction;
use Ak279642\LaravelInfrastructure\Contracts\TransactionManager;

final class CreateProductAction extends BaseAction
{
    public function __construct(
        TransactionManager $transactions,
        private ProductService $products,
        private InventoryRepository $inventory,
    ) {
        parent::__construct($transactions);
    }

    public function execute(
        array $data,
        Category $category,
    ): Product {
        return $this->transactional(function () use (
            $data,
            $category,
        ): Product {
            // Write 1.
            $product = $this->products->createProduct([
                ...$data,
                'category_id' => $category->id,
            ]);

            // Write 2.
            $this->inventory->create([
                'product_id' => $product->id,
                'stock' => $data['opening_stock'] ?? 0,
            ]);

            // If write 2 fails, write 1 rolls back too.
            return $product;
        });
    }
}
```

For one independent write, call the Service/Repository directly.

Laravel's normal transaction API also remains available:

```php
DB::transaction(function (): void {
    // repository writes...
});
```

## 5. StoreProductRequest + resolver

```php
use Ak279642\LaravelInfrastructure\Http\Requests\RepositoryFormRequest;
use Ak279642\LaravelInfrastructure\Validation\RepositoryValidationRule;

final class StoreProductRequest extends RepositoryFormRequest
{
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'image_path' => ['nullable', 'file', 'image'],
        ];
    }

    protected function repositoryValidationRules(): array
    {
        return [
            new RepositoryValidationRule(
                repository: CategoryRepository::class,

                // category_id must exist.
                exists: [
                    'category_id',
                ],

                // Resolver example:
                // load Category once, store it as "category",
                // and preload its parent relation.
                resolve: [
                    [
                        'field' => 'category_id',
                        'as' => 'category',
                        'with' => ['parent'],
                    ],
                ],
            ),
        ];
    }
}
```

Use the named resolved model when you want it directly:

```php
$category = $request->resolvedModel(
    'category',
    Category::class,
);
```

Or just use the repository normally:

```php
$category = $categories->findOrFail(
    $request->validated('category_id'),
);
```

The second call automatically reuses the already-resolved Category when it matches, so a separate context lookup is not required.

Resolver entries support:

```php
[
    'field' => 'category_id', // validated input field
    'as' => 'category',       // optional alias
    'with' => ['parent'],     // optional allowed relations
]
```

## 6. ProductController

Everything is now used in one Controller.

```php
use Ak279642\LaravelInfrastructure\Http\Responses\MessageResponse;
use Ak279642\LaravelInfrastructure\Http\Responses\ResourceResponse;
use Illuminate\Http\Request;

final class ProductController
{
    public function __construct(
        // This is where $this->products comes from in all examples below.
        private ProductRepository $products,

        private ProductService $service,
    ) {}

    public function index(Request $request)
    {
        $paginator = $this->products->paginate(
            filters: [
                'status' => $request->string('status')->toString(),

                'category.slug' => $request
                    ->string('category')
                    ->toString(),

                'search' => $request
                    ->string('search')
                    ->toString(),

                'sort' => [
                    '-created_at',
                ],

                'with' => [
                    'category',
                ],
            ],
            perPage: 20,
        );

        return ResourceResponse::make(
            ProductResource::collection($paginator),
            'Products loaded.',
        );
    }

    public function show(int $id)
    {
        $product = $this->products->findOrFail(
            $id,
            ['category', 'orders'],
        );

        return ResourceResponse::make(
            new ProductResource($product),
            'Product loaded.',
        );
    }

    public function store(
        StoreProductRequest $request,
        CreateProductAction $action,
    ) {
        // Optional named resolver access.
        $category = $request->resolvedModel(
            'category',
            Category::class,
        );

        // Action returns Product, not an HTTP Resource/Response.
        $product = $action->execute(
            $request->validated(),
            $category,
        );

        return ResourceResponse::make(
            new ProductResource($product),
            'Product created.',
            201,
        );
    }

    public function update(
        UpdateProductRequest $request,
        int $id,
    ) {
        $product = $this->products->findOrFail($id);

        // One write: Service is enough.
        $product = $this->service->updateProduct(
            $product,
            $request->validated(),
        );

        return ResourceResponse::make(
            new ProductResource($product),
            'Product updated.',
        );
    }

    public function destroy(int $id)
    {
        $product = $this->products->findOrFail($id);

        $this->service->deleteProduct($product);

        return MessageResponse::make(
            'Product deleted.',
        );
    }

    public function featured()
    {
        // Custom repository method from ProductRepository.
        $products = $this->products->featured(12);

        return ResourceResponse::make(
            ProductResource::collection($products),
            'Featured products loaded.',
        );
    }
}
```

## 7. ProductResource

```php
use Illuminate\Http\Resources\Json\JsonResource;

final class ProductResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'price' => $this->price,

            // Public/no forced guard from image_path config.
            'image_url' => $this->resource
                ->fileAssetUrl('image_path'),

            // Requires the "admin" guard from document_path config.
            'document_url' => $this->resource
                ->fileAssetUrl(
                    'document_path',
                    now()->addMinutes(5),
                ),
        ];
    }
}
```

# Repository query examples

The variable below is the injected `ProductRepository`:

```php
/** @var ProductRepository $products */
$products = $this->products;
```

## Filters + search + relation + scope + sort

```php
$result = $products->get([
    'status' => 'published',

    'price' => [
        'operator' => 'between',
        'value' => [100, 5000],
    ],

    'category.slug' => 'electronics',

    'search' => 'iphone',

    'search_columns' => [
        'name',
        'category.name',
    ],

    'scopes' => [
        'published',
    ],

    'with' => [
        'category',
        'orders',
    ],

    'with_count' => [
        'orders',
    ],

    'sort' => [
        '-created_at',
        'name',
    ],
]);
```

Supported operators:

```text
= != <> > >= < <=
like ilike
in in_or_null not_in
between not_between
null not_null
```

## Aggregates

```php
$result = $products
    ->with(['category', 'orders'])
    ->withCount('orders')
    ->withSum('orders', 'total')
    ->withAvg('orders', 'total')
    ->get([
        'status' => 'published',
    ]);
```

## Bulk operations

```php
// Pagination uses the repository TTL by default.
$products->paginate(perPage: 20);
// Per-method TTL override (seconds).
$products->paginate(perPage: 20, cacheTtl: 30);
// Skip caching for one pagination call.
$products->paginate(perPage: 20, useCache: false);

$products->bulkUpdate(
    // DATA
    ['status' => 'archived'],

    // CONDITION
    ['status' => 'inactive'],
);

$products->bulkDelete([
    'status' => 'archived',
]);

$products->bulkRestore([
    'status' => 'archived',
]);

$products->bulkForceDelete([
    'status' => 'archived',
]);
```

## Large datasets

```php
$products->chunk(
    500,
    function ($chunk) use ($csvExporter): void {
        // $chunk is loaded by ProductRepository for this batch.
        foreach ($chunk as $product) {
            $csvExporter->write([
                $product->id,
                $product->name,
                $product->price,
            ]);
        }
    },
);

foreach ($products->lazy(500) as $product) {
    $searchIndexer->index($product);
}

foreach ($products->cursor() as $product) {
    $feedWriter->write($product);
}
```

# Cache usage

Use Laravel's default cache configuration (`config/cache.php`), not a package-specific cache driver. Set `CACHE_STORE=redis` in your application `.env` if desired. Repository TTLs remain configurable per repository.

Repository default:

```php
use Ak279642\LaravelInfrastructure\Cache\CacheTtl;

protected function defaultCacheTtl(): int
{
    return CacheTtl::MINUTES_10;
}
```

Model-specific:

```php
protected function cacheOptions(): array
{
    return [
        'enabled' => false, // disable repository cache only for this model
    ];
}
```

Per operation, using the injected `ProductRepository` from the Controller above:

```php
// $this->products is ProductRepository.

$fresh = $this->products
    ->withoutCache()
    ->findOrFail($id);

$cachedFor60Seconds = $this->products
    ->withCache(CacheTtl::MINUTE)
    ->findOrFail($id);

$cachedForever = $this->products
    ->rememberForever()
    ->findOrFail($id);

$this->products->clearCache();
```

Custom repository reads use protected `cacheRemember()`, as shown in `ProductRepository::featured()`.

# Public and private media

The application owns its disk aliases and security policies:

```php
// config/laravel-infrastructure.php (only override changed defaults)
'assets' => [
    'disk_aliases' => ['media' => 'public'],
],
```

```php
// Example model file attributes
'image_path' => [
    'directory' => 'products/images',
    'filename_from' => ['name', 'category.slug'], // category must be loaded
    'image' => ['format' => 'webp', 'width' => 720, 'height' => 720],
],
'document_path' => [
    'disk' => 'private',
    'directory' => 'products/documents',
    'access' => ['signed' => true, 'guard' => 'admin'],
],
```

`getFileUrl('image_path')` returns a direct public URL. `fileAssetUrl('image_path')` returns `null` for a missing stored attribute. An opt-in `FileReferenceCast` enables `$product->image_path->getFileUrl()`. Private model file links are signed, expire, and must pass guard/model-level authorization. Generic folder access and old `/uploads` routes have been removed. See [files](docs/files.md), [asset responses](docs/asset-responses.md), and [security](docs/security.md).

# Commands

Storage orphan audit:

```bash
php artisan infrastructure:storage-audit
php artisan infrastructure:storage-audit --delete
# one-time SEO filename repair (dry run, then apply)
php artisan infrastructure:media-rename 'App\Models\Product'
php artisan infrastructure:media-rename 'App\Models\Product' --apply
```

Database backup:

```bash
php artisan infrastructure:database-backup
php artisan infrastructure:database-backup --connection=mysql
```

Backup supports MySQL/MariaDB, PostgreSQL, gzip, excluded table data, Laravel filesystem disks, and retention cleanup.

# Middleware

```text
infrastructure.security-headers
infrastructure.reject-sensitive-paths
```

```php
Route::middleware([
    'infrastructure.reject-sensitive-paths',
    'infrastructure.security-headers',
])->group(function (): void {
    // Routes...
});
```

Request correlation:

```php
use Ak279642\LaravelInfrastructure\Http\Middleware\RequestCorrelationId;

Route::middleware([
    RequestCorrelationId::class,
])->group(function (): void {
    // Routes...
});
```

# Logging / exceptions

```php
enum AppLogDomain: string
{
    case IVR = 'ivr';
}

CustomLog::warning(
    'Webhook could not be matched.',
    ['operation' => 'webhook_match'],
    AppLogDomain::IVR,
);
```

Sensitive credentials/tokens/headers/cookies/secrets are redacted.

Business exception:

```php
throw new BusinessLogicException(
    'Insufficient product stock.',
);
```

# Main environment options

```dotenv
CACHE_STORE=redis # Laravel's existing config/cache.php setting

LARAVEL_INFRASTRUCTURE_TRANSACTION_ATTEMPTS=1

LARAVEL_INFRASTRUCTURE_FILE_DISK=public
LARAVEL_INFRASTRUCTURE_IMAGE_DRIVER=gd

LARAVEL_INFRASTRUCTURE_ASSETS_ENABLED=true

LARAVEL_INFRASTRUCTURE_BACKUP_DISK=local
LARAVEL_INFRASTRUCTURE_BACKUP_PATH=backups/database
LARAVEL_INFRASTRUCTURE_BACKUP_KEEP=3
LARAVEL_INFRASTRUCTURE_BACKUP_COMPRESS=true

LARAVEL_INFRASTRUCTURE_LOGGING_ENABLED=true
LARAVEL_INFRASTRUCTURE_LOG_CHANNEL=
LARAVEL_INFRASTRUCTURE_EXCEPTION_TRACE=false
LARAVEL_INFRASTRUCTURE_EXCEPTION_RENDERER_ENABLED=true
```

Other behavior uses package defaults unless configured directly on the model/repository or in the relevant config section.

# Docs

- [Repositories](docs/repositories.md)
- [Filtering](docs/filtering.md)
- [Caching](docs/caching.md)
- [Validation](docs/validation.md)
- [Transactions](docs/transactions.md)
- [Files](docs/files.md)
- [Slugs](docs/slugs.md)
- [Database backups](docs/database-backups.md)
- [Logging](docs/logging.md)
- [Responses](docs/responses.md)
- [Exceptions](docs/exceptions.md)
- [Security](docs/security.md)
- [Testing](docs/testing.md)

# Test

```bash
composer validate --strict
composer test
composer lint
composer analyse
```

# Scope

Reusable infrastructure only. No application-specific models, RBAC dependency, authentication implementation, UI, business migrations, or domain logic.

No dependency on the host application's `App` namespace.


# Full feature and API reference

> This reference describes the current `main` implementation. Public assets use the stored path, with no model ID, model query, or resource-alias registry. `filename_from`, `FileReferenceCast`, media disk aliases, and a one-time rename command are implemented; Composer installations must update to a release containing these changes.

## Feature index

| Area | Implemented capabilities | Entry point |
| --- | --- | --- |
| Models | Base Eloquent model, model-aware caching, per-model slug fields, file attributes and lifecycle | `Models\BaseModel` |
| Repositories | CRUD, safe filters, nested relation filters, search, sorting, scopes, eager loading, aggregates, pagination, streaming | `Database\Repositories\BaseRepository` |
| Repository mutations | Create/update/upsert, delete/force-delete/restore, bulk update/delete/restore/force-delete, duplicate detection | `BaseRepository`, `HasBulkCache` |
| Validation | Repository-backed existence/uniqueness, resolved models and collections, validation context reuse, named resolvers | `RepositoryFormRequest`, `RepositoryValidationService` |
| Business layers | Repository-backed services, lifecycle hooks, transactional actions, transaction manager interface | `BaseService`, `BaseAction` |
| Caching | Repository/model caching, TTLs, tags, keys, stampede locks, explicit invalidation, no-tag-store fallback | `CacheManager`, `CacheInvalidator` |
| File uploads | Per-field disks/directories, automatic uploads, custom filenames, conversions, safe replacement/deletion | `InteractsWithFiles`, `FileStorage`, `ImageProcessor` |
| File delivery | Direct public media URLs, signed private routes, guard checks, model authorization, 403/404 images | `AssetsController` |
| Maintenance | Model-owned orphan audit on public/private disks, SEO media rename, database backups | Artisan commands |
| Structured logging | Standard package domains, arbitrary application enum/string domains, contextual logs, redaction, correlation IDs | `CustomLog` |
| Log files | Optional Monolog daily and size rotation via a custom Laravel channel factory | `DomainLoggerFactory` |
| HTTP | JSON API response helpers, resources/pagination, package exception types and exception renderer | `ApiResponse`, `ResourceResponse` |
| Security | Sensitive-path rejection, security headers, correlation middleware | HTTP middleware |
| Utilities | Schema metadata registry, immutable operation context, slug lookup, cache tags, TTL constants | See source modules |

## Repository API — complete public method index

These operations are implemented on `BaseRepository` or its included concerns. Not every method has the same signature; inspect the linked [repository guide](docs/repositories.md) and code before combining named arguments.

| Category | Methods |
| --- | --- |
| Foundation | `getModel`, `query`, `clearCache`, `truncate` |
| Reads | `all`, `get`, `first`, `firstOrFail`, `find`, `findOrFail`, `findWhere`, `findWhereIn`, `findDuplicate` |
| Writes | `create`, `update`, `updateOrCreate`, `delete`, `restore`, `forceDelete` |
| Existence and aggregates | `exists`, `doesntExist`, `count`, `sum`, `avg`, `min`, `max`, `pluck`, `groupCount` |
| Pagination | `paginate`, `simplePaginate`, `cursorPaginate` |
| Memory-efficient reads | `chunk`, `lazy`, `cursor` |
| Relations | `with`, `withCount`, `withSum`, `withAvg`, `load`, `loadMissing` |
| Scopes and ordering | `scope`, `orderBy`, `orderByDesc`, `latest`, `oldest` |
| Bulk writes | `bulkUpdate`, `bulkDelete`, `bulkRestore`, `bulkForceDelete` |
| Repository caching | `cacheTtl`, `rememberForever`, `cacheTags`, `withoutCache`, `withCache`, `flushCache` |

Configure `allowedFilters`, `allowedRelationFilters`, `searchable`, `allowedSorts`, `allowedRelations`, `allowedScopes`, `defaultRelations`, `defaultOrder` and strictness in concrete repositories. Filter inputs can include nested relationship keys, operator/value objects, search columns, eager relations/counts, scopes, and sorting. The [filtering guide](docs/filtering.md) documents operator syntax. Do not send unvalidated column/relation identifiers to raw query builders.

Bulk operations currently iterate through affected models rather than issuing one mass SQL statement; this is intentional for Eloquent lifecycle behavior and cache invalidation. For very large operations, measure memory use and query counts before choosing a bulk method.

### Cache API and behavior

`CacheManager` provides `get`, `many`, `put`, `putMany`, `forever`, `putForever`, `remember`, `rememberLocked`, `rememberForever`, `pull`, `add`, `increment`, `decrement`, `has`, `missing`, `forget`, `forgetMany`, `refresh`, `lock`, `flushTags`, `flushAll`, `supportsTags` and `getStore`.

Other cache building blocks:
- `CacheKey`: deterministic key building and normalized parameters.
- `CacheTag`: model/tag helpers.
- `CacheTtl`: readable TTL constants.
- `UnorderedArray`: normalization for unordered parameters.
- `CacheInvalidator` and `CacheObserver`: invalidate dependent caches after writes.
- `Cacheable` and `InteractsWithCache`: reusable caching behavior for application classes and models.

Repository cache can be bypassed per operation using `withoutCache()`, which clones the repository to avoid leaking a bypass to subsequent callers. Laravel's configured cache store is used; support for tag-based invalidation depends on the store, with alternative behavior on non-taggable stores. A cache hit is an optimization, not a permission check or substitute for transactionally fresh reads.

### Validation and operation context

`RepositoryFormRequest` runs repository validation after ordinary Laravel rules pass, then exposes `resolved()`, `resolvedModel()`, and `resolvedCollection()`. Override `repositoryValidationRules()` to return `RepositoryValidationRule` instances; `repositoryValidationData()` can supply transformed input. A `RepositoryValidationService` and request-scoped `ValidationContext` reuse loaded models instead of repeating matching queries.

`ValidationContext` includes `put`, `get`, `getModel`, `requireModel`, `findModel`, `remember`, `models`, `forgetModel`, `findMatching`, `findManyMatching`, `getCollection`, `requireCollection`, `has`, `forget`, `clear`, `snapshot`, `restore`, and `all`.

`OperationContext` is a small immutable container with `has`, `get`, `getOrNull` and `all`; it is not an automatically persisted session or global cache. Use model context to avoid duplicate reads, while still refreshing genuinely time-sensitive eligibility/authorization data.

### Services, actions and transactions

`BaseService` wraps repository operations through protected `findRecord`, `findRecordOrFail`, `recordExists`, `createRecord`, `updateRecord` and `deleteRecord`. Hooks: `beforeCreate`, `afterCreate`, `beforeUpdate`, `afterUpdate`, `beforeDelete`, `afterDelete`. `BaseAction` and the injectable `TransactionManager` support coordinated writes and transaction retries using `LARAVEL_INFRASTRUCTURE_TRANSACTION_ATTEMPTS`. The package provider binds `LaravelTransactionManager` to that contract.

### Slugs

`InteractsWithSlug` and `SlugGenerator` support multiple distinct slug columns per model, configurable source(s), uniqueness, custom separator/options, manual slugs, and optional regeneration when source attributes change. Slug lookup uses `SlugLookupRepository`. Configure with `slugFields()`; no package-global slug mapping is required. Soft-deleted rows may continue to reserve values according to configured uniqueness behavior.

### Files: storage, processing and lifecycle

`InteractsWithFiles` reads `fileAttributes()` (per-attribute options) and optional model-wide `fileOptions()`. Supported configuration includes `disk`, **model-owned** `directory`, `auto_upload`, `filename`, `image`, `access`, `audit`, `delete_on_replace`, `delete_on_delete`, and `delete_on_soft_delete`. File paths, **not disk names**, are stored in ordinary model columns. The disk is resolved from the field configuration.

`FileStorage` supports `store`, `storeContents`, `delete`, `exists` and `url`; paths/directories/filenames are checked for unsafe segments. `ImageProcessor` can process images through Intervention Image (GD/Imagick), choose original/WebP format, resizing strategy, dimensions, position and quality. File replacement/deletion is coordinated with database transactions via `PendingFileUploads` to clean up failed saves and rollbacks.

Current methods are `getFileUrl($attributeOrPath, $seoName = null)` and `fileAssetUrl($column, $expiration = null)`. Generation uses loaded raw attributes only and does not query SQL or the filesystem. For public files, the path is resolved directly from the disk alias: `/media/products/iphone.webp?v=...`. Version derives from the path and already-loaded model timestamp; uploads never overwrite existing filenames.

When a custom `getFileUrl` SEO name is provided, it **must match the stored physical filename**. Configure names during upload using `filename_from` (string/array, supports already-loaded relations such as `brand.slug`), or `filename` (string/callable). Missing relationship data throws instead of initiating a lazy query. Collision-safe writes use cache locks; multi-node systems must configure a shared atomic-lock-capable cache store (for example, Redis).

To opt into object syntax, cast the path column to `Ak279642\LaravelInfrastructure\Files\FileReferenceCast::class`. You can then call `$product->image->getFileUrl()`, while ordinary uncased string attributes continue working unchanged.

**Media security:** `assets.disk_aliases` exposes only `assets.public_disks` (default `['public']`). Do not store protected documents there. Private assets use encrypted signed references, current model loading, configured guard checks and optional `authorizesAssetField()` ownership enforcement. Removed: `assets.resources`, `assets.legacy_uploads`, `assets.folder_access`, `assets.generic_path_patterns`, the former ID-based routes and generic disk URL endpoint. The fallback 403/404 artwork is still available.

### Storage audit — both disks

The audit groups configured attributes by **disk + model-owned directory**, then collects database references without global visibility scopes. It is a deliberate maintenance command and does use database queries to determine orphans; that is separate from the goal of query-free public media delivery.

```bash
php artisan infrastructure:storage-audit
php artisan infrastructure:storage-audit --model=Product
php artisan infrastructure:storage-audit --delete
```

It is a dry run by default. To include models in the scan, register them under `storage_audit.models`; ensure each audited field declares an owned directory and a valid disk. The command reads **raw stored values** so file-reference casts do not cause valid paths to be treated as unreferenced. Shared directories are compared against references from *all registered models* before deletion. Always review dry-run output before `--delete`, particularly after moving a private directory.

### Backups

`infrastructure:database-backup` supports an optional `--connection`, configured Laravel destination disk, backup path, retention count, compression, exclusion of data for selected tables, MySQL/MariaDB and PostgreSQL dump executables. Configure `database_backup` options or the corresponding `LARAVEL_INFRASTRUCTURE_BACKUP_*` environment keys. Verify database CLI binaries, filesystem permissions and restoration procedures in production; this command creates backups but does not itself prove recoverability.

### Structured logging and size rotation

Generic package domains in `LogDomain` are `application`, `api`, `webhook`, `jobs`, `business`, and `errors`. Application-specific domains such as IVR, Meta or Google belong in the host application's own enum or string identifiers.

The logging resolver (current `main`) selects a channel named `domain_{domain}` from the **application's** `config/logging.php`, falling back to the package's configured channel or Laravel default if no matching channel exists. For example:

```php
// config/logging.php — add a channel to the existing channels array
'domain_business' => [
    'driver' => 'custom',
    'via' => \Ak279642\LaravelInfrastructure\Logging\DomainLoggerFactory::class,
    'name' => 'business',
    'path' => storage_path('logs/domains/business.log'),
    'days' => 14,
    'level' => 'info',
],
'domain_ivr' => [
    'driver' => 'custom',
    'via' => \Ak279642\LaravelInfrastructure\Logging\DomainLoggerFactory::class,
    'name' => 'ivr',
    'path' => storage_path('logs/domains/ivr.log'),
    'days' => 14,
    'level' => 'info',
],
```

`DomainLoggerFactory` constructs `DailySizeRotatingFileHandler`; its default maximum file size is **100 MiB** and retention defaults to **14 days** unless overridden in configuration. This custom channel rotates by both day and size; Laravel's built-in `daily` channel only rotates by day. Custom channels must be explicitly declared to use the size-rotating handler. Check process permissions and log collection in multi-instance deployments.

`CustomLog` provides `debug`, `info`, `warning`, `error`, `exception`, `businessException`, `serverException`, `enabled`, and `exceptionContext`. Its structured context includes environment, host, optional request ID/method/URL/route/query-key names/IP/user information, and domain. `LogContextRedactor` reduces accidental exposure of sensitive context. `CorrelationId` supports per-request correlation via the `X-Request-ID` header. Do not log raw credentials or assume redaction covers arbitrary secret-shaped strings.

### HTTP response, exception and security inventory

`ApiResponse::success()` and `ApiResponse::error()` create consistent JSON responses; `MessageResponse::make()` is a message-only helper, and `ResourceResponse::make()` accepts resources, collections and pagination. `ApiExceptionRenderer` normalizes API errors and can be toggled with `LARAVEL_INFRASTRUCTURE_EXCEPTION_RENDERER_ENABLED`.

Provided exception classes: `BaseException`, `AccessForbiddenException`, `BusinessLogicException`, `ConflictException`, `FilterNotAllowedException`, `HttpMethodNotAllowedException`, `HttpRequestException`, `InternalServerException`, `NotFoundException`, `RelationNotAllowedException`, `RepositoryValidationConfigurationException`, `RouteNotFoundException`, `ServiceUnavailableException`, `SortNotAllowedException`, `TooManyRequestsException`, `UnauthorizedException`, and `ValidationException`.

Middleware and security helpers:
- `RejectSensitivePaths`: blocks attempts to access sensitive files/paths.
- `SecurityHeaders`: adds defensive HTTP response headers.
- `RequestCorrelationId`: sets or propagates request correlation metadata.
- Service provider: registers dependencies, Artisan commands, route middleware aliases, asset routes, exception reporting/rendering hooks, and config publishing.

### Configuration checklist

| Config section | Controls |
| --- | --- |
| `transactions` | Transaction retry attempts |
| `files` | Default disk and image driver |
| `assets` | Routing prefix/enabled flag, registered model aliases, allowed disks, access rules, fallback images, optional legacy routes |
| `storage_audit` | Registered models; optional chunk size |
| `database_backup` | Destination, path, retention, compression, exclusions and dump executables |
| `responses` | Exception renderer toggle |
| `logging` | Enabled flag, fallback channel, trace control, exception reporting and correlation settings |

The **authoritative** defaults are in [config/laravel-infrastructure.php](config/laravel-infrastructure.php); override only values your project changes. Host `config/logging.php`, `config/filesystems.php`, `config/cache.php`, guards and application model policies are still owned by your Laravel project.

### Testing and maintenance

The repository contains PHPUnit unit, feature, and architecture tests covering cache keys and concurrency, repositories, query/filter security, validation, file lifecycle, image processing, asset routing/ACLs, transaction behavior, backups, response/exception behavior, and service-provider bindings.

```bash
composer validate --strict
composer test
composer test:unit
composer test:architecture
composer lint
composer analyse
```

Run these commands on a full clone with Composer dependencies installed. Documentation changes alone do not prove these runtime test suites pass on your deployed PHP/Laravel/database combinations.

## Why use this package?

It centralizes allowlisted repository queries, reusable cache and transaction patterns, safe file processing, slug generation, API formatting, repository-backed validation, and maintenance tasks. This reduces repeated boilerplate between projects, encourages consistent validation and authorization boundaries, and gives teams one implementation to test and improve. It does **not** eliminate the need for indexes, application-level permissions, domain-specific business rules, SQL profiling, or integration tests.

# License

MIT.

## Asset 403/404 images and caching

Missing files use the bundled 404 WebP and denied requests use the bundled 403 WebP. The `render_error_images` setting preserves the image-friendly HTTP 200 response with `X-Asset-Error-Status`; set it false for strict 403/404 HTTP codes. Public files have 24-hour caching and no SQL lookup. Private files use `private, no-store` and require authorization.

## SEO-friendly model asset URLs and cache versions

The stored relative path is the public URL's source of truth. Use `filename_from` on uploads, for example `['name', 'brand.slug', 'category.slug']`, which produces `products/iphone-16-pro-apple-smartphones.webp`. With `'disk_aliases' => ['media' => 'public']`, the URL is `/media/products/iphone-16-pro-apple-smartphones.webp?v=...`.

A file's `?v=` value changes when the stored path or loaded model `updated_at` changes; it is **not** a separate content hash. Filename collisions use numeric suffixes. For existing UUID files, run `infrastructure:media-rename` (dry-run first). The command copies before changing the database record and deliberately leaves original files for auditing, so shared references are not accidentally deleted.
