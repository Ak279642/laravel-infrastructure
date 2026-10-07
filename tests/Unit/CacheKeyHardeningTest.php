<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Unit;

use Ak279642\LaravelInfrastructure\Cache\CacheKey;
use Ak279642\LaravelInfrastructure\Exceptions\InvalidCacheConfigurationException;
use PHPUnit\Framework\TestCase;

final class CacheKeyHardeningTest extends TestCase
{
    public function test_equivalent_associative_parameters_are_deterministic(): void
    {
        self::assertSame(
            CacheKey::make('users', [
                'status' => 'active',
                'role_id' => 2,
            ]),
            CacheKey::make('users', [
                'role_id' => 2,
                'status' => 'active',
            ]),
        );
    }

    public function test_unordered_values_do_not_depend_on_input_order(): void
    {
        self::assertSame(
            CacheKey::make('users', [
                'ids' => CacheKey::unordered([1, 2, 3]),
            ]),
            CacheKey::make('users', [
                'ids' => CacheKey::unordered([3, 1, 2]),
            ]),
        );
    }

    public function test_unsupported_objects_are_rejected_instead_of_colliding_as_empty_json_objects(): void
    {
        $this->expectException(
            InvalidCacheConfigurationException::class,
        );

        CacheKey::make('users', [
            'callback' => new class {},
        ]);
    }

    public function test_empty_resource_is_rejected(): void
    {
        $this->expectException(
            InvalidCacheConfigurationException::class,
        );

        CacheKey::make('   ');
    }
}
