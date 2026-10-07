# Files

`FileStorage` provides safe Laravel filesystem storage, deletion, existence checks and URLs.

Models extending `BaseModel` can configure automatic `UploadedFile` handling through `fileAttributes()`.

## Model file definition

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
                'roles' => ['admin', 'manager'],
                'permissions' => ['documents.view'],
                'ability' => null,
            ],
        ],
    ];
}
```

The `access` block is field-specific. It overrides matching global disk/folder access rules.

## Asset URL

Generate the route directly from the model field:

```php
$url = $product->fileAssetUrl(
    'document_path',
    now()->addMinutes(5),
);
```

The generated URL carries model, record and field identity. `AssetsController` verifies that the requested disk/path still matches that exact model field before serving it.

## Access options

- `enabled`: false returns 404.
- `signed`: require a signed URL.
- `guard`: Laravel auth guard, e.g. `admin` or `web`.
- `roles`: any listed role may pass.
- `permissions`: all listed permissions must pass through Laravel Gate.
- `ability`: optional custom Gate ability receiving `($disk, $path)`.

Role checks use `hasAnyRole()` / `hasRole()` when available, so Spatie Permission works without becoming a package dependency. A simple `role` attribute is also supported.

## Folder fallback

Generic assets that are not tied to a model field may use `assets.folder_access`.

Rules merge from `*` through parent folders to the most-specific folder. Model field `access` wins over folder fallback.

## File lifecycle

- successful create/update keeps the new file;
- replaced old files are deleted only after DB commit;
- failed DB writes remove newly uploaded files;
- transaction rollback removes newly uploaded files and keeps the previously committed file;
- delete/force-delete cleanup follows the field options.

## Storage audit

```bash
php artisan infrastructure:storage-audit
php artisan infrastructure:storage-audit --delete
```

The audit only scans directories explicitly owned by configured models. It does not globally scan arbitrary storage folders.

Storage paths reject absolute paths, traversal segments, null bytes and control characters.
