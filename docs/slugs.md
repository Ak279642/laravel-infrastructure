# Slugs

Slug behavior is model-defined. There is no global slug configuration.

## Multiple fields

```php
protected function slugFields(): array
{
    return [
        'slug' => [
            'source' => 'name',
            'unique' => true,
            'regenerate_on_update' => false,
        ],

        'seo_slug' => [
            'source' => 'seo_title',
            'unique' => true,
            'regenerate_on_update' => true,
        ],
    ];
}
```

## Single-slug compatibility

```php
protected function slugOptions(): array
{
    return [
        'enabled' => true,
        'source' => 'name',
        'column' => 'slug',
        'unique' => true,
        'regenerate_on_update' => false,
        'separator' => '-',
        'scope' => [],
    ];
}
```

Declaring a field through `slugFields()` enables that field by default. Manual non-empty slugs are preserved.

Use a database unique index for final concurrency protection.
