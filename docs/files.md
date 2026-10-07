# Files

Models extending `BaseModel` can configure automatic uploads through `fileAttributes()`.

## File definition

```php
protected function fileAttributes(): array
{
    return [
        'document_path' => [
            'disk' => 'private',
            'directory' => 'products/documents',
            'auto_upload' => true,
            'delete_on_replace' => true,
            'delete_on_delete' => true,
            'delete_on_soft_delete' => false,
            'audit' => true,

            'access' => [
                'enabled' => true,
                'signed' => true,
                'guard' => 'admin',
            ],
        ],
    ];
}
```

Access supports only:

- `enabled`: false returns 404.
- `signed`: require a signed URL.
- `guard`: require authentication on that Laravel guard.

There is no role/permission/RBAC logic in asset access.

## Asset URL

```php
$url = $product->fileAssetUrl(
    'document_path',
    now()->addMinutes(5),
);
```

The generated URL includes the model class, record key and file field. `AssetsController` verifies that the requested disk/path still matches that exact field before serving the file.

## Folder fallback

Generic files that are not tied to a model field can use `assets.folder_access`.

```php
'assets' => [
    'allowed_disks' => [
        'public',
        'private',
    ],

    'folder_access' => [
        'private' => [
            '*' => [
                'enabled' => false,
            ],

            'shared/manuals' => [
                'enabled' => true,
                'signed' => true,
                'guard' => 'web',
            ],
        ],
    ],
],
```

Rules merge from `*` through parent folders to the most-specific folder. Model field `access` overrides folder fallback.

## File lifecycle

- successful create/update keeps the new file;
- replaced old files are deleted after DB commit;
- failed DB writes remove newly uploaded files;
- transaction rollback removes newly uploaded files and keeps the previous committed file;
- delete/force-delete cleanup follows the field options.

## Storage audit

```bash
php artisan infrastructure:storage-audit
php artisan infrastructure:storage-audit --delete
```

Only explicitly model-owned directories are scanned.
