<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Repositories\Concerns;

use Ak279642\LaravelInfrastructure\Cache\CacheKey;
use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\CacheTag;
use Ak279642\LaravelInfrastructure\Contracts\CacheableModel;
use Ak279642\LaravelInfrastructure\Exceptions\InvalidCacheConfigurationException;
use DateInterval;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TModel of Model
 * @mixin \Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository<TModel>
 */
trait HasCache
{
    protected ?int $cacheTtl = null;

    protected bool $cacheEnabled = true;

    protected bool $cacheForever = false;

    /** @var list<string> */
    protected array $extraCacheTags = [];

    public function cacheTtl(int $ttl): static
    {
        if ($ttl <= 0) {
            throw new InvalidCacheConfigurationException(
                'Repository cache TTL must be greater than zero.',
            );
        }

        $clone = clone $this;
        $clone->cacheTtl = $ttl;

        return $clone;
    }

    public function rememberForever(): static
    {
        $clone = clone $this;
        $clone->cacheForever = true;

        return $clone;
    }

    /** @param list<string> $tags */
    public function cacheTags(array $tags): static
    {
        $clone = clone $this;
        $clone->extraCacheTags = CacheTag::merge(
            $this->extraCacheTags,
            CacheTag::tags(...$tags),
        );

        return $clone;
    }

    /**
     * Disable cache for only the returned repository clone.
     *
     * This is safe for Octane, queue workers and container singletons because
     * the original repository instance is never mutated.
     */
    public function withoutCache(): static
    {
        $clone = clone $this;
        $clone->cacheEnabled = false;

        return $clone;
    }

    /**
     * @deprecated Repository caching is enabled by default. Prefer get() or
     *             withoutCache()->get() for an explicitly fresh read.
     */
    public function withCache(?int $ttl = null): static
    {
        $clone = clone $this;
        $clone->cacheEnabled = true;

        if ($ttl !== null) {
            if ($ttl <= 0) {
                throw new InvalidCacheConfigurationException(
                    'Repository cache TTL must be greater than zero.',
                );
            }

            $clone->cacheTtl = $ttl;
        }

        return $clone;
    }

    protected function initializeCache(): void {}

    /** @param callable(): mixed $callback @param array<string, mixed> $params */
    protected function cacheRemember(
        string $operation,
        callable $callback,
        array $params = [],
    ): mixed {
        $key = $this->getCacheKey($operation, $params);
        $tags = $this->resolveCacheTags($params);

        if (! $this->cacheEnabled) {
            return $this->getCacheManager()->bypass(
                $key,
                $callback,
                $tags,
                'repository_disabled',
            );
        }

        if ($this->hasCustomQueryState()) {
            return $this->getCacheManager()->bypass(
                $key,
                $callback,
                $tags,
                'custom_query_state',
            );
        }

        if ($this->cacheForever) {
            return $this->getCacheManager()->rememberForever(
                $key,
                $callback,
                $tags,
            );
        }

        return $this->getCacheManager()->remember(
            $key,
            $this->resolvedCacheTtl(),
            $callback,
            $tags,
        );
    }

    /** @param array<string, mixed> $params */
    protected function getCacheKey(
        string $operation,
        array $params = [],
    ): string {
        return CacheKey::make(
            'repository:'.strtolower(
                str_replace('\\', '.', static::class),
            ).':'.$operation,
            $params,
        );
    }

    /** @param array<string, mixed> $params @return list<string> */
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
                $model->getCacheDependencyTags(
                    null,
                    $relations,
                ),
            );
        }

        return CacheTag::tags(...$tags);
    }

    protected function flushCache(): void
    {
        $model = $this->getModel();

        $tags = $model instanceof CacheableModel
            ? CacheTag::merge(
                [$model::cacheTag()],
                $this->extraCacheTags,
            )
            : CacheTag::merge(
                [CacheTag::fromModel($model::class)],
                $this->extraCacheTags,
            );

        if ($tags !== []) {
            $this->getCacheManager()->flushTags($tags);
        }
    }

    protected function getCacheManager(): CacheManager
    {
        return $this->cache;
    }

    private function resolvedCacheTtl(): DateInterval|DateTimeInterface|int|null
    {
        return $this->cacheTtl
            ?? max(
                1,
                (int) config(
                    'laravel-infrastructure.cache.default_ttl',
                    300,
                ),
            );
    }

    /** @return TModel */
    abstract public function getModel(): Model;

    abstract protected function hasCustomQueryState(): bool;
}
