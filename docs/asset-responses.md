# Asset response behavior

The current public-media implementation uses **the stored relative file path**, not a model resource alias and ID.

```php
'assets' => ['disk_aliases' => ['media' => 'public']]
```

With a stored path `categories/gaming-console.webp`, `$category->getFileUrl('image')` generates `/media/categories/gaming-console.webp?v=...`. No extra SQL or storage metadata lookup occurs when creating the link. The public media request only resolves the configured disk and path, checks filesystem existence, and streams the file. It never queries an Eloquent model.

The URL version derives from stored path and model `updated_at`; changing file content at the same path without updating the model cannot reliably invalidate a cached URL. Uploads avoid replacing existing physical filenames, and add `-2`, `-3` on conflicts. Public files are served with `Cache-Control: public, max-age=86400`. Extension and path validation block unsafe file types, dotfiles and traversal.

Private files must use a nonpublic disk. The encrypted signed URL, `/_infrastructure/files/{token}?expires=...&signature=...`, requires valid signature, matching existing model field, authenticated allowed guard or an explicit model authorization hook. The handler may query the database to enforce these permissions; it never maps a private disk to a public alias. Guarded, user-owned files should implement `authorizesAssetField()` as an ownership check. Responses use `Cache-Control: private, no-store, max-age=0`.

Missing and denied files render the package's default 404/403 WebP artworks. By default these render with HTTP 200 and an `X-Asset-Error-Status` header for browsers; set `assets.render_error_images=false` to use real 403/404 codes.

Legacy `/uploads`, generic `/{disk}/{path}` asset routes, `assets.folder_access`, `assets.generic_path_patterns`, and model ID routes are no longer registered. Disk aliases are publicly routable only for disks listed in `assets.public_disks` (default `['public']`). `assets.resources` is no longer used.
