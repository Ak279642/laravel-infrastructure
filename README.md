# Laravel Infrastructure

Reusable Laravel infrastructure for repository-driven applications.

**Requires:** PHP 8.2+ · Laravel 10–13

## Install

```bash
composer require ak279642/laravel-infrastructure
```

Optional config:

```bash
php artisan vendor:publish --tag=laravel-infrastructure-config
```

## What you get

| Feature | Usage |
| --- | --- |
| Repository | CRUD, pagination, aggregates, chunk/lazy/cursor, bulk operations |
| Filters | Allow-listed normal + relation filters |
| Search | Search model and relation fields |
| Sorting | Allow-listed sorting |
| Scopes | Allow-listed Eloquent local scopes |
| Relations | Eager load, load missing, count, sum, average |
| Cache | Global on/off, per-query bypass, TTL, forever, tags, locks, invalidation |
| Validation | Repository-backed exists/unique/exists-in validation |
| Validation reuse | `find()` / `findOrFail()` automatically reuse resolved models |
| Transactions | Direct controller use, `BaseAction`, retry support |
| Services | Optional `BaseService` hooks/helpers |
| Slugs | One or multiple model slug fields |
| Files | Automatic upload, replace/delete lifecycle, rollback cleanup |
| Storage audit | Find/delete orphan files |
| Database backup | MySQL/MariaDB/PostgreSQL backup + gzip + retention |
| Logging | Structured logs + sensitive-data redaction |
| API responses | Stable success/resource response helpers |
| Exceptions | JSON exception normalization |
| Middleware | Security headers, sensitive-path protection, request correlation |

---

# 1. Model setup

Extend `BaseModel` to get cache invalidation, slug support, and automatic file handling.

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

    // Model-level repository cache.
    // Default: true. Set false to disable cache only for Product.
    protected function cacheOptions(): array
    {
        return [
            'enabled' => true,
        ];
    }

    // Multiple slug columns.
    protected function slugFields(): array
    {
        return [
            'slug' => [
                'source' => 'name',
                'regenerate_on_update' => true,
            ],

            'seo_slug' => [
                'source' => 'seo_title',
                'regenerate_on_update' => true,
            ],
        ];
    }

    // Automatic UploadedFile handling.
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
                // default: true when a model-owned directory is configured

                // 'filename' => null,
                // default: null -> generated UUID + uploaded extension
                // may also be a string or callable
            ],

            'document_path' => [
                'disk' => 'private',
                'directory' => 'products/documents',

                // All omitted options use the defaults above.
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

### Upload a file

No separate storage service is required for normal model uploads.

```php
$product->image_path = $request->file('image');
$product->document_path = $request->file('document');

$product->save();
```

Lifecycle:

```text
save success
    -> keep new file
    -> delete replaced old file after DB commit

save failure
    -> delete newly uploaded file

transaction rollback
    -> delete newly uploaded file
    -> keep previously committed file

delete / force-delete
    -> delete configured files according to model options
```

---

# 2. Repository setup

Define what callers are allowed to filter, search, sort, load, and execute.

```php
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;

final class ProductRepository extends BaseRepository
{
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

    // Invalid filters/sorts/relations throw instead of being ignored.
    protected bool $strictFilters = true;
}
```

---

# 3. Use repository directly in a controller

For simple CRUD, inject the repository directly. Controllers should return the package response helpers instead of returning resources/models directly.

```php
use Ak279642\LaravelInfrastructure\Http\Responses\MessageResponse;
use Ak279642\LaravelInfrastructure\Http\Responses\ResourceResponse;
use Illuminate\Http\Request;

final class ProductController
{
    public function __construct(
        private ProductRepository $products,
    ) {}

    public function index(Request $request)
    {
        $paginator = $this->products->paginate(
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

    public function store(StoreProductRequest $request)
    {
        $product = $this->products->create(
            $request->validated(),
            refresh: true,
            with: ['category'],
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
        $product = $this->products->update(
            $id,
            $request->validated(),
            refresh: true,
            with: ['category'],
        );

        return ResourceResponse::make(
            new ProductResource($product),
            'Product updated.',
        );
    }

    public function destroy(int $id)
    {
        $this->products->delete($id);

        return MessageResponse::make(
            'Product deleted.',
        );
    }
}
```

# 4. One GET usage showing query features

```php
$products = $products
    ->withSum('orders', 'total')
    ->withAvg('orders', 'total')
    ->get([
        // Normal filter
        'status' => 'published',

        // Operator filter
        'price' => [
            'operator' => 'between',
            'value' => [100, 5000],
        ],

        // Relation filter
        'category.slug' => 'electronics',

        // Search
        'search' => 'iphone pro',

        // Optional subset of searchable fields
        'search_columns' => [
            'name',
            'category.name',
        ],

        // Allowed Eloquent scopes
        'scopes' => [
            'published',
        ],

        // Eager loading
        'with' => [
            'category',
            'orders',
        ],

        // Relation counts
        'with_count' => [
            'orders',
        ],

        // "-" means DESC
        'sort' => [
            '-created_at',
            'name',
        ],
    ]);
```

That one call uses:

```text
filter
operator filter
relation filter
search
search_columns
scope
with
with_count
withSum
withAvg
sort
cache
ValidationContext reuse
```

Supported filter operators:

```text
= != <> > >= < <=
like ilike
in in_or_null not_in
between not_between
null not_null
```

---

# 5. Repository usage

### Read / aggregate

```php
$filters = [
    'status' => 'published',

    'price' => [
        'operator' => 'between',
        'value' => [100, 5000],
    ],

    'category.slug' => 'electronics',

    'search' => 'iphone',

    'sort' => [
        '-created_at',
    ],

    'with' => [
        'category',
    ],
];

$products = $repo->get($filters);

$product = $repo->findOrFail(
    $id,
    ['category', 'orders'],
);

$first = $repo->first($filters);

$exists = $repo->exists($filters);

$total = $repo->count($filters);
$priceTotal = $repo->sum('price', $filters);
$averagePrice = $repo->avg('price', $filters);
$lowestPrice = $repo->min('price', $filters);
$highestPrice = $repo->max('price', $filters);

$names = $repo->pluck(
    'name',
    'id',
    $filters,
);

$byStatus = $repo->groupCount(
    'status',
    $filters,
);
```

### Pagination with filters

```php
$paginator = $products->paginate(
    filters: [
        'status' => 'published',

        'category.slug' => 'electronics',

        'price' => [
            'operator' => 'between',
            'value' => [100, 5000],
        ],

        'search' => 'iphone',

        'with' => [
            'category',
        ],

        'sort' => [
            '-created_at',
        ],
    ],
    perPage: 20,
);

return ResourceResponse::make(
    ProductResource::collection($paginator),
    'Products loaded.',
);
```

### Large dataset processing

```php
// Export products in batches.
// $products is the Collection fetched by the repository for each chunk.
$repo->chunk(
    500,
    function ($products) use ($csvExporter): void {
        foreach ($products as $product) {
            $csvExporter->write([
                $product->id,
                $product->name,
                $product->price,
            ]);
        }
    },
);

foreach ($repo->lazy(500) as $product) {
    $searchIndexer->index($product);
}

foreach ($repo->cursor() as $product) {
    $feedWriter->write($product);
}
```

### Create / update / delete

```php
$product = $repo->create($data);

$product = $repo->update(
    $id,
    $data,
    refresh: true,
    with: ['category'],
);

$product = $repo->updateOrCreate(
    ['sku' => $sku],
    $data,
);

$repo->delete($id);
$repo->restore($id);
$repo->forceDelete($id);
```

### Bulk operations

```php
$repo->bulkUpdate(
    // DATA to update
    ['status' => 'archived'],

    // CONDITION
    ['status' => 'inactive'],
);

$repo->bulkDelete([
    // CONDITION
    'status' => 'archived',
]);

$repo->bulkRestore([
    // CONDITION
    'status' => 'archived',
]);

$repo->bulkForceDelete([
    // CONDITION
    'status' => 'archived',
]);
```

### Relations / aggregates

```php
$products = $repo
    ->with(['category', 'orders'])
    ->withCount('orders')
    ->withSum('orders', 'total')
    ->withAvg('orders', 'total')
    ->get([
        'status' => 'published',
    ]);
```

### Sort + scope real use case

```php
$featured = $products
    ->scope('published')
    ->orderByDesc('created_at')
    ->get([
        'category_id' => $categoryId,
    ]);

$alphabetical = $products
    ->scope('published')
    ->orderBy('name')
    ->get();

$latest = $products
    ->latest('created_at')
    ->first();

$oldest = $products
    ->oldest('created_at')
    ->first();
```

`scope('published')` calls the allow-listed model `scopePublished()`. Sort helpers only accept allow-listed sort columns.

# 6. Transactions

Use Laravel directly when you want a transaction in a Controller:

```php
use Illuminate\Support\Facades\DB;

$order = DB::transaction(function () use ($data) {
    $product = $this->products->findOrFail(
        $data['product_id'],
    );

    $order = $this->orders->create([
        'product_id' => $product->id,
        'quantity' => $data['quantity'],
    ]);

    $this->products->update(
        $product,
        [
            'stock' => $product->stock
                - $data['quantity'],
        ],
    );

    return $order;
});
```

If any write throws, Laravel rolls back the whole callback.

The package `TransactionManager::run()` is optional and mainly useful when you want an injectable transaction abstraction or retry attempts:

```php
$order = $transactions->run(
    fn () => $service->createOrder($data),
    attempts: 3,
);
```

# 7. Use BaseAction for larger transaction flows

For larger business operations, keep the transaction boundary in an Action.

```php
use Ak279642\LaravelInfrastructure\Actions\BaseAction;
use Ak279642\LaravelInfrastructure\Contracts\TransactionManager;

final class CreateOrderAction extends BaseAction
{
    public function __construct(
        TransactionManager $transactions,
        private CreateOrderService $service,
    ) {
        parent::__construct($transactions);
    }

    public function execute(array $data): Order
    {
        return $this->transactional(
            fn () => $this->service->create($data),
        );
    }
}
```

The Controller converts that model to the API response:

```php
public function store(
    StoreOrderRequest $request,
    CreateOrderAction $action,
) {
    $order = $action->execute(
        $request->validated(),
    );

    return ResourceResponse::make(
        new OrderResource($order),
        'Order created.',
        201,
    );
}
```

---

# 8. BaseService

`BaseService` is optional. Use it when business rules should sit between controllers/actions and repositories.

```php
use Ak279642\LaravelInfrastructure\Services\BaseService;

final class ProductService extends BaseService
{
    public function __construct(
        ProductRepository $products,
    ) {
        parent::__construct($products);
    }

    public function create(array $data): Product
    {
        return $this->createRecord(
            $data,
            refresh: true,
            with: ['category'],
        );
    }

    protected function beforeCreate(array $data): array
    {
        $data['name'] = trim($data['name']);

        return $data;
    }
}
```

Available hooks:

```text
beforeCreate
afterCreate
beforeUpdate
afterUpdate
beforeDelete
afterDelete
```

---

# 9. Custom repository methods + cache

Add domain-specific query methods in your repository when the query is reused or deserves a clear name.

```php
use Illuminate\Database\Eloquent\Collection;

final class ProductRepository extends BaseRepository
{
    // ...allow-lists...

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
            params: [
                'limit' => $limit,
            ],
        );
    }
}
```

Usage:

```php
$featured = $products->featured(12);

return ResourceResponse::make(
    ProductResource::collection($featured),
    'Featured products loaded.',
);
```

`cacheRemember()` gives custom methods the same repository cache TTL, locks, tags, and invalidation behavior.

Use `withoutCache()` when that read must always be fresh:

```php
$fresh = $products
    ->withoutCache()
    ->get(['status' => 'published']);
```

---

# 10. Repository validation + automatic model reuse

Use `RepositoryFormRequest` when request validation also needs repository existence/uniqueness checks.

```php
use Ak279642\LaravelInfrastructure\Http\Requests\RepositoryFormRequest;
use Ak279642\LaravelInfrastructure\Validation\RepositoryValidationRule;

final class StoreOrderRequest extends RepositoryFormRequest
{
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    protected function repositoryValidationRules(): array
    {
        return [
            new RepositoryValidationRule(
                repository: ProductRepository::class,

                exists: [
                    'product_id',
                ],

                resolve: [
                    [
                        'field' => 'product_id',
                        'with' => ['category'],
                    ],
                ],
            ),
        ];
    }
}
```

Then use the repository normally:

```php
$product = $products->findOrFail(
    $request->validated('product_id'),
    ['category'],
);
```

Automatic behavior:

```text
already resolved
    -> find/findOrFail reuses same model
    -> only missing relations are loaded

not resolved
    -> repository queries DB
    -> model is remembered automatically
    -> later lookups reuse it
```

No separate `ValidationContext` call is required for normal use.

Named aliases are optional for cases like billing/shipping addresses:

```php
'resolve' => [
    [
        'field' => 'billing_address_id',
        'as' => 'billing_address',
    ],
]
```

Repository validation supports:

```text
exists
unique
unique with ignore ID
scoped where conditions
existsIn for arrays of IDs
resolve model
resolve collection
load allowed relations while resolving
```

---

# 11. Cache

## Global enable / disable

Enable:

```dotenv
LARAVEL_INFRASTRUCTURE_CACHE_ENABLED=true
LARAVEL_INFRASTRUCTURE_CACHE_STORE=redis
LARAVEL_INFRASTRUCTURE_CACHE_TTL=300
LARAVEL_INFRASTRUCTURE_CACHE_LOCK_SECONDS=10
LARAVEL_INFRASTRUCTURE_CACHE_LOCK_WAIT_SECONDS=3
```

Disable completely:

```dotenv
LARAVEL_INFRASTRUCTURE_CACHE_ENABLED=false
```

## Enable / disable cache for one model

Caching is enabled for models by default.

```php
protected function cacheOptions(): array
{
    return [
        'enabled' => true, // default
    ];
}
```

Disable repository caching only for this model:

```php
protected function cacheOptions(): array
{
    return [
        'enabled' => false,
    ];
}
```

Priority:

```text
global cache false -> cache disabled everywhere
global cache true + model false -> disabled for that model
global cache true + model true -> normal repository caching
```

## Bypass cache for one operation

```php
$product = $products
    ->withoutCache()
    ->findOrFail($id);
```

## Explicitly enable cache

```php
$product = $products
    ->withCache()
    ->findOrFail($id);
```

## Custom TTL

```php
$products = $repository
    ->cacheTtl(60)
    ->get($filters);
```

or:

```php
$product = $repository
    ->withCache(60)
    ->findOrFail($id);
```

## Cache forever

```php
$product = $repository
    ->rememberForever()
    ->findOrFail($id);
```

## Extra cache tags

```php
$products = $repository
    ->cacheTags([
        'tenant:10',
        'catalog',
    ])
    ->get($filters);
```

## Manual clear

```php
$repository->clearCache();
```

---

# 12. Slugs

Multiple fields:

```php
protected function slugFields(): array
{
    return [
        'slug' => [
            'source' => 'name',
        ],

        'seo_slug' => [
            'source' => 'seo_title',
            'regenerate_on_update' => true,
        ],
    ];
}
```

Single-slug/backward-compatible defaults can be configured with `slugOptions()`.

A manually supplied non-empty slug is preserved.

---

# 13. Automatic files

Configure file fields:

```php
protected function fileAttributes(): array
{
    return [
        'avatar_path' => [
            'disk' => 'public',              // default: "public"
            'directory' => 'users/avatars', // default: "uploads"
            'auto_upload' => true,           // default: true
            'delete_on_replace' => true,     // default: true
            'delete_on_delete' => true,      // default: true
            'delete_on_soft_delete' => false,// default: false
            'audit' => true,                 // default: true
            // 'filename' => null,            // default: UUID filename
        ],
    ];
}
```

Use:

```php
$user->avatar_path = $request->file('avatar');

$user->save();
```

---

## Access uploaded files with the package AssetsController

The package registers:

```text
GET /infrastructure/assets/{disk}/{path}

route name:
laravel-infrastructure.assets.show
```

Access control is **disk + folder based**.

Example:

```php
'assets' => [
    'enabled' => true,
    'prefix' => 'infrastructure/assets',

    // First boundary: only these disks are reachable.
    'allowed_disks' => [
        'public',
        'private',
    ],

    // Fallback when a folder rule does not define "signed".
    'signed' => true,

    'folder_access' => [
        'public' => [
            // Default for every folder on public disk.
            '*' => [
                'enabled' => true,
                'signed' => true,
                'guard' => null,
                'roles' => [],
                'permissions' => [],
                'ability' => null,
            ],

            // public/products/images/*
            // Anyone with a signed link may view.
            'products/images' => [
                'signed' => true,
            ],

            // public/downloads/*
            // Public URL, no login and no signature.
            'downloads' => [
                'signed' => false,
            ],
        ],

        'private' => [
            // Deny everything on private disk unless a folder overrides it.
            '*' => [
                'enabled' => false,
            ],

            // private/products/invoices/*
            // Only users from the "admin" guard with admin/account roles.
            'products/invoices' => [
                'enabled' => true,
                'signed' => true,
                'guard' => 'admin',
                'roles' => [
                    'admin',
                    'accounts',
                ],
                'permissions' => [
                    'invoices.view',
                ],
            ],

            // private/hr/contracts/*
            // Different folder, different guard/roles.
            'hr/contracts' => [
                'enabled' => true,
                'signed' => true,
                'guard' => 'web',
                'roles' => [
                    'hr',
                    'admin',
                ],
            ],
        ],
    ],
],
```

Rules inherit from least specific to most specific:

```text
private/*
    ↓
private/products
    ↓
private/products/invoices
```

So a folder can override only what it needs.

### Folder rule options

```php
[
    'enabled' => true,       // false = return 404 for this folder
    'signed' => true,        // require temporary/permanent signed URL
    'guard' => 'admin',      // Laravel auth guard; null = current request user
    'roles' => ['admin'],    // ANY listed role may pass
    'permissions' => [       // ALL listed permissions must pass
        'invoices.view',
    ],
    'ability' => null,       // optional Laravel Gate ability
]
```

### Role support

If your user model provides `hasAnyRole()` or `hasRole()` (for example Spatie Permission), the package uses it automatically.

A simple `role` model attribute is also supported.

### Permission / policy support

Permissions are checked through Laravel Gate, so this works with normal Gates/Policies and permission packages exposing abilities through `can()`.

For custom authorization:

```php
'products/contracts' => [
    'guard' => 'admin',
    'ability' => 'view-product-contracts',
],
```

```php
Gate::define(
    'view-product-contracts',
    function ($user, string $disk, string $path): bool {
        return $user->is_super_admin;
    },
);
```

### Generate the file URL

```php
use Illuminate\Support\Facades\URL;

$url = URL::temporarySignedRoute(
    'laravel-infrastructure.assets.show',
    now()->addMinutes(15),
    [
        'disk' => 'private',
        'path' => $product->invoice_path,
    ],
);
```

Example Resource:

```php
return [
    'id' => $this->id,
    'name' => $this->name,

    'invoice_url' => $this->invoice_path
        ? URL::temporarySignedRoute(
            'laravel-infrastructure.assets.show',
            now()->addMinutes(5),
            [
                'disk' => 'private',
                'path' => $this->invoice_path,
            ],
        )
        : null,
];
```

Request flow:

```text
disk allow-list
    ↓
most-specific folder rule
    ↓
enabled?
    ↓
signed URL?
    ↓
configured guard authenticated?
    ↓
role check
    ↓
permission check
    ↓
optional Gate ability
    ↓
safe path + file exists
    ↓
AssetsController streams file
```

Disable the built-in route:

```php
'assets' => [
    'enabled' => false,
],
```

---

# 14. Storage orphan audit

Preview only:

```bash
php artisan infrastructure:storage-audit
```

Delete confirmed orphan files:

```bash
php artisan infrastructure:storage-audit --delete
```

Only directories explicitly owned by configured models are scanned.

---

# 15. Database backup

Default connection:

```bash
php artisan infrastructure:database-backup
```

Specific connection:

```bash
php artisan infrastructure:database-backup --connection=mysql
```

Supports:

```text
MySQL / MariaDB -> mysqldump
PostgreSQL      -> pg_dump
gzip compression
schema-only excluded tables
Laravel filesystem disks
retention cleanup
custom dump binary path
```

Main config:

```php
'database_backup' => [
    'disk' => 'local',
    'path' => 'backups/database',
    'keep' => 3,
    'compress' => true,
    'exclude_data' => [],
    'mysql_dump_binary' => '',
    'pgsql_dump_binary' => '',
],
```

---

# 16. Security middleware

Aliases:

```text
infrastructure.security-headers
infrastructure.reject-sensitive-paths
```

Usage:

```php
Route::middleware([
    'infrastructure.reject-sensitive-paths',
    'infrastructure.security-headers',
])->group(function (): void {
    // Routes...
});
```

`SecurityHeaders` removes common disclosure headers and adds safe defaults.

`RejectSensitivePaths` blocks common source/config/environment/traversal probes.

---

# 17. Request correlation ID

Add the middleware by class:

```php
use Ak279642\LaravelInfrastructure\Http\Middleware\RequestCorrelationId;

Route::middleware([
    RequestCorrelationId::class,
])->group(function (): void {
    // API routes...
});
```

Default header:

```text
X-Request-ID
```

Configure:

```dotenv
LARAVEL_INFRASTRUCTURE_CORRELATION_HEADER=X-Request-ID
LARAVEL_INFRASTRUCTURE_ACCEPT_CORRELATION_ID=true
```

The same request ID is available to package response/logging infrastructure.

---

# 18. Logging

```php
use Ak279642\LaravelInfrastructure\Logging\CustomLog;
use Ak279642\LaravelInfrastructure\Logging\LogDomain;

CustomLog::info(
    'Product created.',
    [
        'product_id' => $product->id,
    ],
    LogDomain::APPLICATION,
);
```

Exception:

```php
try {
    $service->run();
} catch (Throwable $exception) {
    CustomLog::exception(
        $exception,
        [
            'operation' => 'product_sync',
        ],
    );

    throw $exception;
}
```

Sensitive values such as passwords, tokens, authorization headers, cookies, API keys, secrets, private keys, sessions, and signatures are redacted.

---

# 19. API responses

Message:

```php
return MessageResponse::make(
    'Product deleted.',
);
```

Resource:

```php
return ResourceResponse::make(
    resource: new ProductResource($product),
    message: 'Product loaded.',
);
```

Paginated Laravel resource collections include pagination metadata automatically.

---

# 20. Exception normalization

Enabled by default:

```dotenv
LARAVEL_INFRASTRUCTURE_EXCEPTION_RENDERER_ENABLED=true
```

Disable:

```dotenv
LARAVEL_INFRASTRUCTURE_EXCEPTION_RENDERER_ENABLED=false
```

JSON requests can be normalized for common Laravel/Symfony errors including:

```text
401 authentication
403 authorization
404 not found
405 method not allowed
409 conflict
422 validation
429 rate limit
500 server error
503 service unavailable
```

Normal HTML/web exception rendering remains controlled by the host application.

---

## Throw package exceptions from business code

```php
use Ak279642\LaravelInfrastructure\Exceptions\BusinessLogicException;

if ($product->stock < $quantity) {
    throw new BusinessLogicException(
        'Insufficient product stock.',
    );
}
```

For JSON/API requests the package renderer converts supported exceptions to the standard error envelope.

# 21. Main environment config

```dotenv
# Repository cache
LARAVEL_INFRASTRUCTURE_CACHE_ENABLED=true
LARAVEL_INFRASTRUCTURE_CACHE_STORE=redis
LARAVEL_INFRASTRUCTURE_CACHE_TTL=300
LARAVEL_INFRASTRUCTURE_CACHE_LOCK_SECONDS=10
LARAVEL_INFRASTRUCTURE_CACHE_LOCK_WAIT_SECONDS=3

# Transaction retry attempts used by BaseAction
LARAVEL_INFRASTRUCTURE_TRANSACTION_ATTEMPTS=1

# Database backup
LARAVEL_INFRASTRUCTURE_BACKUP_DISK=local
LARAVEL_INFRASTRUCTURE_BACKUP_PATH=backups/database
LARAVEL_INFRASTRUCTURE_BACKUP_KEEP=3
LARAVEL_INFRASTRUCTURE_BACKUP_COMPRESS=true

# Exceptions
LARAVEL_INFRASTRUCTURE_EXCEPTION_RENDERER_ENABLED=true

# Logging
LARAVEL_INFRASTRUCTURE_LOGGING_ENABLED=true
LARAVEL_INFRASTRUCTURE_LOG_CHANNEL=
LARAVEL_INFRASTRUCTURE_EXCEPTION_TRACE=false
LARAVEL_INFRASTRUCTURE_LOG_CLIENT_EXCEPTIONS=false
LARAVEL_INFRASTRUCTURE_LOG_SERVER_EXCEPTIONS=true

# Correlation IDs
LARAVEL_INFRASTRUCTURE_CORRELATION_HEADER=X-Request-ID
LARAVEL_INFRASTRUCTURE_ACCEPT_CORRELATION_ID=true
```

Full config file:

```text
config/laravel-infrastructure.php
```

---

# 22. Which layer should I use?

```text
Need simple CRUD?
    -> Controller + Repository

Need 2-3 repository writes in one transaction?
    -> Controller + TransactionManager + Repositories

Need reusable business rules?
    -> Service + Repository

Need a larger state-changing use case?
    -> Controller + Action + Service + Repositories
       Action owns the transaction

Need request exists/unique validation?
    -> RepositoryFormRequest + RepositoryValidationRule

Need the same validated model later?
    -> Just call repository find/findOrFail
       Context reuse is automatic
```

---

# Documentation

- [Architecture](docs/architecture.md)
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

# Package scope

Reusable infrastructure only.

No application-specific models, RBAC, authentication, UI, application routes, business migrations, or domain logic.

No dependency on the host application's `App\` namespace.

# License

MIT.
