<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\Concerns\InteractsWithCache;
use Ak279642\LaravelInfrastructure\Contracts\CacheableModel;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RepositoryPolicyDependencyTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('laravel-infrastructure.auto_invalidation.enabled', true);
        $app['config']->set('laravel-infrastructure.auto_invalidation.table_dependencies', []);

        if (getenv('INFRASTRUCTURE_REDIS_TEST') === '1') {
            $app['config']->set('cache.default', 'redis');
            $app['config']->set('cache.stores.redis', [
                'driver' => 'redis', 'connection' => 'cache',
            ]);
            $app['config']->set('cache.prefix', 'infra_policy_sql_dependency_');
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

        Schema::create('policy_test_records', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('policy_test_access', function (Blueprint $table): void {
            $table->id();
            $table->boolean('allowed');
        });

        DB::table('policy_test_records')->insert(['id' => 1, 'name' => 'Example']);
        DB::table('policy_test_access')->insert(['id' => 1, 'allowed' => false]);
    }

    public function test_policy_only_sql_dependencies_invalidate_cached_result_without_manual_tags(): void
    {
        if (! app(CacheManager::class)->supportsTags()) {
            self::markTestSkipped('A tag-capable Redis store is required.');
        }

        $repository = new PolicyDependencyRepository(new PolicyDependencyRecord, app(CacheManager::class));

        self::assertSame('hidden', $repository->policyBasedValue());
        self::assertSame('hidden', $repository->policyBasedValue());
        self::assertSame(1, $repository->calculationCount);

        // No INSERT/UPDATE to policy_test_records, and no relation SQL is
        // executed by the callback. The policy read alone is the dependency.
        DB::table('policy_test_access')->where('id', 1)->update(['allowed' => true]);

        self::assertSame('visible', $repository->policyBasedValue());
        self::assertSame(2, $repository->calculationCount);
        self::assertSame('visible', $repository->policyBasedValue());
        self::assertSame(2, $repository->calculationCount);
    }

    public function test_sql_read_during_scoped_cache_key_compilation_is_a_dependency(): void
    {
        if (! app(CacheManager::class)->supportsTags()) {
            self::markTestSkipped('A tag-capable Redis store is required.');
        }

        $repository = new VisibilityKeyRepository(new PolicyDependencyRecord, app(CacheManager::class));

        self::assertSame('hidden', $repository->keyBasedValue());
        self::assertSame('hidden', $repository->keyBasedValue());
        self::assertSame(1, $repository->calculationCount);

        DB::table('policy_test_access')->where('id', 1)->update(['allowed' => true]);

        self::assertSame('visible', $repository->keyBasedValue());
        self::assertSame(2, $repository->calculationCount);
    }

    public function test_unrelated_writes_do_not_evict_policy_or_model_cache(): void
    {
        if (! app(CacheManager::class)->supportsTags()) {
            self::markTestSkipped('A tag-capable Redis store is required.');
        }

        Schema::create('policy_test_unrelated', function (Blueprint $table): void {
            $table->id();
        });
        $repository = new PolicyDependencyRepository(new PolicyDependencyRecord, app(CacheManager::class));

        self::assertSame('hidden', $repository->policyBasedValue());
        DB::table('policy_test_unrelated')->insert(['id' => 1]);

        self::assertSame('hidden', $repository->policyBasedValue());
        self::assertSame(1, $repository->calculationCount);
    }
}

final class PolicyDependencyRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'policy_test_records';
    protected $guarded = [];
    public $timestamps = false;
}

final class PolicyDependencyRepository extends BaseRepository
{
    public int $calculationCount = 0;
    private bool $visible = false;

    protected function cacheReadPolicy(string $operation, array $params): ?array
    {
        if ($operation !== 'policyBasedValue') {
            return null;
        }

        $this->visible = (bool) DB::table('policy_test_access')
            ->where('id', 1)
            ->value('allowed');

        // Simulates an existing stable cache identity with a separately
        // resolved permission or visibility state.
        return ['mode' => 'persistent', 'params' => ['actor_id' => 1]];
    }

    public function policyBasedValue(): string
    {
        return $this->cacheRemember('policyBasedValue', function (): string {
            $this->calculationCount++;

            return $this->visible ? 'visible' : 'hidden';
        });
    }
}


final class VisibilityKeyRepository extends BaseRepository
{
    public int $calculationCount = 0;
    private bool $visible = false;

    protected function globalQueryFilter(Builder $query): Builder
    {
        $this->visible = (bool) DB::table('policy_test_access')
            ->where('id', 1)
            ->value('allowed');

        return $query;
    }

    public function keyBasedValue(): string
    {
        return $this->cacheRemember('keyBasedValue', function (): string {
            $this->calculationCount++;

            return $this->visible ? 'visible' : 'hidden';
        });
    }
}
