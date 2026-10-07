<?php

declare(strict_types=1);

namespace App\Repositories;

use Ak279642\LaravelInfrastructure\Cache\CacheKey;
use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\CacheTtl;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection;

/**
 * Example application repository.
 *
 * Copy this pattern into your application; this file is documentation and is
 * intentionally not part of the package autoload namespace.
 */
final class CustomerRepository extends BaseRepository
{
    protected array $searchable = [
        'name',
        'email',
        'country.name',
    ];

    protected array $allowedFilters = [
        'status',
        'country_id',
        'created_at',
    ];

    protected array $allowedSorts = [
        'name',
        'email',
        'created_at',
    ];

    protected array $allowedRelations = [
        'country',
        'orders',
    ];

    public function __construct(
        Customer $model,
        CacheManager $cache,
    ) {
        parent::__construct($model, $cache);
    }

    /**
     * Custom cached read.
     *
     * Same country/status parameters reuse the same deterministic repository
     * cache entry. Model-tag invalidation keeps this cache coherent.
     */
    public function activeForCountry(int $countryId): Collection
    {
        return $this
            ->cacheTtl(CacheTtl::MINUTES_10)
            ->cacheRemember(
                operation: 'activeForCountry',
                callback: fn () => $this->query()
                    ->where('country_id', $countryId)
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->get(),
                params: [
                    'country_id' => $countryId,
                    'status' => 'active',
                ],
            );
    }

    /**
     * Custom cached read with eager-loaded dependencies.
     *
     * Pass eager-loaded relation names under the "with" cache parameter so
     * cache-aware related models can contribute dependency tags.
     */
    public function findWithDashboardData(int $customerId): Customer
    {
        /** @var Customer */
        return $this->cacheRemember(
            operation: 'findWithDashboardData',
            callback: fn () => $this->query()
                ->with(['country', 'orders'])
                ->withCount('orders')
                ->findOrFail($customerId),
            params: [
                'id' => $customerId,
                'with' => ['country', 'orders'],
            ],
        );
    }

    /**
     * Arrays that are semantically unordered should use CacheKey::unordered().
     *
     * [1, 2, 3] and [3, 1, 2] then share the same cache key.
     */
    public function byIds(array $ids): Collection
    {
        return $this->cacheRemember(
            operation: 'byIds',
            callback: fn () => $this->query()
                ->whereIn('id', $ids)
                ->get(),
            params: [
                'ids' => CacheKey::unordered($ids),
            ],
        );
    }

    /**
     * Normal repository write methods already invalidate repository cache.
     */
    public function activate(int $customerId): Customer
    {
        /** @var Customer */
        return $this->update(
            id: $customerId,
            data: ['status' => 'active'],
        );
    }

    /**
     * For custom direct/bulk writes, explicitly clear repository cache.
     */
    public function markCountryCustomersInactive(int $countryId): int
    {
        $affected = $this->query()
            ->where('country_id', $countryId)
            ->update([
                'status' => 'inactive',
            ]);

        if ($affected > 0) {
            $this->clearCache();
        }

        return $affected;
    }
}
