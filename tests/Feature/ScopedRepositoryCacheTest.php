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

final class ScopedRepositoryCacheTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('scoped_cache_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tenant_id')->default(10);
            $table->boolean('is_global')->default(false);
            $table->string('name');
            $table->timestamps();
        });
        DB::table('scoped_cache_records')->insert([
            ['id' => 1, 'user_id' => 101, 'name' => 'First'],
            ['id' => 2, 'user_id' => 102, 'name' => 'Second'],
        ]);
    }

    private function repository(): ScopedCacheRepository
    {
        return new ScopedCacheRepository(new ScopedCacheRecord, app(CacheManager::class));
    }

    private function asUser(int $id): void
    {
        $this->be(new ScopedCacheActor(['id' => $id]));
    }

    public function test_repository_can_be_constructed_before_authentication_but_reads_fail_closed(): void
    {
        $repository = $this->repository();

        try {
            $repository->get();
            self::fail('Expected unauthenticated scoped read to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Authenticated cache scope is required.', $exception->getMessage());
        }

        $this->asUser(101);
        self::assertSame(['First'], $repository->get()->pluck('name')->all());
    }

    public function test_scoped_reads_are_isolated_and_an_update_does_not_flush_another_owner(): void
    {
        $this->asUser(101);
        $repo = $this->repository();
        self::assertSame(['First'], $repo->get()->pluck('name')->all());
        self::assertSame(1, $repo->count());
        self::assertNull($repo->find(2));

        $this->asUser(102);
        self::assertSame(['Second'], $repo->get()->pluck('name')->all());
        self::assertSame(1, $repo->count());
        self::assertNull($repo->find(1));

        // Simulate a direct Eloquent write and verify only the owner's cache is invalidated.
        $this->asUser(101);
        ScopedCacheRecord::query()->findOrFail(1)->update(['name' => 'Changed']);
        self::assertSame('Changed', $repo->get()->first()->name);

        $this->asUser(102);
        self::assertSame('Second', $repo->get()->first()->name);
    }

    public function test_transfer_invalidates_old_and_new_owner_lists(): void
    {
        $repo = $this->repository();
        $this->asUser(101);
        self::assertSame(1, $repo->count());
        $this->asUser(102);
        self::assertSame(1, $repo->count());

        ScopedCacheRecord::query()->findOrFail(1)->update(['user_id' => 102]);

        self::assertSame(2, $repo->count());
        $this->asUser(101);
        self::assertSame(0, $repo->count());
    }

    public function test_composite_scopes_restrict_records_and_invalidate_only_matching_partition(): void
    {
        DB::table('scoped_cache_records')->insert([
            ['id' => 3, 'user_id' => 101, 'tenant_id' => 20, 'name' => 'Other tenant'],
        ]);
        $repo = new ScopedCacheRepository(new CompositeCacheRecord, app(CacheManager::class));
        $this->be(new ScopedCacheActor(['id' => 101, 'tenant_id' => 10]));
        self::assertSame(1, $repo->count());
        self::assertNull($repo->find(3));
        CompositeCacheRecord::query()->findOrFail(1)->update(['tenant_id' => 20]);
        self::assertSame(0, $repo->count());

        $this->be(new ScopedCacheActor(['id' => 101, 'tenant_id' => 20]));
        self::assertSame(2, $repo->count());
    }

    public function test_request_attribute_actor_resolves_without_laravel_authentication(): void
    {
        $repository = new ScopedCacheRepository(new RequestActorCacheRecord, app(CacheManager::class));
        request()->attributes->set('user_id', 101);
        request()->attributes->set('user_type', 'user');

        self::assertSame(1, $repository->count());
        self::assertNull($repository->find(2));

        request()->attributes->set('user_id', 102);
        self::assertSame(1, $repository->count());
        self::assertNull($repository->find(1));
    }

    public function test_explicit_global_visibility_includes_global_records_and_refreshes_after_changes(): void
    {
        DB::table('scoped_cache_records')->insert([
            ['id' => 3, 'user_id' => 999, 'tenant_id' => 10, 'name' => 'Shared', 'is_global' => true],
        ]);
        $this->be(new ScopedCacheActor(['id' => 101, 'tenant_id' => 10]));
        $repo = new ScopedCacheRepository(new GlobalVisibleCacheRecord, app(CacheManager::class));
        self::assertSame(2, $repo->count());
        GlobalVisibleCacheRecord::query()->findOrFail(3)->update(['is_global' => false]);
        self::assertSame(1, $repo->count());
    }
}

final class ScopedCacheRepository extends BaseRepository {}

final class ScopedCacheRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return ['scope' => 'user', 'scope_column' => 'user_id'];
    }
}

final class ScopedCacheActor extends Model implements \Illuminate\Contracts\Auth\Authenticatable
{
    use \Illuminate\Auth\Authenticatable;

    protected $guarded = [];
}

final class CompositeCacheRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return ['scopes' => [
            'tenant_id' => 'auth.tenant_id',
            'user_id' => 'auth.id',
        ]];
    }
}

final class GlobalVisibleCacheRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return [
            'scopes' => ['tenant_id' => 'auth.tenant_id', 'user_id' => 'auth.id'],
            'visibility_resolver' => static function ($query): void {
                $query->orWhere('is_global', true);
            },
        ];
    }
}

final class RequestActorCacheRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return ['scopes' => ['user_id' => 'actor.id']];
    }
}
