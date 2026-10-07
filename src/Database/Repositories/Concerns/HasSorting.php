<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Concerns;

use Ak279642\LaravelInfrastructure\Exceptions\SortNotAllowedException;
use Illuminate\Database\Eloquent\Builder;

trait HasSorting
{
    protected function applySorting(Builder $query, string|array $sort): Builder
    {
        if (is_string($sort)) {
            $sort = preg_split('/\s*,\s*/', trim($sort), -1, PREG_SPLIT_NO_EMPTY);
        }

        foreach ($sort as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $column = $key;
                $direction = strtolower($value);

                if (! in_array($direction, ['asc', 'desc'], true)) {
                    $direction = 'asc';
                }
            } else {
                $column = $value;
                $direction = 'asc';

                if (! is_string($column)) {
                    continue;
                }

                if (str_starts_with($column, '-')) {
                    $direction = 'desc';
                    $column = substr($column, 1);
                }
            }

            if (! $this->isSortAllowed($column)) {
                $this->handleDisallowedSort($column);

                continue;
            }

            $query->orderBy($column, $direction);
        }

        return $query;
    }

    protected function isSortAllowed(string $column): bool
    {
        return in_array($column, $this->allowedSorts, true);
    }

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        if (! $this->isSortAllowed($column)) {
            $this->handleDisallowedSort($column);

            return $this;
        }

        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        $this->query->orderBy($column, $direction);

        return $this;
    }

    public function orderByDesc(string $column): static
    {
        if (! $this->isSortAllowed($column)) {
            $this->handleDisallowedSort($column);

            return $this;
        }

        $this->query->orderByDesc($column);

        return $this;
    }

    public function latest(string $column = 'created_at'): static
    {
        if (! $this->isSortAllowed($column)) {
            $this->handleDisallowedSort($column);

            return $this;
        }

        $this->query->latest($column);

        return $this;
    }

    public function oldest(string $column = 'created_at'): static
    {
        if (! $this->isSortAllowed($column)) {
            $this->handleDisallowedSort($column);

            return $this;
        }

        $this->query->oldest($column);

        return $this;
    }

    protected function handleDisallowedSort(string $column): void
    {
        if ($this->strictFilters) {
            throw SortNotAllowedException::forRepository(
                $column,
                static::class,
                $this->allowedSorts,
            );
        }
    }
}
