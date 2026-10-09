<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Contracts;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

interface RepositoryInterface
{
    /**
     * Get all records.
     */
    public function all(array $columns = ['*']): Collection;

    /**
     * Get records with optional conditions.
     */
    public function get(array $filters = [], array $columns = ['*']): Collection;

    /**
     * Get first record.
     */
    public function first(array $filters = [], array $columns = ['*']): ?Model;

    /**
     * Get first record or fail.
     *
     * @throws ModelNotFoundException
     */
    public function firstOrFail(array $filters = [], array $columns = ['*']): Model;

    /**
     * Find record by ID.
     */
    public function find(int|string $id, array $with = [], array $columns = ['*']): ?Model;

    /**
     * Find record by ID or fail.
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(int|string $id, array $with = [], array $columns = ['*']): Model;

    /**
     * Create new record.
     */
    public function create(array $data, bool $refresh = false, array $with = []): Model;

    /**
     * Update existing record.
     */
    public function update(int|string|Model $id, array $data, bool $refresh = false, array $with = []): Model;

    /**
     * Delete record.
     */
    public function delete(int|string|Model $id): bool;

    /**
     * Force delete record (permanent).
     */
    public function forceDelete(int|string|Model $id): bool;

    /**
     * Restore soft-deleted record.
     */
    public function restore(int|string|Model $id): bool;

    /**
     * Check if record exists.
     */
    public function exists(array $filters = []): bool;

    /**
     * Get count of records.
     */
    public function count(array $filters = []): int;

    /**
     * Paginate results with optional per-call caching and TTL override.
     */
    public function paginate(
        array $filters = [],
        int $perPage = 15,
        array $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null,
        bool $useCache = true,
        ?int $cacheTtl = null,
    ): LengthAwarePaginator;

    /**
     * Simple paginate.
     */
    public function simplePaginate(
        int $perPage = 15,
        array $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null
    ): Paginator;

    /**
     * Cursor paginate.
     */
    public function cursorPaginate(
        int $perPage = 15,
        array $columns = ['*'],
        string $cursorName = 'cursor',
        mixed $cursor = null
    ): CursorPaginator;

    /**
     * Begin a transaction.
     */

    /**
     * Get a fresh query builder instance.
     */
    public function query(): Builder;

    /**
     * Get the underlying model instance.
     */
    public function getModel(): Model;
}
