<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\Events\CacheBypassed;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Exceptions\FilterNotAllowedException;
use Ak279642\LaravelInfrastructure\Exceptions\RelationNotAllowedException;
use Ak279642\LaravelInfrastructure\Exceptions\SortNotAllowedException;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Ak279642\LaravelInfrastructure\Validation\ValidationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;

final class RepositoryHardeningTest extends TestCase
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

        Schema::create('hardening_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('secret')->nullable();
        });

        HardeningUser::query()->create([
            'name' => 'Alice',
            'email' => 'alice@example.test',
            'secret' => 'needle',
        ]);
        HardeningUser::query()->create([
            'name' => 'Bob',
            'email' => 'bob@example.test',
            'secret' => 'hidden',
        ]);
    }

    public function test_without_cache_is_consumed_by_one_operation(): void
    {
        Event::fake([CacheBypassed::class]);

        $repository = $this->repository();

        self::assertSame(2, $repository->withoutCache()->count());

        $property = new ReflectionProperty($repository, 'bypassCacheOnce');
        $property->setAccessible(true);

        self::assertFalse($property->getValue($repository));
        self::assertSame(2, $repository->count());

        Event::assertDispatched(CacheBypassed::class, function (CacheBypassed $event): bool {
            return $event->repository === HardeningUserRepository::class
                && $event->operation === 'count';
        });
    }

    public function test_strict_filter_mode_reports_allowed_filters(): void
    {
        $this->expectException(FilterNotAllowedException::class);
        $this->expectExceptionMessage('Filter [stauts] is not allowed');

        $this->repository()->get(['stauts' => 'active']);
    }

    public function test_strict_sort_mode_rejects_unknown_sort(): void
    {
        $this->expectException(SortNotAllowedException::class);

        $this->repository()->get(['sort' => ['secret']]);
    }

    public function test_strict_relation_mode_rejects_unknown_relation(): void
    {
        $this->expectException(RelationNotAllowedException::class);

        $this->repository()->get(['with' => ['secrets']]);
    }

    public function test_client_cannot_expand_search_columns_beyond_repository_allow_list(): void
    {
        $result = $this->repository()->get([
            'search' => 'needle',
            'search_columns' => ['secret'],
        ]);

        self::assertCount(2, $result);
    }

    private function repository(): HardeningUserRepository
    {
        return new HardeningUserRepository(
            new HardeningUser,
            $this->app->make(CacheManager::class),
            $this->app->make(ValidationContext::class),
        );
    }
}

final class HardeningUser extends Model
{
    public $timestamps = false;

    protected $table = 'hardening_users';

    protected $guarded = [];
}

final class HardeningUserRepository extends BaseRepository
{
    protected array $allowedFilters = ['email'];

    protected array $allowedSorts = ['name'];

    protected array $allowedRelations = [];

    protected array $searchable = ['name', 'email'];

    protected bool $strictFilters = true;

    protected bool $strictSorts = true;

    protected bool $strictRelations = true;
}
