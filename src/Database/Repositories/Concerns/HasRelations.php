<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Concerns;

use Ak279642\LaravelInfrastructure\Exceptions\RelationNotAllowedException;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

trait HasRelations
{
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

    protected function applyRelations(Builder $query, array|string $relations): Builder
    {
        $allowed = $this->getAllowedRelations($this->normalizeRelations($relations));

        if ($allowed !== []) {
            $query->with($allowed);
        }

        return $query;
    }

    protected function applyRelationCounts(Builder $query, array|string $relations): Builder
    {
        $allowed = $this->getAllowedRelations($this->normalizeRelations($relations));

        if ($allowed !== []) {
            $query->withCount($allowed);
        }

        return $query;
    }

    protected function getAllowedRelations(array $relations): array
    {
        $result = [];

        foreach ($relations as $key => $value) {
            $relation = is_string($key) ? $key : $value;

            if (! is_string($relation)) {
                continue;
            }

            $canonical = $this->canonicalRelationName($relation);

            if (! $this->isRelationAllowed($canonical) || ! $this->relationExists($canonical)) {
                $this->handleDisallowedRelation($canonical);

                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    protected function isRelationAllowed(string $relation): bool
    {
        return in_array(
            $this->canonicalRelationName($relation),
            array_map(fn (string $allowed): string => $this->canonicalRelationName($allowed), $this->allowedRelations),
            true,
        );
    }

    protected function relationExists(string $relation): bool
    {
        $relation = $this->canonicalRelationName($relation);

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

    public function with(array|string $relations): static
    {
        $relations = $this->getAllowedRelations($this->normalizeRelations($relations));

        if ($relations !== []) {
            $this->query->with($relations);
        }

        return $this;
    }

    public function withCount(array|string $relations): static
    {
        $relations = $this->getAllowedRelations($this->normalizeRelations($relations));

        if ($relations !== []) {
            $this->query->withCount($relations);
        }

        return $this;
    }

    public function withSum(array|string $relations, string $column): static
    {
        $relations = $this->getAllowedRelations($this->normalizeRelations($relations));

        foreach ($relations as $key => $value) {
            $relation = is_string($key) ? $key : $value;

            if (is_string($relation)) {
                $this->query->withSum($relation, $column);
            }
        }

        return $this;
    }

    public function withAvg(array|string $relations, string $column): static
    {
        $relations = $this->getAllowedRelations($this->normalizeRelations($relations));

        foreach ($relations as $key => $value) {
            $relation = is_string($key) ? $key : $value;

            if (is_string($relation)) {
                $this->query->withAvg($relation, $column);
            }
        }

        return $this;
    }

    protected function canonicalRelationName(string $relation): string
    {
        return trim(explode(':', $relation, 2)[0]);
    }

    protected function handleDisallowedRelation(string $relation): void
    {
        if ($this->strictFilters) {
            throw RelationNotAllowedException::forRepository(
                $relation,
                static::class,
                $this->allowedRelations,
            );
        }
    }
}
