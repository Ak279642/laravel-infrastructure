<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache;

use DateInterval;
use DateTimeInterface;

final class CacheInvalidator
{
    public function __construct(private readonly CacheManager $cache) {}

    /** @param list<string> $tags */
    public function forget(string $key, array $tags = []): bool
    {
        return $this->cache->forget($key, $tags);
    }

    /** @param list<string> $keys @param list<string> $tags */
    public function forgetMany(array $keys, array $tags = []): bool
    {
        return $this->cache->forgetMany($keys, $tags);
    }

    /** @param list<string> $tags */
    public function invalidateTags(array $tags): bool
    {
        $tags = CacheTag::tags(...$tags);
        return $tags !== [] && $this->cache->flushTags($tags);
    }

    public function invalidateModel(string $tag, int|string $id): bool
    {
        return $this->invalidateTags(CacheTag::model($tag, $id));
    }

    /** @param list<string> $tags */
    public function invalidateScope(array $tags = []): bool
    {
        return $this->invalidateTags($tags);
    }

    /** @param callable(): mixed $callback @param list<string> $tags */
    public function refresh(
        string $key,
        DateInterval|DateTimeInterface|int|null $ttl,
        callable $callback,
        array $tags = [],
    ): mixed {
        return $this->cache->refresh($key, $ttl, $callback, $tags);
    }
}
