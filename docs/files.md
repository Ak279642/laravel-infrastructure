# Files

Models extending `BaseModel` can configure automatic uploads through `fileAttributes()`.

Published file config is intentionally limited to the default disk and image driver. Directory, lifecycle, image transformation and access behavior belong to the model field.

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
                'guard' => ['admin', 'web'],
            ],
        ],
    ];
}
```

Access supports only:

- `enabled`: false returns 404.
- `signed`: require a signed URL.
- `guard`: a guard name or list of guard names. For a list, authentication on any listed guard is sufficient.

There is no role/permission/RBAC logic in asset access.

## Asset URL

Register a short alias:

```php
'assets' => [
    'resources' => [
        'product' => Product::class,
    ],

    'allowed_disks' => [
        'public',
        'private',
    ],
],
```

Generate the URL:

```php
$url = $product->fileAssetUrl(
    'document_path',
    now()->addMinutes(5),
);
```

A signed model URL includes the configured resource alias and preserves the file extension. Laravel adds an expiry and signature:

```text
/infrastructure/assets/{resource-alias}/10/document_path.pdf
?expires=...
&signature=...
```

Laravel signs the URL path and expiry. Tampering with either invalidates the link, and requests made after `expires` are rejected.

The URL omits the `model` segment and includes the configured resource alias. It hides the PHP model namespace, filesystem disk and stored path. `AssetsController` resolves the model class from the alias, then resolves the disk and stored path internally from the record and file field. Missing stored files return the package's default 404 image.

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

## Model-owned directory

Model-owned uploads must define a directory either on the field or in the model's `fileOptions()`. There is no global `files.directory` fallback.

```php
protected function fileOptions(): array
{
    return [
        'directory' => 'products',
    ];
}
```

or:

```php
'image_path' => [
    'directory' => 'products/images',
],
```

Missing directory configuration throws before storage.

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


## Image processing

The package supports Intervention Image v3 and v4. PHP 8.2 resolves v3; PHP 8.3+ applications may use v4.

Image fields opt into processing directly from `fileAttributes()`. There is no global image enable/disable flag.

```php
'image_path' => [
    'disk' => 'public',
    'directory' => 'products/images',

    'image' => [
        // Presence of this block enables processing.
        'driver' => 'gd',
        'format' => 'webp',
        'resize' => 'scale_down',
        'width' => 720,
        'height' => 720,
        'quality' => 80,
        'position' => 'center',
    ],
],
```

Formats:

- `webp`: convert the output to WebP.
- `original`: keep the decoded image's original format.

Resize modes:

- `none`: encode only.
- `scale`: preserve aspect ratio and allow upscaling.
- `scale_down`: preserve aspect ratio and never enlarge.
- `resize`: force the requested dimensions.
- `resize_down`: force dimensions but do not exceed the original.
- `cover`: crop + resize to exact width/height.
- `cover_down`: crop + resize without enlarging.

`cover` and `cover_down` require both width and height.

`image => true` enables processing with package defaults. Omitting `image` stores the upload without image processing.

The processed output is tracked by the same file lifecycle system, so DB failures and transaction rollbacks remove generated WebP/resized files automatically.

Driver:

```dotenv
LARAVEL_INFRASTRUCTURE_IMAGE_DRIVER=gd
```

Use `gd` with `ext-gd` or `imagick` with `ext-imagick`.
