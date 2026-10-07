<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Concerns;

use Ak279642\LaravelInfrastructure\Exceptions\RelationNotAllowedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;

/**
 * @template TModel of Model
 * @mixin \Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository<TModel>
 */
trait HasRelations
{
    /** @param array<array-key, mixed>|string $relations @return array<array-key, mixed> */
    protected function normalizeRelations(
        array|string $relations,
    ): array {
        if (is_string($relations)) {
            $relations = preg_split(
                '/\s*,\s*/',
                trim($relations),
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [];
        }

        return $relations;
    }

    /** @param Builder<TModel> $query @param array<array-key, mixed>|string $relations @return Builder<TModel> */
    protected function applyRelations(
        Builder $query,
        array|string $relations,
    ): Builder {
        $allowed = $this->getAllowedRelations(
            $this->normalizeRelations($relations),
        );

        if ($allowed !== []) {
            $query->with($allowed);
        }

        return $query;
    }

    /** @param Builder<TModel> $query @param array<array-key, mixed>|string $relations @return Builder<TModel> */
    protected function applyRelationCounts(
        Builder $query,
        array|string $relations,
    ): Builder {
        $allowed = $this->getAllowedRelations(
            $this->normalizeRelations($relations),
        );

        if ($allowed !== []) {
            $query->withCount($allowed);
        }

        return $query;
    }

    /** @param array<array-key, mixed> $relations @return array<array-key, mixed> */
    protected function getAllowedRelations(array $relations): array
    {
        $result = [];

        foreach ($relations as $key => $value) {
            $relation = is_string($key)
                ? $key
                : $value;

            if (! is_string($relation)) {
                continue;
            }

            $permissionName = $this->relationPermissionName(
                $relation,
            );

            if (! $this->isRelationAllowed($permissionName)) {
                if ($this->strictRelations) {
                    throw new RelationNotAllowedException(
                        $permissionName,
                        static::class,
                        $this->allowedRelations,
                    );
                }

                continue;
            }

            if (! $this->relationExists($relation)) {
                if ($this->strictRelations) {
                    throw new RelationNotAllowedException(
                        $permissionName,
                        static::class,
                        $this->allowedRelations,
                    );
                }

                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    protected function isRelationAllowed(string $relation): bool
    {
        return in_array(
            $this->relationPermissionName($relation),
            $this->allowedRelations,
            true,
        );
    }

    protected function relationExists(string $relation): bool
    {
        $relation = $this->relationPermissionName(
            $relation,
        );

        if ($relation === '') {
            return false;
        }

        $model = $this->model;

        foreach (explode('.', $relation) as $segment) {
            if (
                $segment === ''
                || ! method_exists($model, $segment)
            ) {
                return false;
            }

            try {
                $relationObject = $model->{$segment}();

                if (! $relationObject instanceof Relation) {
                    return false;
                }

                $model = $relationObject->getRelated();
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }

    /** @param array<array-key, mixed>|string $relations */
    public function with(array|string $relations): static
    {
        $relations = $this->getAllowedRelations(
            $this->normalizeRelations($relations),
        );

        $clone = $this->forkQuery();

        if ($relations !== []) {
            $clone->query->with($relations);
        }

        return $clone;
    }

    /** @param array<array-key, mixed>|string $relations */
    public function withCount(array|string $relations): static
    {
        $relations = $this->getAllowedRelations(
            $this->normalizeRelations($relations),
        );

        $clone = $this->forkQuery();

        if ($relations !== []) {
            $clone->query->withCount($relations);
        }

        return $clone;
    }

    /** @param array<array-key, mixed>|string $relations */
    public function withSum(
        array|string $relations,
        string $column,
    ): static {
        $relations = $this->getAllowedRelations(
            $this->normalizeRelations($relations),
        );

        $clone = $this->forkQuery();

        foreach ($relations as $key => $value) {
            $relation = is_string($key)
                ? $key
                : $value;

            if (is_string($relation)) {
                $clone->query->withSum(
                    $relation,
                    $column,
                );
            }
        }

        return $clone;
    }

    /** @param array<array-key, mixed>|string $relations */
    public function withAvg(
        array|string $relations,
        string $column,
    ): static {
        $relations = $this->getAllowedRelations(
            $this->normalizeRelations($relations),
        );

        $clone = $this->forkQuery();

        foreach ($relations as $key => $value) {
            $relation = is_string($key)
                ? $key
                : $value;

            if (is_string($relation)) {
                $clone->query->withAvg(
                    $relation,
                    $column,
                );
            }
        }

        return $clone;
    }

    private function relationPermissionName(
        string $relation,
    ): string {
        return trim(
            explode(':', $relation, 2)[0],
        );
    }

    abstract protected function forkQuery(): static;
}
