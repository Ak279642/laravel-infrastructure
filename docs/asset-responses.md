# Assets: package-managed files and error images

The package registers the asset routes, serves files, checks configured access rules, and responds with images for 403 and 404. You do **not** need an app controller, a custom asset authorization middleware, or extra route registration.

| Case | HTTP | Body | HTTP caching |
| --- | --- | --- | --- |
| File exists and access is permitted | 200 | The actual file | Public image: 24 hours, ETag |
| File is missing, path is invalid | 404 | Bundled File Not Found WebP | No-store |
| Guard/signature/model denies access | 403 | Bundled Access Denied WebP | No-store |
| Public image ETag is unchanged | 304 | Empty body | Authorized requests only |

The default artwork consists of the two provided error images, optimized to WebP. The response always has an image `Content-Type`, correct HTTP status, and `X-Content-Type-Options: nosniff`.

## Global image overrides

Edit **one** section of the app's published `config/laravel-infrastructure.php`:

```php
'assets' => [
    'error_images' => [
        403 => resource_path('images/my-403.webp'),
        404 => resource_path('images/my-404.webp'),
    ],
],
```

Both values default to `null`, which uses the artwork bundled with the package. Any readable local WebP, PNG, JPEG, or GIF image can be used. An invalid override falls back to the bundled WebP.

## Routes and access

Default URL prefix: `/infrastructure/assets`. The package owns model URLs and `/{disk}/{path}` URLs. Allowed disks are configured in `assets.allowed_disks` (default `['public']`); model aliases in `assets.resources`. Guard, signature and folder access settings can be applied per model file field or in `assets.folder_access`.

```php
'assets' => [
    'resources' => ['product' => App\Models\Product::class],
    'allowed_disks' => ['public'],
    'folder_access' => [
        'public' => [
            '*' => ['signed' => false],
            'private' => ['guard' => 'admin'],
        ],
    ],
],
```

The optional legacy `/uploads/{file}` alias stays disabled unless `assets.legacy_uploads.enabled` is set. For generic URLs, `assets.generic_path_patterns` may allowlist paths by disk. Model-specific rules override folder rules; `authorizesAssetField(string $column): bool` remains supported on models, and such responses are never shared-cached.

## Cache settings

Repository data caching uses **Laravel's own** `config/cache.php` and `CACHE_STORE`, not a second package cache config. Image browser caching is automatic: only unprotected public images get a 24-hour browser/CDN TTL and metadata ETags; denied, missing, guarded and signed responses are not cached.

## Verify

Run `vendor/bin/phpunit` and `vendor/bin/pint --test` after `composer install`.
