# Laravel Infrastructure

Reusable Laravel infrastructure for repository-driven applications with safe caching, query filters, validation contexts, generic API responses, logging helpers, and transaction boundaries.

[![Tests](https://github.com/Ak279642/laravel-infrastructure/actions/workflows/tests.yml/badge.svg)](https://github.com/Ak279642/laravel-infrastructure/actions/workflows/tests.yml)

## Requirements

- PHP 8.2+
- Laravel 10, 11, 12 or 13

## Installation

```bash
composer require ak279642/laravel-infrastructure
```

Laravel package discovery registers the service provider automatically.

Optional configuration:

```bash
php artisan vendor:publish --tag=laravel-infrastructure-config
```

## Repository usage

Create an application repository by extending `BaseRepository` and injecting the model plus the package cache manager.

```php
<?php

namespace App\Repositories;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use App\Models\Customer;

final class CustomerRepository extends BaseRepository
{
    protected array $searchable = ['name', 'email'];
    protected array $allowedFilters = ['status', 'country_id'];
    protected array $allowedSorts = ['name', 'created_at'];
    protected array $allowedRelations = ['country'];

    public function __construct(Customer $model, CacheManager $cache)
    {
        parent::__construct($model, $cache);
    }
}
```

Typical operations are available from the base repository:

```php
$repository->findOrFail($id);
$repository->get(['status' => 'active']);
$repository->paginate(['search' => 'Acme'], perPage: 25);
$repository->count(['status' => 'active']);
$repository->create($data);
$repository->update($id, $data);
$repository->delete($id);
```

Repositories support filtering, search, sorting, scopes, relations, pagination, aggregates, chunking/lazy iteration and bulk operations.

## Model cache invalidation

Cache behavior is opt-in. Your models do not need to extend a package base model.

```php
<?php

namespace App\Models;

use Ak279642\LaravelInfrastructure\Cache\Concerns\InteractsWithCache;
use Ak279642\LaravelInfrastructure\Contracts\CacheableModel;
use Illuminate\Database\Eloquent\Model;

final class Customer extends Model implements CacheableModel
{
    use InteractsWithCache;
}
```

The concern registers the package observer for that model and invalidates its cache dependencies after committed model changes.

### Cache-store safety

Repository invalidation is tag based. If the selected Laravel cache store does not support tags, repository reads that require invalidation automatically bypass caching rather than risk stale data. Redis and Memcached are appropriate stores when repository caching is required.

## Cache helpers

```php
use Ak279642\LaravelInfrastructure\Cache\CacheKey;
use Ak279642\LaravelInfrastructure\Cache\CacheTag;
use Ak279642\LaravelInfrastructure\Cache\CacheTtl;

$key = CacheKey::make('customers.list', [
    'status' => 'active',
    'ids' => CacheKey::unordered([3, 1, 2]),
]);

$tags = CacheTag::model('customers', 10);
$ttl = CacheTtl::MINUTES_5;
```

## Transaction boundaries

The package exposes a small transaction abstraction intended for actions/orchestrators. Business services and repositories should remain transaction-agnostic.

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

## Validation and operation contexts

`OperationContext` and `ValidationContext` allow services/repositories to share already-resolved state without repeating lookups. `RepositoryValidationService` provides reusable repository-backed validation rules.

## API exceptions and responses

The package includes generic exceptions such as `ValidationException`, `NotFoundException`, `ConflictException`, `UnauthorizedException`, and `AccessForbiddenException`, plus `MessageResponse` and `ResourceResponse` helpers.

## Logging

`CustomLog` adds domain-aware logging and recursively redacts common sensitive keys. It uses Laravel's configured logger by default and can be customized through `config/laravel-infrastructure.php`.

## Design boundaries

This package intentionally contains no application models, Admin/RBAC implementation, Livewire UI, business migrations, application routes, media/image logic, or request-log feature. It also has no dependency on the host application's `App\` namespace.

The intended application flow is:

```text
Controller -> Action -> Service -> Repository -> Eloquent
                |
                +-> transaction boundary
```

- Actions coordinate transactions/orchestration.
- Services own complete business logic.
- Repositories own database querying and persistence.
- This package provides the reusable infrastructure underneath those layers.

## Testing

```bash
composer test
```

The CI matrix validates supported Laravel/PHP combinations and includes an architecture test preventing accidental `App\` dependencies.

## License

MIT. See [LICENSE](LICENSE).
