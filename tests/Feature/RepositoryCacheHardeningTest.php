<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\Concerns\InteractsWithCache;
use Ak279642\LaravelInfrastructure\Contracts\CacheableModel;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class RepositoryCacheHardeningTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cache_hardening_regions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('cache_hardening_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('region_id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('cache_hardening_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('name');
            $table->string('status');
            $table->timestamps();
            $table->softDeletes();
        });

        CacheHardeningRegion::query()->create(['name' => 'East']);
        CacheHardeningAccount::query()->create([
            'region_id' => 1,
            'name' => 'Acme',
        ]);
    }

    public function test_repository_cache_is_enabled_by_default_and_write_lifecycle_invalidates_it(): void
    {
        $repository = $this->repository();

        self::assertCount(0, $repository->get());

        $user = $repository->create([
            'account_id' => 1,
            'name' => 'Alice',
            'status' => 'active',
        ]);

        self::assertSame('Alice', $repository->get()->first()->name);

        $repository->update($user->getKey(), ['name' => 'Alice Updated']);
        self::assertSame('Alice Updated', $repository->get()->first()->name);

        $repository->delete($user->getKey());
        self::assertCount(0, $repository->get());

        $repository->restore($user->getKey());
        self::assertCount(1, $repository->get());

        $repository->forceDelete($user->getKey());
        self::assertCount(0, $repository->get());
    }

    public function test_without_cache_is_clone_scoped_and_consumed_by_one_operation(): void
    {
        $repository = $this->repository();

        $repository->create([
            'account_id' => 1,
            'name' => 'Cached Name',
            'status' => 'active',
        ]);

        self::assertSame('Cached Name', $repository->get()->first()->name);

        DB::table('cache_hardening_users')
            ->where('id', 1)
            ->update(['name' => 'Fresh Name']);

        // Direct query-builder writes do not dispatch Eloquent events, so the
        // already-cached repository result intentionally remains unchanged.
        self::assertSame('Cached Name', $repository->get()->first()->name);

        $freshRepository = $repository->withoutCache();

        self::assertNotSame($repository, $freshRepository);
        self::assertSame('Fresh Name', $freshRepository->get()->first()->name);

        DB::table('cache_hardening_users')
            ->where('id', 1)
            ->update(['name' => 'Newest Name']);

        // The bypass was consumed by the previous operation. Reusing the clone
        // returns to the repository's normal caching policy.
        self::assertSame('Cached Name', $freshRepository->get()->first()->name);

        $repository->clearCache();

        self::assertSame('Newest Name', $repository->get()->first()->name);
    }

    public function test_nested_relation_dependency_tags_invalidate_parent_repository_cache(): void
    {
        $repository = $this->repository();

        $repository->create([
            'account_id' => 1,
            'name' => 'Alice',
            'status' => 'active',
        ]);

        $cached = $repository->get(['with' => ['account.region']])->first();

        self::assertSame('East', $cached->account->region->name);

        CacheHardeningRegion::query()->findOrFail(1)->update(['name' => 'West']);

        $fresh = $repository->get(['with' => ['account.region']])->first();

        self::assertSame('West', $fresh->account->region->name);
    }

    public function test_equivalent_query_shapes_share_a_key_without_exposing_values(): void
    {
        $repository = $this->repository();

        $left = $repository->cacheKeyFor([
            'status' => ['in', ['active', 'pending']],
            'with' => ['account', 'account.region'],
        ]);

        $right = $repository->cacheKeyFor([
            'with' => ['account.region', 'account'],
            'status' => ['in', ['pending', 'active']],
        ]);

        self::assertSame($left, $right);
        self::assertStringNotContainsString('active', $left);
        self::assertStringNotContainsString('pending', $left);
    }

    public function test_locked_population_reuses_cached_value_and_releases_lock_on_exception(): void
    {
        $cache = $this->app->make(CacheManager::class);
        $calls = 0;

        $first = $cache->rememberLocked(
            key: 'hardening.locked',
            ttl: 60,
            callback: function () use (&$calls): string {
                $calls++;

                return 'value';
            },
            tags: ['hardening'],
        );

        $second = $cache->rememberLocked(
            key: 'hardening.locked',
            ttl: 60,
            callback: function () use (&$calls): string {
                $calls++;

                return 'other';
            },
            tags: ['hardening'],
        );

        self::assertSame('value', $first);
        self::assertSame('value', $second);
        self::assertSame(1, $calls);

        try {
            $cache->rememberLocked(
                key: 'hardening.exception',
                ttl: 60,
                callback: static fn (): never => throw new RuntimeException('population failed'),
                tags: ['hardening'],
            );

            self::fail('Expected cache population exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('population failed', $exception->getMessage());
        }

        self::assertSame(
            'recovered',
            $cache->rememberLocked(
                key: 'hardening.exception',
                ttl: 60,
                callback: static fn (): string => 'recovered',
                tags: ['hardening'],
            ),
        );
    }

    public function test_bulk_update_invalidates_cached_collections(): void
    {
        $repository = $this->repository();

        $repository->create([
            'account_id' => 1,
            'name' => 'Alice',
            'status' => 'active',
        ]);
        $repository->create([
            'account_id' => 1,
            'name' => 'Bob',
            'status' => 'active',
        ]);

        self::assertCount(2, $repository->get(['status' => 'active']));

        self::assertSame(
            2,
            $repository->bulkUpdate(
                data: ['status' => 'inactive'],
                filters: ['status' => 'active'],
            ),
        );

        self::assertCount(0, $repository->get(['status' => 'active']));
        self::assertCount(2, $repository->get(['status' => 'inactive']));
    }

    public function test_cache_refresh_and_forever_paths_remain_available(): void
    {
        $cache = $this->app->make(CacheManager::class);

        self::assertSame(
            'forever',
            $cache->rememberForever(
                'hardening.forever',
                static fn (): string => 'forever',
                ['hardening'],
            ),
        );

        self::assertSame(
            'refreshed',
            $cache->refresh(
                key: 'hardening.forever',
                ttl: 60,
                callback: static fn (): string => 'refreshed',
                tags: ['hardening'],
            ),
        );

        self::assertSame('refreshed', $cache->get('hardening.forever', tags: ['hardening']));
    }

    public function test_lock_timeout_path_preserves_availability_and_caches_fallback_result(): void
    {
        $cache = $this->app->make(CacheManager::class);
        $lockName = 'cache-populate:'.hash('sha256', '_hardening.contended');
        $heldLock = $cache->lock($lockName, 10);

        self::assertNotNull($heldLock);
        self::assertTrue($heldLock->get());

        try {
            $calls = 0;

            $value = $cache->rememberLocked(
                key: 'hardening.contended',
                ttl: 60,
                callback: function () use (&$calls): string {
                    $calls++;

                    return 'fallback';
                },
                tags: ['hardening'],
                lockSeconds: 10,
                waitSeconds: 0,
            );

            self::assertSame('fallback', $value);
            self::assertSame(1, $calls);
        } finally {
            $heldLock->release();
        }

        self::assertSame(
            'fallback',
            $cache->rememberLocked(
                key: 'hardening.contended',
                ttl: 60,
                callback: static fn (): string => 'should-not-run',
                tags: ['hardening'],
            ),
        );
    }

    private function repository(): CacheHardeningUserRepository
    {
        return new CacheHardeningUserRepository(
            new CacheHardeningUser,
            $this->app->make(CacheManager::class),
        );
    }
}

final class CacheHardeningUserRepository extends BaseRepository
{
    protected array $allowedFilters = ['status'];

    protected array $allowedSorts = ['name'];

    protected array $allowedRelations = ['account', 'account.region'];

    public function cacheKeyFor(array $filters): string
    {
        $params = [
            'filters' => $filters,
            'columns' => ['*'],
            'with' => $filters['with'] ?? [],
        ];

        return $this->getCacheKey(
            'get',
            $this->normalizeRepositoryCacheParams($params),
        );
    }
}

final class CacheHardeningUser extends Model implements CacheableModel
{
    use InteractsWithCache;
    use SoftDeletes;

    protected $table = 'cache_hardening_users';

    protected $guarded = [];

    public function account()
    {
        return $this->belongsTo(CacheHardeningAccount::class, 'account_id');
    }
}

final class CacheHardeningAccount extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'cache_hardening_accounts';

    protected $guarded = [];

    public function region()
    {
        return $this->belongsTo(CacheHardeningRegion::class, 'region_id');
    }
}

final class CacheHardeningRegion extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'cache_hardening_regions';

    protected $guarded = [];
}
