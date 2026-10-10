<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    /**
     * Apply model scopes to the query.
     */
    protected function applyScopes(Builder $query, array|string $scopes): Builder
    {
        $scopes = $this->normalizeScopes($scopes);

        foreach ($scopes as $scope) {
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

    /**
     * Apply a single scope with optional arguments.
     */
    public function scope(string $scope, mixed ...$args): static
    {
        if ($this->isScopeAllowed($scope)
            && $this->hasScope($this->query, $scope)) {
            // Store scope as a builder modifier; query() must start with a
            // fresh Eloquent builder so request-specific global scopes apply.
            $previous = $this->globalQueryCallback;
            $this->globalQueryCallback = static function (Builder $query) use ($previous, $scope, $args): Builder {
                $query = $previous === null ? $query : ($previous($query) ?? $query);
                $query->{$scope}(...$args);

                return $query;
            };
        }

        return $this;
    }

    /**
     * Normalize scopes.
     */
    protected function normalizeScopes(array|string $scopes): array
    {
        if (is_string($scopes)) {
            $scopes = preg_split(
                '/\s*,\s*/',
                trim($scopes),
                -1,
                PREG_SPLIT_NO_EMPTY
            );
        }

        return array_values(array_unique($scopes));
    }

    protected function isScopeAllowed(string $scope): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $scope) === 1
            && in_array($scope, $this->allowedScopes, true);
    }

    /**
     * Check whether the model defines the given local scope.
     */
    protected function hasScope(Builder $query, string $scope): bool
    {
        return method_exists(
            $query->getModel(),
            'scope'.ucfirst($scope)
        );
    }
}
