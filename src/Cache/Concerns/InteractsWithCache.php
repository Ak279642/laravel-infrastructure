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

        // Snapshot old/new ownership while Eloquent still retains pre-save originals.
        $tags = $this->getCacheInvalidationTags();
        $callback = function () use ($method, $tags): void {
            $observer = app(CacheObserver::class);
            $observer->{$method}($this, $tags);
        };

        $connection = $this->getConnection();

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($callback);

            return;
        }

        $callback();
    }

    /**
     * Resolve ownership dimensions without database queries. Legacy scope/scope_column
     * and new scopes mappings are supported. Each mapping's value can be a user
     * attribute name, "auth.id", or an explicit closure resolver.
     *
     * @return array<string, int|string>
     */
    public function infrastructureCacheScopes(): array
    {
        $options = $this->cacheOptions();
        $definitions = $options['scopes'] ?? null;
        if ($definitions === null && in_array($options['scope'] ?? null, ['user', 'tenant'], true)) {
            $type = $options['scope'];
            $column = $this->infrastructureScopeColumn();
            $definitions = [$column => $type === 'user' ? 'auth.id' : 'auth.'.$column];
        }
        if ($definitions === null) {
            return [];
        }
        if (! is_array($definitions) || $definitions === []) {
            throw new \InvalidArgumentException('Cache scopes must be a non-empty column-to-resolver map.');
        }

        $user = auth()->user();
        if ($user === null) {
            throw new \RuntimeException('Authenticated cache scope is required.');
        }

        $resolved = [];
        foreach ($definitions as $column => $source) {
            if (! is_string($column) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
                throw new \InvalidArgumentException('Invalid cache scope column.');
            }
            $value = match (true) {
                $source === 'auth.id' => $user->getAuthIdentifier(),
                is_string($source) && str_starts_with($source, 'auth.') =>
                    $user->getAttribute(substr($source, 5)),
                $source instanceof \Closure => $source($user),
                default => throw new \InvalidArgumentException('Invalid cache scope resolver.'),
            };
            if (! is_int($value) && (! is_string($value) || $value === '')) {
                throw new \RuntimeException('A non-empty authenticated cache scope is required.');
            }
            $resolved[$column] = $value;
        }
        ksort($resolved);

        return $resolved;
    }

    /** @return array{column:string,value:int|string,tag:string}|null */
    public function infrastructureCacheScope(): ?array
    {
        $scopes = $this->infrastructureCacheScopes();
        if ($scopes === []) {
            return null;
        }

        $column = array_key_first($scopes);
        return [
            'column' => $column,
            'value' => $scopes[$column],
            'tag' => $this->infrastructureScopeTagForValues($scopes),
        ];
    }

    /** @param array<string,int|string> $values */
    public function infrastructureScopeTagForValues(array $values): string
    {
        ksort($values);
        return static::cacheTag().':scope:'.hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    public function infrastructureScopeTagFor(int|string $value): ?string
    {
        $column = $this->infrastructureScopeColumn();
        return $column === null ? null : $this->infrastructureScopeTagForValues([$column => $value]);
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

    /**
     * Visibility resolvers may add OR/global conditions and must return a Builder.
     * Their cache dependencies cannot be inferred from ownership columns, so
     * affected caches use conservative model-wide invalidation.
     */
    public function infrastructureVisibilityResolver(): ?\Closure
    {
        $resolver = $this->cacheOptions()['visibility_resolver'] ?? null;
        if ($resolver !== null && ! $resolver instanceof \Closure) {
            throw new \InvalidArgumentException('Visibility resolver must be a closure.');
        }
        return $resolver;
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
        $options = $this->cacheOptions();
        if (isset($options['visibility_resolver'])) {
            return [static::cacheTag()];
        }

        $definitions = $options['scopes'] ?? null;
        if ($definitions === null) {
            $column = $this->infrastructureScopeColumn();
            $definitions = $column === null ? [] : [$column => true];
        }
        if ($definitions === []) {
            return CacheTag::merge([static::cacheTag()], $this->getCacheTags());
        }

        $old = [];
        $new = [];
        foreach (array_keys($definitions) as $column) {
            if (! is_string($column)) {
                throw new \InvalidArgumentException('Invalid cache scope column.');
            }
            $previous = $this->getRawOriginal($column);
            $current = $this->getAttribute($column);
            if (! is_int($current) && (! is_string($current) || $current === '')) {
                return [static::cacheTag()];
            }
            $new[$column] = $current;
            $old[$column] = is_int($previous) || (is_string($previous) && $previous !== '') ? $previous : $current;
        }
        return CacheTag::tags(
            $this->infrastructureScopeTagForValues($old),
            $this->infrastructureScopeTagForValues($new),
        );
    }

    public function invalidateCache(): void
    {
        // Hook for application models that maintain additional cache state.
    }
}
