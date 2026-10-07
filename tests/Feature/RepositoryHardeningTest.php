<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Exceptions\FilterNotAllowedException;
use Ak279642\LaravelInfrastructure\Exceptions\RelationNotAllowedException;
use Ak279642\LaravelInfrastructure\Exceptions\SortNotAllowedException;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Ak279642\LaravelInfrastructure\Validation\ValidationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            $table->string('status');
            $table->unsignedInteger('age');
        });

        Schema::create('hardening_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hardening_user_id');
            $table->string('city');
        });
    }

    public function test_repository_reads_are_cached_by_default_and_without_cache_is_one_shot(): void
    {
        $user = HardeningUser::query()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
            'status' => 'active',
            'age' => 30,
        ]);

        $repository = $this->repository();

        self::assertSame(
            'Old Name',
            $repository->get()->first()->name,
        );

        DB::table('hardening_users')
            ->where('id', $user->getKey())
            ->update(['name' => 'Fresh Name']);

        // Direct DB writes do not emit Eloquent events, so the cached read is
        // intentionally stale until manually invalidated.
        self::assertSame(
            'Old Name',
            $repository->get()->first()->name,
        );

        self::assertSame(
            'Fresh Name',
            $repository
                ->withoutCache()
                ->get()
                ->first()
                ->name,
        );

        // The original repository was not mutated by withoutCache().
        self::assertSame(
            'Old Name',
            $repository->get()->first()->name,
        );

        $repository->clearCache();

        self::assertSame(
            'Fresh Name',
            $repository->get()->first()->name,
        );
    }

    public function test_supported_compact_filter_operators_are_applied(): void
    {
        HardeningUser::query()->create([
            'name' => 'Adult',
            'email' => 'adult@example.com',
            'status' => 'active',
            'age' => 22,
        ]);

        HardeningUser::query()->create([
            'name' => 'Minor',
            'email' => 'minor@example.com',
            'status' => 'active',
            'age' => 15,
        ]);

        $result = $this->repository()->get([
            'age' => ['>=', 18],
        ]);

        self::assertCount(1, $result);
        self::assertSame('Adult', $result->first()->name);
    }

    public function test_unknown_filter_is_ignored_by_default_but_strict_mode_is_actionable(): void
    {
        HardeningUser::query()->create([
            'name' => 'A',
            'email' => 'a@example.com',
            'status' => 'active',
            'age' => 20,
        ]);

        self::assertCount(
            1,
            $this->repository()->get([
                'stauts' => 'active',
            ]),
        );

        $this->expectException(FilterNotAllowedException::class);
        $this->expectExceptionMessage('Filter [stauts] is not allowed');

        $this->strictRepository()->get([
            'stauts' => 'active',
        ]);
    }

    public function test_search_columns_cannot_expand_the_repository_allow_list(): void
    {
        HardeningUser::query()->create([
            'name' => 'Visible',
            'email' => 'hidden-search@example.com',
            'status' => 'active',
            'age' => 20,
        ]);

        $result = $this->repository()->get([
            'search' => 'hidden-search',
            'search_columns' => ['email'],
        ]);

        self::assertCount(0, $result);

        $this->expectException(FilterNotAllowedException::class);

        $this->strictRepository()->get([
            'search' => 'hidden-search',
            'search_columns' => ['email'],
        ]);
    }

    public function test_relation_filters_require_both_filter_and_relation_allow_lists(): void
    {
        $user = HardeningUser::query()->create([
            'name' => 'A',
            'email' => 'a@example.com',
            'status' => 'active',
            'age' => 20,
        ]);

        HardeningProfile::query()->create([
            'hardening_user_id' => $user->getKey(),
            'city' => 'Delhi',
        ]);

        self::assertCount(
            1,
            $this->repository()->get([
                'profile.city' => 'Delhi',
            ]),
        );

        $this->expectException(FilterNotAllowedException::class);

        $this->strictRepository()->get([
            'profile.country' => 'India',
        ]);
    }

    public function test_unknown_relations_and_sorts_are_ignored_or_rejected_in_strict_mode(): void
    {
        HardeningUser::query()->create([
            'name' => 'A',
            'email' => 'a@example.com',
            'status' => 'active',
            'age' => 20,
        ]);

        self::assertCount(
            1,
            $this->repository()->get([
                'with' => ['passwords'],
                'with_count' => ['tokens'],
                'sort' => ['secret_column'],
            ]),
        );

        try {
            $this->strictRepository()->get([
                'sort' => ['secret_column'],
            ]);

            self::fail('Strict sorting should reject unknown columns.');
        } catch (SortNotAllowedException) {
            self::assertTrue(true);
        }

        $this->expectException(RelationNotAllowedException::class);

        $this->strictRepository()->get([
            'with' => ['passwords'],
        ]);
    }

    public function test_only_explicitly_allowed_scopes_can_be_selected(): void
    {
        HardeningUser::query()->create([
            'name' => 'Active',
            'email' => 'active@example.com',
            'status' => 'active',
            'age' => 20,
        ]);

        HardeningUser::query()->create([
            'name' => 'Inactive',
            'email' => 'inactive@example.com',
            'status' => 'inactive',
            'age' => 20,
        ]);

        self::assertCount(
            1,
            $this->repository()->get([
                'scopes' => ['active'],
            ]),
        );

        self::assertCount(
            2,
            $this->repository()->get([
                'scopes' => ['internalOnly'],
            ]),
        );
    }

    public function test_fluent_query_state_is_isolated_and_not_cached_under_a_generic_key(): void
    {
        HardeningUser::query()->create([
            'name' => 'A',
            'email' => 'a@example.com',
            'status' => 'active',
            'age' => 20,
        ]);

        HardeningUser::query()->create([
            'name' => 'B',
            'email' => 'b@example.com',
            'status' => 'active',
            'age' => 20,
        ]);

        $repository = $this->repository();
        $descending = $repository->orderBy('name', 'desc');

        self::assertSame(
            ['B', 'A'],
            $descending->get()->pluck('name')->all(),
        );

        self::assertSame(
            ['A', 'B'],
            $repository
                ->orderBy('name', 'asc')
                ->get()
                ->pluck('name')
                ->all(),
        );
    }

    private function repository(): HardeningUserRepository
    {
        return new HardeningUserRepository(
            new HardeningUser(),
            $this->app->make(CacheManager::class),
            $this->app->make(ValidationContext::class),
        );
    }

    private function strictRepository(): StrictHardeningUserRepository
    {
        return new StrictHardeningUserRepository(
            new HardeningUser(),
            $this->app->make(CacheManager::class),
            $this->app->make(ValidationContext::class),
        );
    }
}

class HardeningUser extends Model
{
    protected $table = 'hardening_users';

    public $timestamps = false;

    protected $guarded = [];

    public function profile(): HasOne
    {
        return $this->hasOne(
            HardeningProfile::class,
            'hardening_user_id',
        );
    }

    public function scopeActive($query)
    {
        return $query->where(
            'status',
            'active',
        );
    }

    public function scopeInternalOnly($query)
    {
        return $query->where(
            'email',
            'like',
            '%@internal.test',
        );
    }
}

final class HardeningProfile extends Model
{
    protected $table = 'hardening_profiles';

    public $timestamps = false;

    protected $guarded = [];
}

class HardeningUserRepository extends BaseRepository
{
    protected array $searchable = ['name'];

    protected array $allowedFilters = [
        'status',
        'age',
        'profile.city',
    ];

    protected array $allowedSorts = [
        'name',
        'age',
    ];

    protected array $allowedRelations = [
        'profile',
    ];

    protected array $allowedScopes = [
        'active',
    ];
}

final class StrictHardeningUserRepository extends HardeningUserRepository
{
    protected bool $strictFilters = true;

    protected bool $strictSorts = true;

    protected bool $strictRelations = true;
}
