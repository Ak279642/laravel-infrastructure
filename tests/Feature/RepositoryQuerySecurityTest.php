<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Exceptions\FilterNotAllowedException;
use Ak279642\LaravelInfrastructure\Exceptions\RelationNotAllowedException;
use Ak279642\LaravelInfrastructure\Exceptions\SortNotAllowedException;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class RepositoryQuerySecurityTest extends TestCase
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

        Schema::create('query_security_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('query_security_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('name');
            $table->string('email');
            $table->string('status');
            $table->unsignedInteger('age');
            $table->string('secret')->nullable();
            $table->timestamps();
        });

        QuerySecurityAccount::query()->create(['name' => 'Acme']);
        QuerySecurityUser::query()->create([
            'account_id' => 1,
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'status' => 'active',
            'age' => 30,
            'secret' => 'hidden',
        ]);
        QuerySecurityUser::query()->create([
            'account_id' => 1,
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'status' => 'inactive',
            'age' => 17,
            'secret' => 'hidden',
        ]);
    }

    public function test_only_allow_listed_filters_are_applied(): void
    {
        $repository = $this->repository();

        self::assertCount(1, $repository->get(['status' => 'active']));
        self::assertCount(2, $repository->get(['secret' => 'hidden']));
    }

    public function test_supported_operator_formats_remain_compatible(): void
    {
        $repository = $this->repository();

        self::assertSame(
            ['Alice'],
            $repository->get(['age' => ['>=', 18]])->pluck('name')->all(),
        );

        self::assertSame(
            ['Alice'],
            $repository->get(['age' => ['operator' => '>=', 'value' => 18]])->pluck('name')->all(),
        );
    }

    public function test_sort_allow_list_blocks_unapproved_columns(): void
    {
        $repository = $this->repository();

        self::assertSame(
            ['Alice', 'Bob'],
            $repository->get(['sort' => ['secret' => 'desc']])->pluck('name')->all(),
        );
    }

    public function test_relation_filter_requires_explicit_allow_list_and_real_related_column(): void
    {
        $repository = $this->repository();

        self::assertSame(
            ['Alice', 'Bob'],
            $repository->get(['account.name' => 'Acme'])->pluck('name')->all(),
        );

        self::assertCount(
            2,
            $repository->get(['anything.something' => 'value']),
        );

        self::assertCount(
            2,
            $repository->get(['account.missing_column' => 'value']),
        );

        self::assertCount(
            2,
            $repository->get(['account.name) OR 1=1 --' => 'Acme']),
        );
    }

    public function test_relation_and_relation_count_allow_lists_are_enforced(): void
    {
        $repository = $this->repository();

        $user = $repository->get(['with' => ['account']])->first();
        self::assertTrue($user->relationLoaded('account'));

        $blocked = $repository->get(['with' => ['notARealRelation']])->first();
        self::assertFalse($blocked->relationLoaded('notARealRelation'));

        $counted = $repository->get(['with_count' => ['account']])->first();
        self::assertSame(1, $counted->account_count);
    }

    public function test_search_columns_cannot_bypass_searchable_allow_list(): void
    {
        $repository = $this->repository();

        self::assertCount(0, $repository->get([
            'search' => 'hidden',
            'search_columns' => ['secret'],
        ]));
    }

    public function test_strict_mode_throws_actionable_exceptions(): void
    {
        $repository = $this->strictRepository();

        try {
            $repository->get(['stauts' => 'active']);
            self::fail('Expected FilterNotAllowedException.');
        } catch (FilterNotAllowedException $exception) {
            self::assertStringContainsString('stauts', $exception->getMessage());
            self::assertStringContainsString('status', $exception->getMessage());
        }

        $this->expectException(SortNotAllowedException::class);
        $repository->get(['sort' => ['secret']]);
    }

    public function test_strict_relation_rejection_uses_specific_exception(): void
    {
        $this->expectException(RelationNotAllowedException::class);

        $this->strictRepository()->get(['with' => ['notARealRelation']]);
    }

    public function test_pagination_and_explicitly_allowed_scopes_work(): void
    {
        $repository = $this->repository();

        self::assertSame(1, $repository->paginate(['status' => 'active'], 1)->total());
        self::assertSame(['Alice'], $repository->get(['scopes' => ['adult']])->pluck('name')->all());
    }

    public function test_unapproved_model_scope_cannot_be_invoked_from_filters(): void
    {
        $repository = (new QuerySecurityNoScopeRepository(
            new QuerySecurityUser(),
            $this->app->make(CacheManager::class),
        ))->withoutCache();

        self::assertSame(
            ['Alice', 'Bob'],
            $repository->get(['scopes' => ['adult']])->pluck('name')->all(),
        );
    }

    private function repository(): QuerySecurityRepository
    {
        return (new QuerySecurityRepository(
            new QuerySecurityUser(),
            $this->app->make(CacheManager::class),
        ))->withoutCache();
    }

    private function strictRepository(): StrictQuerySecurityRepository
    {
        return (new StrictQuerySecurityRepository(
            new QuerySecurityUser(),
            $this->app->make(CacheManager::class),
        ))->withoutCache();
    }
}

class QuerySecurityRepository extends BaseRepository
{
    protected array $searchable = ['name', 'email'];
    protected array $allowedFilters = ['status', 'age'];
    protected array $allowedRelationFilters = ['account.name'];
    protected array $allowedSorts = ['name', 'created_at'];
    protected array $allowedRelations = ['account'];
    protected array $allowedScopes = ['adult'];
}

final class QuerySecurityNoScopeRepository extends BaseRepository
{
    protected array $allowedFilters = ['status'];
}

final class StrictQuerySecurityRepository extends QuerySecurityRepository
{
    protected bool $strictFilters = true;
}

final class QuerySecurityUser extends Model
{
    protected $table = 'query_security_users';
    protected $guarded = [];

    public function account()
    {
        return $this->belongsTo(QuerySecurityAccount::class, 'account_id');
    }

    public function scopeAdult($query)
    {
        return $query->where('age', '>=', 18);
    }
}

final class QuerySecurityAccount extends Model
{
    protected $table = 'query_security_accounts';
    public $timestamps = false;
    protected $guarded = [];
}
