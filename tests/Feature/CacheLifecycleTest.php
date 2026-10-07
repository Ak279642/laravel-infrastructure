<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Models\BaseModel;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Ak279642\LaravelInfrastructure\Validation\ValidationContext;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CacheLifecycleTest extends TestCase
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
        $app['config']->set(
            'laravel-infrastructure.cache.lock.enabled',
            false,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cache_records', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('cache_parents', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('cache_children', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cache_parent_id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('cache_grandchildren', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cache_child_id');
            $table->string('name');
            $table->timestamps();
        });
    }

    public function test_model_lifecycle_invalidates_repository_cache(): void
    {
        $first = CacheRecord::query()->create([
            'name' => 'One',
        ]);

        $repository = $this->recordRepository();

        self::assertCount(1, $repository->get());

        CacheRecord::query()->create([
            'name' => 'Two',
        ]);

        self::assertCount(2, $repository->get());

        CacheRecord::query()
            ->findOrFail($first->getKey())
            ->update(['name' => 'Updated']);

        self::assertSame(
            'Updated',
            $repository
                ->findOrFail($first->getKey())
                ->name,
        );

        $model = CacheRecord::query()
            ->findOrFail($first->getKey());

        $repository->get();
        $model->delete();

        self::assertCount(1, $repository->get());

        $model->restore();

        self::assertCount(2, $repository->get());

        $model->forceDelete();

        self::assertCount(1, $repository->get());
    }

    public function test_nested_dependency_tags_invalidate_multiple_repository_caches(): void
    {
        $parent = CacheParent::query()->create([
            'name' => 'Parent',
        ]);

        $child = CacheChild::query()->create([
            'cache_parent_id' => $parent->getKey(),
            'name' => 'Child',
        ]);

        $grandchild = CacheGrandchild::query()->create([
            'cache_child_id' => $child->getKey(),
            'name' => 'Old Grandchild',
        ]);

        $firstRepository = $this->parentRepository();
        $secondRepository = $this->alternateParentRepository();

        self::assertSame(
            'Old Grandchild',
            $firstRepository
                ->get(['with' => ['children.grandchildren']])
                ->first()
                ->children
                ->first()
                ->grandchildren
                ->first()
                ->name,
        );

        self::assertSame(
            'Old Grandchild',
            $secondRepository
                ->get(['with' => ['children.grandchildren']])
                ->first()
                ->children
                ->first()
                ->grandchildren
                ->first()
                ->name,
        );

        CacheGrandchild::query()
            ->findOrFail($grandchild->getKey())
            ->update(['name' => 'Fresh Grandchild']);

        foreach ([$firstRepository, $secondRepository] as $repository) {
            self::assertSame(
                'Fresh Grandchild',
                $repository
                    ->get(['with' => ['children.grandchildren']])
                    ->first()
                    ->children
                    ->first()
                    ->grandchildren
                    ->first()
                    ->name,
            );
        }
    }

    public function test_direct_database_writes_require_manual_invalidation(): void
    {
        $record = CacheRecord::query()->create([
            'name' => 'Cached',
        ]);

        $repository = $this->recordRepository();

        self::assertSame(
            'Cached',
            $repository->findOrFail($record->getKey())->name,
        );

        DB::table('cache_records')
            ->where('id', $record->getKey())
            ->update(['name' => 'Direct DB']);

        self::assertSame(
            'Cached',
            $repository->findOrFail($record->getKey())->name,
        );

        $repository->clearCache();

        self::assertSame(
            'Direct DB',
            $repository->findOrFail($record->getKey())->name,
        );
    }

    private function recordRepository(): CacheRecordRepository
    {
        return new CacheRecordRepository(
            new CacheRecord(),
            $this->app->make(CacheManager::class),
            $this->app->make(ValidationContext::class),
        );
    }

    private function parentRepository(): CacheParentRepository
    {
        return new CacheParentRepository(
            new CacheParent(),
            $this->app->make(CacheManager::class),
            $this->app->make(ValidationContext::class),
        );
    }

    private function alternateParentRepository(): AlternateCacheParentRepository
    {
        return new AlternateCacheParentRepository(
            new CacheParent(),
            $this->app->make(CacheManager::class),
            $this->app->make(ValidationContext::class),
        );
    }
}

final class CacheRecord extends BaseModel
{
    use SoftDeletes;

    protected $table = 'cache_records';

    protected $guarded = [];
}

final class CacheParent extends BaseModel
{
    protected $table = 'cache_parents';

    protected $guarded = [];

    public function children(): HasMany
    {
        return $this->hasMany(
            CacheChild::class,
            'cache_parent_id',
        );
    }
}

final class CacheChild extends BaseModel
{
    protected $table = 'cache_children';

    protected $guarded = [];

    public function grandchildren(): HasMany
    {
        return $this->hasMany(
            CacheGrandchild::class,
            'cache_child_id',
        );
    }
}

final class CacheGrandchild extends BaseModel
{
    protected $table = 'cache_grandchildren';

    protected $guarded = [];
}

final class CacheRecordRepository extends BaseRepository {}

class CacheParentRepository extends BaseRepository
{
    protected array $allowedRelations = [
        'children',
        'children.grandchildren',
    ];
}

final class AlternateCacheParentRepository extends CacheParentRepository {}
