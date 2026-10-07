<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class FilterDriverCompatibilityTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $driver = getenv('TEST_DB_DRIVER') ?: 'sqlite';

        $app['config']->set('database.default', 'compatibility');

        $connection = match ($driver) {
            'mysql' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'infrastructure',
                'username' => 'root',
                'password' => 'root',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => 5432,
                'database' => 'infrastructure',
                'username' => 'postgres',
                'password' => 'postgres',
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
                'sslmode' => 'prefer',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        };

        $app['config']->set(
            'database.connections.compatibility',
            $connection,
        );
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('filter_driver_records');

        Schema::create('filter_driver_records', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        FilterDriverRecord::query()->create(['name' => 'Alpha']);
        FilterDriverRecord::query()->create(['name' => 'beta']);
    }

    public function test_ilike_is_case_insensitive_without_invalid_driver_sql(): void
    {
        $repository = $this->repository();

        self::assertSame(
            ['Alpha'],
            $repository
                ->get([
                    'name' => [
                        'operator' => 'ilike',
                        'value' => 'ALP',
                    ],
                ])
                ->pluck('name')
                ->all(),
        );
    }

    public function test_filter_values_remain_bound_data_not_sql_identifiers(): void
    {
        $repository = $this->repository();

        self::assertCount(
            0,
            $repository->get([
                'name' => [
                    'operator' => 'ilike',
                    'value' => "%' OR 1=1 --",
                ],
            ]),
        );
    }

    private function repository(): FilterDriverRepository
    {
        return (new FilterDriverRepository(
            new FilterDriverRecord,
            $this->app->make(CacheManager::class),
        ))->withoutCache();
    }
}

final class FilterDriverRepository extends BaseRepository
{
    protected array $allowedFilters = ['name'];
}

final class FilterDriverRecord extends Model
{
    public $timestamps = false;

    protected $table = 'filter_driver_records';

    protected $guarded = [];
}
