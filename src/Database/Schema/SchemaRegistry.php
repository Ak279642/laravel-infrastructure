<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Database\Schema;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;

final class SchemaRegistry
{
    /**
     * @var array<string, array<string, array<string, true>>>
     */
    private array $columns = [];

    public function has(
        Connection $connection,
        string $table,
        string $column,
    ): bool {
        $connectionName = $connection->getName() ?: config('database.default');

        $this->loadConnection($connection, $connectionName);

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
        $connectionName = $connection->getName() ?: config('database.default');

        $this->loadConnection($connection, $connectionName);

        return array_keys(
            $this->columns[$connectionName][$table] ?? [],
        );
    }

    private function loadConnection(
        Connection $connection,
        string $connectionName,
    ): void {
        if (isset($this->columns[$connectionName])) {
            return;
        }

        $cacheKey = "_schema:columns:{$connectionName}";

        /**
         * One query for the complete schema.
         *
         * @var array<string, array<string, true>> $schema
         */
        $schema = Cache::rememberForever(
            $cacheKey,
            function () use ($connection): array {
                $database = $connection->getDatabaseName();

                $rows = $connection->select(
                    <<<'SQL'
                    SELECT
                        TABLE_NAME AS table_name,
                        COLUMN_NAME AS column_name
                    FROM information_schema.columns
                    WHERE TABLE_SCHEMA = ?
                    ORDER BY TABLE_NAME, ORDINAL_POSITION
                    SQL,
                    [$database],
                );

                $result = [];

                foreach ($rows as $row) {
                    $table = $row->table_name ?? null;
                    $column = $row->column_name ?? null;

                    if (
                        ! is_string($table)
                        || $table === ''
                        || ! is_string($column)
                        || $column === ''
                    ) {
                        continue;
                    }

                    $result[$table][$column] = true;
                }

                return $result;
            },
        );

        $this->columns[$connectionName] = $schema;
    }

    public function clear(
        ?string $connectionName = null,
    ): void {
        $connectionName ??= config('database.default');

        unset($this->columns[$connectionName]);

        Cache::forget(
            "_schema:columns:{$connectionName}",
        );
    }
}
