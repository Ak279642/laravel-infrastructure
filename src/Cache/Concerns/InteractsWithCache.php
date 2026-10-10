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
     * Normalize explicit ownership rules to column => value resolver mappings.
     *
     * Supported: scope => ['column' => 'owner_id', 'guard' => 'web'],
     * scopes => [['column' => 'owner_id', 'guard' => 'web'], ...],
     * legacy scope => user|tenant and scopes => ['owner_id' => 'auth.id'].
     *
     * @return array<string, mixed>
     */
    public function infrastructureCacheScopeDefinitions(): array
    {
        $options = $this->cacheOptions();
        $definitions = $options['scopes'] ?? null;

        if ($definitions === null) {
            $single = $options['scope'] ?? null;
            if (is_array($single)) {
                $definitions = [$single];
            } elseif (in_array($single, ['user', 'tenant'], true)) {
                $column = $this->infrastructureScopeColumn();
                $definitions = [$column => $single === 'user' ? 'auth.id' : 'auth.'.$column];
            }
        }

        if ($definitions === null) {
            return [];
        }
        if (! is_array($definitions) || $definitions === []) {
            throw new \InvalidArgumentException('Cache scopes must be a non-empty column-to-resolver map or list of column/guard rules.');
        }

        if (! array_is_list($definitions)) {
            return $definitions;
        }

        $mapped = [];
        foreach ($definitions as $rule) {
            if (! is_array($rule) || ! isset($rule['column'], $rule['guard'])
                || ! is_string($rule['column']) || ! is_string($rule['guard'])
                || $rule['guard'] === '') {
                throw new \InvalidArgumentException('Each cache scope rule requires a column and guard.');
            }
            $column = $rule['column'];
            if (isset($mapped[$column])) {
                throw new \InvalidArgumentException('Duplicate cache scope column.');
            }
            $attribute = $rule['attribute'] ?? 'id';
            if (! is_string($attribute)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $attribute) !== 1) {
                throw new \InvalidArgumentException('Invalid cache scope attribute.');
            }
            $mapped[$column] = ['guard' => $rule['guard'], 'attribute' => $attribute];
        }

        return $mapped;
    }

    public function infrastructureCacheScopeOperator(): string
    {
        $operator = $this->cacheOptions()['scope_operator'] ?? 'and';
        if (! in_array($operator, ['and', 'or'], true)) {
            throw new \InvalidArgumentException('Cache scope operator must be and or or.');
        }

        return $operator;
    }

    /** @return array<string, int|string> */
    public function infrastructureCacheScopes(): array
    {
        $definitions = $this->infrastructureCacheScopeDefinitions();
        if ($definitions === []) {
            return [];
        }

        $user = auth()->user();
        $actor = null;
        $resolved = [];

        foreach ($definitions as $column => $source) {
            if (! is_string($column)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
                throw new \InvalidArgumentException('Invalid cache scope column.');
            }

            if (is_array($source) && isset($source['guard'], $source['attribute'])) {
                $guardUser = auth($source['guard'])->user();
                if ($guardUser === null) {
                    throw new \RuntimeException('Authenticated cache scope is required.');
                }
                $value = $source['attribute'] === 'id'
                    ? $guardUser->getAuthIdentifier()
                    : data_get($guardUser, $source['attribute']);
            } else {
                $actor ??= $this->infrastructureVisibilityActor();
                $value = match (true) {
                    $source === 'auth.id' => $user?->getAuthIdentifier() ?? ($actor['id'] ?? null),
                    is_string($source) && str_starts_with($source, 'auth.') =>
                        $user?->getAttribute(substr($source, 5)) ?? ($actor[substr($source, 5)] ?? null),
                    is_string($source) && str_starts_with($source, 'actor.') => $actor[substr($source, 6)] ?? null,
                    $source instanceof \Closure => $source($user, $actor),
                    default => throw new \InvalidArgumentException('Invalid cache scope resolver.'),
                };
            }

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
     * Optional application-defined actor and visibility extensions are deliberately
     * not auto-discovered. Laravel global scopes apply through Eloquent as usual.
     * Configured column scopes continue to work independently.
     */
    public function infrastructureVisibilityActor(): ?array
    {
        $resolver = $this->cacheOptions()['actor_resolver'] ?? null;
        if (is_string($resolver) && class_exists($resolver)) {
            $resolver = app($resolver);
        }
        if (is_object($resolver) && method_exists($resolver, 'resolve')) {
            $actor = $resolver->resolve();
            return is_array($actor) && isset($actor['id'], $actor['type']) ? $actor : null;
        }

        $attributes = request()->attributes;
        $id = $attributes->get('user_id');
        $type = $attributes->get('user_type');
        if ((is_int($id) || (is_string($id) && $id !== ''))
            && is_string($type) && $type !== '') {
            return ['id' => $id, 'type' => $type === 'user' ? 'api' : $type,
                'partner_id' => $attributes->get('user')?->partner_id];
        }

        $user = auth()->user();
        return $user === null ? null : [
            'id' => $user->getAuthIdentifier(),
            'type' => 'api',
        ];
    }

    public function infrastructureVisibilityResolver(): mixed
    {
        $resolver = $this->cacheOptions()['visibility_resolver'] ?? null;
        if (is_string($resolver) && class_exists($resolver)) {
            $resolver = app($resolver);
        }
        if ($resolver === null || $resolver instanceof \Closure
            || (is_object($resolver) && method_exists($resolver, 'apply'))) {
            return $resolver;
        }
        throw new \InvalidArgumentException('Invalid visibility resolver.');
    }

    public function infrastructureHasGlobalVisibilityScopes(): bool
    {
        foreach ($this->getGlobalScopes() as $scope) {
            if (! $scope instanceof \Illuminate\Database\Eloquent\SoftDeletingScope) {
                return true;
            }
        }

        return false;
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
        if ($this->infrastructureVisibilityResolver() !== null || $this->infrastructureHasGlobalVisibilityScopes()
            || $this->infrastructureCacheScopeOperator() === 'or') {
            return [static::cacheTag()];
        }

        $definitions = $this->infrastructureCacheScopeDefinitions();
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
