# Caching

Repository reads support deterministic cache keys, model/dependency tags, TTL overrides, one-operation bypasses, forever caching and automatic after-commit invalidation for cache-aware models.

Global settings:

```dotenv
LARAVEL_INFRASTRUCTURE_CACHE_ENABLED=true
LARAVEL_INFRASTRUCTURE_CACHE_STORE=redis
LARAVEL_INFRASTRUCTURE_CACHE_TTL=300
LARAVEL_INFRASTRUCTURE_CACHE_LOCK_SECONDS=10
LARAVEL_INFRASTRUCTURE_CACHE_LOCK_WAIT_SECONDS=3
```

`rememberLocked()` protects expensive population from stampedes when the store supports locks and falls back safely on stores without locks.

Non-taggable stores bypass repository reads that require tag invalidation, favoring correctness over stale cache.


## Model-level cache control

Repository caching is enabled per model by default when global caching is enabled.

Disable it for one model without affecting other repositories:

```php
protected function cacheOptions(): array
{
    return [
        'enabled' => false,
    ];
}
```

Global disable always wins:

```dotenv
LARAVEL_INFRASTRUCTURE_CACHE_ENABLED=false
```

Priority:

```text
global false -> all repository caching disabled
global true + model false -> this model bypasses repository caching
global true + model true -> normal repository caching
```
