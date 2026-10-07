<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Contracts\TransactionManager;
use Ak279642\LaravelInfrastructure\Files\FileStorage;
use Ak279642\LaravelInfrastructure\Slugs\SlugGenerator;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Ak279642\LaravelInfrastructure\Validation\ValidationContext;

final class ServiceProviderTest extends TestCase
{
    public function test_core_services_are_registered(): void
    {
        self::assertInstanceOf(
            CacheManager::class,
            $this->app->make(CacheManager::class),
        );

        self::assertInstanceOf(
            TransactionManager::class,
            $this->app->make(TransactionManager::class),
        );

        self::assertInstanceOf(
            FileStorage::class,
            $this->app->make(FileStorage::class),
        );

        self::assertInstanceOf(
            SlugGenerator::class,
            $this->app->make(SlugGenerator::class),
        );

        self::assertInstanceOf(
            ValidationContext::class,
            $this->app->make(ValidationContext::class),
        );
    }
}
