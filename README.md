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

Use Laravel's configured cache store (Redis recommended for tag invalidation), e.g. `CACHE_STORE=redis`. Repositories cache reads with configurable TTLs, scoped keys and automatic invalidation after writes; `withoutCache()` bypasses one read. See [Caching](docs/caching.md) for full examples.

User/tenant ownership and composite dimensions are **opt-in**:

```php
protected function cacheOptions(): array
{
    return ['scopes' => [
        'tenant_id' => 'auth.tenant_id',
        'user_id' => 'auth.id',
    ]];
}
```

For shared/global visibility, add a `visibility_resolver` that appends explicitly authorized `orWhere` conditions. Those caches use model-wide invalidation to avoid stale public results. Unconfigured models retain existing behavior; see [Caching](docs/caching.md).

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


# Feature reference

For the full API, examples, configuration and edge cases, use the focused guides above. The package provides Eloquent repositories (CRUD, filters, relations, search, aggregates, pagination and streaming), model validation, service/action layers, transactions, cache management, file/image uploads (including HTTPS URLs), public/private media, slugs, storage audits, database backups, logging, API responses and security middleware.

See [Repositories](docs/repositories.md), [Caching](docs/caching.md), [Files](docs/files.md), and the other topic guides for implementation details. No application-specific authentication, authorization or business models are bundled.
