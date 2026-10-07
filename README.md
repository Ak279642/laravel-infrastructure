# Laravel Infrastructure

Opinionated, reusable infrastructure for Laravel applications: repositories, safe tagged caching, repository-aware validation, transaction boundaries, slug/file lifecycle helpers, API responses, exceptions, and logging.

[![Tests](https://github.com/Ak279642/laravel-infrastructure/actions/workflows/tests.yml/badge.svg)](https://github.com/Ak279642/laravel-infrastructure/actions/workflows/tests.yml)

## What this package does

~~~text
Controller
    ↓
Action / Orchestrator   ← transaction boundary
    ↓
Service                 ← complete business logic
    ↓
Repository              ← persistence / query / cache-aware reads
    ↓
Eloquent Model
~~~

**Repository caching is enabled by default.** Read operations use repository cache when it can be invalidated safely. Relevant Eloquent model mutations automatically invalidate affected entries. Use **withoutCache()** only when a fresh database read is explicitly required.

~~~php
$users = $userRepository->get();

$freshUsers = $userRepository
    ->withoutCache()
    ->get();
~~~

withoutCache() returns an isolated repository clone, so it does not permanently disable caching on shared/scoped/singleton repository instances under Octane or long-running workers.

## Requirements

- PHP 8.2+
- Laravel 10, 11, 12 or 13
- Redis, Memcached, or another tag-capable store is recommended for repository caching

## Installation

~~~bash
composer require ak279642/laravel-infrastructure
~~~

Optional configuration:

~~~bash
php artisan vendor:publish --tag=laravel-infrastructure-config
~~~

## Quick Start

### Model

~~~php
namespace App\Models;

use Ak279642\LaravelInfrastructure\Models\BaseModel as InfrastructureModel;

final class User extends InfrastructureModel
{
    protected $fillable = [
        'name',
        'email',
        'status',
    ];
}
~~~

The package base model is optional. Normal Eloquent models can opt into individual concerns.

### Repository

~~~php
namespace App\Repositories;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Validation\ValidationContext;
use App\Models\User;

final class UserRepository extends BaseRepository
{
    protected array $allowedFilters = [
        'status',
        'email',
        'role_id',
    ];

    protected array $allowedSorts = [
        'name',
        'created_at',
    ];

    protected array $allowedRelations = [
        'roles',
        'profile',
    ];

    protected array $allowedScopes = [
        'active',
    ];

    protected array $searchable = [
        'name',
        'email',
    ];

    public function __construct(
        User $model,
        CacheManager $cache,
        ?ValidationContext $context = null,
    ) {
        parent::__construct($model, $cache, $context);
    }
}
~~~

Use it:

~~~php
$users = $repository->get([
    'status' => 'active',
    'age' => ['>=', 18],
    'role_id' => ['in', [1, 2, 3]],
    'sort' => ['name', '-created_at'],
    'with' => ['roles'],
]);
~~~

Request-controlled filters, sorts, relations, search columns, and scopes are limited by explicit repository allow-lists.

## Filtering and strict mode

Unknown query inputs are ignored by default for compatibility.

Enable strict developer feedback where desired:

~~~php
protected bool $strictFilters = true;
protected bool $strictSorts = true;
protected bool $strictRelations = true;
~~~

An invalid input produces an actionable exception, for example:

~~~text
Filter [stauts] is not allowed on UserRepository.
Allowed filters: status, email, role_id
~~~

Compact operators are preferred:

~~~php
[
    'status' => 'active',
    'age' => ['>=', 18],
    'role_id' => ['in', [1, 2, 3]],
    'created_at' => ['between', [$from, $to]],
]
~~~

The historical associative operator format remains supported for backward compatibility.

See [Repository guide](docs/REPOSITORIES.md).

## Pagination

~~~php
$repository->paginate(
    filters: ['status' => 'active'],
    perPage: 25,
);

$repository->simplePaginate(perPage: 25);
$repository->cursorPaginate(perPage: 100);
~~~

Paginator/cursor objects remain database-backed because they contain request-specific state.

## Caching

Normal repository reads cache automatically on tag-capable stores:

~~~php
$users = $repository->get();
$user = $repository->findOrFail($id);
$count = $repository->count(['status' => 'active']);
~~~

Fresh read:

~~~php
$users = $repository
    ->withoutCache()
    ->get();
~~~

Custom repository methods should use the protected cacheRemember() helper so deterministic keys and dependency tags remain consistent:

~~~php
public function activeForCountry(int $countryId)
{
    return $this->cacheRemember(
        operation: 'activeForCountry',
        callback: fn () => $this->query()
            ->where('country_id', $countryId)
            ->where('status', 'active')
            ->get(),
        params: [
            'country_id' => $countryId,
        ],
    );
}
~~~

Cache-miss population uses locks when supported to reduce stampedes. Optional cache events expose hits, misses, invalidation, and bypass reasons without exposing cached values.

Direct DB::table() writes and raw pivot mutations do not fire Eloquent lifecycle events. Call repository clearCache() or the invalidation API afterward.

See [Caching guide](docs/CACHING.md).

## Repository-aware validation

Extend RepositoryFormRequest when validation should resolve records once and reuse them later:

~~~php
final class StoreInvoiceRequest extends RepositoryFormRequest
{
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
        ];
    }

    protected function repositoryValidationRules(): array
    {
        return [
            new RepositoryValidationRule(
                repository: CustomerRepository::class,
                exists: ['customer_id'],
                resolve: [
                    [
                        'field' => 'customer_id',
                        'as' => 'customer',
                    ],
                ],
            ),
        ];
    }
}
~~~

In a service:

~~~php
$customer = $validationContext->requireModel(
    'customer',
    Customer::class,
);
~~~

A later CustomerRepository::findOrFail($id) can reuse the same model. ValidationContext is a scoped binding and is reset across normal request/job/Octane lifecycles.

See [Validation guide](docs/VALIDATION.md).

## Transactions

Transactions stay at the Action/orchestration boundary:

~~~php
final class CreateUserAction
{
    public function __construct(
        private CreateUserService $service,
        private TransactionManager $transactions,
    ) {}

    public function execute(array $data): User
    {
        return $this->transactions->run(
            fn () => $this->service->create($data),
        );
    }
}
~~~

Repositories do not start their own transactions.

## Architecture: Do / Don't

**Do:** Controller → Action → Service → Repository → Model.

**Don't:** put DB::transaction() inside repository methods.

**Do:** keep business rules in Services/Actions.

**Don't:** put domain business rules into BaseRepository.

**Do:** use explicit repository allow-lists.

**Don't:** pass arbitrary client-controlled column/relation/scope names into Eloquent.

See [Architecture guide](docs/ARCHITECTURE.md).

## Advanced features

- [Repositories, filters, sorts, relations, scopes](docs/REPOSITORIES.md)
- [Caching, invalidation, events, locks](docs/CACHING.md)
- [Repository validation and ValidationContext](docs/VALIDATION.md)
- [Files and slugs](docs/FILES-AND-SLUGS.md)
- [Architecture rules](docs/ARCHITECTURE.md)
- [Performance benchmarks](docs/PERFORMANCE.md)
- [Security policy](SECURITY.md)
- [Copyable examples](examples)

## Testing and quality

~~~bash
composer validate --strict
composer test
composer test:architecture
composer analyse
composer format:check
composer audit --no-dev
composer benchmark
~~~

CI runs the supported PHP/Laravel matrix and separate static-analysis, formatting, architecture, and dependency-security gates.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).
