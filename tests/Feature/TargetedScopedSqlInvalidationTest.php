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
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('status');
        });

        DB::table('targeted_scope_orders')->insert([
            ['id' => 1, 'user_id' => 1, 'city_id' => 10, 'agent_id' => 9, 'admin_id' => 77, 'status' => 'pending'],
            ['id' => 2, 'user_id' => 2, 'city_id' => 20, 'agent_id' => 10, 'admin_id' => 88, 'status' => 'pending'],
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

    public function test_automatically_inferred_owner_transfer_refreshes_old_and_new_owners(): void
    {
        $this->needRedis();
        $repo = $this->unscoped();

        self::assertSame(['pending'], $repo->statusesForUser(1));
        self::assertSame(['pending'], $repo->statusesForUser(2));
        self::assertSame(2, $repo->loads);

        UnscopedOrder::query()->findOrFail(1)->update(['user_id' => 2]);

        self::assertSame([], $repo->statusesForUser(1));
        self::assertSame(['pending', 'pending'], $repo->statusesForUser(2));
        self::assertSame(4, $repo->loads);
    }

    public function test_automatically_inferred_owner_cache_falls_back_for_raw_sql(): void
    {
        $this->needRedis();
        $repo = $this->unscoped();

        self::assertSame(['pending'], $repo->statusesForUser(1));
        self::assertSame(['pending'], $repo->statusesForUser(2));
        self::assertSame(2, $repo->loads);

        DB::table('targeted_scope_orders')->where('id', 1)->update(['status' => 'raw']);

        self::assertSame(['pending'], $repo->statusesForUser(2));
        self::assertSame(['raw'], $repo->statusesForUser(1));
        // Query builder writes cannot determine a changed row's actor membership
        // without another SELECT, so conservative invalidation refreshes both.
        self::assertSame(4, $repo->loads);
    }

    public function test_admin_city_in_predicates_invalidate_only_affected_city_partitions(): void
    {
        $this->needRedis();
        $repo = $this->unscoped();

        self::assertSame(['pending'], $repo->statusesForCities([10]));
        self::assertSame(['pending'], $repo->statusesForCities([20]));
        self::assertSame(2, $repo->loads);

        UnscopedOrder::query()->findOrFail(1)->update(['status' => 'city-changed']);

        self::assertSame(['pending'], $repo->statusesForCities([20]));
        self::assertSame(2, $repo->loads);
        self::assertSame(['city-changed'], $repo->statusesForCities([10]));
        self::assertSame(3, $repo->loads);
    }

    public function test_nested_cached_cross_owner_read_disables_unsafe_actor_inference(): void
    {
        $this->needRedis();
        $repo = $this->unscoped();

        // Seed a cached broad root read before the filtered outer operation.
        self::assertSame(['pending', 'pending'], $repo->statuses());
        self::assertSame(
            ['pending', 'pending', 'pending'],
            $repo->combinedForUser(1),
        );
        self::assertSame(2, $repo->loads);

        UnscopedOrder::query()->findOrFail(2)->update(['status' => 'done']);

        self::assertSame(
            ['pending', 'pending', 'done'],
            $repo->combinedForUser(1),
        );
        self::assertSame(4, $repo->loads);
    }

    public function test_external_database_write_can_be_invalidated_through_package_command(): void
    {
        $this->needRedis();
        $repo = $this->unscoped();

        self::assertSame(['pending'], $repo->statusesForUser(1));
        self::assertSame(['pending'], $repo->statusesForUser(2));
        self::assertSame(2, $repo->loads);

        // Bypass Laravel's query-event dispatcher to simulate an external
        // transaction (e.g. a separate database client / CDC source).
        DB::connection()->getPdo()->exec(
            "UPDATE targeted_scope_orders SET status = 'external' WHERE id = 1",
        );

        self::assertSame(['pending'], $repo->statusesForUser(1));
        self::assertSame(2, $repo->loads);

        self::assertSame(0, \Illuminate\Support\Facades\Artisan::call(
            'infrastructure:cache:invalidate-external',
            ['tables' => ['targeted_scope_orders']],
        ));

        self::assertSame(['external'], $repo->statusesForUser(1));
        self::assertSame(['pending'], $repo->statusesForUser(2));
        self::assertSame(4, $repo->loads);
    }

    public function test_external_invalidator_rejects_unsafe_table_identifiers(): void
    {
        $this->needRedis();

        self::assertSame(1, \Illuminate\Support\Facades\Artisan::call(
            'infrastructure:cache:invalidate-external',
            ['tables' => ['targeted_scope_orders;drop table users']],
        ));
    }

    public function test_proven_owner_or_agent_branches_selectively_invalidate(): void
    {
        $this->needRedis();
        $repo = $this->unscoped();

        self::assertSame(['pending'], $repo->statusesForOwnerOrAgent(1, 9));
        self::assertSame(['pending'], $repo->statusesForOwnerOrAgent(2, 10));
        self::assertSame(2, $repo->loads);

        UnscopedOrder::query()->findOrFail(1)->update(['status' => 'changed']);

        self::assertSame(['pending'], $repo->statusesForOwnerOrAgent(2, 10));
        self::assertSame(2, $repo->loads);
        self::assertSame(['changed'], $repo->statusesForOwnerOrAgent(1, 9));
        self::assertSame(3, $repo->loads);
    }

    public function test_parenthesized_actor_or_with_city_and_keeps_unrelated_same_city_cache(): void
    {
        $this->needRedis();
        // Two records share a city, but only the first belongs to the actor OR.
        DB::table('targeted_scope_orders')->where('id', 2)->update(['city_id' => 10]);
        $repo = $this->unscoped();

        self::assertSame(['pending'], $repo->statusesForOwnerOrAgentInCity(1, 9, 10));
        self::assertSame(1, $repo->loads);

        UnscopedOrder::query()->findOrFail(2)->update(['status' => 'unrelated']);

        self::assertSame(['pending'], $repo->statusesForOwnerOrAgentInCity(1, 9, 10));
        self::assertSame(1, $repo->loads);

        UnscopedOrder::query()->findOrFail(1)->update(['status' => 'changed']);
        self::assertSame(['changed'], $repo->statusesForOwnerOrAgentInCity(1, 9, 10));
        self::assertSame(2, $repo->loads);
    }

    public function test_direct_admin_id_partition_refreshes_only_affected_admin(): void
    {
        $this->needRedis();
        $repo = $this->unscoped();

        self::assertSame(['pending'], $repo->statusesForAdmin(77));
        self::assertSame(['pending'], $repo->statusesForAdmin(88));
        self::assertSame(2, $repo->loads);

        UnscopedOrder::query()->findOrFail(1)->update(['status' => 'review']);

        self::assertSame(['pending'], $repo->statusesForAdmin(88));
        self::assertSame(2, $repo->loads);
        self::assertSame(['review'], $repo->statusesForAdmin(77));
        self::assertSame(3, $repo->loads);
    }

    public function test_unrestricted_or_branch_keeps_conservative_invalidation(): void
    {
        $this->needRedis();
        $repo = $this->unscoped();

        self::assertSame(['pending', 'pending'], $repo->statusesForUnsafeOrUser(1));
        self::assertSame(1, $repo->loads);

        UnscopedOrder::query()->findOrFail(2)->update(['status' => 'changed']);

        self::assertSame(['pending'], $repo->statusesForUnsafeOrUser(1));
        self::assertSame(2, $repo->loads);
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

    public function combinedForUser(int $userId): array
    {
        return $this->cacheRemember('combinedForUser', function () use ($userId): array {
            $this->loads++;

            $owned = $this->query()->where('targeted_scope_orders.user_id', $userId)
                ->orderBy('id')->pluck('status')->all();

            return array_merge($owned, $this->statuses());
        }, ['owner' => $userId]);
    }

    public function statusesForOwnerOrAgent(int $userId, int $agentId): array
    {
        return $this->cacheRemember('statusesForOwnerOrAgent', function () use ($userId, $agentId): array {
            $this->loads++;

            return $this->query()
                ->where('targeted_scope_orders.user_id', $userId)
                ->orWhere('targeted_scope_orders.agent_id', $agentId)
                ->orderBy('id')->pluck('status')->all();
        }, ['user_id' => $userId, 'agent_id' => $agentId]);
    }

    public function statusesForOwnerOrAgentInCity(int $userId, int $agentId, int $cityId): array
    {
        return $this->cacheRemember('statusesForOwnerOrAgentInCity', function () use ($userId, $agentId, $cityId): array {
            $this->loads++;

            return $this->query()
                ->where(function (Builder $query) use ($userId, $agentId): void {
                    $query->where('targeted_scope_orders.user_id', $userId)
                        ->orWhere('targeted_scope_orders.agent_id', $agentId);
                })
                ->where('targeted_scope_orders.city_id', $cityId)
                ->orderBy('id')->pluck('status')->all();
        }, ['user_id' => $userId, 'agent_id' => $agentId, 'city_id' => $cityId]);
    }

    public function statusesForAdmin(int $adminId): array
    {
        return $this->cacheRemember('statusesForAdmin', function () use ($adminId): array {
            $this->loads++;

            return $this->query()
                ->where('targeted_scope_orders.admin_id', $adminId)
                ->orderBy('id')->pluck('status')->all();
        }, ['admin_id' => $adminId]);
    }

    public function statusesForUnsafeOrUser(int $userId): array
    {
        return $this->cacheRemember('statusesForUnsafeOrUser', function () use ($userId): array {
            $this->loads++;

            return $this->query()
                ->where('targeted_scope_orders.user_id', $userId)
                ->orWhere('targeted_scope_orders.status', 'pending')
                ->orderBy('id')->pluck('status')->all();
        }, ['user_id' => $userId]);
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
