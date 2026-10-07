# Files

`FileStorage` stores, checks, deletes and generates URLs for Laravel uploads.

Configured model file attributes can auto-store `UploadedFile` instances and control disk, directory, filename, replacement cleanup, delete cleanup and audit participation.

Storage paths reject absolute paths, traversal segments, null bytes and control characters.

Use:

```bash
php artisan infrastructure:storage-audit
php artisan infrastructure:storage-audit --delete
```

The audit only scans explicitly registered model-owned directories and defaults to dry-run.
