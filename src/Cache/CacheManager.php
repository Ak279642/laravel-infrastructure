<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache;

use DateInterval;
use DateTimeInterface;
use Illuminate\Cache\Repository;
use Illuminate\Cache\TaggableStore;
use Illuminate\Cache\TaggedCache;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Store;
use Throwable;

final class CacheManager
{
    private Repository $store;

    private bool $tagsSupported;

    public function __construct(
        private readonly Factory $cache,
        private readonly ?string $storeName = null,
    ) {
        $this->store = $this->resolveStore();
        $this->tagsSupported = $this->resolveTagsSupport();
    }

    public function get(
        string $key,
        mixed $default = null,
        array $tags = [],
    ): mixed {
        $key = $this->normalizeKey($key);
        $store = $this->store($tags);

        $value = $store->get($key, $default);

        // Log::info('Cache GET', [
        //     'key' => $key,
        //     'tags' => $tags,
        //     'hit' => $value !== $default,
        // ]);

        return $value;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function many(
        array $keys,
        array $tags = [],
    ): array {
        $normalizedKeys = array_map(
            fn (string $key) => $this->normalizeKey($key),
            $keys,
        );

        $result = $this->store($tags)->many($normalizedKeys);

        // Log::info('Cache MANY', [
        //     'keys' => $normalizedKeys,
        //     'tags' => $tags,
        //     'count' => count($result),
        // ]);

        return $result;
    }

    public function put(
        string $key,
        mixed $value,
        DateInterval|DateTimeInterface|int|null $ttl = null,
        array $tags = [],
    ): bool {
        $key = $this->normalizeKey($key);

        $result = $this->store($tags)->put(
            $key,
            $value,
            $ttl,
        );

        // Log::info('Cache PUT', [
        //     'key' => $key,
        //     'tags' => $tags,
        //     'ttl' => $ttl,
        //     'success' => $result,
        // ]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function putMany(
        array $values,
        DateInterval|DateTimeInterface|int|null $ttl = null,
        array $tags = [],
    ): bool {
        $normalizedValues = [];

        foreach ($values as $key => $value) {
            $normalizedValues[$this->normalizeKey($key)] = $value;
        }

        $result = $this->store($tags)->putMany(
            $normalizedValues,
            $ttl,
        );

        // Log::info('Cache PUT MANY', [
        //     'keys' => array_keys($normalizedValues),
        //     'tags' => $tags,
        //     'ttl' => $ttl,
        //     'success' => $result,
        // ]);

        return $result;
    }

    public function forever(
        string $key,
        mixed $value,
        array $tags = [],
    ): bool {
        return $this->put(
            $key,
            $value,
            null,
            $tags,
        );
    }

    public function putForever(
        string $key,
        mixed $value,
        array $tags = [],
    ): bool {
        return $this->forever(
            $key,
            $value,
            $tags,
        );
    }

    public function remember(
        string $key,
        DateInterval|DateTimeInterface|int|null $ttl,
        callable $callback,
        array $tags = [],
    ): mixed {
        $key = $this->normalizeKey($key);

        // Log::info('Cache LOOKUP', [
        //     'key' => $key,
        //     'tags' => $tags,
        //     'ttl' => $ttl,
        //     'mode' => 'standard',
        // ]);

        $store = $this->store($tags);

        $missing = new \stdClass;
        $cached = $store->get($key, $missing);

        if ($cached !== $missing) {
            // Log::info('Cache HIT', [
            //     'key' => $key,
            //     'tags' => $tags,
            //     'mode' => 'standard',
            // ]);

            return $cached;
        }

        // Log::info('Cache MISS', [
        //     'key' => $key,
        //     'tags' => $tags,
        //     'mode' => 'standard',
        // ]);

        $value = $callback();

        $store->put(
            $key,
            $value,
            $ttl,
        );

        // Log::info('Cache WRITE', [
        //     'key' => $key,
        //     'tags' => $tags,
        //     'mode' => 'standard',
        // ]);

        return $value;
    }

    public function rememberLocked(
        string $key,
        DateInterval|DateTimeInterface|int|null $ttl,
        callable $callback,
        array $tags = [],
        int $lockSeconds = 10,
        int $waitSeconds = 3,
    ): mixed {
        $key = $this->normalizeKey($key);
        $store = $this->store($tags);

        $missing = new \stdClass;
        $cached = $store->get($key, $missing);

        if ($cached !== $missing) {
            return $cached;
        }

        $lock = $this->lock(
            'cache-populate:'.hash('sha256', $key),
            max(1, $lockSeconds),
        );

        if ($lock === null) {
            $value = $callback();
            $store->put($key, $value, $ttl);

            return $value;
        }

        try {
            return $lock->block(max(0, $waitSeconds), function () use ($store, $key, $ttl, $callback): mixed {
                // Another worker may have populated the value while this worker
                // waited for the lock, so always re-check before doing the work.
                $missing = new \stdClass;
                $cached = $store->get($key, $missing);

                if ($cached !== $missing) {
                    return $cached;
                }

                $value = $callback();
                $store->put($key, $value, $ttl);

                return $value;
            });
        } catch (LockTimeoutException) {
            // A timed-out waiter gets one final cache read. If the lock holder
            // failed before populating the value, preserve availability by
            // performing the work rather than returning an empty/stale result.
            $missing = new \stdClass;
            $cached = $store->get($key, $missing);

            if ($cached !== $missing) {
                return $cached;
            }

            $value = $callback();
            $store->put($key, $value, $ttl);

            return $value;
        }
    }

    /**
     * Dependency-safe non-computing read for callers that calculate multiple
     * cache misses in a single database query.
     *
     * A non-taggable store or an open transaction is always a cache miss.
     */
    public function getWithDependencies(
        string $key,
        mixed $default,
        array $tags,
        ?Connection $connection = null,
    ): mixed {
        $tags = CacheTag::tags(...$tags);
        if ($tags === []) {
            throw new \InvalidArgumentException('Dependency-aware caching requires at least one tag.');
        }

        if (! $this->supportsTags()
            || ($connection !== null && $connection->transactionLevel() > 0)) {
            return $default;
        }

        return $this->get($key, $default, $tags);
    }

    /**
     * Dependency-safe non-computing write for batching cache misses.
     * Returns false instead of publishing uncommitted/unsafe entries.
     */
    public function putWithDependencies(
        string $key,
        mixed $value,
        int $ttl,
        array $tags,
        ?Connection $connection = null,
    ): bool {
        $tags = CacheTag::tags(...$tags);
        if ($tags === []) {
            throw new \InvalidArgumentException('Dependency-aware caching requires at least one tag.');
        }
        if ($ttl < 1) {
            throw new \InvalidArgumentException('Cache TTL must be positive.');
        }

        if (! $this->supportsTags()
            || ($connection !== null && $connection->transactionLevel() > 0)) {
            return false;
        }

        return $this->put($key, $value, $ttl, $tags);
    }

    /**
     * Cache a raw-query or aggregate result only when its dependent model tags
     * can actually be invalidated. This is intentionally stricter than the
     * low-level remember() helper, which also supports untagged ephemeral keys.
     *
     * @param list<string> $tags
     */
    public function rememberWithDependencies(
        string $key,
        int $ttl,
        callable $callback,
        array $tags,
        ?Connection $connection = null,
    ): mixed {
        $tags = CacheTag::tags(...$tags);
        if ($tags === []) {
            throw new \InvalidArgumentException('Dependency-aware caching requires at least one tag.');
        }
        if ($ttl < 1) {
            throw new \InvalidArgumentException('Cache TTL must be positive.');
        }

        // Tagless drivers cannot safely invalidate these records. Never
        // publish uncommitted transaction reads to the shared cache.
        if (! $this->supportsTags()
            || ($connection !== null && $connection->transactionLevel() > 0)) {
            return $callback();
        }

        return $this->rememberLocked($key, $ttl, $callback, $tags);
    }

    public function rememberForever(
        string $key,
        callable $callback,
        array $tags = [],
    ): mixed {
        return $this->remember(
            $key,
            null,
            $callback,
            $tags,
        );
    }

    public function pull(
        string $key,
        mixed $default = null,
        array $tags = [],
    ): mixed {
        $key = $this->normalizeKey($key);

        $value = $this->store($tags)->pull(
            $key,
            $default,
        );

        // Log::info('Cache PULL', [
        //     'key' => $key,
        //     'tags' => $tags,
        //     'hit' => $value !== $default,
        // ]);

        return $value;
    }

    public function add(
        string $key,
        mixed $value,
        DateInterval|DateTimeInterface|int|null $ttl = null,
        array $tags = [],
    ): bool {
        $key = $this->normalizeKey($key);

        $result = $this->store($tags)->add(
            $key,
            $value,
            $ttl,
        );

        // Log::info('Cache ADD', [
        //     'key' => $key,
        //     'tags' => $tags,
        //     'ttl' => $ttl,
        //     'success' => $result,
        // ]);

        return $result;
    }

    public function increment(
        string $key,
        int $amount = 1,
        array $tags = [],
    ): int|bool {
        $key = $this->normalizeKey($key);

        return $this->store($tags)->increment(
            $key,
            $amount,
        );
    }

    public function decrement(
        string $key,
        int $amount = 1,
        array $tags = [],
    ): int|bool {
        $key = $this->normalizeKey($key);

        return $this->store($tags)->decrement(
            $key,
            $amount,
        );
    }

    public function has(
        string $key,
        array $tags = [],
    ): bool {
        $key = $this->normalizeKey($key);

        $result = $this->store($tags)->has($key);

        // Log::info('Cache HAS', [
        //     'key' => $key,
        //     'tags' => $tags,
        //     'result' => $result,
        // ]);

        return $result;
    }

    public function missing(
        string $key,
        array $tags = [],
    ): bool {
        return ! $this->has($key, $tags);
    }

    public function forget(
        string $key,
        array $tags = [],
    ): bool {
        $key = $this->normalizeKey($key);

        $result = $this->store($tags)->forget($key);

        // Log::info('Cache FORGET', [
        //     'key' => $key,
        //     'tags' => $tags,
        //     'success' => $result,
        // ]);

        return $result;
    }

    public function forgetMany(
        array $keys,
        array $tags = [],
    ): bool {
        $success = true;

        foreach ($keys as $key) {
            if (! $this->forget($key, $tags)) {
                $success = false;
            }
        }

        return $success;
    }

    public function refresh(
        string $key,
        DateInterval|DateTimeInterface|int|null $ttl,
        callable $callback,
        array $tags = [],
    ): mixed {
        $this->forget($key, $tags);

        return $this->remember(
            $key,
            $ttl,
            $callback,
            $tags,
        );
    }

    public function lock(
        string $name,
        int $seconds = 0,
        ?string $owner = null,
    ): ?Lock {
        $store = $this->store->getStore();

        if ($store instanceof LockProvider) {
            return $store->lock(
                $name,
                $seconds,
                $owner,
            );
        }

        return null;
    }

    public function flushTags(array $tags): bool
    {
        if (! $this->supportsTags()) {
            // Log::warning('Cache TAG FLUSH skipped - tags unsupported', [
            //     'tags' => $tags,
            // ]);

            return false;
        }

        $normalizedTags = CacheTag::tags(...$tags);

        if ($normalizedTags === []) {
            return false;
        }

        try {
            $result = $this->store($normalizedTags, false)->flush();

            // Log::info('Cache TAG FLUSH', [
            //     'tags' => $normalizedTags,
            //     'success' => $result,
            // ]);

            return $result;
        } catch (Throwable $e) {
            // Log::error('Cache TAG FLUSH failed', [
            //     'tags' => $normalizedTags,
            //     'error' => $e->getMessage(),
            // ]);

            return false;
        }
    }

    public function flushAll(): bool
    {
        $result = $this->store->flush();

        // Log::info('Cache FLUSH ALL', [
        //     'success' => $result,
        // ]);

        return $result;
    }

    public function supportsTags(): bool
    {
        return $this->tagsSupported;
    }

    public function getStore(): Store
    {
        return $this->store->getStore();
    }

    private function normalizeKey(string $key): string
    {
        return '_'.ltrim($key, '_');
    }

    private function resolveStore(): Repository
    {
        $store = $this->cache->store($this->storeName);

        if (! $store instanceof Repository) {
            throw new \LogicException(
                'Laravel infrastructure requires Illuminate\\Cache\\Repository.',
            );
        }

        return $store;
    }

    private function resolveTagsSupport(): bool
    {
        return $this->store->getStore() instanceof TaggableStore;
    }

    private function store(array $tags = [], bool $forRead = true): Repository|TaggedCache
    {
        $normalizedTags = CacheTag::tags(...$tags);
        if ($forRead && $normalizedTags !== []
            && (bool) config('laravel-infrastructure.auto_invalidation.enabled', false)) {
            $normalizedTags = CacheTag::withReadDependencies($normalizedTags, DB::connection());
        }

        if ($normalizedTags === []) {
            return $this->store;
        }

        if (! $this->tagsSupported) {
            return $this->store;
        }

        return $this->store->tags($normalizedTags);
    }
}
