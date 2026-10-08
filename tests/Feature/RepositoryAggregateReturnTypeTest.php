<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;

final class RepositoryAggregateReturnTypeTest extends TestCase
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

        Schema::create('aggregate_return_type_items', function (Blueprint $table): void {
            $table->id();
            $table->decimal('amount', 24, 4);
        });
    }

    public function test_sum_returns_a_decimal_string_without_losing_precision(): void
    {
        $expected = '12345678901234567890.1234';
        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('sum')
            ->once()
            ->with('amount')
            ->andReturn($expected);

        $repository = new AggregateReturnTypeRepository(
            new AggregateReturnTypeItem,
            $this->app->make(CacheManager::class),
        );
        $repository->aggregateQuery = $builder;

        self::assertSame($expected, $repository->sum('amount'));
    }

    public function test_avg_returns_a_decimal_string_without_losing_precision(): void
    {
        $expected = '9876543210.1234';
        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('avg')
            ->once()
            ->with('amount')
            ->andReturn($expected);

        $repository = new AggregateReturnTypeRepository(
            new AggregateReturnTypeItem,
            $this->app->make(CacheManager::class),
        );
        $repository->aggregateQuery = $builder;

        self::assertSame($expected, $repository->avg('amount'));
    }
}

final class AggregateReturnTypeRepository extends BaseRepository
{
    public ?Builder $aggregateQuery = null;

    protected function buildQuery(array $filters = []): Builder
    {
        return $this->aggregateQuery ?? parent::buildQuery($filters);
    }
}

final class AggregateReturnTypeItem extends Model
{
    public $timestamps = false;

    protected $table = 'aggregate_return_type_items';

    protected $guarded = [];
}
