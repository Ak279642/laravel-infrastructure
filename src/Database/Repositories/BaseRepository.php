<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\CacheTag;
use Ak279642\LaravelInfrastructure\Database\Repositories\Concerns\HasBulkCache;
use Ak279642\LaravelInfrastructure\Database\Repositories\Concerns\HasCache;
use Ak279642\LaravelInfrastructure\Database\Repositories\Concerns\HasFilters;
use Ak279642\LaravelInfrastructure\Database\Repositories\Concerns\HasRelations;
use Ak279642\LaravelInfrastructure\Database\Repositories\Concerns\HasScopes;
use Ak279642\LaravelInfrastructure\Database\Repositories\Concerns\HasSorting;
use Ak279642\LaravelInfrastructure\Database\Repositories\Contracts\RepositoryInterface;
use Ak279642\LaravelInfrastructure\Database\Repositories\Contracts\RepositoryValidationRepository;
use Ak279642\LaravelInfrastructure\Database\Schema\SchemaRegistry;
use Ak279642\LaravelInfrastructure\Validation\ValidationContext;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Pagination\Paginator as ConcretePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\LazyCollection;

abstract class BaseRepository implements RepositoryInterface, RepositoryValidationRepository
{
    use HasBulkCache;
    use HasCache;
    use HasFilters;
    use HasRelations;
    use HasScopes;
    use HasSorting;

    protected Builder $query;

    protected array $searchable = [];

    protected array $allowedFilters = [];

    /** Explicit relation-filter allow-list. Dotted entries in allowedFilters remain supported for BC. */
    protected array $allowedRelationFilters = [];

    protected array $allowedSorts = [];

    protected array $allowedRelations = [];

    /** Request-driven model scopes must be explicitly allow-listed. */
    protected array $allowedScopes = [];

    protected array $defaultRelations = [];

    protected array $defaultOrder = [];

    protected bool $strictFilters = false;

    public function __construct(
        protected Model $model,
        protected CacheManager $cache,
        protected ?ValidationContext $validationContext = null,
    ) {
        // Do not resolve authenticated scopes during construction: repositories
        // can be instantiated before a request's authentication is established.
        $this->query = $this->model->newQuery();
        $this->initializeCache();
    }

    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Add a query-builder filter to every read on a cloned repository instance.
     * The resulting SQL and bindings participate in the cache identity.
     */
    public function filterQuery(callable $filter): static
    {
        $repository = clone $this;
        $previous = $repository->globalQueryCallback;
        $next = \Closure::fromCallable($filter);
        $repository->globalQueryCallback = static function (Builder $query) use ($previous, $next): Builder {
            $query = $previous === null ? $query : ($previous($query) ?? $query);

            return $next($query) ?? $query;
        };

        return $repository;
    }

    protected ?\Closure $globalQueryCallback = null;

    /** Override for application-wide repository query restrictions. */
    protected function globalQueryFilter(Builder $query): Builder
    {
        if ($this->globalQueryCallback === null) {
            return $query;
        }

        return ($this->globalQueryCallback)($query) ?? $query;
    }

    public function query(): Builder
    {
        $query = $this->model->newQuery();
        if (! method_exists($this->model, 'infrastructureCacheScopes')) {
            return $this->globalQueryFilter($query);
        }

        $scopes = $this->model->infrastructureCacheScopes();
        $any = $this->model->infrastructureCacheVisibilityScopes();
        $visibility = $this->model->infrastructureVisibilityResolver();
        $actor = $visibility === null ? null : $this->model->infrastructureVisibilityActor();
        $marker = $this->model->infrastructureUsesGlobalScopeMarker();

        // A column-only marker declares the cache partition of a global
        // scope; it never replaces the global scope's actual SQL restriction.
        if ($marker && ! $this->model->infrastructureHasGlobalVisibilityScopes()) {
            throw new \LogicException('A cache scope column marker requires an Eloquent global scope.');
        }

        if (is_object($visibility) && method_exists($visibility, 'apply')) {
            if ($actor === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query = $visibility->apply($query, $this->model, $actor['type'], $actor['id']);
            }
        } elseif ($visibility instanceof \Closure) {
            if ($actor === null && $scopes === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function (Builder $builder) use ($scopes, $visibility, $actor, $marker): void {
                    if ($scopes !== [] && ! $marker) {
                        $builder->where(function (Builder $owned) use ($scopes): void {
                            $operator = $this->model->infrastructureCacheScopeOperator();
                            foreach ($scopes as $column => $value) {
                                $method = $operator === 'or' ? 'orWhere' : 'where';
                                $owned->{$method}($this->model->qualifyColumn($column), $value);
                            }
                        });
                    } else {
                        $builder->whereRaw('1 = 0');
                    }
                    $visibility($builder, auth()->user(), $scopes, $actor);
                });
            }
        } elseif ($scopes !== [] && ! $marker) {
            $operator = $this->model->infrastructureCacheScopeOperator();
            $query->where(function (Builder $builder) use ($scopes, $operator): void {
                foreach ($scopes as $column => $value) {
                    $method = $operator === 'or' ? 'orWhere' : 'where';
                    $builder->{$method}($this->model->qualifyColumn($column), $value);
                }
            });
        }

        // All alternatives are grouped and always AND-ed with the partition.
        if ($any !== []) {
            $query->where(function (Builder $builder) use ($any): void {
                foreach ($any as $column => $value) {
                    $builder->orWhere($this->model->qualifyColumn($column), $value);
                }
            });
        }

        $query = $this->globalQueryFilter($query);
        if ($marker && $query->removedScopes() !== []) {
            // Removing a global scope may expose rows outside the declared
            // cache partition, which targeted write tags cannot invalidate.
            throw new \LogicException('Cannot remove global scopes from a cache-partition marker query.');
        }

        return $query;
    }

    public function clearCache(): void
    {
        $targeted = method_exists($this->model, 'infrastructureUsesTargetedCacheInvalidation')
            && $this->model->infrastructureUsesTargetedCacheInvalidation();
        $tags = $targeted
            ? $this->model->infrastructureCacheReadTags()
            : [CacheTag::fromModel($this->model::class)];

        $this->getCacheManager()->flushTags($tags);
    }

    /** Model event observers already flush the affected old/new groups after commit. */
    protected function clearCacheAfterModelWrite(): void
    {
        if (method_exists($this->model, 'infrastructureCacheReadTags')
            && $this->model->usesInfrastructureCache()) {
            return;
        }

        $this->clearCache();
    }

    public function truncate(): void
    {
        $this->model->newQuery()->truncate();
        // Truncate bypasses model events and removes all partitions.
        $this->getCacheManager()->flushTags([CacheTag::fromModel($this->model::class)]);
    }

    public function all(array $columns = ['*']): Collection
    {
        $columns = $this->safeColumns($columns);

        return $this->cacheRemember('all', fn () => $this->buildQuery()->get($columns), ['columns' => $columns]);
    }

    public function get(array $filters = [], array $columns = ['*']): Collection
    {
        $columns = $this->safeColumns($columns);

        return $this->cacheRemember('get', fn () => $this->buildQuery($filters)->get($columns), [
            'filters' => $filters,
            'columns' => $columns,
            'with' => $filters['with'] ?? [],
        ]);
    }

    public function first(array $filters = [], array $columns = ['*']): ?Model
    {
        $columns = $this->safeColumns($columns);

        return $this->cacheRemember('first', fn () => $this->buildQuery($filters)->first($columns), [
            'filters' => $filters,
            'columns' => $columns,
            'with' => $filters['with'] ?? [],
        ]);
    }

    public function firstOrFail(array $filters = [], array $columns = ['*']): Model
    {
        return $this->first($filters, $columns)
            ?? throw (new ModelNotFoundException)->setModel($this->model::class);
    }

    public function find(int|string $id, array $with = [], array $columns = ['*']): ?Model
    {
        $columns = $this->safeColumns($columns);

        if ($contextModel = $this->findFromContext($id, $with)) {
            return $contextModel;
        }

        return $this->cacheRemember('find', function () use ($id, $with, $columns): ?Model {
            $query = $this->query();
            if ($with !== []) {
                $this->applyRelations($query, $with);
            }

            return $query->find($id, $columns);
        }, ['id' => $id, 'with' => $with, 'columns' => $columns]);
    }

    public function findOrFail(int|string $id, array $with = [], array $columns = ['*']): Model
    {
        return $this->find($id, $with, $columns)
            ?? throw (new ModelNotFoundException)->setModel($this->model::class, [$id]);
    }

    public function create(array $data, bool $refresh = false, array $with = []): Model
    {
        $model = $this->model->newInstance($data);
        $model->save();
        $this->clearCacheAfterModelWrite();

        if ($refresh) {
            $model->refresh();
        }
        if ($with !== []) {
            $this->load($model, $with);
        }

        $this->rememberValidationValue($model);

        return $model;
    }

    public function update(int|string|Model $id, array $data, bool $refresh = false, array $with = []): Model
    {
        $model = $this->resolveMutationModel($id);
        $model->fill($data);
        $model->save();
        $this->clearCacheAfterModelWrite();

        if ($refresh) {
            $model->refresh();
        }
        if ($with !== []) {
            $this->load($model, $with);
        }

        $this->rememberValidationValue($model);

        return $model;
    }

    public function updateOrCreate(array $attributes, array $values = []): Model
    {
        $model = $this->query()->updateOrCreate($attributes, $values);
        $this->clearCacheAfterModelWrite();
        $this->rememberValidationValue($model);

        return $model;
    }

    public function delete(int|string|Model $id): bool
    {
        $model = $this->resolveMutationModel($id);
        $deleted = (bool) $model->delete();

        if ($deleted) {
            $this->clearCacheAfterModelWrite();
            $this->forgetValidationModel($model);
        }

        return $deleted;
    }

    public function forceDelete(int|string|Model $id): bool
    {
        $model = $this->resolveMutationModel(
            $id,
            withoutSoftDeleteScope: true,
        );
        $deleted = in_array(
            SoftDeletes::class,
            class_uses_recursive($model::class),
            true,
        )
            ? (bool) call_user_func([$model, 'forceDelete'])
            : (bool) $model->delete();

        if ($deleted) {
            $this->clearCacheAfterModelWrite();
            $this->forgetValidationModel($model);
        }

        return $deleted;
    }

    public function restore(int|string|Model $id): bool
    {
        $model = $this->resolveMutationModel(
            $id,
            withoutSoftDeleteScope: true,
        );
        if (! in_array(
            SoftDeletes::class,
            class_uses_recursive($model::class),
            true,
        )) {
            return false;
        }

        $restored = (bool) call_user_func([$model, 'restore']);

        if ($restored) {
            $this->clearCacheAfterModelWrite();
            $this->rememberValidationValue($model);
        }

        return $restored;
    }

    public function exists(array $filters = []): bool
    {
        return (bool) $this->cacheRemember('exists', fn () => $this->buildQuery($filters)->exists(), ['filters' => $filters]);
    }

    public function doesntExist(array $filters = []): bool
    {
        return ! $this->exists($filters);
    }

    public function count(array $filters = []): int
    {
        return (int) $this->cacheRemember('count', fn () => $this->buildQuery($filters)->count(), ['filters' => $filters]);
    }

    public function sum(string $column, array $filters = []): float|int|string|null
    {
        $column = $this->safeModelColumn($column);

        return $this->cacheRemember('sum', fn () => $this->buildQuery($filters)->sum($column), ['column' => $column, 'filters' => $filters]);
    }

    public function avg(string $column, array $filters = []): float|int|string|null
    {
        $column = $this->safeModelColumn($column);

        return $this->cacheRemember('avg', fn () => $this->buildQuery($filters)->avg($column), ['column' => $column, 'filters' => $filters]);
    }

    public function min(string $column, array $filters = []): mixed
    {
        $column = $this->safeModelColumn($column);

        return $this->cacheRemember('min', fn () => $this->buildQuery($filters)->min($column), ['column' => $column, 'filters' => $filters]);
    }

    public function max(string $column, array $filters = []): mixed
    {
        $column = $this->safeModelColumn($column);

        return $this->cacheRemember('max', fn () => $this->buildQuery($filters)->max($column), ['column' => $column, 'filters' => $filters]);
    }

    public function pluck(string $column, ?string $key = null, array $filters = []): BaseCollection
    {
        $column = $this->safeModelColumn($column);
        $key = $key === null ? null : $this->safeModelColumn($key);

        return $this->cacheRemember('pluck', fn () => $this->buildQuery($filters)->pluck($column, $key), [
            'column' => $column, 'key' => $key, 'filters' => $filters,
        ]);
    }

    public function groupCount(string $column, array $filters = []): BaseCollection
    {
        $column = $this->safeModelColumn($column);

        return $this->cacheRemember('groupCount', fn () => $this->buildQuery($filters)
            ->select($column)
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy($column)
            ->pluck('aggregate', $column), ['column' => $column, 'filters' => $filters]);
    }

    public function paginate(
        array $filters = [],
        int $perPage = 15,
        array $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null,
        bool $useCache = true,
        ?int $cacheTtl = null,
    ): LengthAwarePaginator {
        if ($cacheTtl !== null && $cacheTtl < 1) {
            throw new \InvalidArgumentException('Pagination cache TTL must be greater than zero.');
        }

        $columns = $this->safeColumns($columns);
        $page ??= ConcretePaginator::resolveCurrentPage($pageName);
        $query = $this->buildQuery($filters);

        // Include the scoped SQL and bindings so different tenant/user scopes
        // cannot share a cached page even when their filters are identical.
        $scopedQuery = $query->toBase();

        return $this->cacheRemember(
            'paginate',
            fn (): LengthAwarePaginator => $query->paginate(
                $perPage,
                $columns,
                $pageName,
                $page,
            ),
            [
                'filters' => $filters,
                'with' => $filters['with'] ?? [],
                'columns' => $columns,
                'per_page' => $perPage,
                'page_name' => $pageName,
                'page' => $page,
                'path' => ConcretePaginator::resolveCurrentPath(),
                'connection' => $this->model->getConnection()->getName(),
                'sql' => $scopedQuery->toSql(),
                'bindings' => $scopedQuery->getBindings(),
                // Keep cache entries with different lifetimes independent.
                'ttl' => $cacheTtl ?? ($this->cacheForever ? 'forever' : $this->cacheTtl),
            ],
            ttlOverride: $cacheTtl,
            useCache: $useCache,
        );
    }

    public function simplePaginate(
        int $perPage = 15,
        array $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null,
    ): Paginator {
        return $this->buildQuery()->simplePaginate(
            $perPage,
            $this->safeColumns($columns),
            $pageName,
            $page,
        );
    }

    public function cursorPaginate(
        int $perPage = 15,
        array $columns = ['*'],
        string $cursorName = 'cursor',
        mixed $cursor = null,
    ): CursorPaginator {
        return $this->buildQuery()->cursorPaginate(
            $perPage,
            $this->safeColumns($columns),
            $cursorName,
            $cursor,
        );
    }

    public function chunk(int $count, callable $callback): bool
    {
        return $this->buildQuery()->chunk($count, $callback);
    }

    public function lazy(int $chunkSize = 1000): LazyCollection
    {
        return $this->buildQuery()->lazy($chunkSize);
    }

    public function cursor(): LazyCollection
    {
        return $this->buildQuery()->cursor();
    }

    public function load(Model $model, array|string $relations): Model
    {
        $relations = $this->getAllowedRelations($this->normalizeRelations($relations));
        if ($relations !== []) {
            $model->load($relations);
        }

        return $model;
    }

    public function loadMissing(Model $model, array|string $relations): Model
    {
        $relations = $this->getAllowedRelations($this->normalizeRelations($relations));
        if ($relations !== []) {
            $model->loadMissing($relations);
        }

        return $model;
    }

    public function findDuplicate(array $fields, int|string|null $ignore = null, array $where = []): ?Model
    {
        if ($fields === []) {
            return null;
        }

        foreach (array_keys($fields) as $field) {
            if (! is_string($field)) {
                throw new \InvalidArgumentException('Duplicate-check fields must use string column names.');
            }

            $column = str_ends_with($field, '.*')
                ? substr($field, 0, -2)
                : $field;

            $this->safeModelColumn($column);
        }

        $where = $this->safeWhere($where);

        return $this->cacheRemember('findDuplicate', function () use ($fields, $ignore, $where): ?Model {
            $query = $this->query();
            if ($ignore !== null) {
                $query->whereKeyNot($ignore);
            }
            foreach ($where as $column => $value) {
                $query->where($column, $value);
            }
            $query->where(function (Builder $builder) use ($fields): void {
                foreach ($fields as $field => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }
                    if (is_string($field) && str_ends_with($field, '.*')) {
                        $jsonField = substr($field, 0, -2);
                        foreach ((array) $value as $jsonValue) {
                            if ($jsonValue !== null && $jsonValue !== '') {
                                $builder->orWhereJsonContains($jsonField, (string) $jsonValue);
                            }
                        }

                        continue;
                    }
                    $builder->orWhere($field, $value);
                }
            });

            return $query->first();
        }, ['fields' => $fields, 'ignore' => $ignore, 'where' => $where]);
    }

    public function findWhereIn(string $field, array $values, array $where = []): Collection
    {
        if ($values === []) {
            return new Collection;
        }

        $field = $this->safeModelColumn($field);
        $where = $this->safeWhere($where);
        $values = $this->normalizeWhereInValues($field, $values);
        $context = $this->validationContextInstance();
        $resolved = ($this->hasOpenTransaction()
            || (method_exists($this->model, 'infrastructureCacheScope')
                && $this->model->infrastructureCacheScope() !== null))
            ? new Collection
            : $context->findManyMatching(
                $this->model::class,
                $field,
                $values,
                $where,
            );

        $resolvedValues = [];

        foreach ($resolved as $model) {
            $resolvedValues[
                $this->whereInValueKey(
                    $field,
                    $model->getAttribute($field),
                )
            ] = true;
        }

        $missing = array_values(array_filter(
            $values,
            fn ($value): bool => ! isset(
                $resolvedValues[$this->whereInValueKey($field, $value)],
            ),
        ));

        if ($missing !== []) {
            $queried = $this->cacheRemember(
                'findWhereIn',
                function () use ($field, $missing, $where): Collection {
                    $query = $this->query()->whereIn($field, $missing);

                    foreach ($where as $column => $value) {
                        $query->where($column, $value);
                    }

                    return $query->get();
                },
                [
                    'field' => $field,
                    'values' => $missing,
                    'where' => $where,
                ],
            );

            $this->rememberValidationValue($queried);
            $resolved = new Collection([
                ...$resolved->all(),
                ...$queried->all(),
            ]);
        }

        $order = [];

        foreach ($values as $index => $value) {
            $order[$this->whereInValueKey($field, $value)] = $index;
        }

        return $resolved
            ->sortBy(
                fn (Model $model): int => $order[
                    $this->whereInValueKey(
                        $field,
                        $model->getAttribute($field),
                    )
                ] ?? PHP_INT_MAX,
            )
            ->values();
    }

    /**
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    protected function normalizeWhereInValues(
        string $field,
        array $values,
    ): array {
        $normalized = [];
        $seen = [];

        foreach ($values as $value) {
            if ($value !== null && ! is_scalar($value)) {
                throw new \InvalidArgumentException(
                    'Where-in values must be scalar identifiers.',
                );
            }

            $value = $this->normalizeWhereInValue($field, $value);
            $key = $this->whereInValueKey($field, $value);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $value;
        }

        return $normalized;
    }

    protected function normalizeWhereInValue(
        string $field,
        mixed $value,
    ): mixed {
        if ($value === null || $field !== $this->model->getKeyName()) {
            return $value;
        }

        if ($this->model->getKeyType() === 'int') {
            if (is_int($value)) {
                return $value;
            }

            if (
                is_string($value)
                && preg_match('/^[+-]?\d+$/D', $value) === 1
            ) {
                return (int) $value;
            }

            return $value;
        }

        return is_scalar($value)
            ? (string) $value
            : $value;
    }

    protected function whereInValueKey(
        string $field,
        mixed $value,
    ): string {
        $value = $this->normalizeWhereInValue($field, $value);

        return $value === null
            ? 'null'
            : 'value:'.(string) $value;
    }

    public function findWhere(mixed $id, array $where = [], array $with = []): ?Model
    {
        $where = $this->safeWhere($where);
        $context = $this->validationContextInstance();
        $conditions = [
            $this->model->getKeyName() => $id,
            ...$where,
        ];

        $model = $this->hasOpenTransaction()
            ? null
            : $context->findMatching(
                $this->model::class,
                $conditions,
            );

        if ($model instanceof Model) {
            if ($with !== []) {
                $this->loadMissing($model, $with);
            }

            return $model;
        }

        $model = $this->cacheRemember(
            'findWhere',
            function () use ($id, $where, $with): ?Model {
                $query = $this->query()->whereKey($id);

                foreach ($where as $column => $value) {
                    $query->where($column, $value);
                }

                if ($with !== []) {
                    $this->applyRelations($query, $with);
                }

                return $query->first();
            },
            [
                'id' => $id,
                'where' => $where,
                'with' => $with,
            ],
        );

        if ($model instanceof Model) {
            $this->rememberValidationValue($model);
        }

        return $model;
    }

    protected function buildQuery(array $filters = []): Builder
    {
        $query = $this->query();

        if ($this->defaultRelations !== []) {
            $this->applyRelations($query, $this->defaultRelations);
        }

        if ($this->defaultOrder !== [] && empty($filters['sort'])) {
            foreach ($this->defaultOrder as $column => $direction) {
                $query->orderBy($column, $direction);
            }
        }

        $this->applyFilters($query, $filters);

        if (! empty($filters['search'])) {
            $this->applySearch($query, (string) $filters['search'], $filters['search_columns'] ?? null);
        }
        if (! empty($filters['scopes'])) {
            $this->applyScopes($query, $filters['scopes']);
        }
        if (! empty($filters['with'])) {
            $this->applyRelations($query, $filters['with']);
        }
        if (! empty($filters['with_count'])) {
            $this->applyRelationCounts($query, $filters['with_count']);
        }
        if (! empty($filters['sort'])) {
            $query->reorder();
            $this->applySorting($query, $filters['sort']);
        } elseif (! empty($filters['sort_by'])) {
            $this->applySorting($query, [(string) $filters['sort_by'] => (string) ($filters['sort_order'] ?? 'asc')]);
        }

        return $query;
    }

    /**
     * @param  array<int, mixed>  $columns
     * @return list<string>
     */
    protected function safeColumns(array $columns): array
    {
        if ($columns === []) {
            throw new \InvalidArgumentException('At least one selected column is required.');
        }

        foreach ($columns as $column) {
            if (! is_string($column)) {
                throw new \InvalidArgumentException('Selected columns must be strings.');
            }

            if ($column !== '*') {
                $this->safeModelColumn($column);
            }
        }

        return array_values($columns);
    }

    /**
     * @param  array<array-key, mixed>  $where
     * @return array<string, mixed>
     */
    protected function safeWhere(array $where): array
    {
        foreach (array_keys($where) as $column) {
            if (! is_string($column)) {
                throw new \InvalidArgumentException('Where conditions must use string column names.');
            }

            $this->safeModelColumn($column);
        }

        return $where;
    }

    protected function safeModelColumn(string $column): string
    {
        if (
            preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1
            || ! app(SchemaRegistry::class)->has(
                $this->model->getConnection(),
                $this->model->getTable(),
                $column,
            )
        ) {
            throw new \InvalidArgumentException(
                "Unsafe or unknown model column [{$column}].",
            );
        }

        return $column;
    }

    protected function validationContextInstance(): ValidationContext
    {
        return $this->validationContext
            ?? app(ValidationContext::class);
    }

    protected function hasOpenTransaction(): bool
    {
        return $this->model->getConnection()->transactionLevel() > 0;
    }

    protected function rememberValidationValue(Model|Collection $value): void
    {
        $context = $this->validationContextInstance();
        $connection = $this->model->getConnection();

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit(
                static function () use ($context, $value): void {
                    $context->remember($value);
                },
            );

            return;
        }

        $context->remember($value);
    }

    protected function forgetValidationModel(Model $model): void
    {
        $context = $this->validationContextInstance();
        $connection = $model->getConnection();

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit(
                static function () use ($context, $model): void {
                    $context->forgetModel($model);
                },
            );

            return;
        }

        $context->forgetModel($model);
    }

    protected function resolveMutationModel(
        int|string|Model $id,
        bool $withoutSoftDeleteScope = false,
    ): Model {
        if (
            $id instanceof Model
            && (
                ! $this->hasOpenTransaction()
                || ! $id->exists
                || $id->getKey() === null
            )
        ) {
            return $id;
        }

        $key = $id instanceof Model
            ? $id->getKey()
            : $id;

        $query = $this->query();

        if ($withoutSoftDeleteScope) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query->findOrFail($key);
    }

    protected function findFromContext(int|string $id, array $with = []): ?Model
    {
        if ($this->hasOpenTransaction()
            || (method_exists($this->model, 'infrastructureCacheScope')
                && $this->model->infrastructureCacheScope() !== null)) {
            return null;
        }

        $context = $this->validationContextInstance();
        $model = $context->findModel($this->model::class, $id);

        if (! $model) {
            return null;
        }

        if ($with !== []) {
            $this->loadMissing($model, $with);
        }

        return $model;
    }
}
