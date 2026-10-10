<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache;

use DateInterval;
use DateTimeInterface;
use Illuminate\Database\Connection;

final class CacheInvalidator
{
    public function __construct(private readonly CacheManager $cache) {}

    public function forget(string $key, array $tags = []): bool
    {
        return $this->cache->forget($key, $tags);
    }

    public function forgetMany(array $keys, array $tags = []): bool
    {
        return $this->cache->forgetMany($keys, $tags);
    }

    public function invalidateTags(array $tags): bool
    {
        $tags = CacheTag::tags(...$tags);

        return $tags !== [] && $this->cache->flushTags($tags);
    }

    public function invalidateModel(string $tag, int|string $id): bool
    {
        return $this->invalidateTags(CacheTag::model($tag, $id));
    }

    public function invalidateScope(array $tags = []): bool
    {
        return $this->invalidateTags($tags);
    }

    /**
     * Invalidate dependent model or query tags after the owning SQL transaction
     * commits. Used for set-based UPDATE/DELETE/INSERT and pivot table writes
     * that intentionally bypass Eloquent events.
     *
     * No SELECT queries or per-row model hydration are performed.
     * Request memoization is cleared immediately to prevent stale reads inside
     * the same request. Rolled-back transactions do not flush shared caches.
     *
     * @param list<string> $tags
     */
    public function invalidateAfterCommit(array $tags, Connection $connection): void
    {
        $tags = CacheTag::tags(...$tags);
        if ($tags === []) {
            return;
        }

        RequestReadCache::clear();

        $callback = function () use ($tags): void {
            $this->invalidateTags($tags);
        };

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($callback);

            return;
        }

        $callback();
    }

    public function refresh(
        string $key,
        DateInterval|DateTimeInterface|int|null $ttl,
        callable $callback,
        array $tags = [],
    ): mixed {
        return $this->cache->refresh($key, $ttl, $callback, $tags);
    }
}
