# Repository validation and ValidationContext

RepositoryFormRequest runs ordinary Laravel validation first. Repository checks run only when those rules succeed.

~~~text
FormRequest rules()
    ↓
Laravel validation
    ↓
repositoryValidationRules()
    ↓
Repository queries
    ↓
ValidationContext
    ↓
Action / Service / Repository reuse
~~~

## Resolve a model

~~~php
new RepositoryValidationRule(
    repository: CustomerRepository::class,
    exists: [
        'customer_id' => [
            'where' => [
                'organization_id' => 'organization_id',
            ],
        ],
    ],
    resolve: [
        [
            'field' => 'customer_id',
            'as' => 'customer',
            'with' => ['organization'],
        ],
    ],
);
~~~

Later:

~~~php
$customer = $context->requireModel(
    'customer',
    Customer::class,
);
~~~

A normal repository findOrFail() can reuse the same resolved model by model class + primary key.

## Collections

existsIn can resolve an array of IDs into a named collection.

~~~php
$products = $context->requireCollection(
    'products',
    Product::class,
);
~~~

## Lifecycle safety

ValidationContext is registered as a Laravel scoped binding, not a singleton.

Laravel clears scoped instances between normal HTTP, Octane, and queue lifecycles. The package regression suite verifies a scoped lifecycle reset receives a clean context.

Do not rebind ValidationContext as an application singleton.

## Uniqueness

Repository uniqueness checks use fresh database reads. They improve validation UX but do not replace database unique constraints.
