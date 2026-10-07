<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RepositoryNonTaggableCacheTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('cache.default', 'file');
        $app['config']->set('cache.stores.file', [
            'driver' => 'file',
            'path' => storage_path('framework/testing/cache-data'),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('non_taggable_cache_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        NonTaggableCacheItem::query()->create(['name' => 'First']);
    }

    public function test_repository_bypasses_cache_when_store_cannot_support_invalidation_tags(): void
    {
        $repository = new NonTaggableCacheRepository(
            new NonTaggableCacheItem(),
            $this->app->make(CacheManager::class),
        );

        self::assertFalse($this->app->make(CacheManager::class)->supportsTags());
        self::assertSame('First', $repository->get()->first()->name);

        DB::table('non_taggable_cache_items')
            ->where('id', 1)
            ->update(['name' => 'Second']);

        self::assertSame('Second', $repository->get()->first()->name);
    }
}

final class NonTaggableCacheRepository extends BaseRepository
{
}

final class NonTaggableCacheItem extends Model
{
    protected $table = 'non_taggable_cache_items';
    protected $guarded = [];
}
