<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\CacheTtl;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Database\Schema\SchemaRegistry;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use PDO;

final class PackagePolishTest extends TestCase
{
    public function test_repository_uses_repository_default_cache_ttl(): void
    {
        $repository = new ConfiguredTtlRepository(
            new PackagePolishModel,
            $this->app->make(CacheManager::class),
        );

        self::assertSame(
            CacheTtl::MINUTES_2,
            $repository->configuredCacheTtl(),
        );
    }

    public function test_repository_explicit_cache_ttl_override_is_preserved(): void
    {
        $repository = new ConfiguredTtlRepository(
            new PackagePolishModel,
            $this->app->make(CacheManager::class),
        );

        $repository->cacheTtl(CacheTtl::MINUTES_15);

        self::assertSame(
            CacheTtl::MINUTES_15,
            $repository->configuredCacheTtl(),
        );
    }

    public function test_published_config_keeps_feature_defaults_out_of_global_config(): void
    {
        self::assertArrayNotHasKey('cache', config('laravel-infrastructure'));
        self::assertNull(
            config('laravel-infrastructure.slug'),
        );
        self::assertNull(
            config('laravel-infrastructure.files.directory'),
        );
        self::assertNull(
            config('laravel-infrastructure.files.image.enabled'),
        );
        self::assertNull(
            config('laravel-infrastructure.assets.url_ttl_minutes'),
        );
        self::assertNull(
            config('laravel-infrastructure.storage_audit.chunk_size'),
        );
        self::assertNull(
            config('laravel-infrastructure.logging.max_depth'),
        );
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

        $registry = new SchemaRegistry;

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
    protected function defaultCacheTtl(): int
    {
        return CacheTtl::MINUTES_2;
    }

    public function configuredCacheTtl(): ?int
    {
        return $this->cacheTtl;
    }
}

final class PackagePolishModel extends Model
{
    protected $table = 'package_polish_models';

    protected $guarded = [];
}
