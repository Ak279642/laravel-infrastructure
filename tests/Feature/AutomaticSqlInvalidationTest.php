<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\SqlCacheDependency;
use Ak279642\LaravelInfrastructure\Contracts\CacheableModel;
use Ak279642\LaravelInfrastructure\Cache\Concerns\InteractsWithCache;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AutomaticSqlInvalidationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('laravel-infrastructure.auto_invalidation.enabled', true);
        $app['config']->set('laravel-infrastructure.auto_invalidation.table_dependencies', [
            'watched_pivots' => [WatchedItem::class],
        ]);

        if (getenv('INFRASTRUCTURE_REDIS_TEST') === '1') {
            $app['config']->set('cache.default', 'redis');
            $app['config']->set('cache.stores.redis', [
                'driver' => 'redis', 'connection' => 'cache',
            ]);
            $app['config']->set('cache.prefix', 'infra_sql_auto_test_');
            $app['config']->set('database.redis.client', 'phpredis');
            $app['config']->set('database.redis.cache', [
                'host' => '127.0.0.1', 'port' => 6379, 'database' => '15',
            ]);
        } else {
            $app['config']->set('cache.default', 'array');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('watched_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('watched_pivots', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });
        DB::table('watched_items')->insert([
            'id' => 1, 'name' => 'before',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function repository(): WatchedRepository
    {
        return new WatchedRepository(new WatchedItem, app(CacheManager::class));
    }

    private function requireTaggedStore(): void
    {
        if (! app(CacheManager::class)->supportsTags()) {
            self::markTestSkipped('Tag-capable Redis cache needed for invalidation tests.');
        }
    }

    public function test_sql_dependency_parser_supports_common_writes_and_reads(): void
    {
        self::assertSame(['watched_items'], SqlCacheDependency::writeTables(
            'update "watched_items" set "name" = ? where "id" = ?',
        ));
        self::assertSame(['watched_items'], SqlCacheDependency::writeTables(
            'insert into watched_items (name) values (?)',
        ));
        self::assertSame(['watched_items'], SqlCacheDependency::writeTables(
            'delete from watched_items where id = ?',
        ));
        self::assertSame([], SqlCacheDependency::writeTables(
            'update first_table join second_table on first_table.id = second_table.id set first_table.name = ?',
        ));
        self::assertNull(SqlCacheDependency::writeTables('select * from watched_items'));
        self::assertNull(SqlCacheDependency::writeTables(
            'with recent as (select * from watched_items) select * from recent',
        ));
        self::assertSame(['watched_items', 'watched_pivots'], SqlCacheDependency::readTables(
            'select * from "watched_items" join watched_pivots on watched_items.id = watched_pivots.id',
        ));
    }

    public function test_raw_write_invalidates_cached_repository_read_without_manual_flushing(): void
    {
        $this->requireTaggedStore();
        $repository = $this->repository();
        self::assertSame('before', $repository->get()->first()->name);

        DB::table('watched_items')->where('id', 1)->update(['name' => 'after']);
        self::assertSame('after', $repository->get()->first()->name);
    }

    public function test_raw_write_rollback_does_not_flush_committed_cache(): void
    {
        $this->requireTaggedStore();
        $repository = $this->repository();
        self::assertSame('before', $repository->get()->first()->name);

        try {
            DB::transaction(static function (): void {
                DB::table('watched_items')->where('id', 1)->update(['name' => 'rolled-back']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            self::assertSame('rollback', $exception->getMessage());
        }
        self::assertSame('before', $repository->get()->first()->name);

        DB::transaction(static function (): void {
            DB::table('watched_items')->where('id', 1)->update(['name' => 'committed']);
        });
        self::assertSame('committed', $repository->get()->first()->name);
    }

    public function test_pivot_dependency_invalidates_cached_parent_model(): void
    {
        $this->requireTaggedStore();
        $repository = $this->repository();
        self::assertSame('before', $repository->get()->first()->name);

        // No watched_items write: the declarative cross-table dependency
        // causes repository cache invalidation without application callbacks.
        $cache = app(CacheManager::class);
        $tags = [WatchedItem::cacheTag()];
        self::assertSame('before', $cache->rememberWithDependencies(
            'watched_pivot_projection', 60, fn (): string => 'before', $tags, DB::connection(),
        ));
        DB::table('watched_pivots')->insert(['label' => 'visibility changed']);
        // A cached dependency read refreshes automatically on pivot change.
        self::assertSame('after', $cache->rememberWithDependencies(
            'watched_pivot_projection', 60, fn (): string => 'after', $tags, DB::connection(),
        ));
    }
}

final class WatchedItem extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'watched_items';
    protected $guarded = [];
}

final class WatchedRepository extends BaseRepository {}
