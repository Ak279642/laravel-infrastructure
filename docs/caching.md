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

## User, tenant, multi-scope and global visibility (opt-in)

The legacy `scope => user|tenant` / `scope_column` configuration remains supported. For AND-combined ownership, configure a column-to-authenticated-value mapping:

```php
protected function cacheOptions(): array
{
    return [
        'scopes' => [
            'tenant_id' => 'auth.tenant_id',
            'user_id' => 'auth.id',
        ],
    ];
}
```

Every dimension is enforced as a query `WHERE` clause, included in repository cache keys and used for targeted composite ownership tags. Supported value resolvers: `auth.id`, `auth.<attribute>` or a closure accepting the authenticated user and returning a nonempty scalar. Missing credentials or values fail closed. Scope changes invalidate previous and new composite partitions after commit using in-memory attributes, without extra ownership queries.

For explicitly shared or global records, define an OR visibility resolver:

```php
protected function cacheOptions(): array
{
    return [
        'scopes' => ['tenant_id' => 'auth.tenant_id', 'user_id' => 'auth.id'],
        'visibility_resolver' => static function ($query, $user, array $scopes): void {
            $query->orWhere('is_global', true); // only if truly visible to all actors
        },
    ];
}
```

The repository builds `(owned conditions OR visibility conditions)`; implement authorization carefully inside the resolver. Cache keys also include the authenticated actor ID. Because shared/global results may be visible across scope partitions, enabling this resolver conservatively keeps **model-wide invalidation** rather than potentially serving stale results. Direct Eloquent queries, custom cached reports, membership changes and external query builders need their own authorization and invalidation rules; this package does not infer relationship-based visibility. Do not use a scoped repository for administrative cross-owner writes. Use Redis or another tag-capable store; the existing uncached fallback remains on non-taggable stores.

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
