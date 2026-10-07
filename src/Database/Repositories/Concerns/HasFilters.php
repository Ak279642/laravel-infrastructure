<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait HasFilters
{
    /**
     * Apply filters to query.
     */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        foreach ($filters as $key => $value) {
            if (in_array($key, [
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
            ], true)) {
                continue;
            }

            if (! $this->isFilterAllowed($key) && ! $this->isRelationFilter($key)) {
                continue;
            }

            if (str_contains($key, '.')) {
                $this->applyNestedFilter($query, $key, $value);

                continue;
            }

            if (is_array($value)) {
                $operator = strtolower($value['operator'] ?? '=');
                $filterValue = $value['value'] ?? null;

                $this->applyOperatorFilter($query, $key, $operator, $filterValue);

                continue;
            }

            $query->where($key, $value);
        }

        return $query;
    }

    /**
     * Apply nested relation filter.
     */
    protected function applyNestedFilter(Builder $query, string $key, mixed $value): Builder
    {
        $segments = explode('.', $key);
        $column = array_pop($segments);
        $relation = implode('.', $segments);

        // Handle != and <> using whereDoesntHave
        if (
            is_array($value)
            && in_array(strtolower($value['operator'] ?? ''), ['!=', '<>'], true)
        ) {
            return $query->whereDoesntHave($relation, function (Builder $q) use ($column, $value) {
                $q->where($column, $value['value']);
            });
        }

        return $query->whereHas($relation, function (Builder $q) use ($column, $value) {
            if (is_array($value)) {
                $operator = strtolower($value['operator'] ?? '=');
                $filterValue = $value['value'] ?? null;

                $this->applyOperatorFilter($q, $column, $operator, $filterValue);

                return;
            }

            $q->where($column, $value);
        });
    }

    /**
     * Apply operator filter.
     */
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

            'ilike' => $query->where($key, 'ILIKE', "%{$value}%"),

            'in' => ! empty($value)
                ? $query->whereIn($key, (array) $value)
                : $query,

            'in_or_null' => $query->where(function (Builder $q) use ($key, $value) {
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

    /**
     * Check if filter is allowed.
     */
    protected function isFilterAllowed(string $key): bool
    {
        return $this->allowedFilters === []
            || in_array($key, $this->allowedFilters, true);
    }

    /**
     * Check if key is a relation filter.
     */
    protected function isRelationFilter(string $key): bool
    {
        return str_contains($key, '.');
    }

    /**
     * Apply search to query.
     */
    protected function applySearch(Builder $query, string $search, ?array $searchColumns = null): Builder
    {
        $searchable = $searchColumns ?? $this->searchable;

        if ($searchable === []) {
            return $query;
        }

        $terms = array_values(array_filter(
            preg_split('/\s+/', trim($search))
        ));

        if ($terms === []) {
            return $query;
        }

        $driver = $query->getConnection()->getDriverName();
        $like = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';

        return $query->where(function (Builder $q) use ($terms, $like, $searchable) {
            foreach ($terms as $term) {
                $q->where(function (Builder $sub) use ($term, $like, $searchable) {
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

    /**
     * Apply nested search.
     */
    protected function applyNestedSearch(
        Builder $query,
        string $column,
        string $term,
        string $like
    ): Builder {
        $segments = explode('.', $column);
        $field = array_pop($segments);
        $relation = implode('.', $segments);

        return $query->orWhereHas($relation, function (Builder $q) use ($field, $term, $like) {
            $q->where($field, $like, "%{$term}%");
        });
    }
}
