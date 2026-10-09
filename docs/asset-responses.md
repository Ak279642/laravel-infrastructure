# Package-managed asset responses

The `laravel-infrastructure` package owns asset routes, access checks and image responses. Do not register an additional backend asset controller or asset authorization middleware. Optional per-model authorization belongs on the model via `authorizesAssetField(string $column): bool`.

## Default responses

| Situation | HTTP status | Body | Cache |
| --- | --- | --- | --- |
| Existing permitted asset | 200 | Original file | Public image TTL for unsigned/unprotected assets; otherwise private no-store by default |
| Missing asset | 404 | Bundled, optimized WebP "File Not Found" | Private no-store |
| Unauthorized request, invalid signature, or forbidden asset | 403 | Bundled, optimized WebP "Access Denied" | Private no-store |
| Conditional request with matching ETag and current authorization | 304 | Empty | Same cache rule as permitted asset |

The bundled 403 and 404 images are 512×512 optimized WebP files based on the provided artwork. Error responses use the correct `Content-Type: image/webp`, status code, and `X-Content-Type-Options: nosniff`.

## Global error images and caching

In the Laravel application `config/laravel-infrastructure.php`, override the relevant keys:

```php
'assets' => [
    'error_images' => [
        403 => resource_path('images/access-denied.webp'), // or null for bundled WebP
        404 => resource_path('images/not-found.webp'),    // or null for bundled WebP
    ],
    'cache' => [
        'enabled' => true,
        'public_max_age' => 86400,
        'private_max_age' => 0,
        'etag' => true,
    ],
],
```

Overrides can be readable local PNG, JPEG, GIF, or WebP files. Missing/unreadable or unsupported overrides fall back to the bundled WebP artwork. Error responses never use browser/shared caches.

## Routes and access

The package registers routes under its configured `assets.prefix` (`infrastructure/assets` by default). Model asset URLs use a configured resource alias and model key. Generic asset routes use `assets.allowed_disks`, `assets.folder_access` and optional `assets.generic_path_patterns` per-disk regular-expression allowlists. The optional legacy `/uploads/{file}` alias is disabled by default and uses the identical generic access checks when enabled:

```php
'assets' => [
    'legacy_uploads' => ['enabled' => true, 'prefix' => 'uploads', 'disk' => 'public'],
    'generic_path_patterns' => [
        'public' => ['#^(?:images|logos)/[a-zA-Z0-9/_-]+\\.(?:webp|jpg|jpeg|png)$#i'],
    ],
],
```

Use model-level `access` options for signed URLs and guard checks. A model with an `authorizesAssetField()` hook never has its response shared-cached, even when the file path is publicly stored. Protected images default to `Cache-Control: private, no-store, max-age=0`.

## Verify

Run `vendor/bin/phpunit` and `vendor/bin/pint --test` in the package checkout with Composer dependencies installed. PHP syntax validation alone does not replace Laravel integration tests. Publish a separate version tag only after tests pass.
