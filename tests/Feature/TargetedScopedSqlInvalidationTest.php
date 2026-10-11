<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\Concerns\InteractsWithCache;
use Ak279642\LaravelInfrastructure\Contracts\CacheableModel;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TargetedScopedSqlInvalidationTest extends TestCase
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
            $app['config']->set('cache.prefix', 'infra_scope_target_test_');
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

        Schema::create('targeted_scope_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('city_id')->default(10);
            $table->string('status');
        });

        DB::table('targeted_scope_orders')->insert([
            ['id' => 1, 'user_id' => 1, 'city_id' => 10, 'status' => 'pending'],
            ['id' => 2, 'user_id' => 2, 'city_id' => 20, 'status' => 'pending'],
        ]);
    }

    private function scoped(): TargetedScopeRepository
    {
        return new TargetedScopeRepository(new TargetedScopeOrder, app(CacheManager::class));
    }

    private function unscoped(): TargetedScopeRepository
    {
        return new TargetedScopeRepository(new UnscopedOrder, app(CacheManager::class));
    }

    private function actor(int $id): void
    {
        request()->attributes->set('user_type', 'user');
        request()->attributes->set('user_id', $id);
    }

    private function needRedis(): void
    {
        if (! app(CacheManager::class)->supportsTags()) {
            self::markTestSkipped('Redis or another tag-capable store is needed.');
        }
    }

    public function test_single_eloquent_owner_update_recalculates_only_affected_scope(): void
    {
        $this->needRedis();
        $repo = $this->scoped();

        $this->actor(1);
        self::assertSame(['pending'], $repo->statuses());
        $this->actor(2);
        self::assertSame(['pending'], $repo->statuses());
        self::assertSame(2, $repo->loads);

        $record = TargetedScopeOrder::query()->findOrFail(2);
        $record->update(['status' => 'done']);

        $this->actor(1);
        self::assertSame(['pending'], $repo->statuses());
        self::assertSame(2, $repo->loads);

        $this->actor(2);
        self::assertSame(['done'], $repo->statuses());
        self::assertSame(3, $repo->loads);
    }

    public function test_simple_explicit_owner_sql_is_targeted_without_model_cache_options(): void
    {
        $this->needRedis();
        $repo = $this->unscoped();

        self::assertSame(['pending'], $repo->statusesForUser(1));
        self::assertSame(['pending'], $repo->statusesForUser(2));
        self::assertSame(2, $repo->loads);

        UnscopedOrder::query()->findOrFail(1)->update(['status' => 'changed']);

        self::assertSame(['pending'], $repo->statusesForUser(2));
        self::assertSame(2, $repo->loads);
        self::assertSame(['changed'], $repo->statusesForUser(1));
        self::assertSame(3, $repo->loads);
    }

    public function test_admin_city_in_predicates_invalidate_only_affected_city_partitions(): void
    {
        $this->needRedis();
        $repo = $this->unscoped();

        self::assertSame(['pending'], $repo->statusesForCities([10]));
        self::assertSame(['pending'], $repo->statusesForCities([20]));
        self::assertSame(2, $repo->loads);

        UnscopedOrder::query()->findOrFail(1)->update(['status' => 'city-changed']);

        self::assertSame([], $repo->statusesForCities([20]));
        self::assertSame(2, $repo->loads);
        self::assertSame(['city-changed'], $repo->statusesForCities([10]));
        self::assertSame(3, $repo->loads);
    }

    public function test_complex_or_predicates_keep_safe_table_wide_invalidation(): void
    {
        $this->needRedis();
        $repo = $this->unscoped();

        self::assertSame(['pending', 'pending'], $repo->statusesForComplexOrUser(1));
        self::assertSame(1, $repo->loads);

        UnscopedOrder::query()->findOrFail(2)->update(['status' => 'changed']);

        self::assertSame(['pending', 'changed'], $repo->statusesForComplexOrUser(1));
        self::assertSame(2, $repo->loads);
    }

    public function test_transfer_invalidates_both_old_and_new_owner_partitions(): void
    {
        $this->needRedis();
        $repo = $this->scoped();
        $this->actor(1);
        self::assertSame(['pending'], $repo->statuses());
        $this->actor(2);
        self::assertSame(['pending'], $repo->statuses());
        self::assertSame(2, $repo->loads);

        TargetedScopeOrder::query()->findOrFail(1)->update(['user_id' => 2]);

        $this->actor(1);
        self::assertSame([], $repo->statuses());
        $this->actor(2);
        self::assertSame(['pending', 'pending'], $repo->statuses());
        self::assertSame(4, $repo->loads);
    }

    public function test_raw_sql_write_falls_back_to_all_dependent_scopes(): void
    {
        $this->needRedis();
        $repo = $this->scoped();
        $this->actor(1);
        self::assertSame(['pending'], $repo->statuses());
        $this->actor(2);
        self::assertSame(['pending'], $repo->statuses());
        self::assertSame(2, $repo->loads);

        DB::table('targeted_scope_orders')->where('id', 1)->update(['status' => 'raw']);

        $this->actor(1);
        self::assertSame(['raw'], $repo->statuses());
        $this->actor(2);
        self::assertSame(['pending'], $repo->statuses());
        self::assertSame(4, $repo->loads);
    }

    public function test_unscoped_readers_refresh_on_scoped_eloquent_update(): void
    {
        $this->needRedis();
        $repo = $this->unscoped();
        self::assertSame(['pending', 'pending'], $repo->statuses());

        TargetedScopeOrder::query()->findOrFail(1)->update(['status' => 'changed']);

        self::assertSame(['changed', 'pending'], $repo->statuses());
        self::assertSame(2, $repo->loads);
    }
}

final class TargetedScopeOrder extends Model implements CacheableModel
{
    use InteractsWithCache;

    public $timestamps = false;
    protected $table = 'targeted_scope_orders';
    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return ['scope' => 'user'];
    }
}

final class UnscopedOrder extends Model implements CacheableModel
{
    use InteractsWithCache;

    public $timestamps = false;
    protected $table = 'targeted_scope_orders';
    protected $guarded = [];
}

final class TargetedScopeRepository extends BaseRepository
{
    public int $loads = 0;

    public function statuses(): array
    {
        return $this->cacheRemember('statuses', function (): array {
            $this->loads++;

            return $this->query()->orderBy('id')->pluck('status')->all();
        });
    }

    public function statusesForUser(int $userId): array
    {
        return $this->cacheRemember('statusesForUser', function () use ($userId): array {
            $this->loads++;

            return $this->query()->where('targeted_scope_orders.user_id', $userId)
                ->orderBy('id')->pluck('status')->all();
        }, ['owner' => $userId]);
    }

    public function statusesForCities(array $cities): array
    {
        return $this->cacheRemember('statusesForCities', function () use ($cities): array {
            $this->loads++;

            return $this->query()->whereIn('targeted_scope_orders.city_id', $cities)
                ->orderBy('id')->pluck('status')->all();
        }, ['cities' => $cities]);
    }

    public function statusesForComplexOrUser(int $userId): array
    {
        return $this->cacheRemember('statusesForOrUser', function () use ($userId): array {
            $this->loads++;

            return $this->query()->where('targeted_scope_orders.user_id', $userId)
                ->orWhere('targeted_scope_orders.user_id', 2)
                ->orderBy('id')->pluck('status')->all();
        }, ['owner' => $userId]);
    }
}
