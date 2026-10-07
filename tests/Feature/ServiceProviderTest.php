<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Contracts\TransactionManager;
use Ak279642\LaravelInfrastructure\LaravelInfrastructureServiceProvider;
use Ak279642\LaravelInfrastructure\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_core_services_are_registered(): void
    {
        self::assertInstanceOf(CacheManager::class, $this->app->make(CacheManager::class));
        self::assertInstanceOf(TransactionManager::class, $this->app->make(TransactionManager::class));
    }
}
