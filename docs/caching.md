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

## Image caching

Public images automatically use `Cache-Control: public, max-age=86400` and metadata ETags. Protected, signed, and model-authorized images always use `private, no-store, max-age=0`. There is no separate assets cache configuration, and these HTTP browser headers are not Laravel's server-side cache store.
