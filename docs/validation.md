# Validation and automatic context reuse

`RepositoryFormRequest`, `RepositoryValidationRule`, `RepositoryValidationService` and `ValidationContext` support repository-backed validation.

## Basic repository validation

```php
protected function repositoryValidationRules(): array
{
    return [
        new RepositoryValidationRule(
            repository: CategoryRepository::class,

            exists: [
                'category_id',
            ],
        ),
    ];
}
```

## Resolver example

Resolve the validated model once, give it a name and optionally preload allowed relations:

```php
protected function repositoryValidationRules(): array
{
    return [
        new RepositoryValidationRule(
            repository: CategoryRepository::class,

            exists: [
                'category_id',
            ],

            resolve: [
                [
                    'field' => 'category_id',
                    'as' => 'category',
                    'with' => ['parent'],
                ],
            ],
        ),
    ];
}
```

Use the named resolved model directly from the request:

```php
$category = $request->resolvedModel(
    'category',
    Category::class,
);
```

Or use the repository normally:

```php
$category = $categories->findOrFail(
    $request->validated('category_id'),
);
```

The normal repository lookup automatically reuses the already-resolved model when it matches.

Resolver options:

```php
[
    'field' => 'category_id',
    'as' => 'category',
    'with' => ['parent'],
]
```

- `field`: validated input field.
- `as`: optional alias used by `resolvedModel()` / `resolved()`.
- `with`: optional allowed relations to preload.

## Automatic reuse

`ValidationContext` is a scoped identity map:

- existing matching models are reused before querying;
- missing relations are loaded onto the same model instance;
- `findWhereIn()` queries only values missing from context;
- newly queried repository records are remembered automatically;
- create/update/restore refresh context;
- delete/force-delete evict context entries;
- failed validation restores the previous context.

Repository writes are transaction-aware. Reads bypass the identity map inside open database transactions, and write-side context mutations are deferred until commit so rolled-back writes cannot leave stale models in context.
