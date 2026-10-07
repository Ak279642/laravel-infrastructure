<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Concerns;

use Ak279642\LaravelInfrastructure\Exceptions\FilterNotAllowedException;
use Illuminate\Database\Eloquent\Builder;

trait HasFilters
{
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        foreach ($filters as $key => $value) {
            if ($this->isRepositoryMetaFilter($key)) {
                continue;
            }

            if (! $this->isFilterAllowed($key)) {
                $this->handleDisallowedFilter($key);

                continue;
            }

            if (str_contains($key, '.')) {
                $relation = substr($key, 0, (int) strrpos($key, '.'));

                if (! $this->isRelationAllowed($relation)) {
                    $this->handleDisallowedFilter($key);

                    continue;
                }

                $this->applyNestedFilter($query, $key, $value);

                continue;
            }

            if (is_array($value)) {
                [$operator, $filterValue] = $this->normalizeOperatorFilter($value);

                $this->applyOperatorFilter($query, $key, $operator, $filterValue);

                continue;
            }

            $query->where($key, $value);
        }

        return $query;
    }

    protected function applyNestedFilter(Builder $query, string $key, mixed $value): Builder
    {
        $segments = explode('.', $key);
        $column = array_pop($segments);
        $relation = implode('.', $segments);

        if (is_array($value)) {
            [$operator, $filterValue] = $this->normalizeOperatorFilter($value);

            if (in_array($operator, ['!=', '<>'], true)) {
                return $query->whereDoesntHave($relation, function (Builder $q) use ($column, $filterValue): void {
                    $q->where($column, $filterValue);
                });
            }

            return $query->whereHas($relation, function (Builder $q) use ($column, $operator, $filterValue): void {
                $this->applyOperatorFilter($q, $column, $operator, $filterValue);
            });
        }

        return $query->whereHas($relation, function (Builder $q) use ($column, $value): void {
            $q->where($column, $value);
        });
    }

    protected function applyOperatorFilter(
        Builder $query,
        string $key,
        string $operator,
        mixed $value
    ): Builder {
        $driver = $query->getConnection()->getDriverName();

        return match ($operator) {
            '=', '!=', '<>', '>', '>=', '<', '<=' => $query->where($key, $operator, $value),

            'like' => $query->where(
                $key,
                $driver === 'pgsql' ? 'ILIKE' : 'LIKE',
                "%{$value}%"
            ),

            'ilike' => $driver === 'pgsql'
                ? $query->where($key, 'ILIKE', "%{$value}%")
                : $query->whereRaw('LOWER('.$query->getGrammar()->wrap($key).') LIKE ?', ['%'.strtolower((string) $value).'%']),

            'in' => ! empty($value)
                ? $query->whereIn($key, (array) $value)
                : $query,

            'in_or_null' => $query->where(function (Builder $q) use ($key, $value): void {
                $q->whereIn($key, (array) $value)->orWhereNull($key);
            }),

            'not_in' => ! empty($value)
                ? $query->whereNotIn($key, (array) $value)
                : $query,

            'between' => is_array($value) && count($value) === 2
                ? $query->whereBetween($key, $value)
                : $query,

            'not_between' => is_array($value) && count($value) === 2
                ? $query->whereNotBetween($key, $value)
                : $query,

            'null' => $query->whereNull($key),

            'not_null' => $query->whereNotNull($key),

            default => $query->where($key, $value),
        };
    }

    protected function isFilterAllowed(string $key): bool
    {
        return in_array($key, $this->allowedFilters, true);
    }

    protected function applySearch(Builder $query, string $search, ?array $searchColumns = null): Builder
    {
        $searchable = $this->resolveSearchColumns($searchColumns);

        if ($searchable === []) {
            return $searchColumns === null
                ? $query
                : $query->whereRaw('1 = 0');
        }

        $terms = array_values(array_filter(
            preg_split('/\s+/', trim($search))
        ));

        if ($terms === []) {
            return $query;
        }

        $driver = $query->getConnection()->getDriverName();
        $like = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';

        return $query->where(function (Builder $q) use ($terms, $like, $searchable): void {
            foreach ($terms as $term) {
                $q->where(function (Builder $sub) use ($term, $like, $searchable): void {
                    foreach ($searchable as $column) {
                        if (str_contains($column, '.')) {
                            $this->applyNestedSearch($sub, $column, $term, $like);

                            continue;
                        }

                        $sub->orWhere($column, $like, "%{$term}%");
                    }
                });
            }
        });
    }

    protected function applyNestedSearch(
        Builder $query,
        string $column,
        string $term,
        string $like
    ): Builder {
        $segments = explode('.', $column);
        $field = array_pop($segments);
        $relation = implode('.', $segments);

        if (! $this->isRelationAllowed($relation)) {
            $this->handleDisallowedFilter($column);

            return $query;
        }

        return $query->orWhereHas($relation, function (Builder $q) use ($field, $term, $like): void {
            $q->where($field, $like, "%{$term}%");
        });
    }

    protected function resolveSearchColumns(?array $requested): array
    {
        if ($requested === null) {
            return $this->searchable;
        }

        $allowed = array_values(array_intersect($requested, $this->searchable));

        if ($this->strictFilters && count($allowed) !== count(array_unique($requested))) {
            $invalid = array_values(array_diff(array_unique($requested), $this->searchable));
            $this->handleDisallowedFilter((string) ($invalid[0] ?? 'search_columns'));
        }

        return $allowed;
    }

    protected function normalizeOperatorFilter(array $value): array
    {
        if (array_is_list($value) && count($value) === 2 && is_string($value[0])) {
            return [strtolower($value[0]), $value[1]];
        }

        return [
            strtolower((string) ($value['operator'] ?? '=')),
            $value['value'] ?? null,
        ];
    }

    protected function isRepositoryMetaFilter(string|int $key): bool
    {
        return is_string($key) && in_array($key, [
            'search',
            'scopes',
            'with',
            'sort',
            'sort_by',
            'sort_order',
            'page',
            'per_page',
            'search_columns',
            'with_count',
        ], true);
    }

    protected function handleDisallowedFilter(string $key): void
    {
        if ($this->strictFilters) {
            throw FilterNotAllowedException::forRepository(
                $key,
                static::class,
                $this->allowedFilters,
            );
        }
    }
}
