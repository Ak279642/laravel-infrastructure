<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache\Concerns;

use Ak279642\LaravelInfrastructure\Cache\CacheTag;
use Ak279642\LaravelInfrastructure\Contracts\CacheableModel;
use Ak279642\LaravelInfrastructure\Observers\CacheObserver;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Opt-in cache metadata and automatic invalidation for Eloquent models.
 *
 * Models using this concern should implement CacheableModel.
 */
trait InteractsWithCache
{
    /**
     * Override cache behavior per model.
     *
     * @return array{enabled?:bool}
     */
    protected function cacheOptions(): array
    {
        return [];
    }

    public function usesInfrastructureCache(): bool
    {
        return (bool) (
            $this->cacheOptions()['enabled']
            ?? true
        );
    }

    public static function bootInteractsWithCache(): void
    {
        static::created(function (self $model): void {
            $model->runInfrastructureCacheObserverAfterCommit('created');
        });
        static::updated(function (self $model): void {
            $model->runInfrastructureCacheObserverAfterCommit('updated');
        });
        static::deleted(function (self $model): void {
            $model->runInfrastructureCacheObserverAfterCommit('deleted');
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::registerModelEvent(
                'restored',
                function (self $model): void {
                    $model->runInfrastructureCacheObserverAfterCommit('restored');
                },
            );
            static::registerModelEvent(
                'forceDeleted',
                function (self $model): void {
                    $model->runInfrastructureCacheObserverAfterCommit('forceDeleted');
                },
            );
        }
    }

    private function runInfrastructureCacheObserverAfterCommit(
        string $method,
    ): void {
        if (! $this->usesInfrastructureCache()) {
            return;
        }

        $callback = function () use ($method): void {
            $observer = app(CacheObserver::class);
            $observer->{$method}($this);
        };

        $connection = $this->getConnection();

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($callback);

            return;
        }

        $callback();
    }

    /**
     * Opt-in ownership partition. Unconfigured models retain model-wide invalidation.
     * A scope must be enforced by the repository, not merely added to a cache key.
     *
     * @return array{column:string,value:int|string,tag:string}|null
     */
    public function infrastructureCacheScope(): ?array
    {
        $options = $this->cacheOptions();
        $scope = $options['scope'] ?? null;
        if (! in_array($scope, ['user', 'tenant'], true)) {
            return null;
        }

        $column = $options['scope_column'] ?? ($scope === 'user' ? 'user_id' : 'tenant_id');
        if (! is_string($column) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
            throw new \InvalidArgumentException('Invalid cache scope column.');
        }

        $user = auth()->user();
        $value = $scope === 'user' ? auth()->id() : $user?->getAttribute($column);
        if (! is_int($value) && (! is_string($value) || $value === '')) {
            throw new \RuntimeException('An authenticated cache scope is required.');
        }

        return [
            'column' => $column,
            'value' => $value,
            'tag' => static::cacheTag().':scope:'.$scope.':'.hash('sha256', (string) $value),
        ];
    }

    public function infrastructureScopeTagFor(int|string $value): ?string
    {
        $scope = $this->cacheOptions()['scope'] ?? null;
        if (! in_array($scope, ['user', 'tenant'], true)) {
            return null;
        }

        return static::cacheTag().':scope:'.$scope.':'.hash('sha256', (string) $value);
    }

    public function infrastructureScopeColumn(): ?string
    {
        $options = $this->cacheOptions();
        if (! in_array($options['scope'] ?? null, ['user', 'tenant'], true)) {
            return null;
        }

        $column = $options['scope_column'] ?? ($options['scope'] === 'user' ? 'user_id' : 'tenant_id');
        if (! is_string($column) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
            throw new \InvalidArgumentException('Invalid cache scope column.');
        }

        return $column;
    }

    public static function cacheTag(): string
    {
        return CacheTag::fromModel(static::class);
    }

    public function getCacheTags(): array
    {
        $id = $this->getKey();

        return $id === null
            ? [static::cacheTag()]
            : CacheTag::model(static::cacheTag(), $id);
    }

    public function getCacheDependencyTags(mixed $result = null, array $relations = []): array
    {
        $tags = [static::cacheTag()];

        if ($result instanceof CacheableModel) {
            $tags = CacheTag::merge($tags, $result->getCacheTags());
        }

        if ($result instanceof Collection) {
            foreach ($result as $item) {
                if ($item instanceof CacheableModel) {
                    $tags = CacheTag::merge($tags, $item->getCacheTags());
                }
            }
        }

        foreach ($relations as $relation) {
            if (! is_string($relation) || $relation === '') {
                continue;
            }

            $relation = trim(explode(':', $relation, 2)[0]);

            if ($relation === '') {
                continue;
            }

            $tags = CacheTag::merge(
                $tags,
                $this->getNestedRelationDependencyTags($relation),
            );

            $root = explode('.', $relation)[0];

            if ($root !== '' && $this->relationLoaded($root)) {
                $loaded = $this->getRelation($root);
                $items = $loaded instanceof Collection
                    ? $loaded
                    : collect([$loaded]);

                foreach ($items as $item) {
                    if ($item instanceof CacheableModel) {
                        $tags = CacheTag::merge(
                            $tags,
                            $item->getCacheTags(),
                        );
                    }
                }
            }
        }

        return CacheTag::tags(...$tags);
    }

    private function getNestedRelationDependencyTags(string $relation): array
    {
        $tags = [];
        $model = $this;

        foreach (explode('.', $relation) as $segment) {
            if ($segment === '' || ! method_exists($model, $segment)) {
                break;
            }

            try {
                $relationObject = $model->{$segment}();
            } catch (Throwable) {
                break;
            }

            if (! $relationObject instanceof Relation) {
                break;
            }

            $related = $relationObject->getRelated();

            if ($related instanceof CacheableModel) {
                $tags[] = $related::cacheTag();
            }

            $model = $related;
        }

        return CacheTag::tags(...$tags);
    }

    public function getCacheInvalidationTags(): array
    {
        $column = $this->infrastructureScopeColumn();
        if ($column !== null) {
            $tags = [];
            foreach ([$this->getRawOriginal($column), $this->getAttribute($column)] as $value) {
                if (is_int($value) || (is_string($value) && $value !== '')) {
                    $tags[] = $this->infrastructureScopeTagFor($value);
                }
            }
            return CacheTag::tags(...$tags);
        }

        return CacheTag::merge(
            [static::cacheTag()],
            $this->getCacheTags(),
        );
    }

    public function invalidateCache(): void
    {
        // Hook for application models that maintain additional cache state.
    }
}
