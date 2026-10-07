# Laravel Infrastructure

Reusable Laravel infrastructure for repository-driven Laravel applications.

**Requires:** PHP 8.2+ · Laravel 10–13

## Install

```bash
composer require ak279642/laravel-infrastructure
```

Optional config:

```bash
php artisan vendor:publish --tag=laravel-infrastructure-config
```

## Features

```text
Repository CRUD + filters + search + sort + scopes
Relations + counts + sum + avg
Pagination + chunk + lazy + cursor
Bulk update/delete/restore/force-delete
Repository cache + model cache toggle + per-query cache controls
Repository validation + automatic resolved-model reuse
BaseService + BaseAction + transaction retries
Multiple slugs per model
Automatic file upload + rollback/failure cleanup
Per-file guard/role/permission visibility
Signed AssetsController URLs
Storage orphan audit
Database backup
Structured logging + redaction
API response helpers + exception normalization
Security middleware + request correlation ID
```

# Complete example

This example shows the normal package flow once:

```text
Model
  ↓
Repository
  ↓
Service
  ↓
Action when a transaction is needed
  ↓
Controller
  ↓
ResourceResponse
```

## 1. Model

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

    // Repository cache for this model.
    protected function cacheOptions(): array
    {
        return [
            'enabled' => true, // default: true
        ];
    }

    // Multiple independent slug columns.
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

    // UploadedFile fields + access rules live together.
    protected function fileAttributes(): array
    {
        return [
            'image_path' => [
                'disk' => 'public',
                // default: config files.disk -> "public"

                'directory' => 'products/images',
                // default: config files.directory -> "uploads"

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
                // default: generated UUID + uploaded extension
                // may also be a string/callable

                'access' => [
                    'enabled' => true,
                    // default: true

                    'signed' => false,
                    // default: assets.signed -> true
                    // false means normal route URL is allowed

                    'guard' => null,
                    // default: null -> current request user/no forced guard

                    'roles' => [],
                    // default: []
                    // ANY listed role may pass

                    'permissions' => [],
                    // default: []
                    // ALL listed permissions must pass

                    'ability' => null,
                    // default: null
                    // optional Laravel Gate ability
                ],
            ],

            'document_path' => [
                'disk' => 'private',
                'directory' => 'products/documents',

                // Only authenticated admin-guard users with an allowed role
                // and documents.view permission can access this field.
                'access' => [
                    'enabled' => true,
                    'signed' => true,
                    'guard' => 'admin',
                    'roles' => [
                        'admin',
                        'manager',
                    ],
                    'permissions' => [
                        'documents.view',
                    ],
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

Upload normally:

```php
$product->image_path = $request->file('image');
$product->document_path = $request->file('document');
$product->save();
```

File lifecycle is automatic:

```text
save succeeds       -> keep new file; old replaced file deleted after commit
save fails          -> remove newly uploaded file
transaction rollback-> remove newly uploaded file; keep previous committed file
delete/force-delete -> delete file according to field options
```

Generate the correct asset URL from the model field:

```php
$imageUrl = $product->fileAssetUrl('image_path');

$documentUrl = $product->fileAssetUrl(
    'document_path',
    now()->addMinutes(5),
);
```

`fileAssetUrl()` includes model + record + field identity. `AssetsController` verifies that the requested path is still the value stored in that field before serving it, then applies that field's `access` rules.

## 2. Repository

```php
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

final class ProductRepository extends BaseRepository
{
    // Direct model filters accepted from callers.
    protected array $allowedFilters = [
        'id',
        'category_id',
        'status',
        'price',
        'created_at',
    ];

    // Explicit dotted relation filters.
    protected array $allowedRelationFilters = [
        'category.slug',
    ];

    // Search model + relation columns.
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

    // Unknown filters/sorts/relations throw instead of being ignored.
    protected bool $strictFilters = true;

    // Example custom repository method with the same package cache behavior.
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

            // Params are part of the deterministic cache key.
            params: [
                'limit' => $limit,
            ],
        );
    }
}
```

## 3. Service

Use a Service for reusable business rules. It does not need to own the transaction.

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

    // BaseService hook: normalize data before create.
    protected function beforeCreate(array $data): array
    {
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

## 4. Action

Use an Action when one use case performs multiple writes that must commit/rollback together.

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

    public function execute(array $data): Product
    {
        return $this->transactional(function () use ($data): Product {
            // Write 1
            $product = $this->products->createProduct($data);

            // Write 2
            $this->inventory->create([
                'product_id' => $product->id,
                'stock' => $data['opening_stock'] ?? 0,
            ]);

            // If write 2 throws, write 1 is rolled back too.
            return $product;
        });
    }
}
```

For a simple one-record write, call the Service/Repository directly. Use an Action transaction only when the whole operation must be atomic.

You can also use Laravel directly:

```php
DB::transaction(function (): void {
    // repository writes...
});
```

## 5. Request validation

Repository validation runs after normal Laravel rules and can preload models.

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

                // Resolve it once so later repository find/findOrFail can reuse it.
                resolve: [
                    [
                        'field' => 'category_id',
                    ],
                ],
            ),
        ];
    }
}
```

Normal repository lookups reuse matching resolved models automatically. No separate `ValidationContext` call is required.

## 6. Controller

One controller shows where each layer belongs.

```php
use Ak279642\LaravelInfrastructure\Http\Responses\MessageResponse;
use Ak279642\LaravelInfrastructure\Http\Responses\ResourceResponse;
use Illuminate\Http\Request;

final class ProductController
{
    public function __construct(
        private ProductRepository $products,
        private ProductService $service,
    ) {}

    public function index(Request $request)
    {
        $paginator = $this->products->paginate(
            // Request may contain only allow-listed filters/search/sort/etc.
            filters: $request->all(),
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
        // Action returns the Product model, not an HTTP response.
        $product = $action->execute(
            $request->validated(),
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

        // Single write: Service is enough; no Action required.
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
}
```

## 7. Resource with file URLs

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

            // Uses image_path access rules from Product::fileAttributes().
            'image_url' => $this->resource
                ->fileAssetUrl('image_path'),

            // Uses admin guard/role/permission rules from document_path.
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

## Filter + search + relation + sort

```php
$products = $products->get([
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

## Pagination with filters

```php
$paginator = $products->paginate(
    filters: [
        'status' => 'published',
        'category.slug' => 'electronics',
        'search' => 'iphone',
        'sort' => ['-created_at'],
        'with' => ['category'],
    ],
    perPage: 20,
);
```

## Relations / aggregates

```php
$products = $products
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
        // $chunk is the Collection loaded by this repository batch.
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

# Cache controls

Global:

```dotenv
LARAVEL_INFRASTRUCTURE_CACHE_ENABLED=true
LARAVEL_INFRASTRUCTURE_CACHE_STORE=redis
LARAVEL_INFRASTRUCTURE_CACHE_TTL=300
```

Disable one model:

```php
protected function cacheOptions(): array
{
    return [
        'enabled' => false,
    ];
}
```

Per operation:

```php
$fresh = $products
    ->withoutCache()
    ->findOrFail($id);

$cached = $products
    ->withCache(60)
    ->findOrFail($id);

$forever = $products
    ->rememberForever()
    ->findOrFail($id);

$products->clearCache();
```

Custom repository methods should use protected `cacheRemember()` as shown in `featured()`.

# Asset access

Normal case: define access directly inside the model file field.

```php
'document_path' => [
    'disk' => 'private',
    'directory' => 'products/documents',

    'access' => [
        'signed' => true,
        'guard' => 'admin',
        'roles' => ['admin', 'manager'],
        'permissions' => ['documents.view'],
    ],
],
```

Rules:

```text
enabled=false   -> 404
signed=true     -> signed URL required
guard=admin     -> authenticate with admin guard
roles=[...]     -> ANY listed role may pass
permissions=[...] -> ALL listed permissions must pass
ability=...     -> optional Laravel Gate ability
```

Role detection supports `hasAnyRole()` / `hasRole()` (including Spatie Permission) and a simple `role` attribute.

Permissions use Laravel Gate.

Global disk/folder rules remain available as fallback for generic assets that are not generated from a model field:

```php
'assets' => [
    'allowed_disks' => [
        'public',
        'private',
    ],

    'folder_access' => [
        'private' => [
            '*' => [
                'enabled' => false,
            ],

            'shared/manuals' => [
                'enabled' => true,
                'signed' => true,
                'guard' => 'web',
                'roles' => ['staff'],
            ],
        ],
    ],
],
```

Model field `access` overrides matching folder fallback rules.

# Commands

Storage orphan audit:

```bash
php artisan infrastructure:storage-audit
php artisan infrastructure:storage-audit --delete
```

Database backup:

```bash
php artisan infrastructure:database-backup
php artisan infrastructure:database-backup --connection=mysql
```

Backup supports MySQL/MariaDB, PostgreSQL, gzip compression, excluded table data, Laravel filesystem disks, and retention cleanup.

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
CustomLog::info(
    'Product created.',
    ['product_id' => $product->id],
    LogDomain::APPLICATION,
);
```

Sensitive passwords, tokens, authorization headers, cookies, API keys, secrets, sessions, signatures, and nested sensitive values are redacted.

Throw a package business exception:

```php
throw new BusinessLogicException(
    'Insufficient product stock.',
);
```

JSON exception normalization is enabled by default:

```dotenv
LARAVEL_INFRASTRUCTURE_EXCEPTION_RENDERER_ENABLED=true
```

# Main environment options

```dotenv
# Cache
LARAVEL_INFRASTRUCTURE_CACHE_ENABLED=true
LARAVEL_INFRASTRUCTURE_CACHE_STORE=redis
LARAVEL_INFRASTRUCTURE_CACHE_TTL=300
LARAVEL_INFRASTRUCTURE_CACHE_LOCK_SECONDS=10
LARAVEL_INFRASTRUCTURE_CACHE_LOCK_WAIT_SECONDS=3

# Action transaction retries
LARAVEL_INFRASTRUCTURE_TRANSACTION_ATTEMPTS=1

# Asset URLs
LARAVEL_INFRASTRUCTURE_ASSETS_ENABLED=true
LARAVEL_INFRASTRUCTURE_ASSETS_PREFIX=infrastructure/assets
LARAVEL_INFRASTRUCTURE_ASSETS_SIGNED=true
LARAVEL_INFRASTRUCTURE_ASSETS_URL_TTL=15

# Database backup
LARAVEL_INFRASTRUCTURE_BACKUP_DISK=local
LARAVEL_INFRASTRUCTURE_BACKUP_PATH=backups/database
LARAVEL_INFRASTRUCTURE_BACKUP_KEEP=3
LARAVEL_INFRASTRUCTURE_BACKUP_COMPRESS=true

# Logging / exceptions
LARAVEL_INFRASTRUCTURE_LOGGING_ENABLED=true
LARAVEL_INFRASTRUCTURE_EXCEPTION_RENDERER_ENABLED=true
LARAVEL_INFRASTRUCTURE_EXCEPTION_TRACE=false

# Request correlation
LARAVEL_INFRASTRUCTURE_CORRELATION_HEADER=X-Request-ID
LARAVEL_INFRASTRUCTURE_ACCEPT_CORRELATION_ID=true
```

# Documentation

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

Reusable infrastructure only. No application-specific models, RBAC package dependency, authentication implementation, UI, business migrations, or domain logic.

No dependency on the host application's `App\` namespace.

# License

MIT.
