<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class RepositoryEdgeCasesTest extends TestCase
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

        Schema::create('edge_plain_items', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
            $table->string('code')->nullable();
        });

        Schema::create('edge_soft_items', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
            $table->softDeletes();
        });

        Schema::create('edge_regions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('score');
        });

        Schema::create('edge_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('region_id');
            $table->unsignedInteger('balance');
        });

        Schema::create('edge_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
        });

        Schema::create('edge_uuid_items', function (Blueprint $table): void {
            $table->string('uuid')->primary();
            $table->string('label');
        });

        EdgeRegion::query()->create(['score' => 20]);
        EdgeAccount::query()->create([
            'region_id' => 1,
            'balance' => 100,
        ]);
        EdgeUser::query()->create(['account_id' => 1]);
    }

    public function test_non_soft_delete_bulk_restore_matches_single_restore_behavior(): void
    {
        $repository = $this->plainRepository();
        $item = $repository->create([
            'status' => 'active',
            'code' => 'one',
        ]);

        self::assertFalse($repository->restore($item));
        self::assertSame(
            0,
            $repository->bulkRestore(['status' => 'active']),
        );
        self::assertSame(1, EdgePlainItem::query()->count());
    }

    public function test_non_soft_delete_bulk_force_delete_falls_back_to_normal_delete(): void
    {
        $repository = $this->plainRepository();

        $first = $repository->create([
            'status' => 'active',
            'code' => 'one',
        ]);

        self::assertTrue($repository->forceDelete($first));

        $repository->create([
            'status' => 'active',
            'code' => 'two',
        ]);
        $repository->create([
            'status' => 'active',
            'code' => 'three',
        ]);

        self::assertSame(
            2,
            $repository->bulkForceDelete(['status' => 'active']),
        );
        self::assertSame(0, EdgePlainItem::query()->count());
    }

    public function test_soft_delete_bulk_restore_and_force_delete_remain_supported(): void
    {
        $repository = $this->softRepository();

        $repository->create(['status' => 'active']);
        $repository->create(['status' => 'active']);

        self::assertSame(
            2,
            $repository->bulkDelete(['status' => 'active']),
        );
        self::assertSame(2, EdgeSoftItem::withTrashed()->count());

        self::assertSame(
            2,
            $repository->bulkRestore(['status' => 'active']),
        );
        self::assertSame(2, EdgeSoftItem::query()->count());

        $repository->bulkDelete(['status' => 'active']);

        self::assertSame(
            2,
            $repository->bulkForceDelete(['status' => 'active']),
        );
        self::assertSame(0, EdgeSoftItem::withTrashed()->count());
    }

    public function test_empty_bulk_matches_return_zero(): void
    {
        $repository = $this->plainRepository();

        self::assertSame(
            0,
            $repository->bulkUpdate(
                ['status' => 'inactive'],
                ['status' => 'missing'],
            ),
        );
        self::assertSame(
            0,
            $repository->bulkDelete(['status' => 'missing']),
        );
        self::assertSame(
            0,
            $repository->bulkRestore(['status' => 'missing']),
        );
        self::assertSame(
            0,
            $repository->bulkForceDelete(['status' => 'missing']),
        );
    }

    public function test_bulk_writes_invalidate_repository_cache_without_model_cache_trait(): void
    {
        $repository = $this->plainRepository();

        $repository->create([
            'status' => 'active',
            'code' => 'one',
        ]);
        $repository->create([
            'status' => 'active',
            'code' => 'two',
        ]);

        self::assertCount(
            2,
            $repository->get(['status' => 'active']),
        );

        self::assertSame(
            2,
            $repository->bulkUpdate(
                ['status' => 'inactive'],
                ['status' => 'active'],
            ),
        );

        self::assertCount(
            0,
            $repository->get(['status' => 'active']),
        );
        self::assertCount(
            2,
            $repository->get(['status' => 'inactive']),
        );
    }

    public function test_find_where_in_normalizes_integer_identifier_collisions_and_preserves_first_order(): void
    {
        $repository = $this->plainRepository();

        $repository->create(['status' => 'active', 'code' => 'one']);
        $repository->create(['status' => 'active', 'code' => 'two']);
        $repository->create(['status' => 'active', 'code' => 'three']);

        $models = $repository->findWhereIn(
            'id',
            ['002', 999, 1, '1', 2, '0002'],
        );

        self::assertSame([2, 1], $models->modelKeys());
    }

    public function test_find_where_in_handles_string_and_duplicate_identifiers(): void
    {
        $repository = $this->plainRepository();

        $repository->create(['status' => 'active', 'code' => '1']);
        $repository->create(['status' => 'active', 'code' => '01']);

        $models = $repository->findWhereIn(
            'code',
            [1, '1', '01', 'missing', '01'],
        );

        self::assertSame(
            ['1', '01'],
            $models->pluck('code')->all(),
        );
    }

    public function test_find_where_in_handles_uuid_identifiers_and_missing_values(): void
    {
        $repository = $this->uuidRepository();

        EdgeUuidItem::query()->create([
            'uuid' => 'uuid-b',
            'label' => 'B',
        ]);
        EdgeUuidItem::query()->create([
            'uuid' => 'uuid-a',
            'label' => 'A',
        ]);

        $models = $repository->findWhereIn(
            'uuid',
            ['uuid-a', 'missing', 'uuid-a', 'uuid-b'],
        );

        self::assertSame(
            ['uuid-a', 'uuid-b'],
            $models->modelKeys(),
        );
    }

    public function test_direct_relation_aggregates_validate_and_execute(): void
    {
        $sumRepository = $this->userRepository();
        $sum = $sumRepository
            ->withSum('account', 'balance')
            ->pendingFirst();

        self::assertNotNull($sum);
        self::assertSame(
            100,
            (int) $this->aggregateAttribute(
                $sum,
                '_sum_balance',
            ),
        );

        $avgRepository = $this->userRepository();
        $avg = $avgRepository
            ->withAvg('account', 'balance')
            ->pendingFirst();

        self::assertNotNull($avg);
        self::assertSame(
            100.0,
            (float) $this->aggregateAttribute(
                $avg,
                '_avg_balance',
            ),
        );
    }

    public function test_invalid_relation_aggregate_columns_throw_instead_of_being_silently_ignored(): void
    {
        foreach ([
            fn () => $this->userRepository()->withSum(
                'account',
                'missing_column',
            ),
            fn () => $this->userRepository()->withAvg(
                'account',
                'missing_column',
            ),
        ] as $operation) {
            try {
                $operation();
                self::fail(
                    'Expected invalid aggregate column to be rejected.',
                );
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString(
                    'account.missing_column',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_nested_relation_aggregates_fail_early_with_clear_error(): void
    {
        foreach ([
            fn () => $this->userRepository()->withSum(
                'account.region',
                'score',
            ),
            fn () => $this->userRepository()->withAvg(
                'account.region',
                'score',
            ),
        ] as $operation) {
            try {
                $operation();
                self::fail(
                    'Expected nested aggregate relation to be rejected.',
                );
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString(
                    'account.region',
                    $exception->getMessage(),
                );
            }
        }
    }

    private function aggregateAttribute(
        Model $model,
        string $suffix,
    ): mixed {
        foreach ($model->getAttributes() as $key => $value) {
            if (str_ends_with($key, $suffix)) {
                return $value;
            }
        }

        self::fail(
            "Expected aggregate attribute ending in [{$suffix}].",
        );
    }

    private function plainRepository(): EdgePlainRepository
    {
        return new EdgePlainRepository(
            new EdgePlainItem,
            $this->app->make(CacheManager::class),
        );
    }

    private function softRepository(): EdgeSoftRepository
    {
        return new EdgeSoftRepository(
            new EdgeSoftItem,
            $this->app->make(CacheManager::class),
        );
    }

    private function uuidRepository(): EdgeUuidRepository
    {
        return new EdgeUuidRepository(
            new EdgeUuidItem,
            $this->app->make(CacheManager::class),
        );
    }

    private function userRepository(): EdgeUserRepository
    {
        return new EdgeUserRepository(
            new EdgeUser,
            $this->app->make(CacheManager::class),
        );
    }
}

final class EdgePlainRepository extends BaseRepository
{
    protected array $allowedFilters = [
        'id',
        'status',
        'code',
    ];
}

final class EdgeSoftRepository extends BaseRepository
{
    protected array $allowedFilters = [
        'id',
        'status',
    ];
}

final class EdgeUuidRepository extends BaseRepository
{
    protected array $allowedFilters = ['uuid'];
}

final class EdgeUserRepository extends BaseRepository
{
    protected array $allowedRelations = [
        'account',
        'account.region',
    ];

    public function pendingFirst(): ?Model
    {
        return $this->query->first();
    }
}

final class EdgePlainItem extends Model
{
    public $timestamps = false;

    protected $table = 'edge_plain_items';

    protected $guarded = [];
}

final class EdgeSoftItem extends Model
{
    use SoftDeletes;

    public $timestamps = false;

    protected $table = 'edge_soft_items';

    protected $guarded = [];
}

final class EdgeRegion extends Model
{
    public $timestamps = false;

    protected $table = 'edge_regions';

    protected $guarded = [];
}

final class EdgeAccount extends Model
{
    public $timestamps = false;

    protected $table = 'edge_accounts';

    protected $guarded = [];

    public function region()
    {
        return $this->belongsTo(EdgeRegion::class, 'region_id');
    }
}

final class EdgeUser extends Model
{
    public $timestamps = false;

    protected $table = 'edge_users';

    protected $guarded = [];

    public function account()
    {
        return $this->belongsTo(EdgeAccount::class, 'account_id');
    }
}

final class EdgeUuidItem extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'edge_uuid_items';

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $guarded = [];
}
