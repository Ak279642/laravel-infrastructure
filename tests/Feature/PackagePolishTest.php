<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Database\Schema\SchemaRegistry;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use PDO;

final class PackagePolishTest extends TestCase
{
    public function test_repository_uses_configured_default_cache_ttl(): void
    {
        $this->app['config']->set(
            'laravel-infrastructure.cache.default_ttl',
            123,
        );

        $repository = new ConfiguredTtlRepository(
            new PackagePolishModel(),
            $this->app->make(CacheManager::class),
        );

        self::assertSame(123, $repository->configuredCacheTtl());
    }

    public function test_repository_explicit_cache_ttl_override_is_preserved(): void
    {
        $this->app['config']->set(
            'laravel-infrastructure.cache.default_ttl',
            123,
        );

        $repository = new ExplicitTtlRepository(
            new PackagePolishModel(),
            $this->app->make(CacheManager::class),
        );

        self::assertSame(900, $repository->configuredCacheTtl());
    }

    public function test_schema_registry_does_not_cross_contaminate_databases_with_same_logical_connection_name(): void
    {
        $first = $this->sqliteConnection('tenant-a');
        $second = $this->sqliteConnection('tenant-b');

        $first->statement(
            'CREATE TABLE package_polish_items '.
            '(id INTEGER PRIMARY KEY, name TEXT)',
        );

        $second->statement(
            'CREATE TABLE package_polish_items '.
            '(id INTEGER PRIMARY KEY, email TEXT)',
        );

        $registry = new SchemaRegistry();

        self::assertTrue(
            $registry->has(
                $first,
                'package_polish_items',
                'name',
            ),
        );
        self::assertFalse(
            $registry->has(
                $first,
                'package_polish_items',
                'email',
            ),
        );

        self::assertTrue(
            $registry->has(
                $second,
                'package_polish_items',
                'email',
            ),
        );
        self::assertFalse(
            $registry->has(
                $second,
                'package_polish_items',
                'name',
            ),
        );
    }

    private function sqliteConnection(
        string $hostIdentity,
    ): SQLiteConnection {
        return new SQLiteConnection(
            new PDO('sqlite::memory:'),
            'shared-database-name',
            '',
            [
                'name' => 'tenant',
                'driver' => 'sqlite',
                'host' => $hostIdentity,
            ],
        );
    }
}

class ConfiguredTtlRepository extends BaseRepository
{
    public function configuredCacheTtl(): ?int
    {
        return $this->cacheTtl;
    }
}

final class ExplicitTtlRepository extends ConfiguredTtlRepository
{
    protected ?int $cacheTtl = 900;
}

final class PackagePolishModel extends Model
{
    protected $table = 'package_polish_models';

    protected $guarded = [];
}
