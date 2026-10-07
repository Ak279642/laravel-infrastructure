<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Concerns;

use Ak279642\LaravelInfrastructure\Cache\CacheKey;
use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\CacheTag;
use Ak279642\LaravelInfrastructure\Cache\CacheTtl;
use Ak279642\LaravelInfrastructure\Contracts\CacheableModel;
use Illuminate\Database\Eloquent\Model;

trait HasCache
{
    protected ?int $cacheTtl = CacheTtl::MINUTES_5;
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

    protected function initializeCache(): void {}

    protected function cacheRemember(string $operation, callable $callback, array $params = []): mixed
    {
        $bypass = $this->cacheBypassOnce;

        // Consume the bypass before executing the callback so nested repository
        // operations cannot accidentally inherit request-specific state.
        $this->cacheBypassOnce = false;

        if (! $this->cacheEnabled || $bypass) {
            return $callback();
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
            ttl: $this->cacheForever ? null : $this->cacheTtl,
            callback: $callback,
            tags: $tags,
            lockSeconds: $this->cacheLockSeconds,
            waitSeconds: $this->cacheLockWaitSeconds,
        );
    }

    protected function getCacheKey(string $operation, array $params = []): string
    {
        return CacheKey::make(
            'repository:'.strtolower(str_replace('\\', '.', static::class)).':'.$operation,
            [
                'model' => strtolower(str_replace('\\', '.', $this->getModel()::class)),
                'params' => $params,
            ],
        );
    }

    protected function resolveCacheTags(array $params = []): array
    {
        $model = $this->getModel();
        $tags = CacheTag::merge(
            [CacheTag::fromModel($model::class)],
            $this->extraCacheTags,
        );

        if ($model instanceof CacheableModel) {
            $relations = $params['with'] ?? [];
            $relations = is_array($relations) ? $relations : [];
            $tags = CacheTag::merge(
                $tags,
                [$model::cacheTag()],
                $model->getCacheDependencyTags(null, $relations),
            );
        }

        return CacheTag::tags(...$tags);
    }

    protected function flushCache(): void
    {
        $model = $this->getModel();
        $tags = $model instanceof CacheableModel
            ? CacheTag::merge([$model::cacheTag()], $this->extraCacheTags)
            : CacheTag::merge([CacheTag::fromModel($model::class)], $this->extraCacheTags);

        if ($tags !== []) {
            $this->getCacheManager()->flushTags($tags);
        }
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
