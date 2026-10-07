<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Contracts;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TModel of Model
 */
interface RepositoryInterface
{
    /**
     * @param list<string> $columns
     * @return Collection<int, TModel>
     */
    public function all(array $columns = ['*']): Collection;

    /**
     * @param array<string, mixed> $filters
     * @param list<string> $columns
     * @return Collection<int, TModel>
     */
    public function get(array $filters = [], array $columns = ['*']): Collection;

    /**
     * @param array<string, mixed> $filters
     * @param list<string> $columns
     * @return TModel|null
     */
    public function first(array $filters = [], array $columns = ['*']): ?Model;

    /**
     * @param array<string, mixed> $filters
     * @param list<string> $columns
     * @return TModel
     */
    public function firstOrFail(array $filters = [], array $columns = ['*']): Model;

    /**
     * @param list<string> $with
     * @param list<string> $columns
     * @return TModel|null
     */
    public function find(int|string $id, array $with = [], array $columns = ['*']): ?Model;

    /**
     * @param list<string> $with
     * @param list<string> $columns
     * @return TModel
     */
    public function findOrFail(int|string $id, array $with = [], array $columns = ['*']): Model;

    /**
     * @param array<string, mixed> $data
     * @param list<string> $with
     * @return TModel
     */
    public function create(array $data, bool $refresh = false, array $with = []): Model;

    /**
     * @param TModel|int|string $id
     * @param array<string, mixed> $data
     * @param list<string> $with
     * @return TModel
     */
    public function update(int|string|Model $id, array $data, bool $refresh = false, array $with = []): Model;

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     * @return TModel
     */
    public function updateOrCreate(array $attributes, array $values = []): Model;

    /** @param TModel|int|string $id */
    public function delete(int|string|Model $id): bool;

    /** @param TModel|int|string $id */
    public function forceDelete(int|string|Model $id): bool;

    /** @param TModel|int|string $id */
    public function restore(int|string|Model $id): bool;

    /** @param array<string, mixed> $filters */
    public function exists(array $filters = []): bool;

    /** @param array<string, mixed> $filters */
    public function doesntExist(array $filters = []): bool;

    /** @param array<string, mixed> $filters */
    public function count(array $filters = []): int;

    /** @param array<string, mixed> $filters */
    public function sum(string $column, array $filters = []): float|int|null;

    /** @param array<string, mixed> $filters */
    public function avg(string $column, array $filters = []): float|int|null;

    /** @param array<string, mixed> $filters */
    public function min(string $column, array $filters = []): mixed;

    /** @param array<string, mixed> $filters */
    public function max(string $column, array $filters = []): mixed;

    /**
     * @param array<string, mixed> $filters
     * @return \Illuminate\Support\Collection<array-key, mixed>
     */
    public function pluck(string $column, ?string $key = null, array $filters = []): \Illuminate\Support\Collection;

    /**
     * @param array<string, mixed> $filters
     * @return \Illuminate\Support\Collection<array-key, int>
     */
    public function groupCount(string $column, array $filters = []): \Illuminate\Support\Collection;

    /**
     * @param array<string, mixed> $filters
     * @param list<string> $columns
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(
        array $filters = [],
        int $perPage = 15,
        array $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null,
    ): LengthAwarePaginator;

    /**
     * @param list<string> $columns
     * @return Paginator<int, TModel>
     */
    public function simplePaginate(
        int $perPage = 15,
        array $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null,
    ): Paginator;

    /**
     * @param list<string> $columns
     * @return CursorPaginator<int, TModel>
     */
    public function cursorPaginate(
        int $perPage = 15,
        array $columns = ['*'],
        string $cursorName = 'cursor',
        mixed $cursor = null,
    ): CursorPaginator;

    /** @return Builder<TModel> */
    public function query(): Builder;

    /** @return TModel */
    public function getModel(): Model;
}
