<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Schema;

use Illuminate\Database\Connection;

final class SchemaRegistry
{
    /**
     * Columns are cached in memory for the lifetime of the package service.
     *
     * @var array<string, array<string, array<string, true>>>
     */
    private array $columns = [];

    public function has(
        Connection $connection,
        string $table,
        string $column,
    ): bool {
        $connectionName = $this->connectionName($connection);

        $this->loadTable(
            $connection,
            $connectionName,
            $table,
        );

        return isset(
            $this->columns[$connectionName][$table][$column],
        );
    }

    /**
     * @return list<string>
     */
    public function columns(
        Connection $connection,
        string $table,
    ): array {
        $connectionName = $this->connectionName($connection);

        $this->loadTable(
            $connection,
            $connectionName,
            $table,
        );

        return array_keys(
            $this->columns[$connectionName][$table] ?? [],
        );
    }

    private function loadTable(
        Connection $connection,
        string $connectionName,
        string $table,
    ): void {
        if (isset($this->columns[$connectionName][$table])) {
            return;
        }

        $columns = $connection
            ->getSchemaBuilder()
            ->getColumnListing($table);

        $this->columns[$connectionName][$table] = array_fill_keys(
            array_values(array_filter(
                $columns,
                static fn ($column): bool => is_string($column) && $column !== '',
            )),
            true,
        );
    }

    public function clear(
        ?string $connectionName = null,
        ?string $table = null,
    ): void {
        if ($connectionName === null) {
            $this->columns = [];

            return;
        }

        if ($table === null) {
            unset($this->columns[$connectionName]);

            return;
        }

        unset($this->columns[$connectionName][$table]);
    }

    private function connectionName(Connection $connection): string
    {
        return (string) (
            $connection->getName()
            ?: config('database.default')
            ?: 'default'
        );
    }
}
