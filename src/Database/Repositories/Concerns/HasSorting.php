<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Concerns;

use Ak279642\LaravelInfrastructure\Exceptions\SortNotAllowedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TModel of Model
 * @mixin \Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository<TModel>
 */
trait HasSorting
{
    /** @param Builder<TModel> $query @param array<array-key, string>|string $sort @return Builder<TModel> */
    protected function applySorting(
        Builder $query,
        string|array $sort,
    ): Builder {
        if (is_string($sort)) {
            $sort = preg_split(
                '/\s*,\s*/',
                trim($sort),
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [];
        }

        foreach ($sort as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $column = $key;
                $direction = strtolower($value);
            } elseif (is_string($value)) {
                $column = $value;
                $direction = 'asc';

                if (str_starts_with($column, '-')) {
                    $direction = 'desc';
                    $column = substr($column, 1);
                }
            } else {
                continue;
            }

            if (! $this->isSortAllowed($column)) {
                if ($this->strictSorts) {
                    throw new SortNotAllowedException(
                        $column,
                        static::class,
                        $this->allowedSorts,
                    );
                }

                continue;
            }

            if (! in_array(
                $direction,
                ['asc', 'desc'],
                true,
            )) {
                $direction = 'asc';
            }

            $query->orderBy(
                $column,
                $direction,
            );
        }

        return $query;
    }

    protected function isSortAllowed(string $column): bool
    {
        return in_array(
            $column,
            $this->allowedSorts,
            true,
        );
    }

    public function orderBy(
        string $column,
        string $direction = 'asc',
    ): static {
        if (! $this->isSortAllowed($column)) {
            if ($this->strictSorts) {
                throw new SortNotAllowedException(
                    $column,
                    static::class,
                    $this->allowedSorts,
                );
            }

            return clone $this;
        }

        $direction = strtolower($direction);

        if (! in_array(
            $direction,
            ['asc', 'desc'],
            true,
        )) {
            $direction = 'asc';
        }

        $clone = $this->forkQuery();
        $clone->query->orderBy(
            $column,
            $direction,
        );

        return $clone;
    }

    public function orderByDesc(string $column): static
    {
        return $this->orderBy(
            $column,
            'desc',
        );
    }

    public function latest(
        string $column = 'created_at',
    ): static {
        return $this->orderBy(
            $column,
            'desc',
        );
    }

    public function oldest(
        string $column = 'created_at',
    ): static {
        return $this->orderBy(
            $column,
            'asc',
        );
    }

    abstract protected function forkQuery(): static;
}
