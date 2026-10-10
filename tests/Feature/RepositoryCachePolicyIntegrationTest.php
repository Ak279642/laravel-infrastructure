<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheInvalidator;
use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\CacheTag;
use Ak279642\LaravelInfrastructure\Cache\RequestReadCache;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Models\BaseModel;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RepositoryCachePolicyIntegrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('policy_cache_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('name');
            $table->timestamps();
        });
        DB::table('policy_cache_records')->insert([
            'owner_id' => 1, 'name' => 'Before',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function repository(): PolicyCacheRepository
    {
        return new PolicyCacheRepository(new PolicyCacheRecord, app(CacheManager::class));
    }

    public function test_request_memo_isolated_by_policy_identity_and_cleared_on_model_write(): void
    {
        $repo = $this->repository();
        request()->attributes->set('policy_owner', 1);
        self::assertSame('Before', $repo->firstForActor()?->name);

        // One request-memoized result is used until a model write occurs.
        DB::table('policy_cache_records')->update(['name' => 'Raw']);
        self::assertSame('Before', $repo->firstForActor()?->name);

        PolicyCacheRecord::query()->firstOrFail()->update(['name' => 'After']);
        self::assertSame('After', $repo->firstForActor()?->name);

        request()->attributes->set('policy_owner', 2);
        self::assertNull($repo->firstForActor());
        request()->attributes->set('policy_owner', 1);
        self::assertSame('After', $repo->firstForActor()?->name);
    }

    public function test_bulk_sql_invalidation_is_delayed_until_commit_and_discarded_on_rollback(): void
    {
        $repo = $this->repository();
        self::assertSame('Before', $repo->get()->first()->name);

        DB::beginTransaction();
        DB::table('policy_cache_records')->update(['name' => 'Rolled back']);
        $repo->notifyBulkWrite();
        DB::rollBack();

        // Rollback must leave the committed cache intact.
        self::assertSame('Before', $repo->get()->first()->name);

        DB::transaction(function () use ($repo): void {
            DB::table('policy_cache_records')->update(['name' => 'Committed']);
            $repo->notifyBulkWrite();
        });

        self::assertSame('Committed', $repo->get()->first()->name);
    }

    public function test_dependency_aware_cache_refreshes_on_explicit_invalidation(): void
    {
        $cache = app(CacheManager::class);
        $invalidator = app(CacheInvalidator::class);
        $tags = [CacheTag::fromModel(PolicyCacheRecord::class)];
        $read = fn (): string => (string) DB::table('policy_cache_records')->value('name');
        self::assertSame('Before', $cache->rememberWithDependencies(
            'policy.aggregate', 60, $read, $tags, DB::connection(),
        ));

        DB::table('policy_cache_records')->update(['name' => 'New']);
        self::assertSame('Before', $cache->rememberWithDependencies(
            'policy.aggregate', 60, $read, $tags, DB::connection(),
        ));
        $invalidator->invalidateAfterCommit($tags, DB::connection());
        self::assertSame('New', $cache->rememberWithDependencies(
            'policy.aggregate', 60, $read, $tags, DB::connection(),
        ));
    }

    public function test_request_cache_supports_null_and_explicit_clear(): void
    {
        $calls = 0;
        $fn = function () use (&$calls): ?string {
            $calls++;
            return null;
        };
        self::assertNull(RequestReadCache::remember('nullable', $fn));
        self::assertNull(RequestReadCache::remember('nullable', $fn));
        self::assertSame(1, $calls);
        RequestReadCache::clear();
        self::assertNull(RequestReadCache::remember('nullable', $fn));
        self::assertSame(2, $calls);
    }
}

final class PolicyCacheRecord extends BaseModel
{
    protected $table = 'policy_cache_records';

    protected $guarded = [];
}

final class PolicyCacheRepository extends BaseRepository
{
    protected function cacheReadPolicy(string $operation, array $params): ?array
    {
        if ($operation !== 'policy_read') {
            return null;
        }
        return [
            'mode' => 'request',
            'params' => ['owner' => request()->attributes->get('policy_owner')],
        ];
    }

    public function firstForActor(): ?PolicyCacheRecord
    {
        $owner = (int) request()->attributes->get('policy_owner');
        return $this->cacheRemember(
            'policy_read',
            fn () => $this->query()->where('owner_id', $owner)->first(),
            ['owner' => $owner],
        );
    }

    public function notifyBulkWrite(): void
    {
        $this->invalidateAfterBulkWrite();
    }
}
