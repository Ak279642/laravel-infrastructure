<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Schema;

use Ak279642\LaravelInfrastructure\Cache\CacheTtl;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class SchemaRegistry
{
    /**
     * Per-process metadata, keyed by logical connection and physical database.
     *
     * @var array<string, array<string, array<string, true>>>
     */
    private array $columns = [];

    /** @var array<string, true> */
    private array $databaseSnapshotsLoaded = [];

    /** @var array<string, string> */
    private array $databaseSnapshotKeys = [];

    public function has(Connection $connection, string $table, string $column): bool
    {
        $identity = $this->connectionIdentity($connection);
        $this->loadTable($connection, $identity, $table);

        return isset($this->columns[$identity][$table][$column]);
    }

    /**
     * @return list<string>
     */
    public function columns(Connection $connection, string $table): array
    {
        $identity = $this->connectionIdentity($connection);
        $this->loadTable($connection, $identity, $table);

        return array_keys($this->columns[$identity][$table] ?? []);
    }

    private function loadTable(
        Connection $connection,
        string $identity,
        string $table,
    ): void {
        if (isset($this->columns[$identity][$table])) {
            return;
        }

        // For MySQL/MariaDB, one information_schema query fetches columns for
        // every table in the selected database. The snapshot is shared across
        // requests through Laravel's normal cache store.
        if ($this->loadDatabaseSnapshot($connection, $identity)) {
            // Negative table lookups must not cause additional schema queries.
            $this->columns[$identity][$table] ??= [];

            return;
        }

        // SQLite, PostgreSQL, and snapshot failures retain the existing,
        // driver-native discovery behavior (one query per distinct table).
        $columns = $connection->getSchemaBuilder()->getColumnListing($table);
        $this->columns[$identity][$table] = array_fill_keys(
            array_values(array_filter(
                $columns,
                static fn (mixed $column): bool => is_string($column) && $column !== '',
            )),
            true,
        );
    }

    private function loadDatabaseSnapshot(Connection $connection, string $identity): bool
    {
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return false;
        }

        $database = $connection->getDatabaseName();
        if (! is_string($database) || $database === '') {
            return false;
        }

        if (isset($this->databaseSnapshotsLoaded[$identity])) {
            return true;
        }

        // Never share schema metadata across databases, tenant connections,
        // hosts, or table prefixes.
        $cacheKey = 'laravel-infrastructure:schema:v1:'.hash('sha256', $identity);

        try {
            $snapshot = Cache::remember(
                $cacheKey,
                CacheTtl::HOUR,
                static function () use ($connection, $database): array {
                    $rows = $connection->select(
                        'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name '
                        .'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?',
                        [$database],
                    );

                    $tables = [];
                    $prefix = $connection->getTablePrefix();

                    foreach ($rows as $row) {
                        $physicalTable = $row->table_name ?? null;
                        $column = $row->column_name ?? null;

                        if (
                            ! is_string($physicalTable) || $physicalTable === ''
                            || ! is_string($column) || $column === ''
                        ) {
                            continue;
                        }

                        $tables[$physicalTable][$column] = true;

                        if ($prefix !== '' && str_starts_with($physicalTable, $prefix)) {
                            // Eloquent's unprefixed model table also resolves
                            // against its actual prefixed database table.
                            $logicalTable = substr($physicalTable, strlen($prefix));
                            if ($logicalTable !== '') {
                                $tables[$logicalTable][$column] = true;
                            }
                        }
                    }

                    return $tables;
                },
            );

            if (! is_array($snapshot)) {
                return false;
            }

            $this->columns[$identity] = $snapshot;
            $this->databaseSnapshotsLoaded[$identity] = true;
            $this->databaseSnapshotKeys[$identity] = $cacheKey;

            return true;
        } catch (Throwable) {
            // Keep compatibility with restricted information_schema privileges
            // or an unavailable cache store. Native table listing can still
            // validate allowed columns securely.
            return false;
        }
    }

    /**
     * Clear cached metadata for all known databases, one connection, or one
     * table. A database-wide snapshot must be refreshed as a whole.
     */
    public function clear(?string $connectionName = null, ?string $table = null): void
    {
        if ($connectionName === null) {
            foreach ($this->databaseSnapshotKeys as $cacheKey) {
                Cache::forget($cacheKey);
            }

            $this->columns = [];
            $this->databaseSnapshotsLoaded = [];
            $this->databaseSnapshotKeys = [];

            return;
        }

        $prefix = $connectionName.'|';

        foreach (array_keys($this->columns + $this->databaseSnapshotKeys) as $identity) {
            if ($identity !== $connectionName && ! str_starts_with($identity, $prefix)) {
                continue;
            }

            if (isset($this->databaseSnapshotKeys[$identity])) {
                Cache::forget($this->databaseSnapshotKeys[$identity]);
                unset(
                    $this->columns[$identity],
                    $this->databaseSnapshotsLoaded[$identity],
                    $this->databaseSnapshotKeys[$identity],
                );

                continue;
            }

            if ($table === null) {
                unset($this->columns[$identity]);
            } else {
                unset($this->columns[$identity][$table]);
            }
        }
    }

    private function connectionIdentity(Connection $connection): string
    {
        $name = (string) (
            $connection->getName()
            ?: config('database.default')
            ?: 'default'
        );

        $physicalIdentity = [
            'driver' => $connection->getDriverName(),
            'host' => $connection->getConfig('host'),
            'port' => $connection->getConfig('port'),
            'database' => $connection->getDatabaseName(),
            'schema' => $connection->getConfig('schema'),
            'search_path' => $connection->getConfig('search_path'),
            'prefix' => $connection->getTablePrefix(),
        ];

        return $name.'|'.hash('sha256', serialize($physicalIdentity));
    }
}
