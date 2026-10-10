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

## Ownership, global scopes and query-builder filters

Repository reads respect Eloquent global scopes automatically. Scoped SQL and
bindings, along with repository-wide query-builder filters, participate in cache
keys. Without declared ownership boundaries, the package uses safe model-wide
invalidation for arbitrary visibility policies.

For a single owner, specify a column and optionally a guard:

```php
protected function cacheOptions(): array
{
    return ['scope' => ['column' => 'partner_id', 'guard' => 'partner']];
}
```

The guard is optional and defaults to the current Laravel guard. An optional
attribute (such as tenant_id) may be resolved instead of the guard's ID.
Missing required identities fail closed.

For creator, assignee, or assigner visibility, each short column name resolves
to the authenticated user's ID:

```php
protected function cacheOptions(): array
{
    return [
        'scopes' => ['user_id', 'assigned_to', 'assigned_by'],
        'scope_operator' => 'or',
    ];
}
```

Legacy explicit guard rules, multiple guards, associative auth/actor sources,
and the old user/tenant aliases are backward compatible. An OR rule can use
any active named guard, but never returns unrestricted data when none matches.

Tenant AND ownership requires a grouped alternative visibility declaration:

```php
protected function cacheOptions(): array
{
    return [
        'scope' => ['tenant_id' => 'auth.tenant_id'],
        'visibility' => ['any' => ['user_id', 'assigned_to', 'assigned_by']],
    ];
}
```

This yields tenant_id = ? AND (user_id = ? OR assigned_to = ? OR
assigned_by = ?). Tenant/owner pairs are the invalidation dependencies. Writes
invalidate old and new visibility groups for changed rows; unrelated tenants
are not flushed.

Already using an Eloquent tenant global scope? It is applied automatically;
no scope configuration is needed for correct isolation. For precise tenant
invalidation, declare only the boundary:

```php
protected function cacheOptions(): array
{
    return ['scope' => 'tenant_id'];
}
```

A column-only scope is a cache-partition marker; it does NOT add a WHERE clause.
It requires an Eloquent global scope and assumes that scope strictly limits
results to the named partition. Do not use it for shared cross-tenant records
or a global scope that only filters status. Such policies require broad
invalidation or a precise ownership restriction.

Repository-wide query-builder filters also participate in cache identity:

```php
$openTasks = $tasks->filterQuery(
    fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('status', 'open'),
);
$openTasks->paginate(perPage: 20);
```

An application repository can alternatively override
globalQueryFilter(Builder $query): Builder. Allowed local scope() modifiers
are also included in the effective query. Compiling SQL and bindings adds
no database read.

Cache invalidation principles:

- AND partitions use composite tags; OR ownership uses per-column visibility
  tags; tenant plus OR ownership uses tenant/owner composite tags.
- Model events capture original and new ownership values and invalidate only
  potentially affected groups, after the transaction commits.
- All entries ALSO retain the model tag for guaranteed broad fallback when
  ownership cannot be safely calculated.
- Unknown global scopes, custom visibility resolvers, mixed OR partitions with
  visibility.any, and unbounded policies conservatively use the model tag.
- Model-level actor_resolver and visibility_resolver remain supported. Changes
  to permissions or membership outside the cached model need explicit
  invalidation. Caching is not an authorization replacement.
- Raw DB::table writes do not fire Eloquent model events: explicitly invalidate
  the affected cache or use normal model writes.
- Use Redis or another cache store supporting Laravel tags. Non-taggable
  stores bypass cached repository reads for correctness.

### Precise invalidation for custom visibility resolvers

A custom class visibility resolver still works with no new configuration and
uses safe model-wide invalidation by default. For precise invalidation, it may
optionally implement BOTH of these methods in addition to its existing apply()
method:

```php
public function cacheReadTags(Model $model, ?array $actor): array
{
    return [$model::cacheTag().':viewer:'.$actor['id']];
}

public function cacheInvalidationTags(Model $model): array
{
    $prefix = $model::cacheTag().':viewer:';

    return [
        $prefix.$model->getRawOriginal('user_id'),
        $prefix.$model->getAttribute('user_id'),
    ];
}
```

The values must represent the FULL visibility policy, including shared
records, group membership, or administrative access if applicable. Invalid or
empty read tag lists are rejected rather than cached unsafely. Empty write
tags instead trigger model-wide invalidation. Never opt into targeted tags
for visibility branches that those tags do not cover.

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

## Request-only caching and application cache policies

Repositories may override `cacheReadPolicy(string $operation, array $params): ?array`.
The default `null` preserves the repository's standard tagged persistent caching
and is backward compatible. An application policy can choose:

- `['mode' => 'persistent', 'params' => ['role' => $roleId], 'ttl' => 120]`:
  persist with extra authorization context included in the cache key.
- `['mode' => 'request', 'params' => ['actor' => $actorId]]`:
  memoize a read only for the current request, never to Redis.
- `['mode' => 'none']`: run the callback without caching.

Explicit `withoutCache()`, `useCache: false`, disabled models and reads inside an
open transaction bypass **all** policy caching. Request-only memoization is held
on Laravel request attributes, not static globals; it caches `null` correctly.
Eloquent writes clear that memo immediately, including during transactions.
The package automatically includes global scopes, compiled SQL/bindings and
resolved actors in repository cache keys. The application must add policy
context for permissions not reflected in SQL (for example role changes).

### Efficient set-based SQL and pivot mutations

The existing `bulkUpdate()`/`bulkDelete()` API intentionally hydrates and
writes each model, triggering Eloquent events. For very large sets, using one
SQL statement is more efficient, but Eloquent will not emit model events.
Use the inherited package helper from inside a repository:

```php
DB::transaction(function () use ($ids) {
    $count = DB::table('tasks')->whereIn('id', $ids)->update(['status' => 'done']);
    if ($count > 0) {
        $this->invalidateAfterBulkWrite([
            CacheTag::fromModel(OtherDependentModel::class),
        ]);
    }
});
```

This invalidates the model and dependency tags **after commit**, without
reading or hydrating the changed rows. On rollback the shared cache remains.
Without a reliable old/new ownership snapshot, bulk invalidation must use
the model-wide tag: attempting a specific actor's scope could leave stale
caches for previous owners or other viewers.

For a pivot write or raw query in a service (outside a repository), inject
`CacheInvalidator` and call
`invalidateAfterCommit($modelTags, $connection)` after the SQL change.

### Dependency-safe batched lookups

For reports where one query calculates several missing actor or tenant buckets,
use `CacheManager::getWithDependencies($key, $missing, $tags, $connection)`
and `putWithDependencies($key, $value, $ttlSeconds, $tags, $connection)`.
Both require nonempty tags and skip shared caching when the store lacks tags or
the connection is inside a transaction. Reads return the supplied missing value
in either case, while writes return false. Calculate all missing buckets in one
SQL operation instead of replacing batch computation with per-actor reads.

Include the complete set of model dependency tags. Eloquent changes invalidate
their model tags after commit; raw SQL changes require an explicit
`CacheInvalidator::invalidateAfterCommit(...)`. The application selects the
dependency models, but the package owns cache storage and safety.

### Cached custom SQL reads

Use `CacheManager::rememberWithDependencies($key, $ttlSeconds, $callback,
$tags, $connection)` when a specialized read cannot use repository caching.
It requires dependency tags and a positive TTL; if tags are unavailable
or the passed connection has an open transaction, it executes the callback
without publishing potentially stale or uncommitted data. Call
`CacheInvalidator::invalidateAfterCommit($tags, $connection)` for SQL changes
to those dependencies. These operations do not introduce additional database
reads; Redis/tag support is recommended.



## Automatic raw-SQL and pivot invalidation (opt-in)

Laravel Infrastructure can observe **successful** database writes without
turning set-based mutations into N individual Eloquent saves. Opt in:

```php
// config/laravel-infrastructure.php
'auto_invalidation' => [
    'enabled' => true,
    // Declarative cross-table dependencies, not per-write flush calls:
    'table_dependencies' => [
        'partner_pincodes' => [Partner::class, ServiceRequest::class],
        'admin_role_permissions' => [AdminRole::class, Admin::class],
    ],
],
```

- The package listens to Laravel `QueryExecuted` (SQL **already executed**),
  parses standard INSERT/UPDATE/DELETE/REPLACE/TRUNCATE targets and attaches
  physical root-model table tags to repository reads and model-tagged custom
  cached reads without compiling the repository query a second time.
- On a successful write, the relevant table and declared dependent model tags
  are invalidated. Within transactions, changed tags are deduplicated and
  flushed once after commit; full rollback discards them.
- Changes to pivot/visibility tables cannot always be inferred from the
  cached root model: declare their affected models once in
  `table_dependencies`. The application does not call an invalidation method
  after each write.
- No additional database queries, model hydration, or per-record updates.
  The package still honors its existing `cacheReadPolicy` and scope logic.
- Complex or unrecognized mutation SQL invalidates a conservative
  connection/database fallback tag rather than risking stale results.
- This mode is intentionally **more conservative** than scoped Eloquent
  observer invalidation. Writes can evict unrelated cached owner partitions
  for the same physical table. Measure Redis hit rate before enabling it
  globally when extremely high cache retention is required.
- The watcher observes only writes executed through **the current Laravel
  connection**. External jobs/applications writing through another process
  with no watcher, database triggers writing other tables, queue events that
  do not write through Laravel, and arbitrary untagged cache keys cannot be
  made automatically coherent without an external change feed.
- Use a tag-capable Laravel store (Redis recommended). No separate package
  cache store exists. Repository read caching falls back to uncached reads
  on stores without tags to preserve correctness.

**This is automatic invalidation, not automatic caching of `query()`.**
Repository methods and explicit package cache operations remain the read
caching boundary.

## Batch read caching without get/put orchestration

For metrics and reports that use a single SQL query for all missing actors,
use the package's unified `rememberBatch()` API:

```php
$values = $cache->rememberBatch(
    'metrics:partner', $partnerIds, 3600,
    fn (array $missingIds): array => $repository->metricsForPartners($missingIds),
    fn (int $id): array => [CacheTag::fromModel(ServiceRequest::class), 'partner:'.$id],
    $repository->getModel()->getConnection(),
);
```

It deduplicates IDs, reads cache hits, executes the loader **once for all
misses**, and populates each missing entry with dependency tags. It preserves
the first-seen requested ID order and uses the normal after-commit write
invalidation. It does not hydrate models or introduce per-ID SQL reads.
On non-taggable stores or open transactions it executes the loader without
publishing stale or uncommitted values.
