<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache\Concerns;

use Ak279642\LaravelInfrastructure\Cache\CacheTag;
use Ak279642\LaravelInfrastructure\Cache\RequestReadCache;
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
        // Request-level memoization must be invalidated immediately, even when
        // shared tag invalidation is deferred until the transaction commits.
        RequestReadCache::clear();

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
        $definitions = $options['scopes'] ?? $options['scope'] ?? null;

        if ($definitions === 'user' || $definitions === 'tenant') {
            $column = $this->infrastructureScopeColumn();
            $definitions = [$column => $definitions === 'user' ? 'auth.id' : 'auth.'.$column];
        } elseif (is_string($definitions)) {
            // A column-only declaration describes a boundary already enforced
            // by an application's Eloquent global scope.
            $definitions = [$definitions => 'auth.'.$definitions];
        }

        return $this->normalizeInfrastructureScopeDefinitions($definitions);
    }

    /** @return array<string,mixed> */
    public function infrastructureCacheVisibilityDefinitions(): array
    {
        $visibility = $this->cacheOptions()['visibility'] ?? null;
        if ($visibility === null) {
            return [];
        }
        if (! is_array($visibility) || array_keys($visibility) !== ['any']) {
            throw new \InvalidArgumentException('Cache visibility must declare an any group.');
        }

        return $this->normalizeInfrastructureScopeDefinitions($visibility['any']);
    }

    public function infrastructureUsesGlobalScopeMarker(): bool
    {
        $options = $this->cacheOptions();
        $scope = $options['scope'] ?? null;

        return ! isset($options['scopes'])
            && is_string($scope)
            && ! in_array($scope, ['user', 'tenant'], true);
    }

    /** @return array<string,mixed> */
    private function normalizeInfrastructureScopeDefinitions(mixed $definitions): array
    {
        if ($definitions === null) {
            return [];
        }
        if (! is_array($definitions) || $definitions === []) {
            throw new \InvalidArgumentException('Cache scopes must contain column/resolver definitions.');
        }
        if (isset($definitions['column'])) {
            $definitions = [$definitions];
        }

        if (! array_is_list($definitions)) {
            return $definitions;
        }

        $mapped = [];
        foreach ($definitions as $rule) {
            if (is_string($rule)) {
                $column = $rule;
                $source = 'auth.id';
            } elseif (is_array($rule) && isset($rule['column']) && is_string($rule['column'])) {
                $column = $rule['column'];
                $guard = $rule['guard'] ?? null;
                if ($guard !== null && (! is_string($guard) || $guard === '')) {
                    throw new \InvalidArgumentException('Invalid cache scope guard.');
                }
                $attribute = $rule['attribute'] ?? 'id';
                if (! is_string($attribute)
                    || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $attribute) !== 1) {
                    throw new \InvalidArgumentException('Invalid cache scope attribute.');
                }
                $source = $guard === null
                    ? 'auth.'.$attribute
                    : ['guard' => $guard, 'attribute' => $attribute];
            } else {
                throw new \InvalidArgumentException('Invalid cache scope rule.');
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1
                || isset($mapped[$column])) {
                throw new \InvalidArgumentException('Invalid or duplicate cache scope column.');
            }
            $mapped[$column] = $source;
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
        return $this->resolveInfrastructureCacheScopes(
            $this->infrastructureCacheScopeDefinitions(),
            $this->infrastructureCacheScopeOperator(),
        );
    }

    /** @return array<string, int|string> */
    public function infrastructureCacheVisibilityScopes(): array
    {
        return $this->resolveInfrastructureCacheScopes(
            $this->infrastructureCacheVisibilityDefinitions(),
            'or',
        );
    }

    /** @param array<string,mixed> $definitions
     *  @return array<string,int|string>
     */
    private function resolveInfrastructureCacheScopes(array $definitions, string $operator): array
    {
        if ($definitions === []) {
            return [];
        }

        $user = null;
        $actor = null;
        $loadedDefaultGuard = false;
        $resolved = [];

        foreach ($definitions as $column => $source) {
            if (! is_string($column)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
                throw new \InvalidArgumentException('Invalid cache scope column.');
            }

            if (is_array($source) && isset($source['guard'], $source['attribute'])) {
                $guardUser = auth($source['guard'])->user();
                if ($guardUser === null) {
                    if ($operator === 'or') {
                        continue;
                    }
                    throw new \RuntimeException('Authenticated cache scope is required.');
                }
                $value = $source['attribute'] === 'id'
                    ? $guardUser->getAuthIdentifier()
                    : data_get($guardUser, $source['attribute']);
            } else {
                if (! $loadedDefaultGuard) {
                    $user = auth()->user();
                    $loadedDefaultGuard = true;
                }
                $actor ??= $this->infrastructureVisibilityActor();
                if ($user === null && $actor === null && is_string($source)
                    && (str_starts_with($source, 'auth.') || str_starts_with($source, 'actor.'))) {
                    throw new \RuntimeException('Authenticated cache scope is required.');
                }
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

        if ($resolved === []) {
            throw new \RuntimeException('Authenticated cache scope is required.');
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
        // Database raw attributes and guard IDs may use different PHP scalar types.
        // Normalize tags so a string guard ID and integer database ID invalidate together.
        $canonical = array_map(static fn (int|string $value): string => (string) $value, $values);
        return static::cacheTag().':scope:'.hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
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

    /**
     * The read is tagged by its complete AND partition and each possible OR
     * visibility branch. Tags are calculated without database lookups.
     *
     * @return list<string>
     */
    public function infrastructureCacheReadTags(): array
    {
        if ($resolver = $this->infrastructureTargetedVisibilityResolver()) {
            $tags = CacheTag::tags(...$resolver->cacheReadTags(
                $this,
                $this->infrastructureVisibilityActor(),
            ));
            if ($tags === []) {
                throw new \LogicException('Targeted visibility resolver must return cache read tags.');
            }

            return $tags;
        }

        $base = $this->infrastructureCacheScopes();
        $any = $this->infrastructureCacheVisibilityScopes();

        return $this->infrastructureScopeGroupTags($base, $any);
    }

    /**
     * A partition is safe only when the declared columns bound the entire
     * read. Unknown visibility resolvers retain model-wide invalidation.
     */
    /**
     * Advanced resolvers can opt in to exact cache tags. Both methods are
     * required; otherwise they get safe model-wide invalidation.
     */
    private function infrastructureTargetedVisibilityResolver(): ?object
    {
        $resolver = $this->infrastructureVisibilityResolver();

        return is_object($resolver)
            && method_exists($resolver, 'cacheReadTags')
            && method_exists($resolver, 'cacheInvalidationTags')
            ? $resolver
            : null;
    }

    public function infrastructureUsesTargetedCacheInvalidation(): bool
    {
        if ($this->infrastructureVisibilityResolver() !== null) {
            return $this->infrastructureTargetedVisibilityResolver() !== null;
        }

        $base = $this->infrastructureCacheScopeDefinitions();
        $any = $this->infrastructureCacheVisibilityDefinitions();

        // Mixed (A OR B) AND (C OR D) needs cross-product tags; use the
        // model-wide fallback until that composition is explicitly supported.
        return $this->infrastructureVisibilityResolver() === null
            && ($base !== [] || $any !== [])
            && ! ($base !== [] && $any !== []
                && $this->infrastructureCacheScopeOperator() === 'or');
    }

    /** @param array<string,int|string> $base
     *  @param array<string,int|string> $any
     *  @return list<string>
     */
    private function infrastructureScopeGroupTags(array $base, array $any): array
    {
        if ($base === [] && $any === []) {
            return [];
        }

        $tags = [];
        if ($any !== []) {
            foreach ($any as $column => $value) {
                $tags[] = $this->infrastructureScopeTagForValues([...$base, $column => $value]);
            }
        } elseif ($this->infrastructureCacheScopeOperator() === 'or') {
            foreach ($base as $column => $value) {
                $tags[] = $this->infrastructureScopeTagForValues([$column => $value]);
            }
        } elseif ($base !== []) {
            $tags[] = $this->infrastructureScopeTagForValues($base);
        }

        return CacheTag::tags(...$tags);
    }

    /** @return list<string> */
    public function getCacheInvalidationTags(): array
    {
        if ($resolver = $this->infrastructureTargetedVisibilityResolver()) {
            $tags = CacheTag::tags(...$resolver->cacheInvalidationTags($this));

            return $tags !== [] ? $tags : [static::cacheTag()];
        }

        if (! $this->infrastructureUsesTargetedCacheInvalidation()) {
            return [static::cacheTag()];
        }

        $baseColumns = array_keys($this->infrastructureCacheScopeDefinitions());
        $anyColumns = array_keys($this->infrastructureCacheVisibilityDefinitions());
        $oldBase = $newBase = $oldAny = $newAny = [];

        foreach (array_unique([...$baseColumns, ...$anyColumns]) as $column) {
            if (! is_string($column)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
                throw new \InvalidArgumentException('Invalid cache scope column.');
            }
            $oldValue = $this->getRawOriginal($column);
            $newValue = $this->getAttribute($column);

            if (in_array($column, $baseColumns, true)) {
                if (is_int($oldValue) || (is_string($oldValue) && $oldValue !== '')) {
                    $oldBase[$column] = $oldValue;
                }
                if (is_int($newValue) || (is_string($newValue) && $newValue !== '')) {
                    $newBase[$column] = $newValue;
                }
            }
            if (in_array($column, $anyColumns, true)) {
                if (is_int($oldValue) || (is_string($oldValue) && $oldValue !== '')) {
                    $oldAny[$column] = $oldValue;
                }
                if (is_int($newValue) || (is_string($newValue) && $newValue !== '')) {
                    $newAny[$column] = $newValue;
                }
            }
        }

        // Incomplete AND partitions cannot be mapped to an actor reliably.
        // Broad invalidation is safer than leaving a stale cached result.
        if ($this->infrastructureCacheScopeOperator() === 'and'
            && (count($newBase) !== count($baseColumns)
                || (! $this->wasRecentlyCreated && count($oldBase) !== count($baseColumns)))) {
            return [static::cacheTag()];
        }

        $tags = CacheTag::merge(
            $this->infrastructureScopeGroupTags($oldBase, $oldAny),
            $this->infrastructureScopeGroupTags($newBase, $newAny),
        );

        return $tags !== [] ? $tags : [static::cacheTag()];
    }

    public function invalidateCache(): void
    {
        // Hook for application models that maintain additional cache state.
    }
}
