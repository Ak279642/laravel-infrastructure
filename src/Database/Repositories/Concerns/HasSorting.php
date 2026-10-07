<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait HasSorting
{
    /**
     * Apply sorting to the query.
     */
    protected function applySorting(Builder $query, string|array $sort): Builder
    {
        if (is_string($sort)) {
            $sort = preg_split('/\s*,\s*/', trim($sort), -1, PREG_SPLIT_NO_EMPTY);
        }

        foreach ($sort as $key => $value) {
            // Associative format: ['created_at' => 'desc']
            if (is_string($key) && is_string($value)) {
                $column = $key;
                $direction = strtolower($value);

                if (! in_array($direction, ['asc', 'desc'], true)) {
                    $direction = 'asc';
                }
            } else {
                // Existing format: ['created_at', '-id']
                $column = $value;
                $direction = 'asc';

                if (str_starts_with($column, '-')) {
                    $direction = 'desc';
                    $column = substr($column, 1);
                }
            }

            if (! $this->isSortAllowed($column)) {
                continue;
            }

            $query->orderBy($column, $direction);
        }

        return $query;
    }

    /**
     * Determine whether the column is allowed for sorting.
     */
    protected function isSortAllowed(string $column): bool
    {
        return $this->allowedSorts === []
            || in_array($column, $this->allowedSorts, true);
    }

    /**
     * Apply order by.
     */
    public function orderBy(string $column, string $direction = 'asc'): static
    {
        if (! $this->isSortAllowed($column)) {
            return $this;
        }

        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        $this->query->orderBy($column, $direction);

        return $this;
    }

    /**
     * Apply descending order.
     */
    public function orderByDesc(string $column): static
    {
        if ($this->isSortAllowed($column)) {
            $this->query->orderByDesc($column);
        }

        return $this;
    }

    /**
     * Order by latest.
     */
    public function latest(string $column = 'created_at'): static
    {
        if ($this->isSortAllowed($column)) {
            $this->query->latest($column);
        }

        return $this;
    }

    /**
     * Order by oldest.
     */
    public function oldest(string $column = 'created_at'): static
    {
        if ($this->isSortAllowed($column)) {
            $this->query->oldest($column);
        }

        return $this;
    }
}
