<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\CacheTtl;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class RepositoryPaginationCacheTest extends TestCase
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

        Schema::create('pagination_cache_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('tenant_id')->default(1);
            $table->string('name');
            $table->string('status');
        });
    }

    public function test_pages_are_cached_separately_and_repository_writes_invalidate_them(): void
    {
        $repository = $this->repository();

        $first = $repository->create(['name' => 'First', 'status' => 'active']);
        $repository->create(['name' => 'Second', 'status' => 'active']);

        self::assertSame('First', $repository->paginate(perPage: 1, page: 1)->items()[0]->name);
        self::assertSame('Second', $repository->paginate(perPage: 1, page: 2)->items()[0]->name);

        DB::table('pagination_cache_items')->where('id', $first->getKey())->update(['name' => 'Changed']);

        self::assertSame('First', $repository->paginate(perPage: 1, page: 1)->items()[0]->name);
        self::assertSame('Second', $repository->paginate(perPage: 1, page: 2)->items()[0]->name);

        $repository->update($first->getKey(), ['name' => 'After Write']);

        self::assertSame('After Write', $repository->paginate(perPage: 1, page: 1)->items()[0]->name);
    }

    public function test_default_ttl_per_call_override_and_bypass_do_not_change_repository_settings(): void
    {
        $repository = $this->repository();
        $record = $repository->create(['name' => 'Initial', 'status' => 'active']);

        self::assertSame(CacheTtl::MINUTES_10, $repository->configuredTtl());
        self::assertSame('Initial', $repository->paginate()->items()[0]->name);

        DB::table('pagination_cache_items')->where('id', $record->getKey())->update(['name' => 'Next']);

        self::assertSame('Initial', $repository->paginate()->items()[0]->name);
        self::assertSame('Next', $repository->paginate(cacheTtl: 30)->items()[0]->name);
        self::assertSame(CacheTtl::MINUTES_10, $repository->configuredTtl());

        DB::table('pagination_cache_items')->where('id', $record->getKey())->update(['name' => 'Fresh']);

        self::assertSame('Next', $repository->paginate(cacheTtl: 30)->items()[0]->name);
        self::assertSame('Fresh', $repository->paginate(useCache: false)->items()[0]->name);
        self::assertSame('Initial', $repository->paginate()->items()[0]->name);
        self::assertSame(CacheTtl::MINUTES_10, $repository->configuredTtl());

        self::assertSame('Fresh', $repository->withoutCache()->paginate()->items()[0]->name);
    }

    public function test_requested_page_is_used_in_cache_key(): void
    {
        $repository = $this->repository();
        $repository->create(['name' => 'First', 'status' => 'active']);
        $repository->create(['name' => 'Second', 'status' => 'active']);

        request()->query->set('page', 1);
        self::assertSame('First', $repository->paginate(perPage: 1)->items()[0]->name);

        request()->query->set('page', 2);
        self::assertSame('Second', $repository->paginate(perPage: 1)->items()[0]->name);

        request()->query->remove('page');
    }

    public function test_global_scope_bindings_isolate_cached_tenant_results(): void
    {
        DB::table('pagination_cache_items')->insert([
            ['tenant_id' => 1, 'name' => 'One', 'status' => 'active'],
            ['tenant_id' => 2, 'name' => 'Two', 'status' => 'active'],
        ]);

        config()->set('pagination_test.tenant', 1);
        $repository = new TenantPaginationRepository(
            new TenantPaginationItem,
            $this->app->make(CacheManager::class),
        );
        self::assertSame('One', $repository->paginate()->items()[0]->name);

        config()->set('pagination_test.tenant', 2);
        self::assertSame('Two', $repository->paginate()->items()[0]->name);
    }

    public function test_invalid_cache_ttl_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repository()->paginate(cacheTtl: 0);
    }

    private function repository(): PaginationCacheRepository
    {
        return new PaginationCacheRepository(
            new PaginationCacheItem,
            $this->app->make(CacheManager::class),
        );
    }
}

class PaginationCacheRepository extends BaseRepository
{
    protected array $allowedFilters = ['status', 'tenant_id'];

    protected function defaultCacheTtl(): int
    {
        return CacheTtl::MINUTES_10;
    }

    public function configuredTtl(): ?int
    {
        return $this->cacheTtl;
    }
}

class TenantPaginationRepository extends BaseRepository
{
}

class PaginationCacheItem extends Model
{
    public $timestamps = false;

    protected $table = 'pagination_cache_items';

    protected $guarded = [];
}

class TenantPaginationItem extends PaginationCacheItem
{
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $builder->where('tenant_id', config('pagination_test.tenant', 1));
        });
    }
}
