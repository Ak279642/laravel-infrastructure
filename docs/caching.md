# Caching

The package uses **Laravel's default cache store**. Configure it once in your application's `config/cache.php` and `.env`:

```dotenv
CACHE_STORE=redis
```

No `laravel-infrastructure.cache` section, separate cache driver, or package cache environment variables are required. All repository caching, tags and locks operate through Laravel's configured cache store.

## Repository policy

Repositories use a default TTL of 5 minutes, which can be changed per repository or per call:

```php
use Ak279642\LaravelInfrastructure\Cache\CacheTtl;

final class ProductRepository extends BaseRepository
{
    protected function defaultCacheTtl(): int
    {
        return CacheTtl::MINUTES_10;
    }
}
```

```php
$product = $this->products->cacheTtl(CacheTtl::MINUTE)->findOrFail($id);
$product = $this->products->withCache(CacheTtl::MINUTES_15)->findOrFail($id);
$product = $this->products->withoutCache()->findOrFail($id);
$product = $this->products->rememberForever()->findOrFail($id);
$this->products->clearCache();
```

Disable caching for one model using its own options:

```php
protected function cacheOptions(): array
{
    return ['enabled' => false];
}
```

For application-wide behavior, change Laravel's cache store. For operation-level bypass use `withoutCache()`. Locks use repository defaults (10-second lock, 3-second wait) and can be adjusted by overriding the repository's protected `$cacheLockSeconds` and `$cacheLockWaitSeconds`.

## Ownership and Eloquent global scopes

**No configuration is required** for normal repositories. Laravel applies any Eloquent global scopes defined on your models (such as active status or application-defined visibility). The package detects custom global scopes without depending on their class names. Repository cache keys reflect the scoped SQL and bindings, and use broad model-level invalidation for safety. Permission/membership changes that do not update the cached model require explicit invalidation.

To enforce straightforward ownership when no Eloquent global visibility policy exists, opt in per model:

```php
protected function cacheOptions(): array
{
    return ['scopes' => [
        'company_code' => 'auth.company_code',
        'created_by' => 'auth.id',
    ]];
}
```

These conditions combine with AND. Alternatives: `actor.id`, `actor.partner_id`, `auth.<attribute>`, or a closure resolver. A legacy `scope => user|tenant` remains supported. Request attributes `user_id`, `user_type` (and optional `user`) supply actor identity without using a default auth guard. An absent required scope value fails closed.

Configured ownership allows targeted invalidation of previous and new ownership partitions after model writes, without extra database reads. Custom global scopes, which may expose records across partitions, use conservative model-wide invalidation instead. Eloquent global scopes remain responsible for data visibility; a cache scope is not a substitute for authorization. Custom `actor_resolver` or `visibility_resolver` classes remain optional overrides, but **no application-specific class name is auto-detected**.

Use Redis or another tagged cache store. Non-taggable stores preserve the uncached repository fallback.

## Image caching

Public images automatically use `Cache-Control: public, max-age=86400` and metadata ETags. Protected, signed, and model-authorized images always use `private, no-store, max-age=0`. There is no separate assets cache configuration, and these HTTP browser headers are not Laravel's server-side cache store.

## Pagination caching

The `paginate()` method caches results by default using the repository's normal TTL. No extra config is needed. Disable caching or override the TTL (in **seconds**) for an individual pagination call:

```php
// Repository TTL (default 5 minutes unless overridden by defaultCacheTtl()).
$products->paginate(perPage: 20);

// Cache just this page for 30 seconds; does not change the repository TTL.
$products->paginate(perPage: 20, cacheTtl: 30);

// Fetch a fresh page without populating or reading the repository cache.
$products->paginate(perPage: 20, useCache: false);
```

A `cacheTtl` override must be a positive integer. Cache keys vary by resolved page number, page parameter name, page size, selected columns, filters, sorts, relations, current URL path, scoped query SQL/bindings, connection, and effective TTL. This prevents sharing pages across differing query scopes or TTLs. Repository writes clear the tagged cache; `withoutCache()`, model cache settings, non-taggable stores, and open transactions follow the normal repository cache rules. A custom `cacheTtl` overrides `rememberForever()` **for that pagination call only**.

`simplePaginate()`, `cursorPaginate()`, `chunk()`, `lazy()` and `cursor()` keep their existing uncached behavior.

## Schema metadata cache

MySQL/MariaDB column validation uses a single database-wide
`information_schema.COLUMNS` snapshot, cached for one hour through Laravel's
existing cache store. The key includes the physical database identity and
connection name. Other databases retain per-table metadata caching.

Run `php artisan cache:clear` after schema migrations (especially when adding
new model fields) so workers fetch the updated schema. No package config
section or new cache driver is needed.
