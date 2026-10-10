# Files and media

The package's `BaseModel` uses `InteractsWithFiles`; it stores **relative paths only** in your existing database fields. Disks and access come from `fileAttributes()`, not additional database columns.

## Upload configuration

```php
class Product extends \Ak279642\LaravelInfrastructure\Models\BaseModel
{
    protected function fileAttributes(): array
    {
        return [
            'image' => [
                'directory' => 'products',
                'filename_from' => ['name', 'brand.slug', 'category.slug'],
                'image' => ['format' => 'webp', 'width' => 720, 'height' => 720],
            ],
            'invoice' => [
                'disk' => 'private',
                'directory' => 'invoices',
                'access' => ['signed' => true, 'guard' => 'web'],
            ],
        ];
    }
}
```

If the first upload has a name `iPhone 16 Pro`, loaded `brand.slug=apple` and `category.slug=phones`, it stores `products/iphone-16-pro-apple-phones.webp`. For a collision, it chooses `...-2.webp`, then `...-3.webp`, under an atomic cache lock. Use a **shared Redis lock store** across multiple app servers to prevent concurrent filename races. Explicit `filename` strings and `filename` callbacks are still supported and take priority over `filename_from`; absent both, UUID filenames remain the default.

No hidden relation loads are allowed for `filename_from`. Reuse models resolved during validation or preload them:

```php
$product->setRelation('brand', $brand);
$product->setRelation('category', $category);
$product->image = $request->file('image');
$product->save();
```

## Public URLs

```php
// config/laravel-infrastructure.php
'assets' => [
    'disk_aliases' => ['media' => 'public'],
],
```

```php
$product->getFileUrl('image');
$product->fileAssetUrl('image'); // null if the attribute has no stored path
$product->getFileUrl('products/iphone-16-pro-apple-phones.webp');
```

URL: `/media/products/iphone-16-pro-apple-phones.webp?v=...`. An absent alias defaults to the disk name (for the default disk, `/public/products/...`). The URL exposes the actual relative public path, but never a model ID. If a custom SEO name is passed to `getFileUrl($field, $name)`, it must match the already-stored filename; the package will not generate broken aliases. Change filename at upload or run the migration command.

Public media requests require no database query and are served from the Laravel storage disk. The public route only permits approved static-file extensions and rejects unsafe paths. A public disk is inherently accessible: **never put protected files there**, even if the model configuration sets a guard.

## Optional object syntax

```php
protected function casts(): array
{
    return ['image' => \Ak279642\LaravelInfrastructure\Files\FileReferenceCast::class];
}

$product->image->getFileUrl(); // optional: throws only if field isn't a valid file
(string) $product->image; // stored relative path
```

The cast is opt-in. Noncast columns remain ordinary strings. The upload lifecycle accepts `UploadedFile` values.

## Private files

```php
'invoice' => [
    'disk' => 'private',
    'directory' => 'invoices',
    'access' => ['signed' => true, 'guard' => 'web'],
],
```

Define the private disk outside the web-public directory in your application's `config/filesystems.php`. A private link uses `/_infrastructure/files/{encrypted-token}?expires=...&signature=...`, never `/media/... `. It reloads the model on delivery to verify that the current file path still matches, and checks guards and optional `authorizesAssetField(string $field)` authorization. A private route **can use SQL for authorization**, unlike public media. Configure ownership hooks for customer-specific documents. Private responses use `Cache-Control: private, no-store`.

## Lifecycles, caching and conversions

`InteractsWithFiles` auto-uploads `UploadedFile` values on save, cleans up failed/rolled-back uploads, deletes replaced files after successful commits, and respects soft-delete/force-delete options. The active disk comes from model options. `ImageProcessor` supports GD or Imagick, WebP or original format, quality, dimensions, position, and `none`, `scale`, `scale_down`, `resize`, `resize_down`, `cover`, and `cover_down`. Uploads never intentionally overwrite an existing filename. URL generation performs no storage metadata calls; `v` is derived from the path and loaded model timestamp. Public requests currently use 24-hour caching, not immutable caching.

## Repair existing UUID filenames

```bash
php artisan infrastructure:media-rename 'App\Models\Product'
php artisan infrastructure:media-rename 'App\Models\Product' --apply
```

This command scans model fields with `filename_from` on public disks, preloads declared relationships, and proposes the physical SEO filename. It is a **dry run** by default. On `--apply`, it first copies the file, conditionally updates the existing path field, and leaves old files in place to avoid breaking shared references. Review [storage audit](#storage-audit) before cleanup.

## Storage audit

```bash
php artisan infrastructure:storage-audit
php artisan infrastructure:storage-audit --model=Product
php artisan infrastructure:storage-audit --delete
```

This optional maintenance command scans explicitly registered models and model-owned directories across both public and private disks. It groups references by disk and directory and reads raw database paths. No disk column is needed. It intentionally executes SQL during an audit; normal public delivery does not.
