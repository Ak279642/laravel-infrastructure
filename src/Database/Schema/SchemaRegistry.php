<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Schema;

use Illuminate\Database\Connection;

final class SchemaRegistry
{
    /**
     * Columns are cached in memory for the lifetime of the package service.
     *
     * The first key includes the logical connection name and the physical
     * database identity. This prevents a long-running worker from reusing
     * schema metadata after a tenant/database switch on the same connection.
     *
     * @var array<string, array<string, array<string, true>>>
     */
    private array $columns = [];

    public function has(
        Connection $connection,
        string $table,
        string $column,
    ): bool {
        $connectionIdentity = $this->connectionIdentity($connection);

        $this->loadTable(
            $connection,
            $connectionIdentity,
            $table,
        );

        return isset(
            $this->columns[$connectionIdentity][$table][$column],
        );
    }

    /**
     * @return list<string>
     */
    public function columns(
        Connection $connection,
        string $table,
    ): array {
        $connectionIdentity = $this->connectionIdentity($connection);

        $this->loadTable(
            $connection,
            $connectionIdentity,
            $table,
        );

        return array_keys(
            $this->columns[$connectionIdentity][$table] ?? [],
        );
    }

    private function loadTable(
        Connection $connection,
        string $connectionIdentity,
        string $table,
    ): void {
        if (isset($this->columns[$connectionIdentity][$table])) {
            return;
        }

        $columns = $connection
            ->getSchemaBuilder()
            ->getColumnListing($table);

        $this->columns[$connectionIdentity][$table] = array_fill_keys(
            array_values(array_filter(
                $columns,
                static fn ($column): bool => is_string($column) && $column !== '',
            )),
            true,
        );
    }

    /**
     * Clear all cached schema metadata, or all metadata belonging to a logical
     * Laravel connection name. The public connection-name behavior is kept for
     * backwards compatibility even though internal keys are database-specific.
     */
    public function clear(
        ?string $connectionName = null,
        ?string $table = null,
    ): void {
        if ($connectionName === null) {
            $this->columns = [];

            return;
        }

        $prefix = $connectionName.'|';

        foreach (array_keys($this->columns) as $identity) {
            if (
                $identity !== $connectionName
                && ! str_starts_with($identity, $prefix)
            ) {
                continue;
            }

            if ($table === null) {
                unset($this->columns[$identity]);

                continue;
            }

            unset($this->columns[$identity][$table]);
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

        return $name.'|'.hash(
            'sha256',
            serialize($physicalIdentity),
        );
    }
}
