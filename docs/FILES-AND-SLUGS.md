# Files and slugs

## File storage

~~~php
$path = $files->store(
    file: $request->file('avatar'),
    directory: 'customers/avatars',
    disk: 'public',
);
~~~

Generated filenames use UUIDs. Custom filenames must be plain filenames without path separators or control characters.

Directories/stored paths reject traversal segments, absolute paths, Windows drive paths, URL schemes, null bytes, and control characters.

## File lifecycle

~~~php
protected function fileAttributes(): array
{
    return [
        'avatar',
        'document_path' => [
            'disk' => 'private',
            'delete_on_replace' => true,
            'delete_on_delete' => true,
            'delete_on_soft_delete' => false,
        ],
    ];
}
~~~

Old files are removed only after a successful database commit. A rolled-back transaction retains the old file.

If another configured attribute still references the same disk/path, replacement cleanup does not delete that shared file.

Cleanup failures are logged by default instead of corrupting a successful database write. Enable files.throw_on_cleanup_failure only when the application intentionally wants cleanup errors to propagate.

## Slugs

~~~php
protected function slugOptions(): array
{
    return [
        'enabled' => true,
        'source' => ['name', 'title'],
        'column' => 'slug',
        'unique' => true,
        'separator' => '-',
        'regenerate_on_update' => false,
        'scope' => ['organization_id'],
    ];
}
~~~

Tests cover normal, duplicate, scoped, manual, regeneration, empty-source, special/Unicode, and database-constraint behavior.

Application-level slug lookups cannot eliminate concurrent insert races. Always keep the appropriate database unique index as the final correctness guarantee.
