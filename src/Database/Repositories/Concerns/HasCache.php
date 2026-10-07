<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Concerns;

use Ak279642\LaravelInfrastructure\Contracts\CacheableModel;
use Ak279642\LaravelInfrastructure\Cache\CacheKey;
use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\CacheTag;
use Ak279642\LaravelInfrastructure\Cache\CacheTtl;
use DateInterval;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

trait HasCache
{
    protected ?int $cacheTtl = CacheTtl::MINUTES_5;
    protected bool $cacheEnabled = true;
    protected bool $cacheForever = false;
    protected array $extraCacheTags = [];

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
        $this->cacheEnabled = false;
        return $this;
    }

    public function withCache(?int $ttl = null): static
    {
        $this->cacheEnabled = true;
        if ($ttl !== null) {
            $this->cacheTtl = $ttl;
        }
        return $this;
    }

    protected function initializeCache(): void {}

    protected function cacheRemember(string $operation, callable $callback, array $params = []): mixed
    {
        if (! $this->cacheEnabled) {
            return $callback();
        }

        $key = $this->getCacheKey($operation, $params);
        $tags = $this->resolveCacheTags($params);

        // Repository invalidation is tag based. On non-taggable stores we prefer
        // correctness over serving cache entries that cannot be invalidated safely.
        if ($tags !== [] && ! $this->getCacheManager()->supportsTags()) {
            return $callback();
        }

        if ($this->cacheForever) {
            return $this->getCacheManager()->rememberForever($key, $callback, $tags);
        }

        return $this->getCacheManager()->remember($key, $this->cacheTtl, $callback, $tags);
    }

    protected function getCacheKey(string $operation, array $params = []): string
    {
        return CacheKey::make(
            'repository:'.strtolower(str_replace('\\', '.', static::class)).':'.$operation,
            $params,
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

    abstract public function getModel(): Model;
}
