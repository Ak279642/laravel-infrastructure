<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Concerns;

use Ak279642\LaravelInfrastructure\Cache\CacheKey;
use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\CacheTag;
use Ak279642\LaravelInfrastructure\Cache\CacheTtl;
use Ak279642\LaravelInfrastructure\Cache\SqlCacheDependency;
use Ak279642\LaravelInfrastructure\Cache\RequestReadCache;
use Ak279642\LaravelInfrastructure\Contracts\CacheableModel;
use Illuminate\Database\Eloquent\Model;

trait HasCache
{
    protected ?int $cacheTtl = null;

    protected bool $cacheEnabled = true;

    protected bool $cacheForever = false;

    protected array $extraCacheTags = [];

    /**
     * A one-operation bypass flag used only on the clone returned by withoutCache().
     *
     * Keeping the bypass on a clone prevents state leakage between Octane requests,
     * queue jobs, singleton/scoped services, and concurrent callers.
     */
    protected bool $cacheBypassOnce = false;

    protected int $cacheLockSeconds = 10;

    protected int $cacheLockWaitSeconds = 3;

    protected function defaultCacheTtl(): int
    {
        return CacheTtl::MINUTES_5;
    }

    public function cacheTtl(int $ttl): static
    {
        $this->cacheTtl = $ttl;

        return $this;
    }

    public function rememberForever(): static
    {
        $this->cacheForever = true;

        return $this;
    }

    public function cacheTags(array $tags): static
    {
        $this->extraCacheTags = CacheTag::tags(...$tags);

        return $this;
    }

    public function withoutCache(): static
    {
        $repository = clone $this;
        $repository->query = clone $this->query;
        $repository->cacheBypassOnce = true;

        return $repository;
    }

    public function withCache(?int $ttl = null): static
    {
        $this->cacheBypassOnce = false;
        $this->cacheEnabled = true;

        if ($ttl !== null) {
            $this->cacheTtl = $ttl;
        }

        return $this;
    }

    protected function initializeCache(): void
    {
        $modelCacheEnabled = ! method_exists(
            $this->model,
            'usesInfrastructureCache',
        ) || $this->model->usesInfrastructureCache();

        // Use Laravel's default cache store; per-model/operation switches remain.
        $this->cacheEnabled = $this->cacheEnabled && $modelCacheEnabled;

        if ($this->cacheTtl !== null) {
            return;
        }

        $this->cacheTtl = max(
            1,
            $this->defaultCacheTtl(),
        );
    }

    /**
     * Optional policy for authenticated or permission-sensitive reads.
     *
     * Return null for the package's default persistent cache.
     * Return ['mode' => 'request'] for one-request memoization,
     * ['mode' => 'none'] to bypass, or ['mode' => 'persistent',
     * 'params' => [...], 'ttl' => 120] to customize the cache identity/TTL.
     * The policy must include any authorization context that is not already
     * represented by Eloquent scopes and their SQL bindings.
     *
     * @return array<string, mixed>|null
     */
    protected function cacheReadPolicy(string $operation, array $params): ?array
    {
        return null;
    }

    protected function cacheRemember(
        string $operation,
        callable $callback,
        array $params = [],
        ?int $ttlOverride = null,
        bool $useCache = true,
    ): mixed {
        $bypass = $this->cacheBypassOnce || ! $useCache;

        // Consume the bypass before executing the callback so nested repository
        // operations cannot accidentally inherit request-specific state.
        $this->cacheBypassOnce = false;

        if (! $this->cacheEnabled || $bypass) {
            return $callback();
        }

        // Never cache data read from an open transaction. The query may observe
        // uncommitted state that disappears on rollback.
        if ($this->getModel()->getConnection()->transactionLevel() > 0) {
            return $callback();
        }

        $policy = $this->cacheReadPolicy($operation, $params);
        if ($policy !== null) {
            $mode = $policy['mode'] ?? 'persistent';
            if (! in_array($mode, ['persistent', 'request', 'none'], true)) {
                throw new \InvalidArgumentException('Invalid repository cache policy mode.');
            }

            if ($mode === 'none') {
                return $callback();
            }

            $context = $policy['params'] ?? [];
            if (! is_array($context)) {
                throw new \InvalidArgumentException('Repository cache policy params must be an array.');
            }
            // Never let policy context overwrite the caller's operation inputs.
            $params['__cache_policy'] = $context;

            if ($mode === 'request') {
                return RequestReadCache::remember(
                    $this->getCacheKey($operation, $this->normalizeRepositoryCacheParams($params)),
                    $callback,
                );
            }

            if ($ttlOverride === null && isset($policy['ttl'])) {
                if (! is_int($policy['ttl']) || $policy['ttl'] < 1) {
                    throw new \InvalidArgumentException('Repository cache policy TTL must be a positive integer.');
                }
                $ttlOverride = $policy['ttl'];
            }
        }

        $tags = $this->resolveCacheTags($params);
        $keyParams = $this->normalizeRepositoryCacheParams($params);
        $key = $this->getCacheKey($operation, $keyParams);

        // Repository invalidation is tag based. On non-taggable stores we prefer
        // correctness over serving cache entries that cannot be invalidated safely.
        if ($tags !== [] && ! $this->getCacheManager()->supportsTags()) {
            return $callback();
        }

        return $this->getCacheManager()->rememberLocked(
            key: $key,
            ttl: $ttlOverride ?? ($this->cacheForever ? null : $this->cacheTtl),
            callback: $callback,
            tags: $tags,
            lockSeconds: max(1, $this->cacheLockSeconds),
            waitSeconds: max(0, $this->cacheLockWaitSeconds),
        );
    }

    protected function getCacheKey(string $operation, array $params = []): string
    {
        $model = $this->getModel();
        if (method_exists($model, 'infrastructureCacheScopes')) {
            $params['infrastructure_scopes'] = $model->infrastructureCacheScopes();
            $params['scope_operator'] = $model->infrastructureCacheScopeOperator();
            $params['visibility_scopes'] = $model->infrastructureCacheVisibilityScopes();
            if ($model->infrastructureVisibilityResolver() !== null
                || $model->infrastructureHasGlobalVisibilityScopes()) {
                $actor = $model->infrastructureVisibilityActor();
                $params['visibility_actor'] = $actor === null
                    ? null
                    : [$actor['type'] ?? null, $actor['id'] ?? null, $actor['partner_id'] ?? null];
            }
        }

        // Avoid invoking custom visibility callbacks a second time for the
        // common ownership-only case. An explicit repository query filter or
        // visibility resolver must be compiled to distinguish its SQL.
        $customFilter = $this->globalQueryCallback !== null
            || (new \ReflectionMethod($this, 'globalQueryFilter'))
                ->getDeclaringClass()->getName() !== \Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository::class;
        $customVisibility = method_exists($model, 'infrastructureVisibilityResolver')
            && $model->infrastructureVisibilityResolver() !== null;
        $query = ($customFilter || $customVisibility
            ? $this->query()
            : $model->newQuery())->toBase();
        $params['effective_sql'] = $query->toSql();
        $params['effective_bindings'] = $query->getBindings();
        $params['connection'] = [$model->getConnection()->getName(), $model->getConnection()->getDatabaseName()];

        return CacheKey::make(
            'repository:'.strtolower(str_replace('\\', '.', static::class)).':'.$operation,
            [
                'model' => strtolower(str_replace('\\', '.', $model::class)),
                'params' => $params,
            ],
        );
    }

    protected function resolveCacheTags(array $params = []): array
    {
        $model = $this->getModel();
        $modelTag = CacheTag::fromModel($model::class);
        $targeted = method_exists($model, 'infrastructureUsesTargetedCacheInvalidation')
            && $model->infrastructureUsesTargetedCacheInvalidation();

        // Keep the model tag even on targeted entries: unknown permissions,
        // other dependency changes and explicit full flushes need a safe path.
        $tags = CacheTag::merge(
            [$modelTag],
            $targeted ? $model->infrastructureCacheReadTags() : [],
            $this->extraCacheTags,
        );

        // Physical table tags enable automatic invalidation for raw/pivot SQL
        // writes. Compile scoped SQL without executing additional SELECTs.
        if ((bool) config('laravel-infrastructure.auto_invalidation.enabled', false)) {
            $connection = $model->getConnection();
            $tags[] = SqlCacheDependency::databaseTag($connection);
            $tags[] = SqlCacheDependency::tableTag($connection, $model->getTable());
        }

        if ($model instanceof CacheableModel) {
            $relations = $params['with'] ?? [];
            $relations = is_array($relations) ? $relations : [];
            $tags = CacheTag::merge(
                $tags,
                $model->getCacheDependencyTags(null, $relations),
            );
        }

        return CacheTag::tags(...$tags);
    }

    protected function flushCache(): void
    {
        $model = $this->getModel();
        $targeted = method_exists($model, 'infrastructureUsesTargetedCacheInvalidation')
            && $model->infrastructureUsesTargetedCacheInvalidation();
        $tags = $targeted
            ? $model->infrastructureCacheReadTags()
            : [CacheTag::fromModel($model::class)];

        $this->getCacheManager()->flushTags($tags);
    }

    protected function getCacheManager(): CacheManager
    {
        return $this->cache;
    }

    protected function normalizeRepositoryCacheParams(array $params): array
    {
        if (isset($params['with']) && is_array($params['with']) && $this->isStringList($params['with'])) {
            $params['with'] = CacheKey::unordered($params['with']);
        }

        if (isset($params['filters']) && is_array($params['filters'])) {
            $params['filters'] = $this->normalizeRepositoryCacheFilters($params['filters']);
        }

        return $params;
    }

    protected function normalizeRepositoryCacheFilters(array $filters): array
    {
        foreach (['with', 'with_count', 'search_columns'] as $key) {
            if (isset($filters[$key]) && is_array($filters[$key]) && $this->isStringList($filters[$key])) {
                $filters[$key] = CacheKey::unordered($filters[$key]);
            }
        }

        foreach ($filters as $key => $filter) {
            if (! is_string($key) || ! is_array($filter)) {
                continue;
            }

            if (array_is_list($filter) && count($filter) === 2 && is_string($filter[0])) {
                $operator = strtolower($filter[0]);

                if (in_array($operator, ['in', 'not_in', 'in_or_null'], true) && is_array($filter[1])) {
                    $filters[$key][1] = CacheKey::unordered($filter[1]);
                }

                continue;
            }

            $operator = strtolower((string) ($filter['operator'] ?? ''));

            if (
                in_array($operator, ['in', 'not_in', 'in_or_null'], true)
                && isset($filter['value'])
                && is_array($filter['value'])
            ) {
                $filters[$key]['value'] = CacheKey::unordered($filter['value']);
            }
        }

        return $filters;
    }

    protected function isStringList(array $value): bool
    {
        return array_is_list($value)
            && count(array_filter($value, 'is_string')) === count($value);
    }

    abstract public function getModel(): Model;
}
