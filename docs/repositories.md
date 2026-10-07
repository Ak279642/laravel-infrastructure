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
