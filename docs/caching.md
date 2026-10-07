# Caching

Repository reads support deterministic keys, model/dependency tags, repository-specific TTLs, per-operation overrides, one-operation bypasses, forever caching, lock protection and after-commit invalidation.

## Global settings

Global config controls cache infrastructure only:

```dotenv
LARAVEL_INFRASTRUCTURE_CACHE_ENABLED=true
LARAVEL_INFRASTRUCTURE_CACHE_STORE=redis
LARAVEL_INFRASTRUCTURE_CACHE_LOCK_SECONDS=10
LARAVEL_INFRASTRUCTURE_CACHE_LOCK_WAIT_SECONDS=3
```

There is no global repository TTL.

## Repository TTL

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

If not overridden, repositories use `CacheTtl::MINUTES_5`.

Per operation:

```php
$product = $this->products
    ->cacheTtl(CacheTtl::MINUTE)
    ->findOrFail($id);

$product = $this->products
    ->withCache(CacheTtl::MINUTES_15)
    ->findOrFail($id);

$product = $this->products
    ->withoutCache()
    ->findOrFail($id);

$product = $this->products
    ->rememberForever()
    ->findOrFail($id);

$this->products->clearCache();
```

## Model-level cache switch

```php
protected function cacheOptions(): array
{
    return [
        'enabled' => false,
    ];
}
```

Global disable always wins. Otherwise the model switch and repository TTL policy apply.
