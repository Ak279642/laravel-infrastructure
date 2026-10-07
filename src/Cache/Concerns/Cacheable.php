<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache\Concerns;

use Ak279642\LaravelInfrastructure\Cache\CacheInvalidator;
use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Lock;

trait Cacheable
{
    private ?CacheManager $cacheManagerInstance = null;

    private ?CacheInvalidator $cacheInvalidatorInstance = null;

    protected function cacheManager(): CacheManager
    {
        return $this->cacheManagerInstance ??= app(CacheManager::class);
    }

    protected function cacheInvalidator(): CacheInvalidator
    {
        return $this->cacheInvalidatorInstance ??= app(CacheInvalidator::class);
    }

    protected function cacheGet(string $key, mixed $default = null, array $tags = []): mixed
    {
        return $this->cacheManager()->get($key, $default, $tags);
    }

    protected function cacheMany(array $keys, array $tags = []): array
    {
        return $this->cacheManager()->many($keys, $tags);
    }

    protected function cacheHas(string $key, array $tags = []): bool
    {
        return $this->cacheManager()->has($key, $tags);
    }

    protected function cachePut(string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null, array $tags = []): bool
    {
        return $this->cacheManager()->put($key, $value, $ttl, $tags);
    }

    protected function cacheRemember(string $key, DateInterval|DateTimeInterface|int|null $ttl, callable $callback, array $tags = []): mixed
    {
        return $this->cacheManager()->remember($key, $ttl, $callback, $tags);
    }

    protected function cacheRememberForever(string $key, callable $callback, array $tags = []): mixed
    {
        return $this->cacheManager()->rememberForever($key, $callback, $tags);
    }

    protected function cacheForget(string $key, array $tags = []): bool
    {
        return $this->cacheInvalidator()->forget($key, $tags);
    }

    protected function cacheInvalidateTags(array $tags): bool
    {
        return $this->cacheInvalidator()->invalidateTags($tags);
    }

    protected function cacheInvalidateModel(string $tag, int|string $id): bool
    {
        return $this->cacheInvalidator()->invalidateModel($tag, $id);
    }

    protected function cacheRefresh(string $key, DateInterval|DateTimeInterface|int|null $ttl, callable $callback, array $tags = []): mixed
    {
        return $this->cacheInvalidator()->refresh($key, $ttl, $callback, $tags);
    }

    protected function cacheLock(string $name, int $seconds = 0, ?string $owner = null): ?Lock
    {
        return $this->cacheManager()->lock($name, $seconds, $owner);
    }
}
