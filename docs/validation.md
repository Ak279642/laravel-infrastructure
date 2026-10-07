# Validation and automatic context reuse

`RepositoryFormRequest`, `RepositoryValidationService`, `RepositoryValidationRule` and `ValidationContext` support repository-backed exists/unique/collection validation.

`ValidationContext` is a scoped identity map:

- existing matching models are reused before querying;
- missing relations are loaded onto the same model instance;
- `findWhereIn()` queries only values missing from context;
- newly queried validation records are remembered automatically;
- create/update/restore refresh context;
- delete/force-delete evict context entries;
- failed validation restores the previous context.

This prevents repeated validation -> service -> repository lookups within one request/use case.


Repository writes are transaction-aware. During an open database transaction, repository reads bypass the identity map and write-side context mutations are deferred until commit. Rolled-back creates, updates, deletes, restores, and bulk writes therefore cannot leave phantom or stale models in ValidationContext.
