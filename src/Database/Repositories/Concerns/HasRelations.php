<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Concerns;

use Ak279642\LaravelInfrastructure\Exceptions\RelationNotAllowedException;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

trait HasRelations
{
    /**
     * Normalize relation input while preserving keyed relation constraints.
     */
    protected function normalizeRelations(array|string $relations): array
    {
        if (is_string($relations)) {
            $relations = preg_split(
                '/\s*,\s*/',
                trim($relations),
                -1,
                PREG_SPLIT_NO_EMPTY
            );
        }

        return $relations;
    }

    /**
     * Apply eager loaded relations.
     *
     * Invalid relation names are ignored so a stale/optional relation
     * cannot break an otherwise valid repository query.
     */
    protected function applyRelations(Builder $query, array|string $relations): Builder
    {
        $relations = $this->normalizeRelations($relations);
        $allowed = $this->getAllowedRelations($relations);

        if ($allowed !== []) {
            $query->with($allowed);
        }

        return $query;
    }

    /**
     * Filter relations by repository permissions and actual model relations.
     */
    protected function getAllowedRelations(array $relations): array
    {
        $result = [];

        foreach ($relations as $key => $value) {
            $relation = is_string($key) ? $key : $value;

            if (! is_string($relation) || ! $this->relationExists($relation)) {
                if ($this->strictRelations && is_string($relation)) {
                    throw RelationNotAllowedException::forRepository(
                        $relation,
                        static::class,
                        $this->allowedRelations,
                    );
                }

                continue;
            }

            $baseRelation = trim(explode(':', $relation, 2)[0]);

            if (
                $this->allowedRelations !== []
                && ! in_array($baseRelation, $this->allowedRelations, true)
            ) {
                if ($this->strictRelations) {
                    throw RelationNotAllowedException::forRepository(
                        $baseRelation,
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

    /**
     * Determine whether a direct or nested Eloquent relation exists.
     *
     * Supports constrained relations such as `relation:id,name`.
     */
    protected function relationExists(string $relation): bool
    {
        $relation = trim(explode(':', $relation, 2)[0]);

        if ($relation === '') {
            return false;
        }

        $model = $this->model;

        foreach (explode('.', $relation) as $segment) {
            if ($segment === '' || ! method_exists($model, $segment)) {
                return false;
            }

            try {
                $relationObject = $model->{$segment}();
                $model = $relationObject->getRelated();
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }

    /**
     * Eager load relations.
     */
    public function with(array|string $relations): static
    {
        $relations = $this->normalizeRelations($relations);
        $relations = $this->getAllowedRelations($relations);

        if ($relations !== []) {
            $this->query->with($relations);
        }

        return $this;
    }

    /**
     * Eager load relation counts.
     */
    public function withCount(array|string $relations): static
    {
        $relations = $this->normalizeRelations($relations);
        $relations = $this->getAllowedRelations($relations);

        if ($relations !== []) {
            $this->query->withCount($relations);
        }

        return $this;
    }

    /**
     * Eager load relation sums.
     */
    public function withSum(array|string $relations, string $column): static
    {
        $relations = $this->normalizeRelations($relations);
        $relations = $this->getAllowedRelations($relations);

        foreach ($relations as $key => $value) {
            $relation = is_string($key) ? $key : $value;

            if (is_string($relation)) {
                $this->query->withSum($relation, $column);
            }
        }

        return $this;
    }

    /**
     * Eager load relation averages.
     */
    public function withAvg(array|string $relations, string $column): static
    {
        $relations = $this->normalizeRelations($relations);
        $relations = $this->getAllowedRelations($relations);

        foreach ($relations as $key => $value) {
            $relation = is_string($key) ? $key : $value;

            if (is_string($relation)) {
                $this->query->withAvg($relation, $column);
            }
        }

        return $this;
    }
}
