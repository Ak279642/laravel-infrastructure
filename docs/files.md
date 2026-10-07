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

Uploads are written before the database save so the stored path can be persisted. If a later database transaction rolls back, the newly uploaded replacement can remain as an orphan. Run the storage audit to report or remove those unreferenced files.

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
