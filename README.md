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
                'directory' => 'products/images',
                'auto_upload' => true,
                'delete_on_replace' => true,
                'delete_on_delete' => true,
                'delete_on_soft_delete' => false,
            ],

            'document_path' => [
                'disk' => 'private',
                'directory' => 'products/documents',
                'auto_upload' => true,
                'delete_on_replace' => true,
                'delete_on_delete' => true,
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

You do not need a Service or Action for simple CRUD.

```php
use Illuminate\Http\Request;

final class ProductController
{
    public function __construct(
        private ProductRepository $products,
    ) {}

    public function index(Request $request)
    {
        return ProductResource::collection(
            $this->products->paginate(
                filters: $request->all(),
                perPage: 20,
            ),
        );
    }

    public function show(int $id)
    {
        return new ProductResource(
            $this->products->findOrFail(
                $id,
                ['category', 'orders'],
            ),
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

        return MessageResponse::make('Product deleted.');
    }
}
```

---

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

# 5. Main repository methods

### Reads

```php
$repo->all();

$repo->get($filters);
$repo->first($filters);
$repo->firstOrFail($filters);

$repo->find($id);
$repo->find($id, ['category']);
$repo->findOrFail($id, ['category']);

$repo->exists($filters);
$repo->doesntExist($filters);

$repo->count($filters);
$repo->sum('price', $filters);
$repo->avg('price', $filters);
$repo->min('price', $filters);
$repo->max('price', $filters);

$repo->pluck('name', 'id', $filters);
$repo->groupCount('status', $filters);
```

### Pagination / large datasets

```php
$repo->paginate(
    filters: $filters,
    perPage: 20,
);

$repo->simplePaginate(perPage: 20);

$repo->cursorPaginate(perPage: 20);

$repo->chunk(500, function ($products): void {
    // process chunk
});

foreach ($repo->lazy(500) as $product) {
    // low-memory processing
}

foreach ($repo->cursor() as $product) {
    // cursor processing
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
$repo->forceDelete($id);
$repo->restore($id);
```

### Bulk operations

```php
$repo->bulkUpdate(
    ['status' => 'archived'],
    ['status' => 'inactive'],
);

$repo->bulkDelete([
    'status' => 'archived',
]);

$repo->bulkRestore([
    'status' => 'archived',
]);

$repo->bulkForceDelete([
    'status' => 'archived',
]);
```

### Relation helpers

```php
$repo
    ->with(['category', 'orders'])
    ->withCount('orders')
    ->withSum('orders', 'total')
    ->withAvg('orders', 'total');

$repo->load($product, ['category']);

$repo->loadMissing(
    $product,
    ['category', 'orders'],
);
```

### Sorting helpers

```php
$repo->orderBy('name');

$repo->orderByDesc('created_at');

$repo->latest('created_at');

$repo->oldest('created_at');
```

### Scope helper

```php
$repo->scope('published')->get();
```

---

# 6. Transactions directly in a controller

Inject `TransactionManager` and use repositories inside the transaction.

```php
use Ak279642\LaravelInfrastructure\Contracts\TransactionManager;

final class OrderController
{
    public function __construct(
        private TransactionManager $transactions,
        private OrderRepository $orders,
        private ProductRepository $products,
    ) {}

    public function store(StoreOrderRequest $request)
    {
        $data = $request->validated();

        $order = $this->transactions->run(function () use ($data) {
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

        return ResourceResponse::make(
            new OrderResource($order),
            'Order created.',
            201,
        );
    }
}
```

Retry deadlocks when needed:

```php
$result = $transactions->run(
    callback: fn () => $service->process(),
    attempts: 3,
);
```

Use this direct controller style when the operation is small.

---

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

Controller:

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

Use:

```text
simple CRUD                  -> Controller + Repository
small multi-write operation  -> Controller + TransactionManager + Repositories
larger business operation    -> Controller + Action + Service + Repositories
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

# 9. Repository validation + automatic model reuse

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

# 10. Cache

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

Cache behavior also includes:

```text
automatic write invalidation
lock-based stampede protection
tag-aware invalidation
safe fallback on non-taggable stores
no caching of reads inside open DB transactions
```

---

# 11. Slugs

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

# 12. Automatic files

Configure file fields:

```php
protected function fileAttributes(): array
{
    return [
        'avatar_path' => [
            'disk' => 'public',
            'directory' => 'users/avatars',
            'auto_upload' => true,
            'delete_on_replace' => true,
            'delete_on_delete' => true,
            'delete_on_soft_delete' => false,
        ],
    ];
}
```

Use:

```php
$user->avatar_path = $request->file('avatar');

$user->save();
```

The package handles storage path assignment, replacement cleanup, failed-save cleanup, transaction rollback cleanup, delete cleanup, and force-delete cleanup.

---

# 13. Storage orphan audit

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

# 14. Database backup

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

# 15. Security middleware

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

# 16. Request correlation ID

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

# 17. Logging

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

# 18. API responses

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

# 19. Exception normalization

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

# 20. Main environment config

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

# 21. Which layer should I use?

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
