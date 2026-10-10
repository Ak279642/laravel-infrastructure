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
