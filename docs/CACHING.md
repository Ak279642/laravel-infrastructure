# Caching

## Default behavior

Repository caching is enabled by default:

~~~php
$users = $repository->get();
~~~

Use a fresh read only when required:

~~~php
$users = $repository
    ->withoutCache()
    ->get();
~~~

withoutCache(), cacheTtl(), cacheTags(), and rememberForever() return repository clones rather than mutating the original repository. This prevents state leakage in Octane, queue workers, and long-running console processes.

The legacy withCache() method remains for backward compatibility but is deprecated because caching is already enabled by default.

## Cache stores

Repository entries use model/dependency tags. If tags are required but the configured store does not support tags, the package bypasses caching rather than creating entries it cannot invalidate safely.

Redis, Memcached, and Laravel's tag-capable array store are suitable. Confirm tag support for custom stores.

## Deterministic keys

CacheKey::make() recursively normalizes parameters.

- associative key ordering does not change the key;
- CacheKey::unordered() makes list order semantically irrelevant;
- models include class + primary key;
- enums and dates are normalized;
- unsupported arbitrary objects/resources are rejected rather than silently collapsing into a colliding representation.

A package-specific key prefix prevents collisions with unrelated application keys.

## Custom repository methods

~~~php
public function activeForCountry(int $countryId)
{
    return $this->cacheRemember(
        'activeForCountry',
        fn () => $this->query()
            ->where('country_id', $countryId)
            ->where('status', 'active')
            ->get(),
        ['country_id' => $countryId],
    );
}
~~~

For eager-loaded dependencies, include relations in cache params:

~~~php
return $this->cacheRemember(
    'dashboard',
    fn () => $this->query()
        ->with('orders.items.product')
        ->findOrFail($id),
    [
        'id' => $id,
        'with' => ['orders.items.product'],
    ],
);
~~~

Nested dependency tags are resolved recursively. Mutating a cache-aware nested model invalidates entries tagged with that dependency.

## Invalidation

Repository writes clear the repository model tag. Cache-aware Eloquent models also invalidate after successful create, update, delete, restore, and force-delete events.

### Direct database and pivot writes

The package cannot detect writes that bypass Eloquent:

~~~php
DB::table('users')
    ->where('id', $id)
    ->update(['status' => 'inactive']);
~~~

After such writes:

~~~php
$repository->clearCache();
~~~

The same rule applies to raw pivot mutations that do not touch a cache-aware model.

## Cache observability

Enable:

~~~dotenv
LARAVEL_INFRASTRUCTURE_CACHE_EVENTS=true
~~~

Events:

- CacheHit
- CacheMiss
- CacheInvalidated
- CacheBypassed

Events contain keys, tags, and reasons only; cached values are never exposed.

## Stampede protection

When the cache store implements Laravel's LockProvider, cache misses use a key-specific lock.

~~~php
'cache' => [
    'lock' => [
        'enabled' => true,
        'seconds' => 10,
        'wait_seconds' => 3,
    ],
],
~~~

The callback runs only after a second cache check under the lock. Locks are released even when the callback throws.

On lock timeout/unavailability the package prioritizes availability, executes the callback without caching that bypass result, and emits CacheBypassed.

## Fluent query state

Fluent orderBy(), with(), and scope() methods operate on repository clones. Because arbitrary builder state is not encoded into generic repository cache keys, those customized chains bypass caching to prevent key collisions.

Prefer filter-array APIs for cached request-driven queries.

## Forever caching

~~~php
$result = $repository
    ->rememberForever()
    ->get();
~~~

Use only where the Eloquent invalidation path is reliable.
