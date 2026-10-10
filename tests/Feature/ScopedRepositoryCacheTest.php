<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\Concerns\InteractsWithCache;
use Ak279642\LaravelInfrastructure\Contracts\CacheableModel;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ScopedRepositoryCacheTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('auth.providers.scoped_cache', [
            'driver' => 'eloquent', 'model' => ScopedCacheActor::class,
        ]);
        foreach (['partner', 'agent'] as $guard) {
            $app['config']->set('auth.guards.'.$guard, [
                'driver' => 'session', 'provider' => 'scoped_cache',
            ]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('scoped_cache_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tenant_id')->default(10);
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->boolean('is_global')->default(false);
            $table->string('name');
            $table->timestamps();
        });
        DB::table('scoped_cache_records')->insert([
            ['id' => 1, 'user_id' => 101, 'name' => 'First'],
            ['id' => 2, 'user_id' => 102, 'name' => 'Second'],
        ]);
    }

    private function repository(): ScopedCacheRepository
    {
        return new ScopedCacheRepository(new ScopedCacheRecord, app(CacheManager::class));
    }

    private function asUser(int $id): void
    {
        $this->be(new ScopedCacheActor(['id' => $id]));
    }

    public function test_repository_can_be_constructed_before_authentication_but_reads_fail_closed(): void
    {
        $repository = $this->repository();

        try {
            $repository->get();
            self::fail('Expected unauthenticated scoped read to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Authenticated cache scope is required.', $exception->getMessage());
        }

        $this->asUser(101);
        self::assertSame(['First'], $repository->get()->pluck('name')->all());
    }

    public function test_scoped_reads_are_isolated_and_an_update_does_not_flush_another_owner(): void
    {
        $this->asUser(101);
        $repo = $this->repository();
        self::assertSame(['First'], $repo->get()->pluck('name')->all());
        self::assertSame(1, $repo->count());
        self::assertNull($repo->find(2));

        $this->asUser(102);
        self::assertSame(['Second'], $repo->get()->pluck('name')->all());
        self::assertSame(1, $repo->count());
        self::assertNull($repo->find(1));

        // Simulate a direct Eloquent write and verify only the owner's cache is invalidated.
        $this->asUser(101);
        ScopedCacheRecord::query()->findOrFail(1)->update(['name' => 'Changed']);
        self::assertSame('Changed', $repo->get()->first()->name);

        $this->asUser(102);
        self::assertSame('Second', $repo->get()->first()->name);
    }

    public function test_transfer_invalidates_old_and_new_owner_lists(): void
    {
        $repo = $this->repository();
        $this->asUser(101);
        self::assertSame(1, $repo->count());
        $this->asUser(102);
        self::assertSame(1, $repo->count());

        ScopedCacheRecord::query()->findOrFail(1)->update(['user_id' => 102]);

        self::assertSame(2, $repo->count());
        $this->asUser(101);
        self::assertSame(0, $repo->count());
    }

    public function test_composite_scopes_restrict_records_and_invalidate_only_matching_partition(): void
    {
        DB::table('scoped_cache_records')->insert([
            ['id' => 3, 'user_id' => 101, 'tenant_id' => 20, 'name' => 'Other tenant'],
        ]);
        $repo = new ScopedCacheRepository(new CompositeCacheRecord, app(CacheManager::class));
        $this->be(new ScopedCacheActor(['id' => 101, 'tenant_id' => 10]));
        self::assertSame(1, $repo->count());
        self::assertNull($repo->find(3));
        CompositeCacheRecord::query()->findOrFail(1)->update(['tenant_id' => 20]);
        self::assertSame(0, $repo->count());

        $this->be(new ScopedCacheActor(['id' => 101, 'tenant_id' => 20]));
        self::assertSame(2, $repo->count());
    }

    public function test_request_attribute_actor_resolves_without_laravel_authentication(): void
    {
        $repository = new ScopedCacheRepository(new RequestActorCacheRecord, app(CacheManager::class));
        request()->attributes->set('user_id', 101);
        request()->attributes->set('user_type', 'user');

        self::assertSame(1, $repository->count());
        self::assertNull($repository->find(2));

        request()->attributes->set('user_id', 102);
        self::assertSame(1, $repository->count());
        self::assertNull($repository->find(1));
    }

    public function test_class_visibility_resolver_accepts_request_actor(): void
    {
        $repository = new ScopedCacheRepository(new ClassResolverCacheRecord, app(CacheManager::class));
        request()->attributes->set('user_id', 101);
        request()->attributes->set('user_type', 'user');

        self::assertSame(['First'], $repository->get()->pluck('name')->all());

        request()->attributes->set('user_type', 'partner');
        request()->attributes->set('user_id', 102);
        self::assertSame(['Second'], $repository->get()->pluck('name')->all());
    }

    public function test_eloquent_global_scope_is_detected_without_visibility_resolver(): void
    {
        $repository = new ScopedCacheRepository(new GlobalScopeOnlyRecord, app(CacheManager::class));

        request()->attributes->set('user_id', 101);
        self::assertSame(['First'], $repository->get()->pluck('name')->all());

        request()->attributes->set('user_id', 102);
        self::assertSame(['Second'], $repository->get()->pluck('name')->all());

        GlobalScopeOnlyRecord::withoutGlobalScopes()->findOrFail(2)->update(['name' => 'Updated']);
        self::assertSame(['Updated'], $repository->get()->pluck('name')->all());
    }

    public function test_single_named_guard_can_filter_a_custom_column(): void
    {
        $repository = new ScopedCacheRepository(new PartnerGuardCacheRecord, app(CacheManager::class));

        try {
            $repository->get();
            self::fail('Expected missing guard to deny reads.');
        } catch (\RuntimeException $e) {
            self::assertSame('Authenticated cache scope is required.', $e->getMessage());
        }

        $this->be(new ScopedCacheActor(['id' => 101]), 'partner');
        self::assertSame(['First'], $repository->get()->pluck('name')->all());

        $this->be(new ScopedCacheActor(['id' => 102]), 'partner');
        self::assertSame(['Second'], $repository->get()->pluck('name')->all());

        PartnerGuardCacheRecord::query()->findOrFail(2)->update(['name' => 'Updated']);
        self::assertSame(['Updated'], $repository->get()->pluck('name')->all());
    }

    public function test_repeated_guard_supports_different_columns_with_or(): void
    {
        DB::table('scoped_cache_records')->insert([
            ['id' => 3, 'user_id' => 999, 'assigned_to' => 101, 'assigned_by' => 102, 'name' => 'Shared assignment'],
            ['id' => 4, 'user_id' => 888, 'assigned_to' => 102, 'assigned_by' => 103, 'name' => 'Second assignment'],
        ]);
        $repository = new ScopedCacheRepository(new AssignedGuardCacheRecord, app(CacheManager::class));

        $this->be(new ScopedCacheActor(['id' => 101]), 'partner');
        self::assertSame(['First', 'Shared assignment'], $repository->get()->pluck('name')->all());

        $this->be(new ScopedCacheActor(['id' => 102]), 'partner');
        self::assertSame(['Second', 'Shared assignment', 'Second assignment'], $repository->get()->pluck('name')->all());

        AssignedGuardCacheRecord::query()->findOrFail(3)->update(['name' => 'Changed assignment']);
        self::assertContains('Changed assignment', $repository->get()->pluck('name')->all());

        $this->be(new ScopedCacheActor(['id' => 101]), 'partner');
        self::assertContains('Changed assignment', $repository->get()->pluck('name')->all());
    }

    public function test_repeated_guard_uses_and_by_default(): void
    {
        DB::table('scoped_cache_records')->where('id', 1)->update(['assigned_to' => 101]);
        DB::table('scoped_cache_records')->where('id', 2)->update(['assigned_to' => 101]);
        $repository = new ScopedCacheRepository(new AssignedAndGuardCacheRecord, app(CacheManager::class));

        $this->be(new ScopedCacheActor(['id' => 101]), 'partner');
        self::assertSame(['First'], $repository->get()->pluck('name')->all());

        $this->be(new ScopedCacheActor(['id' => 102]), 'partner');
        self::assertSame([], $repository->get()->pluck('name')->all());
    }

    public function test_multiple_named_guards_and_custom_guard_attribute(): void
    {
        $repository = new ScopedCacheRepository(new MultipleGuardsCacheRecord, app(CacheManager::class));

        $this->be(new ScopedCacheActor(['id' => 101]), 'partner');
        $this->be(new ScopedCacheActor(['id' => 77, 'tenant_id' => 10]), 'agent');
        self::assertSame(['First'], $repository->get()->pluck('name')->all());

        $this->be(new ScopedCacheActor(['id' => 77, 'tenant_id' => 20]), 'agent');
        self::assertSame([], $repository->get()->pluck('name')->all());
    }

    public function test_or_rules_can_select_one_of_multiple_guards(): void
    {
        $repository = new ScopedCacheRepository(new AlternativeGuardsCacheRecord, app(CacheManager::class));

        try {
            $repository->count();
            self::fail('Expected at least one authenticated guard.');
        } catch (\RuntimeException $e) {
            self::assertSame('Authenticated cache scope is required.', $e->getMessage());
        }

        $this->be(new ScopedCacheActor(['id' => 101]), 'partner');
        self::assertSame(['First'], $repository->get()->pluck('name')->all());

        auth('partner')->logout();
        $this->be(new ScopedCacheActor(['id' => 10]), 'agent');
        self::assertSame(['First', 'Second'], $repository->get()->pluck('name')->all());
    }

    public function test_global_scope_and_guard_rules_apply_together(): void
    {
        DB::table('scoped_cache_records')->insert([
            ['id' => 3, 'user_id' => 101, 'name' => 'Hidden', 'is_global' => true],
        ]);
        $repository = new ScopedCacheRepository(new GlobalAndGuardCacheRecord, app(CacheManager::class));

        $this->be(new ScopedCacheActor(['id' => 101]), 'partner');
        self::assertSame(['First'], $repository->get()->pluck('name')->all());

        $this->be(new ScopedCacheActor(['id' => 102]), 'partner');
        self::assertSame(['Second'], $repository->get()->pluck('name')->all());
    }

    public function test_explicit_global_visibility_includes_global_records_and_refreshes_after_changes(): void
    {
        DB::table('scoped_cache_records')->insert([
            ['id' => 3, 'user_id' => 999, 'tenant_id' => 10, 'name' => 'Shared', 'is_global' => true],
        ]);
        $this->be(new ScopedCacheActor(['id' => 101, 'tenant_id' => 10]));
        $repo = new ScopedCacheRepository(new GlobalVisibleCacheRecord, app(CacheManager::class));
        self::assertSame(2, $repo->count());
        GlobalVisibleCacheRecord::query()->findOrFail(3)->update(['is_global' => false]);
        self::assertSame(1, $repo->count());
    }
    public function test_shorthand_or_visibility_invalidates_previous_and_new_assignees(): void
    {
        DB::table('scoped_cache_records')->insert([
            ['id' => 3, 'user_id' => 999, 'assigned_to' => 101, 'assigned_by' => 102, 'name' => 'Delegated'],
        ]);
        $repository = new ScopedCacheRepository(new ShorthandAssignmentRecord, app(CacheManager::class));

        $this->asUser(101);
        self::assertSame(2, $repository->count());
        $this->asUser(102);
        self::assertSame(2, $repository->count());
        $this->asUser(103);
        self::assertSame(0, $repository->count());

        ShorthandAssignmentRecord::query()->findOrFail(3)->update(['assigned_to' => 103]);

        self::assertSame(1, $repository->count());
        $this->asUser(101);
        self::assertSame(1, $repository->count());
        $this->asUser(103);
        self::assertSame(1, $repository->count());
    }

    public function test_tenant_and_owner_visibility_preserves_other_partitions(): void
    {
        DB::table('scoped_cache_records')->insert([
            ['id' => 3, 'tenant_id' => 20, 'user_id' => 101, 'assigned_to' => null, 'name' => 'Separate tenant'],
            ['id' => 4, 'tenant_id' => 10, 'user_id' => 999, 'assigned_to' => 101, 'name' => 'Delegated'],
        ]);
        $repository = new ScopedCacheRepository(new TenantAssignmentRecord, app(CacheManager::class));

        $this->be(new ScopedCacheActor(['id' => 101, 'tenant_id' => 10]));
        self::assertSame(2, $repository->count());

        $this->be(new ScopedCacheActor(['id' => 101, 'tenant_id' => 20]));
        self::assertSame(1, $repository->count());

        $this->be(new ScopedCacheActor(['id' => 102, 'tenant_id' => 10]));
        self::assertSame(1, $repository->count());

        TenantAssignmentRecord::query()->findOrFail(4)->update(['assigned_to' => 102]);

        self::assertSame(2, $repository->count());
        $this->be(new ScopedCacheActor(['id' => 101, 'tenant_id' => 10]));
        self::assertSame(1, $repository->count());
        $this->be(new ScopedCacheActor(['id' => 101, 'tenant_id' => 20]));
        self::assertSame(1, $repository->count());

        TenantAssignmentRecord::query()->findOrFail(4)->update(['tenant_id' => 20]);
        $this->be(new ScopedCacheActor(['id' => 102, 'tenant_id' => 10]));
        self::assertSame(1, $repository->count());
        $this->be(new ScopedCacheActor(['id' => 102, 'tenant_id' => 20]));
        self::assertSame(1, $repository->count());
    }

    public function test_global_scope_partition_marker_is_cache_only(): void
    {
        DB::table('scoped_cache_records')->insert([
            ['id' => 3, 'tenant_id' => 20, 'user_id' => 101, 'name' => 'Tenant twenty'],
        ]);
        $repository = new ScopedCacheRepository(new GlobalTenantRecord, app(CacheManager::class));
        $this->be(new ScopedCacheActor(['id' => 101, 'tenant_id' => 10]));
        self::assertSame(2, $repository->count());
        $this->be(new ScopedCacheActor(['id' => 101, 'tenant_id' => 20]));
        self::assertSame(1, $repository->count());

        GlobalTenantRecord::withoutGlobalScopes()->findOrFail(1)->update(['tenant_id' => 20]);

        self::assertSame(2, $repository->count());
        $this->be(new ScopedCacheActor(['id' => 101, 'tenant_id' => 10]));
        self::assertSame(1, $repository->count());
    }

    public function test_query_builder_filter_changes_cache_identity_and_invalidation(): void
    {
        $this->asUser(101);
        $repository = new ScopedCacheRepository(new ScopedCacheRecord, app(CacheManager::class));
        $first = $repository->filterQuery(
            static fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('name', 'First')
        );
        $second = $repository->filterQuery(
            static fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('name', 'Second')
        );

        self::assertSame(1, $first->count());
        self::assertSame(0, $second->count());
        ScopedCacheRecord::query()->findOrFail(1)->update(['name' => 'Second']);
        self::assertSame(0, $first->count());
        self::assertSame(1, $second->count());
    }

    public function test_new_record_invalidates_only_its_declared_visibility_groups(): void
    {
        $repository = new ScopedCacheRepository(new ShorthandAssignmentRecord, app(CacheManager::class));
        $this->asUser(101);
        self::assertSame(1, $repository->count());
        $this->asUser(102);
        self::assertSame(1, $repository->count());

        ShorthandAssignmentRecord::query()->create([
            'user_id' => 999,
            'assigned_to' => 101,
            'name' => 'New assignment',
        ]);

        $this->asUser(101);
        self::assertSame(2, $repository->count());
        $this->asUser(102);
        self::assertSame(1, $repository->count());
    }

    public function test_query_filter_composes_with_named_repository_scope(): void
    {
        $this->asUser(101);
        $repository = new ScopedFilteredCacheRepository(new LocalScopeCacheRecord, app(CacheManager::class));
        $combined = $repository->scope('namedFirst')
            ->filterQuery(static fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('tenant_id', 10));
        self::assertSame(['First'], $combined->get()->pluck('name')->all());

        $other = $repository->filterQuery(
            static fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('name', 'Unmatched')
        );
        self::assertSame([], $other->get()->pluck('name')->all());
    }

    public function test_custom_visibility_resolver_can_supply_precise_cache_tags(): void
    {
        $repository = new ScopedCacheRepository(new TaggedResolverRecord, app(CacheManager::class));
        request()->attributes->set('user_id', 101);
        request()->attributes->set('user_type', 'user');
        self::assertSame(['First'], $repository->get()->pluck('name')->all());

        request()->attributes->set('user_id', 102);
        self::assertSame(['Second'], $repository->get()->pluck('name')->all());

        TaggedResolverRecord::query()->findOrFail(1)->update(['user_id' => 102]);
        self::assertSame(['First', 'Second'], $repository->get()->pluck('name')->all());

        request()->attributes->set('user_id', 101);
        self::assertSame([], $repository->get()->pluck('name')->all());
    }

    public function test_global_scope_marker_without_a_global_scope_is_rejected(): void
    {
        $this->be(new ScopedCacheActor(['id' => 101, 'tenant_id' => 10]));
        $repository = new ScopedCacheRepository(new MissingGlobalScopeRecord, app(CacheManager::class));
        $this->expectException(\LogicException::class);
        $repository->get();
    }
}

final class ScopedCacheRepository extends BaseRepository {}

final class ScopedFilteredCacheRepository extends BaseRepository
{
    protected array $allowedScopes = ['namedFirst'];
}


final class ScopedCacheRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return ['scope' => 'user', 'scope_column' => 'user_id'];
    }
}

final class ScopedCacheActor extends Model implements \Illuminate\Contracts\Auth\Authenticatable
{
    use \Illuminate\Auth\Authenticatable;

    protected $guarded = [];
}

final class CompositeCacheRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return ['scopes' => [
            'tenant_id' => 'auth.tenant_id',
            'user_id' => 'auth.id',
        ]];
    }
}

final class GlobalVisibleCacheRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return [
            'scopes' => ['tenant_id' => 'auth.tenant_id', 'user_id' => 'auth.id'],
            'visibility_resolver' => static function ($query): void {
                $query->orWhere('is_global', true);
            },
        ];
    }
}

final class RequestActorCacheRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return ['scopes' => ['user_id' => 'actor.id']];
    }
}

final class ClassResolverCacheRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return ['visibility_resolver' => ScopedTestVisibilityResolver::class];
    }
}

final class ScopedTestVisibilityResolver
{
    public function apply(
        \Illuminate\Database\Eloquent\Builder $query,
        Model $model,
        ?string $actorType = null,
        int|string|null $actorId = null,
    ): \Illuminate\Database\Eloquent\Builder {
        return $query->where($model->qualifyColumn('user_id'), $actorId);
    }
}

final class GlobalScopeOnlyRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope('owner', static function (\Illuminate\Database\Eloquent\Builder $query): void {
            $query->where('user_id', request()->attributes->get('user_id'));
        });
    }
}

final class PartnerGuardCacheRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return ['scope' => ['column' => 'user_id', 'guard' => 'partner']];
    }
}

final class AssignedGuardCacheRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return [
            'scopes' => [
                ['column' => 'user_id', 'guard' => 'partner'],
                ['column' => 'assigned_to', 'guard' => 'partner'],
                ['column' => 'assigned_by', 'guard' => 'partner'],
            ],
            'scope_operator' => 'or',
        ];
    }
}

final class AssignedAndGuardCacheRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return ['scopes' => [
            ['column' => 'user_id', 'guard' => 'partner'],
            ['column' => 'assigned_to', 'guard' => 'partner'],
        ]];
    }
}

final class MultipleGuardsCacheRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return ['scopes' => [
            ['column' => 'user_id', 'guard' => 'partner'],
            ['column' => 'tenant_id', 'guard' => 'agent', 'attribute' => 'tenant_id'],
        ]];
    }
}

final class GlobalAndGuardCacheRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope('published', static function (\Illuminate\Database\Eloquent\Builder $query): void {
            $query->where('is_global', false);
        });
    }

    protected function cacheOptions(): array
    {
        return ['scope' => ['column' => 'user_id', 'guard' => 'partner']];
    }
}

final class AlternativeGuardsCacheRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return [
            'scopes' => [
                ['column' => 'user_id', 'guard' => 'partner'],
                ['column' => 'tenant_id', 'guard' => 'agent'],
            ],
            'scope_operator' => 'or',
        ];
    }
}


final class ShorthandAssignmentRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return ['scopes' => ['user_id', 'assigned_to', 'assigned_by'], 'scope_operator' => 'or'];
    }
}

final class TenantAssignmentRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return [
            'scope' => ['tenant_id' => 'auth.tenant_id'],
            'visibility' => ['any' => ['user_id', 'assigned_to', 'assigned_by']],
        ];
    }
}

final class GlobalTenantRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', static function (\Illuminate\Database\Eloquent\Builder $query): void {
            $query->where('tenant_id', auth()->user()?->tenant_id ?? -1);
        });
    }

    protected function cacheOptions(): array
    {
        return ['scope' => 'tenant_id'];
    }
}

final class LocalScopeCacheRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return ['scope' => 'user'];
    }

    public function scopeNamedFirst(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where('name', 'First');
    }
}

final class MissingGlobalScopeRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return ['scope' => 'tenant_id'];
    }
}


final class TaggedResolverRecord extends Model implements CacheableModel
{
    use InteractsWithCache;

    protected $table = 'scoped_cache_records';
    protected $guarded = [];

    protected function cacheOptions(): array
    {
        return ['visibility_resolver' => TaggedScopeVisibilityResolver::class];
    }
}

final class TaggedScopeVisibilityResolver
{
    public function apply(
        \Illuminate\Database\Eloquent\Builder $query,
        Model $model,
        ?string $actorType = null,
        int|string|null $actorId = null,
    ): \Illuminate\Database\Eloquent\Builder {
        return $query->where($model->qualifyColumn('user_id'), $actorId);
    }

    public function cacheReadTags(Model $model, ?array $actor): array
    {
        return [$model::cacheTag().':viewer:'.$actor['id']];
    }

    public function cacheInvalidationTags(Model $model): array
    {
        $prefix = $model::cacheTag().':viewer:';

        return [
            $prefix.$model->getRawOriginal('user_id'),
            $prefix.$model->getAttribute('user_id'),
        ];
    }
}
