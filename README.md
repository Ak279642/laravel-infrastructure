# Laravel Infrastructure

Production-oriented Laravel infrastructure for repository-driven applications.

## Requirements

- PHP 8.2+
- Laravel 10, 11, 12, or 13

## Install

```bash
composer require ak279642/laravel-infrastructure
```

Optional config:

```bash
php artisan vendor:publish --tag=laravel-infrastructure-config
```

## Features

| Feature | Included |
| --- | --- |
| Repositories | CRUD, pagination, aggregates, bulk operations |
| Filtering | Allow-listed filters and relation filters |
| Search / sorting | Controlled searchable and sortable fields |
| Relations | Eager loading, counts, sums, averages |
| Cache | Locks, invalidation, transaction-safe reads |
| Validation | Repository validation + automatic `ValidationContext` reuse |
| Transactions | `TransactionManager` + `BaseAction` |
| Slugs | Multiple model-level slug fields |
| Files | Automatic uploads, replace/delete lifecycle, rollback cleanup |
| Storage audit | Orphan-file detection and deletion |
| Database backup | MySQL/MariaDB/PostgreSQL dumps, gzip, retention |
| Logging | Structured logging with sensitive-data redaction |
| HTTP | API response helpers and exception normalization |
| Middleware | Security headers, sensitive-path blocking, request correlation |
| Services | Optional `BaseService` helpers |

## Repository example

```php
final class CustomerRepository extends BaseRepository
{
    protected array $allowedFilters = [
        'status',
        'country_id',
    ];

    protected array $allowedSorts = [
        'name',
        'created_at',
    ];

    protected array $searchable = [
        'name',
        'email',
    ];

    protected array $allowedRelations = [
        'country',
        'orders',
    ];
}
```

```php
$customer = $repository->findOrFail($id);

$customers = $repository->get([
    'status' => 'active',
]);

$repository->create($data);
$repository->update($id, $data);
$repository->delete($id);
$repository->forceDelete($id);
$repository->restore($id);

$repository->bulkUpdate($data, $filters);
$repository->bulkDelete($filters);
$repository->bulkRestore($filters);
$repository->bulkForceDelete($filters);
```

## Relations and aggregates

```php
$repository
    ->with(['country'])
    ->withCount('orders')
    ->withSum('orders', 'total')
    ->withAvg('orders', 'total');
```

Relations must be allow-listed. Invalid aggregate columns throw instead of being silently ignored.

## ValidationContext

Repository validation can resolve models once and reuse them later.

```php
$customer = $validationContext->requireModel(
    'customer',
    Customer::class,
);
```

Repository lookups automatically reuse matching resolved models. Missing models are queried and remembered.

## Transactions

```php
final class CreateCustomerAction extends BaseAction
{
    public function execute(array $data): Customer
    {
        return $this->transactional(
            fn () => $this->service->create($data),
        );
    }
}
```

## Slugs

```php
protected function slugOptions(): array
{
    return [
        'slug' => ['source' => 'name'],
        'seo_slug' => ['source' => 'seo_title'],
    ];
}
```

## Automatic file uploads

```php
protected function fileAttributes(): array
{
    return [
        'avatar_path' => [
            'disk' => 'public',
            'directory' => 'users/avatars',
        ],
    ];
}
```

```php
$user->avatar_path = $request->file('avatar');
$user->save();
```

Successful replacement:

```text
new file stored
    ↓
DB write commits
    ↓
old file deleted
```

Failed write or rollback:

```text
new file stored
    ↓
DB INSERT/UPDATE fails or transaction rolls back
    ↓
new file deleted
    ↓
previous committed file remains
```

## Storage audit

Preview orphan files:

```bash
php artisan infrastructure:storage-audit
```

Delete confirmed orphan files:

```bash
php artisan infrastructure:storage-audit --delete
```

Only explicitly configured model-owned directories are scanned.

## Database backup

```bash
php artisan infrastructure:database-backup
php artisan infrastructure:database-backup --connection=mysql
```

Supports:

- MySQL / MariaDB via `mysqldump`
- PostgreSQL via `pg_dump`
- optional gzip
- schema-only excluded tables
- Laravel filesystem disks
- retention pruning

See [docs/database-backups.md](docs/database-backups.md).

## Security middleware

Available aliases:

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

## Cache

```dotenv
LARAVEL_INFRASTRUCTURE_CACHE_ENABLED=true
LARAVEL_INFRASTRUCTURE_CACHE_STORE=redis
LARAVEL_INFRASTRUCTURE_CACHE_TTL=300
LARAVEL_INFRASTRUCTURE_CACHE_LOCK_SECONDS=10
LARAVEL_INFRASTRUCTURE_CACHE_LOCK_WAIT_SECONDS=3
```

Repository cache includes invalidation, lock-based stampede protection, taggable/non-taggable store support, and transaction-safe reads.

## Logging

```php
CustomLog::info(
    'Customer created.',
    ['customer_id' => $customer->id],
    LogDomain::APPLICATION,
);
```

Passwords, tokens, authorization headers, cookies, API keys, secrets, and related sensitive values are redacted.

## API responses

```php
return MessageResponse::make('Customer deleted.');
```

```php
return ResourceResponse::make(
    resource: new CustomerResource($customer),
    message: 'Customer loaded.',
);
```

JSON exception normalization can be disabled with:

```dotenv
LARAVEL_INFRASTRUCTURE_EXCEPTION_RENDERER_ENABLED=false
```

## Main config

```dotenv
LARAVEL_INFRASTRUCTURE_CACHE_ENABLED=true
LARAVEL_INFRASTRUCTURE_CACHE_STORE=redis
LARAVEL_INFRASTRUCTURE_CACHE_TTL=300

LARAVEL_INFRASTRUCTURE_TRANSACTION_ATTEMPTS=1

LARAVEL_INFRASTRUCTURE_BACKUP_DISK=local
LARAVEL_INFRASTRUCTURE_BACKUP_PATH=backups/database
LARAVEL_INFRASTRUCTURE_BACKUP_KEEP=3
LARAVEL_INFRASTRUCTURE_BACKUP_COMPRESS=true

LARAVEL_INFRASTRUCTURE_EXCEPTION_RENDERER_ENABLED=true

LARAVEL_INFRASTRUCTURE_LOGGING_ENABLED=true
LARAVEL_INFRASTRUCTURE_LOG_CHANNEL=
LARAVEL_INFRASTRUCTURE_EXCEPTION_TRACE=false
LARAVEL_INFRASTRUCTURE_CORRELATION_HEADER=X-Request-ID
```

Full configuration:

```text
config/laravel-infrastructure.php
```

## Documentation

- [Architecture](docs/architecture.md)
- [Repositories](docs/repositories.md)
- [Filtering](docs/filtering.md)
- [Caching](docs/caching.md)
- [Validation](docs/validation.md)
- [Transactions](docs/transactions.md)
- [Files](docs/files.md)
- [Database backups](docs/database-backups.md)
- [Logging](docs/logging.md)
- [Responses](docs/responses.md)
- [Exceptions](docs/exceptions.md)
- [Slugs](docs/slugs.md)
- [Security](docs/security.md)
- [Testing](docs/testing.md)

## Testing

```bash
composer validate --strict
composer test
composer lint
composer analyse
```

## Package scope

Reusable infrastructure only. No application models, RBAC, authentication, UI, application routes, business migrations, or domain logic.

No dependency on the host application's `App\` namespace.

## License

MIT. See [LICENSE](LICENSE).
