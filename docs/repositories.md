# Repositories

Extend `BaseRepository` for shared CRUD, pagination, streaming, aggregates, duplicate/existence helpers, validation-aware lookup and cache integration.

Configure request-facing query capabilities explicitly:

```php
protected array $searchable = ['name', 'email'];
protected array $allowedFilters = ['status'];
protected array $allowedRelationFilters = ['country.code'];
protected array $allowedSorts = ['name', 'created_at'];
protected array $allowedRelations = ['country', 'orders'];
protected array $allowedScopes = ['active'];
```

Selected/aggregate/helper columns are validated against the model schema before being used.


Bulk mutation semantics follow the single-record APIs:

- `bulkRestore()` returns `0` for models that do not use `SoftDeletes`, matching `restore()` returning `false`.
- `bulkForceDelete()` permanently deletes soft-deleted models and falls back to normal model deletion for non-soft-delete models, matching `forceDelete()`.
- successful bulk mutations invalidate repository cache state even when the model does not use the optional cache observer trait.

`withSum()` and `withAvg()` validate the related column through the shared schema registry. Unknown aggregate columns throw `InvalidArgumentException` instead of being silently ignored. Nested dotted relation aggregates are rejected explicitly because they are not consistently supported by Laravel's native aggregate API across the package's declared framework range.

`findWhereIn()` normalizes primary-key identifier values before deduplication and ordering. Integer keys treat equivalent numeric strings such as `2` and `"002"` as the same identifier; string/UUID keys remain string identifiers. Returned models preserve the first requested identifier order while duplicates and missing values are omitted.

## Pagination caching

`paginate()` caches each page by default using the repository's configured TTL, with no additional configuration:

```php
$products->paginate(filters: ['status' => 'active'], perPage: 20);
$products->paginate(perPage: 20, useCache: false); // uncached read
$products->paginate(perPage: 20, cacheTtl: 30);    // 30 seconds for this call
```

Caching respects model settings, the repository's `withoutCache()` flag, write invalidation, and transaction bypass. The optional `cacheTtl` must be positive and does not mutate the repository's TTL. Page number, filters, sorting, page size, columns, page name, request path and SQL scopes determine cache identity.
