# Database Backups

The package includes an opt-in Artisan command for logical MySQL/MariaDB and PostgreSQL backups:

```bash
php artisan infrastructure:database-backup
php artisan infrastructure:database-backup --connection=mysql
```

The command intentionally does not support SQLite or SQL Server. It delegates to the database vendor's native dump tool:

- MySQL / MariaDB: `mysqldump`
- PostgreSQL: `pg_dump`

Install the matching client tool on the server, or configure an explicit binary path.

## Configuration

Publish the package configuration and adjust:

```php
'database_backup' => [
    'disk' => 'local',
    'path' => 'backups/database',
    'keep' => 3,
    'compress' => true,

    // These tables keep schema definitions but omit row data.
    'exclude_data' => [
        // 'jobs',
        // 'failed_jobs',
    ],

    'mysql_dump_binary' => '',
    'pgsql_dump_binary' => '',
],
```

Environment overrides are available for the common deployment settings:

```dotenv
LARAVEL_INFRASTRUCTURE_BACKUP_DISK=local
LARAVEL_INFRASTRUCTURE_BACKUP_PATH=backups/database
LARAVEL_INFRASTRUCTURE_BACKUP_KEEP=3
LARAVEL_INFRASTRUCTURE_BACKUP_COMPRESS=true
LARAVEL_INFRASTRUCTURE_MYSQLDUMP_BINARY=
LARAVEL_INFRASTRUCTURE_PG_DUMP_BINARY=
```

## Behavior

The command:

1. writes table definitions first;
2. appends row data while omitting data for configured `exclude_data` tables;
3. optionally gzip-compresses the SQL file;
4. streams the finished file to the configured Laravel filesystem disk;
5. keeps only the newest configured number of `.sql` / `.sql.gz` backups in the backup directory;
6. always removes local temporary files.

Database passwords are passed to the native client through `MYSQL_PWD` or `PGPASSWORD`, not interpolated into a shell command. Process execution uses argument arrays with shell bypass enabled.

The backup storage path must be relative and traversal-safe. Configured excluded table names are validated before being passed to dump tools.

For remote disks such as S3, configure a normal Laravel filesystem disk and set `database_backup.disk` to that disk name.

Backups should still be tested by restoring them in your deployment environment. Creating a dump successfully is not a substitute for restore testing.
