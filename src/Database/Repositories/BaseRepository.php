<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories;

use Ak279642\LaravelInfrastructure\Cache\CacheKey;
use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\CacheTag;
use Ak279642\LaravelInfrastructure\Database\Repositories\Concerns\HasBulkCache;
use Ak279642\LaravelInfrastructure\Database\Repositories\Concerns\HasCache;
use Ak279642\LaravelInfrastructure\Database\Repositories\Concerns\HasFilters;
use Ak279642\LaravelInfrastructure\Database\Repositories\Concerns\HasRelations;
use Ak279642\LaravelInfrastructure\Database\Repositories\Concerns\HasScopes;
use Ak279642\LaravelInfrastructure\Database\Repositories\Concerns\HasSorting;
use Ak279642\LaravelInfrastructure\Database\Repositories\Contracts\RepositoryInterface;
use Ak279642\LaravelInfrastructure\Validation\ValidationContext;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\LazyCollection;

abstract class BaseRepository implements RepositoryInterface
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

    protected array $allowedSorts = [];

    protected array $allowedRelations = [];

    protected array $allowedScopes = [];

    protected array $defaultRelations = [];

    protected array $defaultOrder = [];

    protected bool $strictFilters = false;

    protected bool $strictSorts = false;

    protected bool $strictRelations = false;

    protected bool $customQueryState = false;

    public function __construct(
        protected Model $model,
        protected CacheManager $cache,
        protected ?ValidationContext $validationContext = null,
    ) {
        $this->query = $this->model->newQuery();
        $this->initializeCache();
    }

    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Return a completely fresh builder for custom repository methods.
     *
     * Direct custom queries are intentionally not merged into the fluent
     * one-shot query state used by orderBy()/with()/scope().
     */
    public function query(): Builder
    {
        return $this->model->newQuery();
    }

    public function clearCache(): void
    {
        $this->getCacheManager()->flushTags([
            CacheTag::fromModel($this->model::class),
        ]);
    }

    public function truncate(): void
    {
        $this->model->newQuery()->truncate();
        $this->clearCache();
    }

    public function all(
        array $columns = ['*'],
    ): Collection {
        return $this->cacheRemember(
            'all',
            fn (): Collection => $this->buildQuery()->get($columns),
            ['columns' => $columns],
        );
    }

    public function get(
        array $filters = [],
        array $columns = ['*'],
    ): Collection {
        return $this->cacheRemember(
            'get',
            fn (): Collection => $this
                ->buildQuery($filters)
                ->get($columns),
            [
                'filters' => $filters,
                'columns' => $columns,
                'with' => $filters['with'] ?? [],
            ],
        );
    }

    public function first(
        array $filters = [],
        array $columns = ['*'],
    ): ?Model {
        return $this->cacheRemember(
            'first',
            fn (): ?Model => $this
                ->buildQuery($filters)
                ->first($columns),
            [
                'filters' => $filters,
                'columns' => $columns,
                'with' => $filters['with'] ?? [],
            ],
        );
    }

    public function firstOrFail(
        array $filters = [],
        array $columns = ['*'],
    ): Model {
        return $this->first(
            $filters,
            $columns,
        ) ?? throw (new ModelNotFoundException())
            ->setModel($this->model::class);
    }

    public function find(
        int|string $id,
        array $with = [],
        array $columns = ['*'],
    ): ?Model {
        if (
            $columns === ['*']
            && ($contextModel = $this->findFromContext(
                $id,
                $with,
            ))
        ) {
            return $contextModel;
        }

        return $this->cacheRemember(
            'find',
            function () use (
                $id,
                $with,
                $columns,
            ): ?Model {
                $query = $this->readQuery();

                if ($with !== []) {
                    $this->applyRelations(
                        $query,
                        $with,
                    );
                }

                return $query->find(
                    $id,
                    $columns,
                );
            },
            [
                'id' => $id,
                'with' => $with,
                'columns' => $columns,
            ],
        );
    }

    public function findOrFail(
        int|string $id,
        array $with = [],
        array $columns = ['*'],
    ): Model {
        return $this->find(
            $id,
            $with,
            $columns,
        ) ?? throw (new ModelNotFoundException())
            ->setModel(
                $this->model::class,
                [$id],
            );
    }

    public function create(
        array $data,
        bool $refresh = false,
        array $with = [],
    ): Model {
        $model = $this->model->newInstance($data);
        $model->save();

        $this->clearCache();

        if ($refresh) {
            $model->refresh();
        }

        if ($with !== []) {
            $this->load(
                $model,
                $with,
            );
        }

        return $model;
    }

    public function update(
        int|string|Model $id,
        array $data,
        bool $refresh = false,
        array $with = [],
    ): Model {
        $model = $id instanceof Model
            ? $id
            : $this->findOrFail($id);

        $model->fill($data);
        $model->save();

        $this->clearCache();

        if ($refresh) {
            $model->refresh();
        }

        if ($with !== []) {
            $this->load(
                $model,
                $with,
            );
        }

        return $model;
    }

    public function updateOrCreate(
        array $attributes,
        array $values = [],
    ): Model {
        $model = $this
            ->query()
            ->updateOrCreate(
                $attributes,
                $values,
            );

        $this->clearCache();

        return $model;
    }

    public function delete(
        int|string|Model $id,
    ): bool {
        $model = $id instanceof Model
            ? $id
            : $this->findOrFail($id);

        $deleted = (bool) $model->delete();

        if ($deleted) {
            $this->clearCache();
        }

        return $deleted;
    }

    public function forceDelete(
        int|string|Model $id,
    ): bool {
        $model = $id instanceof Model
            ? $id
            : $this
                ->query()
                ->withTrashed()
                ->findOrFail($id);

        $deleted = method_exists(
            $model,
            'forceDelete',
        )
            ? (bool) $model->forceDelete()
            : (bool) $model->delete();

        if ($deleted) {
            $this->clearCache();
        }

        return $deleted;
    }

    public function restore(
        int|string|Model $id,
    ): bool {
        $model = $id instanceof Model
            ? $id
            : $this
                ->query()
                ->withTrashed()
                ->findOrFail($id);

        if (! method_exists($model, 'restore')) {
            return false;
        }

        $restored = (bool) $model->restore();

        if ($restored) {
            $this->clearCache();
        }

        return $restored;
    }

    public function exists(array $filters = []): bool
    {
        return (bool) $this->cacheRemember(
            'exists',
            fn (): bool => $this
                ->buildQuery($filters)
                ->exists(),
            ['filters' => $filters],
        );
    }

    public function doesntExist(array $filters = []): bool
    {
        return ! $this->exists($filters);
    }

    public function count(array $filters = []): int
    {
        return (int) $this->cacheRemember(
            'count',
            fn (): int => $this
                ->buildQuery($filters)
                ->count(),
            ['filters' => $filters],
        );
    }

    public function sum(
        string $column,
        array $filters = [],
    ): float|int|null {
        return $this->cacheRemember(
            'sum',
            fn (): float|int => $this
                ->buildQuery($filters)
                ->sum($column),
            [
                'column' => $column,
                'filters' => $filters,
            ],
        );
    }

    public function avg(
        string $column,
        array $filters = [],
    ): float|int|null {
        return $this->cacheRemember(
            'avg',
            fn (): float|int|null => $this
                ->buildQuery($filters)
                ->avg($column),
            [
                'column' => $column,
                'filters' => $filters,
            ],
        );
    }

    public function min(
        string $column,
        array $filters = [],
    ): mixed {
        return $this->cacheRemember(
            'min',
            fn (): mixed => $this
                ->buildQuery($filters)
                ->min($column),
            [
                'column' => $column,
                'filters' => $filters,
            ],
        );
    }

    public function max(
        string $column,
        array $filters = [],
    ): mixed {
        return $this->cacheRemember(
            'max',
            fn (): mixed => $this
                ->buildQuery($filters)
                ->max($column),
            [
                'column' => $column,
                'filters' => $filters,
            ],
        );
    }

    public function pluck(
        string $column,
        ?string $key = null,
        array $filters = [],
    ): BaseCollection {
        return $this->cacheRemember(
            'pluck',
            fn (): BaseCollection => $this
                ->buildQuery($filters)
                ->pluck(
                    $column,
                    $key,
                ),
            [
                'column' => $column,
                'key' => $key,
                'filters' => $filters,
            ],
        );
    }

    public function groupCount(
        string $column,
        array $filters = [],
    ): BaseCollection {
        return $this->cacheRemember(
            'groupCount',
            fn (): BaseCollection => $this
                ->buildQuery($filters)
                ->selectRaw(
                    $column.', COUNT(*) as aggregate',
                )
                ->groupBy($column)
                ->pluck(
                    'aggregate',
                    $column,
                ),
            [
                'column' => $column,
                'filters' => $filters,
            ],
        );
    }

    public function paginate(
        array $filters = [],
        int $perPage = 15,
        array $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null,
    ): LengthAwarePaginator {
        return $this
            ->buildQuery($filters)
            ->paginate(
                $perPage,
                $columns,
                $pageName,
                $page,
            );
    }

    public function simplePaginate(
        int $perPage = 15,
        array $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null,
    ): Paginator {
        return $this
            ->buildQuery()
            ->simplePaginate(
                $perPage,
                $columns,
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
        return $this
            ->buildQuery()
            ->cursorPaginate(
                $perPage,
                $columns,
                $cursorName,
                $cursor,
            );
    }

    public function chunk(
        int $count,
        callable $callback,
    ): bool {
        return $this
            ->buildQuery()
            ->chunk(
                $count,
                $callback,
            );
    }

    public function lazy(
        int $chunkSize = 1000,
    ): LazyCollection {
        return $this
            ->buildQuery()
            ->lazy($chunkSize);
    }

    public function cursor(): LazyCollection
    {
        return $this
            ->buildQuery()
            ->cursor();
    }

    public function load(
        Model $model,
        array|string $relations,
    ): Model {
        $relations = $this->getAllowedRelations(
            $this->normalizeRelations($relations),
        );

        if ($relations !== []) {
            $model->load($relations);
        }

        return $model;
    }

    public function loadMissing(
        Model $model,
        array|string $relations,
    ): Model {
        $relations = $this->getAllowedRelations(
            $this->normalizeRelations($relations),
        );

        if ($relations !== []) {
            $model->loadMissing($relations);
        }

        return $model;
    }

    /**
     * Duplicate checks are intentionally fresh database reads. Caching a
     * uniqueness pre-check can return stale information inside write flows.
     */
    public function findDuplicate(
        array $fields,
        ?int $ignore = null,
        array $where = [],
    ): ?Model {
        if ($fields === []) {
            return null;
        }

        $query = $this->query();

        if ($ignore !== null) {
            $query->whereKeyNot($ignore);
        }

        foreach ($where as $column => $value) {
            $query->where(
                $column,
                $value,
            );
        }

        $query->where(
            function (Builder $builder) use ($fields): void {
                foreach ($fields as $field => $value) {
                    if (
                        $value === null
                        || $value === ''
                    ) {
                        continue;
                    }

                    if (
                        is_string($field)
                        && str_ends_with(
                            $field,
                            '.*',
                        )
                    ) {
                        $jsonField = substr(
                            $field,
                            0,
                            -2,
                        );

                        foreach ((array) $value as $jsonValue) {
                            if (
                                $jsonValue !== null
                                && $jsonValue !== ''
                            ) {
                                $builder->orWhereJsonContains(
                                    $jsonField,
                                    (string) $jsonValue,
                                );
                            }
                        }

                        continue;
                    }

                    $builder->orWhere(
                        $field,
                        $value,
                    );
                }
            },
        );

        return $query->first();
    }

    public function findWhereIn(
        string $field,
        array $values,
        array $where = [],
    ): Collection {
        if ($values === []) {
            return new Collection();
        }

        $uniqueValues = array_values(
            array_unique($values),
        );

        return $this->cacheRemember(
            'findWhereIn',
            function () use (
                $field,
                $uniqueValues,
                $where,
            ): Collection {
                $query = $this
                    ->query()
                    ->whereIn(
                        $field,
                        $uniqueValues,
                    );

                foreach ($where as $column => $value) {
                    $query->where(
                        $column,
                        $value,
                    );
                }

                return $query->get();
            },
            [
                'field' => $field,
                'values' => CacheKey::unordered(
                    $uniqueValues,
                ),
                'where' => $where,
            ],
        );
    }

    public function findWhere(
        mixed $id,
        array $where = [],
        array $with = [],
    ): ?Model {
        return $this->cacheRemember(
            'findWhere',
            function () use (
                $id,
                $where,
                $with,
            ): ?Model {
                $query = $this
                    ->query()
                    ->whereKey($id);

                foreach ($where as $column => $value) {
                    $query->where(
                        $column,
                        $value,
                    );
                }

                if ($with !== []) {
                    $this->applyRelations(
                        $query,
                        $with,
                    );
                }

                return $query->first();
            },
            [
                'id' => $id,
                'where' => $where,
                'with' => $with,
            ],
        );
    }

    protected function buildQuery(
        array $filters = [],
    ): Builder {
        $query = $this->readQuery();

        if ($this->defaultRelations !== []) {
            $this->applyRelations(
                $query,
                $this->defaultRelations,
            );
        }

        if (
            $this->defaultOrder !== []
            && empty($filters['sort'])
            && empty($filters['sort_by'])
        ) {
            foreach ($this->defaultOrder as $column => $direction) {
                $query->orderBy(
                    $column,
                    $direction,
                );
            }
        }

        $this->applyFilters(
            $query,
            $filters,
        );

        if (! empty($filters['search'])) {
            $this->applySearch(
                $query,
                (string) $filters['search'],
                is_array($filters['search_columns'] ?? null)
                    ? $filters['search_columns']
                    : null,
            );
        }

        if (! empty($filters['scopes'])) {
            $this->applyScopes(
                $query,
                $filters['scopes'],
            );
        }

        if (! empty($filters['with'])) {
            $this->applyRelations(
                $query,
                $filters['with'],
            );
        }

        if (! empty($filters['with_count'])) {
            $this->applyRelationCounts(
                $query,
                $filters['with_count'],
            );
        }

        if (! empty($filters['sort'])) {
            $query->reorder();

            $this->applySorting(
                $query,
                $filters['sort'],
            );
        } elseif (! empty($filters['sort_by'])) {
            $query->reorder();

            $this->applySorting(
                $query,
                [
                    (string) $filters['sort_by']
                        => (string) (
                            $filters['sort_order']
                            ?? 'asc'
                        ),
                ],
            );
        }

        return $query;
    }

    protected function findFromContext(
        int|string $id,
        array $with = [],
    ): ?Model {
        if ($this->customQueryState) {
            return null;
        }

        $context = app()->bound(
            ValidationContext::class,
        )
            ? app(ValidationContext::class)
            : $this->validationContext;

        if (! $context) {
            return null;
        }

        $model = $context->findModel(
            $this->model::class,
            $id,
        );

        if (! $model) {
            return null;
        }

        if ($with !== []) {
            $this->loadMissing(
                $model,
                $with,
            );
        }

        return $model;
    }

    protected function forkQuery(): static
    {
        $clone = clone $this;
        $clone->query = clone $this->query;
        $clone->customQueryState = true;

        return $clone;
    }

    protected function hasCustomQueryState(): bool
    {
        return $this->customQueryState;
    }

    private function readQuery(): Builder
    {
        return clone $this->query;
    }
}
