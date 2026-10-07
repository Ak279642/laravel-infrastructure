<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Actions\BaseAction;
use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Contracts\TransactionManager;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Exceptions\RepositoryValidationConfigurationException;
use Ak279642\LaravelInfrastructure\Services\BaseService;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Ak279642\LaravelInfrastructure\Validation\RepositoryValidationRule;
use Ak279642\LaravelInfrastructure\Validation\RepositoryValidationService;
use Ak279642\LaravelInfrastructure\Validation\ValidationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class ValidationActionServiceHardeningTest extends TestCase
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

        Schema::create('batch3_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('code');
            $table->timestamps();
        });

        Schema::create('batch3_products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('batch3_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function test_scoped_validation_uses_explicit_null_input_instead_of_literal_field_name(): void
    {
        Batch3Record::query()->create([
            'organization_id' => null,
            'code' => 'ABC',
        ]);

        $errors = $this->app->make(RepositoryValidationService::class)->errors([
            new RepositoryValidationRule(
                repository: Batch3RecordRepository::class,
                unique: ['code'],
                where: ['organization_id' => 'organization_id'],
            ),
        ], [
            'organization_id' => null,
            'code' => 'ABC',
        ]);

        self::assertArrayHasKey('code', $errors);
        self::assertSame([], $this->app->make(ValidationContext::class)->all());
    }

    public function test_exists_in_resolution_is_reused_by_repository_find_without_duplicate_lookup(): void
    {
        $first = Batch3Product::query()->create(['name' => 'One']);
        $second = Batch3Product::query()->create(['name' => 'Two']);

        $service = $this->app->make(RepositoryValidationService::class);

        self::assertSame([], $service->errors([
            new RepositoryValidationRule(
                repository: Batch3ProductRepository::class,
                existsIn: [
                    'field' => 'product_ids',
                    'column' => 'id',
                    'values' => 'product_ids',
                ],
                resolve: [
                    [
                        'field' => 'product_ids',
                        'as' => 'products',
                    ],
                ],
            ),
        ], [
            'product_ids' => [$first->getKey(), $second->getKey()],
        ]));

        $context = $this->app->make(ValidationContext::class);
        $products = $context->requireCollection('products', Batch3Product::class);
        $repository = $this->app->make(Batch3ProductRepository::class);

        self::assertSame(
            $products->firstWhere('id', $first->getKey()),
            $repository->findOrFail($first->getKey()),
        );
    }

    public function test_validation_configuration_failure_clears_partially_resolved_context(): void
    {
        $product = Batch3Product::query()->create(['name' => 'One']);
        $service = $this->app->make(RepositoryValidationService::class);

        try {
            $service->errors([
                new RepositoryValidationRule(
                    repository: Batch3ProductRepository::class,
                    exists: ['product_id'],
                    resolve: [
                        [
                            'field' => 'product_id',
                            'as' => 'product',
                        ],
                    ],
                ),
                new RepositoryValidationRule(
                    repository: Batch3InvalidValidationRepository::class,
                    exists: ['product_id'],
                ),
            ], [
                'product_id' => $product->getKey(),
            ]);

            self::fail('Expected repository validation configuration exception.');
        } catch (RepositoryValidationConfigurationException $exception) {
            self::assertStringContainsString(
                Batch3InvalidValidationRepository::class,
                $exception->getMessage(),
            );
        }

        self::assertSame([], $this->app->make(ValidationContext::class)->all());
    }

    public function test_base_service_helpers_keep_business_logic_out_of_repository(): void
    {
        $repository = $this->app->make(Batch3ItemRepository::class);
        $service = new Batch3ItemService($repository);

        $item = $service->create('  alpha  ');

        self::assertSame('ALPHA', $item->name);
        self::assertTrue($service->existsByName('ALPHA'));

        $updated = $service->rename($item->getKey(), ' beta ');

        self::assertSame('BETA', $updated->name);
        self::assertSame($updated->getKey(), $service->find($updated->getKey())->getKey());
        self::assertTrue($service->remove($updated->getKey()));
        self::assertFalse($service->existsByName('BETA'));
    }

    public function test_action_owns_transaction_and_rolls_back_service_writes_on_failure(): void
    {
        $service = new Batch3ItemService(
            $this->app->make(Batch3ItemRepository::class),
        );

        $action = new Batch3CreateItemAction(
            $this->app->make(TransactionManager::class),
            $service,
        );

        self::assertSame('COMMITTED', $action->execute(' committed ')->name);

        try {
            $action->execute(' rollback ', fail: true);
            self::fail('Expected action failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('force rollback', $exception->getMessage());
        }

        self::assertSame(
            ['COMMITTED'],
            Batch3Item::query()->orderBy('id')->pluck('name')->all(),
        );
    }
}

final class Batch3RecordRepository extends BaseRepository
{
}

final class Batch3ProductRepository extends BaseRepository
{
}

final class Batch3ItemRepository extends BaseRepository
{
    protected array $allowedFilters = ['name'];
}

final class Batch3Record extends Model
{
    protected $table = 'batch3_records';
    protected $guarded = [];
}

final class Batch3Product extends Model
{
    protected $table = 'batch3_products';
    protected $guarded = [];
}

final class Batch3Item extends Model
{
    protected $table = 'batch3_items';
    protected $guarded = [];
}

final class Batch3InvalidValidationRepository
{
}

final class Batch3ItemService extends BaseService
{
    public function __construct(Batch3ItemRepository $repository)
    {
        parent::__construct($repository);
    }

    public function create(string $name): Batch3Item
    {
        /** @var Batch3Item */
        return $this->createRecord(['name' => $name]);
    }

    public function rename(int|string $id, string $name): Batch3Item
    {
        /** @var Batch3Item */
        return $this->updateRecord($id, ['name' => $name]);
    }

    public function find(int|string $id): Batch3Item
    {
        /** @var Batch3Item */
        return $this->findRecordOrFail($id);
    }

    public function existsByName(string $name): bool
    {
        return $this->recordExists(['name' => $name]);
    }

    public function remove(int|string $id): bool
    {
        return $this->deleteRecord($id);
    }

    protected function beforeCreate(array $data): array
    {
        $data['name'] = strtoupper(trim((string) $data['name']));

        return $data;
    }

    protected function beforeUpdate(int|string|Model $id, array $data): array
    {
        $data['name'] = strtoupper(trim((string) $data['name']));

        return $data;
    }
}

final class Batch3CreateItemAction extends BaseAction
{
    public function __construct(
        TransactionManager $transactions,
        private readonly Batch3ItemService $service,
    ) {
        parent::__construct($transactions);
    }

    public function execute(string $name, bool $fail = false): Batch3Item
    {
        return $this->transactional(function () use ($name, $fail): Batch3Item {
            $item = $this->service->create($name);

            if ($fail) {
                throw new RuntimeException('force rollback');
            }

            return $item;
        });
    }
}
