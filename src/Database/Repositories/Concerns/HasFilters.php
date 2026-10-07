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

            $allowed = $this->isFilterAllowed($key) || $this->isRelationFilterAllowed($key);

            if (! $allowed) {
                if ($this->strictFilters) {
                    throw FilterNotAllowedException::forRepository(
                        (string) $key,
                        static::class,
                        $this->allowedFilters,
                    );
                }

                continue;
            }

            if (str_contains($key, '.')) {
                $this->applyNestedFilter($query, $key, $value);

                continue;
            }

            if (is_array($value)) {
                [$operator, $filterValue] = $this->normalizeOperatorInput($value);
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

        if (
            is_array($value)
            && in_array(strtolower((string) ($value['operator'] ?? $value[0] ?? '')), ['!=', '<>'], true)
        ) {
            [, $filterValue] = $this->normalizeOperatorInput($value);

            return $query->whereDoesntHave($relation, function (Builder $q) use ($column, $filterValue): void {
                $q->where($column, $filterValue);
            });
        }

        return $query->whereHas($relation, function (Builder $q) use ($column, $value): void {
            if (is_array($value)) {
                [$operator, $filterValue] = $this->normalizeOperatorInput($value);
                $this->applyOperatorFilter($q, $column, $operator, $filterValue);

                return;
            }

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
            'like' => $query->where($key, $driver === 'pgsql' ? 'ILIKE' : 'LIKE', "%{$value}%"),
            'ilike' => $query->where($key, $driver === 'pgsql' ? 'ILIKE' : 'LIKE', "%{$value}%"),
            'in' => ! empty($value) ? $query->whereIn($key, (array) $value) : $query,
            'in_or_null' => $query->where(function (Builder $q) use ($key, $value): void {
                $q->whereIn($key, (array) $value)->orWhereNull($key);
            }),
            'not_in' => ! empty($value) ? $query->whereNotIn($key, (array) $value) : $query,
            'between' => is_array($value) && count($value) === 2 ? $query->whereBetween($key, $value) : $query,
            'not_between' => is_array($value) && count($value) === 2 ? $query->whereNotBetween($key, $value) : $query,
            'null' => $query->whereNull($key),
            'not_null' => $query->whereNotNull($key),
            default => $query->where($key, $value),
        };
    }

    protected function isFilterAllowed(string $key): bool
    {
        if (str_contains($key, '.')) {
            return false;
        }

        return $this->allowedFilters === []
            || in_array($key, $this->allowedFilters, true);
    }

    protected function isRelationFilterAllowed(string $key): bool
    {
        if (! str_contains($key, '.')) {
            return false;
        }

        $segments = explode('.', $key);
        array_pop($segments);
        $relation = implode('.', $segments);

        if ($relation === '' || ! $this->relationExists($relation)) {
            return false;
        }

        if ($this->allowedRelations === []) {
            return true;
        }

        return in_array($relation, $this->allowedRelations, true)
            || in_array(explode('.', $relation)[0], $this->allowedRelations, true);
    }

    protected function applySearch(Builder $query, string $search, ?array $searchColumns = null): Builder
    {
        $searchable = $searchColumns ?? $this->searchable;

        if ($searchable === []) {
            return $query;
        }

        if ($searchColumns !== null) {
            $searchable = array_values(array_intersect($searchColumns, $this->searchable));

            if ($searchable === []) {
                return $query;
            }
        }

        $terms = array_values(array_filter(preg_split('/\s+/', trim($search))));

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

        return $query->orWhereHas($relation, function (Builder $q) use ($field, $term, $like): void {
            $q->where($field, $like, "%{$term}%");
        });
    }

    private function normalizeOperatorInput(array $value): array
    {
        if (array_is_list($value)) {
            return [
                strtolower((string) ($value[0] ?? '=')),
                $value[1] ?? null,
            ];
        }

        return [
            strtolower((string) ($value['operator'] ?? '=')),
            $value['value'] ?? null,
        ];
    }
}
