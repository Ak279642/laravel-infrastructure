# Repositories

Repositories own persistence and query infrastructure. They do not own HTTP concerns, business rules, or transaction boundaries.

## Configuration

~~~php
final class UserRepository extends BaseRepository
{
    protected array $searchable = [
        'name',
        'email',
    ];

    protected array $allowedFilters = [
        'status',
        'email',
        'profile.city',
    ];

    protected array $allowedSorts = [
        'name',
        'created_at',
    ];

    protected array $allowedRelations = [
        'roles',
        'profile',
        'orders.items',
    ];

    protected array $allowedScopes = [
        'active',
    ];

    protected array $defaultRelations = ['profile'];

    protected array $defaultOrder = [
        'created_at' => 'desc',
    ];
}
~~~

Empty allow-lists do not grant request-controlled access.

## Filters

Supported operators:

- =, !=, <>
- >, >=, <, <=
- like, ilike
- in, in_or_null, not_in
- between, not_between
- null, not_null

Preferred compact form:

~~~php
[
    'status' => 'active',
    'age' => ['>=', 18],
    'role_id' => ['in', [1, 2, 3]],
    'created_at' => ['between', [$from, $to]],
]
~~~

The historical associative operator/value form remains supported.

Nested filters such as profile.city must be present in allowedFilters and their relation path must also be in allowedRelations.

## Strict mode

Default behavior ignores unknown request inputs.

~~~php
protected bool $strictFilters = true;
protected bool $strictSorts = true;
protected bool $strictRelations = true;
~~~

Strict mode throws FilterNotAllowedException, SortNotAllowedException, or RelationNotAllowedException with the repository's allowed values.

## Search

Only server-defined searchable columns can be searched. A caller may narrow search_columns, but cannot expand beyond the repository list.

If an override contains only invalid columns, the package retains the repository's safe default search list instead of turning the search into an unfiltered query.

## Relations

with, with_count, with(), withCount(), withSum(), and withAvg() all pass through the relation allow-list.

Nested relations must be explicitly listed.

## Scopes

Only scopes in allowedScopes may be selected from request filters.

~~~php
$repository->get([
    'scopes' => ['active'],
]);
~~~

This prevents request input from invoking arbitrary internal local scopes.

## Sorting

~~~php
$repository->get([
    'sort' => ['name', '-created_at'],
]);
~~~

Only allowedSorts are accepted.

## Pagination and streaming

~~~php
$repository->paginate($filters, 25);
$repository->simplePaginate(25);
$repository->cursorPaginate(100);

$repository->chunk(500, $callback);
$repository->lazy(1000);
$repository->cursor();
~~~

These remain database-backed rather than caching request-specific paginator/cursor objects.

## Bulk writes

bulkUpdate(), bulkDelete(), bulkRestore(), and bulkForceDelete() process Eloquent models so lifecycle events run, then clear repository cache after successful mutations.

For true SQL bulk writes performed manually with DB::table(), invalidate manually.

## Duplicate checks

findDuplicate() intentionally performs a fresh database read. Database unique constraints remain the final correctness mechanism.
