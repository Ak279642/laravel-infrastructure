<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\Concerns\InteractsWithCache;
use Ak279642\LaravelInfrastructure\Contracts\CacheableModel;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RepositoryLoadMissingCacheTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('laravel-infrastructure.auto_invalidation.enabled', true);

        if (getenv('INFRASTRUCTURE_REDIS_TEST') === '1') {
            $app['config']->set('cache.default', 'redis');
            $app['config']->set('cache.stores.redis', [
                'driver' => 'redis', 'connection' => 'cache',
            ]);
            $app['config']->set('cache.prefix', 'infra_load_missing_test_');
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

        Schema::create('load_cache_states', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('load_cache_cities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('state_id');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('load_cache_parents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('city_id');
            $table->timestamps();
        });
        Schema::create('load_cache_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('name');
            $table->timestamps();
        });

        DB::table('load_cache_states')->insert([
            ['id' => 1, 'name' => 'State A'],
            ['id' => 2, 'name' => 'State B'],
        ]);
        DB::table('load_cache_cities')->insert([
            ['id' => 1, 'state_id' => 1, 'name' => 'City A'],
            ['id' => 2, 'state_id' => 2, 'name' => 'City B'],
        ]);
        DB::table('load_cache_parents')->insert(['id' => 1, 'city_id' => 1]);
        DB::table('load_cache_plans')->insert([
            ['id' => 1, 'parent_id' => 1, 'actor_id' => 10, 'name' => 'Plan 10'],
            ['id' => 2, 'parent_id' => 1, 'actor_id' => 20, 'name' => 'Plan 20'],
        ]);
        request()->attributes->set('user_type', 'partner');
        request()->attributes->set('user_id', 10);
    }

    private function repository(): LoadCacheRepository
    {
        return new LoadCacheRepository(new LoadCacheParent, app(CacheManager::class));
    }

    private function requireTaggedCache(): void
    {
        if (! app(CacheManager::class)->supportsTags()) {
            self::markTestSkipped('Tagged Redis cache is required for persistent relation tests.');
        }
    }

    public function test_nested_and_sibling_relations_are_reused_without_sql(): void
    {
        $this->requireTaggedCache();
        $repo = $this->repository();

        $first = LoadCacheParent::findOrFail(1);
        $repo->loadMissing($first, ['plan', 'city.state']);
        self::assertSame('Plan 10', $first->plan->name);
        self::assertSame('State A', $first->city->state->name);

        $second = LoadCacheParent::findOrFail(1);
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            if (str_starts_with(strtolower(trim($event->sql)), 'select')) {
                $queries[] = $event->sql;
            }
        });

        $repo->loadMissing($second, ['city.state', 'plan']);
        self::assertSame('Plan 10', $second->plan->name);
        self::assertSame('State A', $second->city->state->name);
        self::assertCount(0, $queries, 'Cached loadMissing must not issue relation SELECT queries.');
    }

    public function test_related_eloquent_and_raw_writes_invalidate_relation_snapshot(): void
    {
        $this->requireTaggedCache();
        $repo = $this->repository();
        $repo->loadMissing(LoadCacheParent::findOrFail(1), ['plan', 'city.state']);

        LoadCacheState::findOrFail(1)->update(['name' => 'Updated State']);
        $again = LoadCacheParent::findOrFail(1);
        $repo->loadMissing($again, ['plan', 'city.state']);
        self::assertSame('Updated State', $again->city->state->name);

        DB::table('load_cache_plans')->where('id', 1)->update(['name' => 'Updated Plan']);
        $latest = LoadCacheParent::findOrFail(1);
        $repo->loadMissing($latest, ['plan', 'city.state']);
        self::assertSame('Updated Plan', $latest->plan->name);
    }

    public function test_different_actors_cannot_share_scoped_relation_graphs(): void
    {
        $this->requireTaggedCache();
        $repo = $this->repository();

        $first = LoadCacheParent::findOrFail(1);
        $repo->loadMissing($first, ['plan']);
        self::assertSame('Plan 10', $first->plan->name);

        request()->attributes->set('user_id', 20);
        $second = LoadCacheParent::findOrFail(1);
        $repo->loadMissing($second, ['plan']);
        self::assertSame('Plan 20', $second->plan->name);
    }

    public function test_loaded_parent_relation_and_dirty_foreign_key_are_not_overwritten(): void
    {
        $repo = $this->repository();
        $first = LoadCacheParent::findOrFail(1);
        $repo->loadMissing($first, ['city.state']);

        $second = LoadCacheParent::findOrFail(1);
        $existingCity = LoadCacheCity::findOrFail(1);
        $second->setRelation('city', $existingCity);
        $repo->loadMissing($second, ['city.state']);
        self::assertSame($existingCity, $second->city);
        self::assertSame('State A', $second->city->state->name);

        $dirty = LoadCacheParent::findOrFail(1);
        $dirty->city_id = 2;
        $repo->loadMissing($dirty, ['city.state']);
        self::assertSame('State B', $dirty->city->state->name);
    }

    public function test_reads_inside_transactions_do_not_publish_uncommitted_relation_snapshots(): void
    {
        $this->requireTaggedCache();
        $repo = $this->repository();

        DB::beginTransaction();
        try {
            DB::table('load_cache_states')->where('id', 1)->update(['name' => 'Uncommitted']);
            $pending = LoadCacheParent::findOrFail(1);
            $repo->loadMissing($pending, ['city.state']);
            self::assertSame('Uncommitted', $pending->city->state->name);
        } finally {
            DB::rollBack();
        }

        $fresh = LoadCacheParent::findOrFail(1);
        $repo->loadMissing($fresh, ['city.state']);
        self::assertSame('State A', $fresh->city->state->name);
    }
}

final class LoadCacheParent extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'load_cache_parents';
    protected $guarded = [];

    public function city(): BelongsTo
    {
        return $this->belongsTo(LoadCacheCity::class, 'city_id');
    }

    public function plan(): HasOne
    {
        return $this->hasOne(LoadCachePlan::class, 'parent_id');
    }
}

final class LoadCacheCity extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'load_cache_cities';
    protected $guarded = [];

    public function state(): BelongsTo
    {
        return $this->belongsTo(LoadCacheState::class, 'state_id');
    }
}

final class LoadCacheState extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'load_cache_states';
    protected $guarded = [];
}

final class LoadCachePlan extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'load_cache_plans';
    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope('actor', static function (Builder $query): void {
            $query->where('actor_id', request()->attributes->get('user_id'));
        });
    }
}

final class LoadCacheRepository extends BaseRepository
{
    protected array $allowedRelations = ['plan', 'city', 'city.state'];
}
