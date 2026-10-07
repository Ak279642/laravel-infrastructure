# Laravel Infrastructure

Reusable Laravel infrastructure for repository-driven applications.

## Install

```bash
composer require ak279642/laravel-infrastructure
```

**Requires:** PHP 8.2+ · Laravel 10–13

Optional config:

```bash
php artisan vendor:publish --tag=laravel-infrastructure-config
```

## Features at a glance

```text
Repository CRUD + bulk operations
Safe filters + relation filters
Search + sorting + scopes
Relations + counts + sum + avg
Pagination
Repository cache + locks + invalidation
ValidationContext model reuse
Transactions + BaseAction + BaseService
Multi-field slugs
Automatic model file uploads
File rollback/failure cleanup
Storage orphan audit
Database backup
Structured secure logging
API responses + exception normalization
Security middleware
Request correlation
```

## Full example

### Model: slugs + files + scope + relations

```php
use Ak279642\LaravelInfrastructure\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;

final class Product extends BaseModel
{
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

    protected function fileAttributes(): array
    {
        return [
            'image_path' => [
                'disk' => 'public',
                'directory' => 'products/images',
                'auto_upload' => true,
                'delete_on_replace' => true,
                'delete_on_delete' => true,
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

Upload usage:

```php
$product->image_path = $request->file('image');
$product->document_path = $request->file('document');
$product->save();
```

File lifecycle:

```text
success  -> new file kept, replaced old file deleted after commit
failure  -> newly uploaded file deleted
rollback -> newly uploaded file deleted, previous committed file kept
delete   -> configured file deleted
```

### Repository: define allowed capabilities

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

    protected bool $strictFilters = true;
}
```

### One GET usage showing the query features

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

        // Explicit relation filter
        'category.slug' => 'electronics',

        // Search
        'search' => 'iphone pro',

        // Restrict search to selected allowed fields
        'search_columns' => [
            'name',
            'category.name',
        ],

        // Allowed local model scopes
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

        // Sort: "-" means DESC
        'sort' => [
            '-created_at',
            'name',
        ],
    ]);
```

This one flow uses:

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

## Main repository methods

```php
$repo->all();
$repo->get($filters);
$repo->first($filters);
$repo->firstOrFail($filters);

$repo->find($id);
$repo->findOrFail($id);

$repo->create($data);
$repo->update($id, $data);
$repo->updateOrCreate($attributes, $values);

$repo->delete($id);
$repo->forceDelete($id);
$repo->restore($id);

$repo->exists($filters);
$repo->doesntExist($filters);
$repo->count($filters);

$repo->sum('price', $filters);
$repo->avg('price', $filters);
$repo->min('price', $filters);
$repo->max('price', $filters);

$repo->pluck('name', 'id', $filters);
$repo->groupCount('status', $filters);

$repo->paginate($filters, perPage: 20);

$repo->bulkUpdate($data, $filters);
$repo->bulkDelete($filters);
$repo->bulkRestore($filters);
$repo->bulkForceDelete($filters);
```

## ValidationContext

Validate and resolve once:

```php
new RepositoryValidationRule(
    repository: ProductRepository::class,
    exists: ['product_id'],
    resolve: [
        [
            'field' => 'product_id',
            'as' => 'product',
            'with' => ['category'],
        ],
    ],
);
```

Reuse later:

```php
$product = $validationContext->requireModel(
    'product',
    Product::class,
);
```

Normal repository lookups automatically reuse a matching resolved model when available.

## Transaction / Action

```php
final class CreateProductAction extends BaseAction
{
    public function execute(array $data): Product
    {
        return $this->transactional(
            fn () => $this->service->create($data),
        );
    }
}
```

## Commands

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

Backup supports MySQL/MariaDB, PostgreSQL, gzip, excluded table data, Laravel disks, and retention cleanup.

## Security middleware

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

## Cache config

```dotenv
LARAVEL_INFRASTRUCTURE_CACHE_ENABLED=true
LARAVEL_INFRASTRUCTURE_CACHE_STORE=redis
LARAVEL_INFRASTRUCTURE_CACHE_TTL=300
LARAVEL_INFRASTRUCTURE_CACHE_LOCK_SECONDS=10
LARAVEL_INFRASTRUCTURE_CACHE_LOCK_WAIT_SECONDS=3
```

## Logging

```php
CustomLog::info(
    'Product created.',
    ['product_id' => $product->id],
    LogDomain::APPLICATION,
);
```

Sensitive passwords, tokens, headers, cookies, API keys, secrets, and nested sensitive values are redacted.

## API responses

```php
return MessageResponse::make('Product deleted.');

return ResourceResponse::make(
    resource: new ProductResource($product),
    message: 'Product loaded.',
);
```

## Docs

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

## Test

```bash
composer validate --strict
composer test
composer lint
composer analyse
```

## Scope

Reusable infrastructure only. No application-specific models, RBAC, authentication, UI, routes, business migrations, or domain logic.

No dependency on the host application's `App\` namespace.

## License

MIT.
