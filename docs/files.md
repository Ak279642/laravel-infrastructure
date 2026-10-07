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


File replacement and delete cleanup is commit-aware. When a model write runs inside a database transaction, old files are deleted only after the outer transaction commits. A rollback therefore never leaves a restored database row pointing at a file that was already removed.

Uploads are written before the database save so the stored path can be persisted. Failed database writes and transaction rollbacks are compensated automatically: newly uploaded files are removed while the previously committed file remains intact.

Custom filenames must be plain relative filenames: path separators, hidden dotfiles, null bytes, control characters, and malformed extensions are rejected.


## Failed writes and transaction rollbacks

Automatic model uploads use compensating cleanup.

- If a create/update stores a file and the database save then fails, the newly stored file is removed.
- If an upload occurs inside a database transaction, the package tracks the file until the outer transaction completes.
- A rollback removes files created by the rolled-back transaction level.
- A successful commit releases rollback tracking and keeps the new file.
- Replacement cleanup remains after-commit, so the previously referenced file is not deleted until the database change is durable.
- On the default soft-delete configuration, a soft delete keeps the file; force delete removes it. Non-soft deletes remove their configured files normally.

This prevents failed inserts, failed updates, and rolled-back transactions from leaving newly uploaded orphan files while preserving the file referenced by the committed database row.


## Built-in asset route

The package serves files through:

```text
GET /infrastructure/assets/{disk}/{path}
laravel-infrastructure.assets.show
```

Asset access is configured per disk and per folder.

```php
'assets' => [
    'allowed_disks' => [
        'public',
        'private',
    ],

    'folder_access' => [
        'public' => [
            '*' => [
                'signed' => true,
            ],

            'downloads' => [
                'signed' => false,
            ],
        ],

        'private' => [
            '*' => [
                'enabled' => false,
            ],

            'products/invoices' => [
                'enabled' => true,
                'signed' => true,
                'guard' => 'admin',
                'roles' => ['admin', 'accounts'],
                'permissions' => ['invoices.view'],
            ],

            'hr/contracts' => [
                'enabled' => true,
                'guard' => 'web',
                'roles' => ['hr', 'admin'],
            ],
        ],
    ],
],
```

Rules merge from `*` through matching parent folders to the most-specific folder.

Supported folder options:

- `enabled`: deny the folder with 404 when false.
- `signed`: require or skip signed URLs for that folder.
- `guard`: authenticate through a specific Laravel guard.
- `roles`: any listed role may pass.
- `permissions`: all listed permissions must pass through Laravel Gate.
- `ability`: optional custom Gate ability receiving `($disk, $path)`.

Role checks use `hasAnyRole()` / `hasRole()` when available, so Spatie Permission works without becoming a package dependency. A simple `role` attribute is also supported.

Generate URLs with `URL::temporarySignedRoute()` using route name `laravel-infrastructure.assets.show`.
