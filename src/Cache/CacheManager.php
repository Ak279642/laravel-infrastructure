<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache;

use Ak279642\LaravelInfrastructure\Cache\Events\CacheBypassed;
use Ak279642\LaravelInfrastructure\Cache\Events\CacheHit;
use Ak279642\LaravelInfrastructure\Cache\Events\CacheInvalidated;
use Ak279642\LaravelInfrastructure\Cache\Events\CacheMiss;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Cache\TaggableStore;
use Illuminate\Cache\TaggedCache;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

final class CacheManager
{
    private CacheRepository $store;

    private bool $tagsSupported;

    public function __construct(
        private readonly Factory $cache,
        private readonly ?string $storeName = null,
        private readonly ?Dispatcher $events = null,
    ) {
        $this->store = $this->resolveStore();
        $this->tagsSupported = $this->resolveTagsSupport();
    }

    /** @param list<string> $tags */
    public function get(
        string $key,
        mixed $default = null,
        array $tags = [],
    ): mixed {
        $key = $this->normalizeKey($key);
        $tags = CacheTag::tags(...$tags);

        if ($this->tagsUnsupported($tags)) {
            $this->emit(new CacheBypassed($key, 'tags_unsupported', $tags));

            return $default;
        }

        $store = $this->store($tags);

        if ($store->has($key)) {
            $this->emit(new CacheHit($key, $tags));

            return $store->get($key);
        }

        $this->emit(new CacheMiss($key, $tags));

        return $default;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    /** @param list<string> $keys @param list<string> $tags @return array<string, mixed> */
    public function many(
        array $keys,
        array $tags = [],
    ): array {
        $tags = CacheTag::tags(...$tags);

        if ($this->tagsUnsupported($tags)) {
            foreach ($keys as $key) {
                $this->emit(new CacheBypassed(
                    $this->normalizeKey($key),
                    'tags_unsupported',
                    $tags,
                ));
            }

            return array_fill_keys($keys, null);
        }

        $normalizedKeys = array_map(
            fn (string $key): string => $this->normalizeKey($key),
            $keys,
        );

        return $this->store($tags)->many($normalizedKeys);
    }

    /** @param list<string> $tags */
    public function put(
        string $key,
        mixed $value,
        DateInterval|DateTimeInterface|int|null $ttl = null,
        array $tags = [],
    ): bool {
        $key = $this->normalizeKey($key);
        $tags = CacheTag::tags(...$tags);

        if ($this->tagsUnsupported($tags)) {
            $this->emit(new CacheBypassed($key, 'tags_unsupported', $tags));

            return false;
        }

        return $this->write(
            $this->store($tags),
            $key,
            $value,
            $ttl,
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    /** @param array<string, mixed> $values @param list<string> $tags */
    public function putMany(
        array $values,
        DateInterval|DateTimeInterface|int|null $ttl = null,
        array $tags = [],
    ): bool {
        $tags = CacheTag::tags(...$tags);

        if ($this->tagsUnsupported($tags)) {
            foreach (array_keys($values) as $key) {
                $this->emit(new CacheBypassed(
                    $this->normalizeKey((string) $key),
                    'tags_unsupported',
                    $tags,
                ));
            }

            return false;
        }

        $normalizedValues = [];

        foreach ($values as $key => $value) {
            $normalizedValues[$this->normalizeKey((string) $key)] = $value;
        }

        if ($ttl === null) {
            $success = true;

            foreach ($normalizedValues as $key => $value) {
                if (! $this->store($tags)->forever($key, $value)) {
                    $success = false;
                }
            }

            return $success;
        }

        return $this->store($tags)->putMany(
            $normalizedValues,
            $ttl,
        );
    }

    /** @param list<string> $tags */
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

    /** @param list<string> $tags */
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

    /** @param callable(): mixed $callback @param list<string> $tags */
    public function remember(
        string $key,
        DateInterval|DateTimeInterface|int|null $ttl,
        callable $callback,
        array $tags = [],
    ): mixed {
        $key = $this->normalizeKey($key);
        $tags = CacheTag::tags(...$tags);

        if ($this->tagsUnsupported($tags)) {
            return $this->bypassNormalized(
                $key,
                $callback,
                $tags,
                'tags_unsupported',
            );
        }

        $store = $this->store($tags);

        if ($store->has($key)) {
            $this->emit(new CacheHit($key, $tags));

            return $store->get($key);
        }

        $this->emit(new CacheMiss($key, $tags));

        if (! $this->locksEnabled()) {
            return $this->populate(
                $store,
                $key,
                $ttl,
                $callback,
            );
        }

        $lock = $this->lock(
            $this->rememberLockName($key),
            max(1, (int) config(
                'laravel-infrastructure.cache.lock.seconds',
                10,
            )),
        );

        if (! $lock) {
            return $this->populate(
                $store,
                $key,
                $ttl,
                $callback,
            );
        }

        $waitSeconds = max(0, (int) config(
            'laravel-infrastructure.cache.lock.wait_seconds',
            3,
        ));

        try {
            if ($waitSeconds === 0) {
                if (! $lock->get()) {
                    return $this->bypassNormalized(
                        $key,
                        $callback,
                        $tags,
                        'lock_unavailable',
                    );
                }

                try {
                    return $this->populateAfterLock(
                        $store,
                        $key,
                        $ttl,
                        $callback,
                        $tags,
                    );
                } finally {
                    $lock->release();
                }
            }

            return $lock->block(
                $waitSeconds,
                fn () => $this->populateAfterLock(
                    $store,
                    $key,
                    $ttl,
                    $callback,
                    $tags,
                ),
            );
        } catch (LockTimeoutException) {
            return $this->bypassNormalized(
                $key,
                $callback,
                $tags,
                'lock_timeout',
            );
        }
    }

    /** @param callable(): mixed $callback @param list<string> $tags */
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

    /** @param callable(): mixed $callback @param list<string> $tags */
    public function bypass(
        string $key,
        callable $callback,
        array $tags = [],
        string $reason = 'disabled',
    ): mixed {
        return $this->bypassNormalized(
            $this->normalizeKey($key),
            $callback,
            CacheTag::tags(...$tags),
            $reason,
        );
    }

    /** @param list<string> $tags */
    public function pull(
        string $key,
        mixed $default = null,
        array $tags = [],
    ): mixed {
        $key = $this->normalizeKey($key);
        $tags = CacheTag::tags(...$tags);

        if ($this->tagsUnsupported($tags)) {
            $this->emit(new CacheBypassed($key, 'tags_unsupported', $tags));

            return $default;
        }

        return $this->store($tags)->pull(
            $key,
            $default,
        );
    }

    /** @param list<string> $tags */
    public function add(
        string $key,
        mixed $value,
        DateInterval|DateTimeInterface|int|null $ttl = null,
        array $tags = [],
    ): bool {
        $key = $this->normalizeKey($key);
        $tags = CacheTag::tags(...$tags);

        if ($this->tagsUnsupported($tags)) {
            $this->emit(new CacheBypassed($key, 'tags_unsupported', $tags));

            return false;
        }

        if ($ttl === null) {
            return $this->store($tags)->add(
                $key,
                $value,
                PHP_INT_MAX,
            );
        }

        return $this->store($tags)->add(
            $key,
            $value,
            $ttl,
        );
    }

    /** @param list<string> $tags */
    public function increment(
        string $key,
        int $amount = 1,
        array $tags = [],
    ): int|bool {
        $key = $this->normalizeKey($key);
        $tags = CacheTag::tags(...$tags);

        if ($this->tagsUnsupported($tags)) {
            $this->emit(new CacheBypassed($key, 'tags_unsupported', $tags));

            return false;
        }

        return $this->store($tags)->increment(
            $key,
            $amount,
        );
    }

    /** @param list<string> $tags */
    public function decrement(
        string $key,
        int $amount = 1,
        array $tags = [],
    ): int|bool {
        $key = $this->normalizeKey($key);
        $tags = CacheTag::tags(...$tags);

        if ($this->tagsUnsupported($tags)) {
            $this->emit(new CacheBypassed($key, 'tags_unsupported', $tags));

            return false;
        }

        return $this->store($tags)->decrement(
            $key,
            $amount,
        );
    }

    /** @param list<string> $tags */
    public function has(
        string $key,
        array $tags = [],
    ): bool {
        $key = $this->normalizeKey($key);
        $tags = CacheTag::tags(...$tags);

        if ($this->tagsUnsupported($tags)) {
            $this->emit(new CacheBypassed($key, 'tags_unsupported', $tags));

            return false;
        }

        return $this->store($tags)->has($key);
    }

    /** @param list<string> $tags */
    public function missing(
        string $key,
        array $tags = [],
    ): bool {
        return ! $this->has($key, $tags);
    }

    /** @param list<string> $tags */
    public function forget(
        string $key,
        array $tags = [],
    ): bool {
        $key = $this->normalizeKey($key);
        $tags = CacheTag::tags(...$tags);

        if ($this->tagsUnsupported($tags)) {
            $this->emit(new CacheBypassed($key, 'tags_unsupported', $tags));

            return false;
        }

        return $this->store($tags)->forget($key);
    }

    /** @param list<string> $keys @param list<string> $tags */
    public function forgetMany(
        array $keys,
        array $tags = [],
    ): bool {
        $success = true;

        foreach ($keys as $key) {
            if (! $this->forget((string) $key, $tags)) {
                $success = false;
            }
        }

        return $success;
    }

    /** @param callable(): mixed $callback @param list<string> $tags */
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

    /** @param list<string> $tags */
    public function flushTags(array $tags): bool
    {
        $normalizedTags = CacheTag::tags(...$tags);

        if ($normalizedTags === []) {
            return false;
        }

        if (! $this->supportsTags()) {
            $this->emit(new CacheBypassed(
                '',
                'tags_unsupported',
                $normalizedTags,
            ));

            return false;
        }

        try {
            $result = $this->store($normalizedTags)->flush();

            $this->emit(new CacheInvalidated(
                $normalizedTags,
                $result,
            ));

            return $result;
        } catch (Throwable) {
            $this->emit(new CacheInvalidated(
                $normalizedTags,
                false,
            ));

            return false;
        }
    }

    public function flushAll(): bool
    {
        $result = $this->store->flush();

        $this->emit(new CacheInvalidated([], $result));

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
        $key = trim($key);

        $prefix = trim(
            (string) config(
                'laravel-infrastructure.cache.key_prefix',
                'laravel-infrastructure',
            ),
            " :\t\n\r\0\x0B",
        );

        $key = ltrim($key, ':');

        return $prefix === ''
            ? $key
            : $prefix.':'.$key;
    }

    private function resolveStore(): CacheRepository
    {
        return $this->cache->store($this->storeName);
    }

    private function resolveTagsSupport(): bool
    {
        return $this->store->getStore() instanceof TaggableStore;
    }

    /** @param list<string> $tags */
    private function store(array $tags = []): CacheRepository|TaggedCache
    {
        if ($tags === []) {
            return $this->store;
        }

        return $this->store->tags($tags);
    }

    /** @param list<string> $tags */
    private function tagsUnsupported(array $tags): bool
    {
        return $tags !== [] && ! $this->tagsSupported;
    }

    private function locksEnabled(): bool
    {
        return (bool) config(
            'laravel-infrastructure.cache.lock.enabled',
            true,
        );
    }

    private function rememberLockName(string $normalizedKey): string
    {
        return 'laravel-infrastructure:remember:'.hash(
            'sha256',
            $normalizedKey,
        );
    }

    /** @param callable(): mixed $callback */
    private function populate(
        CacheRepository|TaggedCache $store,
        string $key,
        DateInterval|DateTimeInterface|int|null $ttl,
        callable $callback,
    ): mixed {
        $value = $callback();

        $this->write($store, $key, $value, $ttl);

        return $value;
    }

    /** @param callable(): mixed $callback @param list<string> $tags */
    private function populateAfterLock(
        CacheRepository|TaggedCache $store,
        string $key,
        DateInterval|DateTimeInterface|int|null $ttl,
        callable $callback,
        array $tags,
    ): mixed {
        if ($store->has($key)) {
            $this->emit(new CacheHit($key, $tags));

            return $store->get($key);
        }

        return $this->populate(
            $store,
            $key,
            $ttl,
            $callback,
        );
    }

    /** @param mixed $value */
    private function write(
        CacheRepository|TaggedCache $store,
        string $key,
        mixed $value,
        DateInterval|DateTimeInterface|int|null $ttl,
    ): bool {
        if ($ttl === null) {
            return $store->forever($key, $value);
        }

        return $store->put($key, $value, $ttl);
    }

    /** @param callable(): mixed $callback @param list<string> $tags */
    private function bypassNormalized(
        string $normalizedKey,
        callable $callback,
        array $tags,
        string $reason,
    ): mixed {
        $this->emit(new CacheBypassed(
            $normalizedKey,
            $reason,
            $tags,
        ));

        return $callback();
    }

    private function emit(object $event): void
    {
        if (
            $this->events === null
            || ! (bool) config(
                'laravel-infrastructure.cache.events.enabled',
                false,
            )
        ) {
            return;
        }

        $this->events->dispatch($event);
    }
}
