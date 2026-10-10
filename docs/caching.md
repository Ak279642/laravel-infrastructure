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

**Already using Eloquent global scopes? No additional configuration is required.** The package respects normal Laravel global scopes without depending on their class names. Scope SQL/bindings and available actor identity contribute to cache keys; models with custom global scopes use conservative model-wide invalidation.

**No global ownership scope?** Specify the database column and Laravel authentication guard:

```php
protected function cacheOptions(): array
{
    return ['scope' => [
        'column' => 'partner_id',
        'guard' => 'partner',
    ]];
}
```

This filters by `partner_id = auth('partner')->id()`. A missing authenticated guard or required attribute rejects the scoped read instead of returning unrestricted records.

**Multiple columns, even with the same guard:** Use `scopes`. By default conditions are combined with **AND**. Set `scope_operator => 'or'` if matching **any** of the columns should grant a match:

```php
protected function cacheOptions(): array
{
    return [
        'scopes' => [
            ['column' => 'user_id', 'guard' => 'web'],
            ['column' => 'assigned_to', 'guard' => 'web'],
            ['column' => 'assigned_by', 'guard' => 'web'],
        ],
        'scope_operator' => 'or',
    ];
}
```

The package groups the OR conditions within parentheses, so any existing Eloquent global scope still applies as an additional condition. With `and`, every column must match the configured actor.

**Different guards and a non-ID attribute:**

```php
protected function cacheOptions(): array
{
    return ['scopes' => [
        ['column' => 'partner_id', 'guard' => 'partner'],
        ['column' => 'company_id', 'guard' => 'admin', 'attribute' => 'company_id'],
    ]];
}
```

With the default AND operator, both guards must be authenticated. Set `'scope_operator' => 'or'` to match rules for whichever named guards are authenticated; inactive guards are skipped, and a request with **no matching authenticated guard fails closed**. If several named guards are authenticated at once, their rules are OR-combined. Use your existing Eloquent visibility scope for more complex authorization decisions.

Existing `scope => 'user' | 'tenant'`, `scope_column`, and `scopes => ['column' => 'auth.id' | 'auth.attribute' | 'actor.id' | Closure]` configurations remain supported. Optional `actor_resolver` and `visibility_resolver` extensions remain available for backward compatibility; they are not auto-detected.

**Cache isolation and invalidation:** Resolved column values and AND/OR operator are included in the cache keys. Simple AND ownership scopes use targeted tags for the previous and new owner after writes. OR rules and custom Eloquent global scopes use model-wide invalidation because a record can appear in multiple actors' result sets. Permission or group membership changes that do not write to the cached model require explicit invalidation. Caching does not replace authorization.

Use Redis or another tagged cache store for persistent repository caches. The package does not cache tagged entries on stores without tag support.

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
