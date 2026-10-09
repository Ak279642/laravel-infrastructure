<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Database\Schema\SchemaRegistry;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;

final class SchemaRegistryDatabaseSnapshotTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_mysql_schema_is_loaded_once_across_tables_and_registry_instances(): void
    {
        $connection = new SchemaSnapshotFakeConnection;
        $registry = new SchemaRegistry;

        self::assertTrue($registry->has($connection, 'users', 'email'));
        self::assertTrue($registry->has($connection, 'products', 'id'));
        self::assertFalse($registry->has($connection, 'products', 'missing'));
        self::assertFalse($registry->has($connection, 'missing_table', 'id'));
        self::assertSame(1, $connection->schemaQueries);

        $anotherRegistry = new SchemaRegistry;
        self::assertTrue($anotherRegistry->has($connection, 'users', 'id'));
        self::assertSame(1, $connection->schemaQueries);

        $registry->clear('schema-snapshot-test');
        self::assertTrue($anotherRegistry->has($connection, 'products', 'name'));
        // The other registry retains its own in-process snapshot.
        self::assertSame(1, $connection->schemaQueries);

        $freshRegistry = new SchemaRegistry;
        self::assertTrue($freshRegistry->has($connection, 'products', 'name'));
        self::assertSame(2, $connection->schemaQueries);
    }

    public function test_schema_cache_respects_connection_prefix(): void
    {
        $connection = new SchemaSnapshotFakeConnection('pre_');
        $registry = new SchemaRegistry;

        self::assertTrue($registry->has($connection, 'users', 'email'));
        self::assertSame(1, $connection->schemaQueries);
    }
}

final class SchemaSnapshotFakeConnection extends Connection
{
    public int $schemaQueries = 0;

    public function __construct(string $prefix = '')
    {
        parent::__construct(
            null,
            'schema_snapshot_database',
            $prefix,
            [
                'driver' => 'mysql',
                'name' => 'schema-snapshot-test',
            ],
        );
    }

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        $this->schemaQueries++;

        if (! str_contains($query, 'information_schema.COLUMNS')) {
            throw new \LogicException('Unexpected schema SQL.');
        }

        $prefix = $this->getTablePrefix();

        return [
            (object) ['table_name' => $prefix.'users', 'column_name' => 'id'],
            (object) ['table_name' => $prefix.'users', 'column_name' => 'email'],
            (object) ['table_name' => $prefix.'products', 'column_name' => 'id'],
            (object) ['table_name' => $prefix.'products', 'column_name' => 'name'],
        ];
    }
}
