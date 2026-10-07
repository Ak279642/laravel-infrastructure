<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    protected function applyScopes(
        Builder $query,
        array|string $scopes,
    ): Builder {
        foreach ($this->normalizeScopes($scopes) as $scope) {
            if (
                ! $this->isScopeAllowed($scope)
                || ! $this->hasScope($query, $scope)
            ) {
                continue;
            }

            $query->{$scope}();
        }

        return $query;
    }

    public function scope(
        string $scope,
        mixed ...$args,
    ): static {
        $clone = $this->forkQuery();

        if (
            $this->isScopeAllowed($scope)
            && $this->hasScope($clone->query, $scope)
        ) {
            $clone->query->{$scope}(...$args);
        }

        return $clone;
    }

    protected function normalizeScopes(
        array|string $scopes,
    ): array {
        if (is_string($scopes)) {
            $scopes = preg_split(
                '/\s*,\s*/',
                trim($scopes),
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [];
        }

        return array_values(array_unique(array_filter(
            $scopes,
            'is_string',
        )));
    }

    protected function hasScope(
        Builder $query,
        string $scope,
    ): bool {
        return method_exists(
            $query->getModel(),
            'scope'.ucfirst($scope),
        );
    }

    protected function isScopeAllowed(string $scope): bool
    {
        return in_array(
            $scope,
            $this->allowedScopes,
            true,
        );
    }

    abstract protected function forkQuery(): static;
}
