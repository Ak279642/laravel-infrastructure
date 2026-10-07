<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Concerns;

use Ak279642\LaravelInfrastructure\Exceptions\FilterNotAllowedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TModel of Model
 * @mixin \Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository<TModel>
 */
trait HasFilters
{
    private const RESERVED_FILTER_KEYS = [
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
    ];

    private const FILTER_OPERATORS = [
        '=',
        '!=',
        '<>',
        '>',
        '>=',
        '<',
        '<=',
        'like',
        'ilike',
        'in',
        'in_or_null',
        'not_in',
        'between',
        'not_between',
        'null',
        'not_null',
    ];

    /** @param Builder<TModel> $query @param array<string, mixed> $filters @return Builder<TModel> */
    protected function applyFilters(
        Builder $query,
        array $filters,
    ): Builder {
        foreach ($filters as $key => $value) {
            if (
                ! is_string($key)
                || in_array(
                    $key,
                    self::RESERVED_FILTER_KEYS,
                    true,
                )
            ) {
                continue;
            }

            if (! $this->isFilterAllowed($key)) {
                if ($this->strictFilters) {
                    throw new FilterNotAllowedException(
                        $key,
                        static::class,
                        $this->allowedFilters,
                    );
                }

                continue;
            }

            if (str_contains($key, '.')) {
                if (! $this->isRelationFilter($key)) {
                    if ($this->strictFilters) {
                        throw new FilterNotAllowedException(
                            $key,
                            static::class,
                            $this->allowedFilters,
                        );
                    }

                    continue;
                }

                $this->applyNestedFilter(
                    $query,
                    $key,
                    $value,
                );

                continue;
            }

            if (is_array($value)) {
                [$operator, $filterValue] = $this->normalizeOperatorFilter(
                    $key,
                    $value,
                );

                $this->applyOperatorFilter(
                    $query,
                    $key,
                    $operator,
                    $filterValue,
                );

                continue;
            }

            $query->where($key, $value);
        }

        return $query;
    }

    /** @param Builder<TModel> $query @return Builder<TModel> */
    protected function applyNestedFilter(
        Builder $query,
        string $key,
        mixed $value,
    ): Builder {
        $segments = explode('.', $key);
        $column = array_pop($segments);
        $relation = implode('.', $segments);

        if (is_array($value)) {
            [$operator, $filterValue] = $this->normalizeOperatorFilter(
                $key,
                $value,
            );

            if (in_array($operator, ['!=', '<>'], true)) {
                return $query->whereDoesntHave(
                    $relation,
                    fn (Builder $nested): Builder => $nested->where(
                        $column,
                        '=',
                        $filterValue,
                    ),
                );
            }

            return $query->whereHas(
                $relation,
                function (Builder $nested) use (
                    $column,
                    $operator,
                    $filterValue,
                ): void {
                    $this->applyOperatorFilter(
                        $nested,
                        $column,
                        $operator,
                        $filterValue,
                    );
                },
            );
        }

        return $query->whereHas(
            $relation,
            fn (Builder $nested): Builder => $nested->where(
                $column,
                $value,
            ),
        );
    }

    /** @param Builder<TModel> $query @return Builder<TModel> */
    protected function applyOperatorFilter(
        Builder $query,
        string $key,
        string $operator,
        mixed $value,
    ): Builder {
        $driver = $query->getModel()->getConnection()->getDriverName();

        return match ($operator) {
            '=', '!=', '<>', '>', '>=', '<', '<=' => $query->where(
                $key,
                $operator,
                $value,
            ),

            'like', 'ilike' => $query->where(
                $key,
                $driver === 'pgsql' ? 'ILIKE' : 'LIKE',
                '%'.(string) $value.'%',
            ),

            'in' => (array) $value !== []
                ? $query->whereIn($key, (array) $value)
                : $query,

            'in_or_null' => (array) $value === []
                ? $query->whereNull($key)
                : $query->where(
                    function (Builder $nested) use (
                        $key,
                        $value,
                    ): void {
                        $nested
                            ->whereIn($key, (array) $value)
                            ->orWhereNull($key);
                    },
                ),

            'not_in' => (array) $value !== []
                ? $query->whereNotIn($key, (array) $value)
                : $query,

            'between' => is_array($value) && count($value) === 2
                ? $query->whereBetween($key, array_values($value))
                : $query,

            'not_between' => is_array($value) && count($value) === 2
                ? $query->whereNotBetween($key, array_values($value))
                : $query,

            'null' => $query->whereNull($key),

            'not_null' => $query->whereNotNull($key),

            default => $query,
        };
    }

    protected function isFilterAllowed(string $key): bool
    {
        return in_array(
            $key,
            $this->allowedFilters,
            true,
        );
    }

    protected function isRelationFilter(string $key): bool
    {
        if (! str_contains($key, '.')) {
            return false;
        }

        $segments = explode('.', $key);
        array_pop($segments);

        return $this->isRelationAllowed(
            implode('.', $segments),
        );
    }

    /** @param Builder<TModel> $query @param list<string>|null $searchColumns @return Builder<TModel> */
    protected function applySearch(
        Builder $query,
        string $search,
        ?array $searchColumns = null,
    ): Builder {
        $searchable = $this->searchable;

        if ($searchColumns !== null) {
            $requested = array_values(array_filter(
                $searchColumns,
                'is_string',
            ));

            $invalid = array_values(array_diff(
                $requested,
                $searchable,
            ));

            if ($invalid !== [] && $this->strictFilters) {
                throw new FilterNotAllowedException(
                    'search_columns:'.implode(',', $invalid),
                    static::class,
                    $searchable,
                );
            }

            $allowedRequested = array_values(array_intersect(
                $requested,
                $searchable,
            ));

            // If a caller supplied only disallowed columns, ignore that
            // override and retain the repository's safe default search list.
            // This avoids both unauthorized search expansion and accidentally
            // turning a non-empty search into an unfiltered query.
            if ($allowedRequested !== []) {
                $searchable = $allowedRequested;
            }
        }

        if ($searchable === []) {
            return $query;
        }

        $terms = array_values(array_filter(
            preg_split('/\s+/', trim($search)) ?: [],
        ));

        if ($terms === []) {
            return $query;
        }

        $driver = $query->getModel()->getConnection()->getDriverName();
        $like = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';

        return $query->where(
            function (Builder $outer) use (
                $terms,
                $like,
                $searchable,
            ): void {
                foreach ($terms as $term) {
                    $outer->where(
                        function (Builder $inner) use (
                            $term,
                            $like,
                            $searchable,
                        ): void {
                            foreach ($searchable as $column) {
                                if (str_contains($column, '.')) {
                                    $this->applyNestedSearch(
                                        $inner,
                                        $column,
                                        $term,
                                        $like,
                                    );

                                    continue;
                                }

                                $inner->orWhere(
                                    $column,
                                    $like,
                                    "%{$term}%",
                                );
                            }
                        },
                    );
                }
            },
        );
    }

    /** @param Builder<TModel> $query @return Builder<TModel> */
    protected function applyNestedSearch(
        Builder $query,
        string $column,
        string $term,
        string $like,
    ): Builder {
        $segments = explode('.', $column);
        $field = array_pop($segments);
        $relation = implode('.', $segments);

        return $query->orWhereHas(
            $relation,
            fn (Builder $nested): Builder => $nested->where(
                $field,
                $like,
                "%{$term}%",
            ),
        );
    }

    /**
     * @return array{0:string,1:mixed}
     */
    private function normalizeOperatorFilter(
        string $key,
        array $value,
    ): array {
        if (
            array_is_list($value)
            && count($value) === 2
            && is_string($value[0])
        ) {
            $operator = strtolower(trim($value[0]));
            $filterValue = $value[1];
        } else {
            $operator = strtolower(
                (string) ($value['operator'] ?? '='),
            );
            $filterValue = $value['value'] ?? null;
        }

        if (! in_array(
            $operator,
            self::FILTER_OPERATORS,
            true,
        )) {
            if ($this->strictFilters) {
                throw new FilterNotAllowedException(
                    "{$key} operator {$operator}",
                    static::class,
                    $this->allowedFilters,
                );
            }

            return ['=', $filterValue];
        }

        return [$operator, $filterValue];
    }
}
