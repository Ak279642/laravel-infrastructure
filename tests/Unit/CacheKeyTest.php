<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Unit;

use Ak279642\LaravelInfrastructure\Cache\CacheKey;
use PHPUnit\Framework\TestCase;

final class CacheKeyTest extends TestCase
{
    public function test_associative_parameter_order_is_deterministic(): void
    {
        self::assertSame(
            CacheKey::make('users', ['status' => 'active', 'page' => 2]),
            CacheKey::make('users', ['page' => 2, 'status' => 'active']),
        );
    }

    public function test_unordered_arrays_ignore_item_order(): void
    {
        self::assertSame(
            CacheKey::make('users', ['ids' => CacheKey::unordered([3, 1, 2])]),
            CacheKey::make('users', ['ids' => CacheKey::unordered([1, 2, 3])]),
        );
    }

    public function test_normal_arrays_preserve_item_order(): void
    {
        self::assertNotSame(
            CacheKey::make('users', ['ids' => [3, 1, 2]]),
            CacheKey::make('users', ['ids' => [1, 2, 3]]),
        );
    }
}
