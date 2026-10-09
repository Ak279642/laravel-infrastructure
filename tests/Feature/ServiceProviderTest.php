<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheInvalidator;
use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Contracts\TransactionManager;
use Ak279642\LaravelInfrastructure\Database\Schema\SchemaRegistry;
use Ak279642\LaravelInfrastructure\Exceptions\ApiExceptionRenderer;
use Ak279642\LaravelInfrastructure\Files\FileStorage;
use Ak279642\LaravelInfrastructure\Observers\CacheObserver;
use Ak279642\LaravelInfrastructure\Slugs\SlugGenerator;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Ak279642\LaravelInfrastructure\Validation\ValidationContext;

final class ServiceProviderTest extends TestCase
{
    public function test_package_uses_laravel_default_cache_store(): void
    {
        self::assertArrayNotHasKey('cache', config('laravel-infrastructure'));
        self::assertSame(
            $this->app['cache']->store()->getStore(),
            $this->app->make(CacheManager::class)->getStore(),
        );
    }

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

    public function test_singletons_are_stable_and_request_context_is_scoped(): void
    {
        foreach ([
            CacheManager::class,
            CacheInvalidator::class,
            CacheObserver::class,
            SchemaRegistry::class,
            FileStorage::class,
            SlugGenerator::class,
            ApiExceptionRenderer::class,
        ] as $service) {
            self::assertSame(
                $this->app->make($service),
                $this->app->make($service),
                "Expected [{$service}] to be a singleton.",
            );
        }

        $firstContext = $this->app->make(ValidationContext::class);

        self::assertSame(
            $firstContext,
            $this->app->make(ValidationContext::class),
        );

        $this->app->forgetScopedInstances();

        self::assertNotSame(
            $firstContext,
            $this->app->make(ValidationContext::class),
        );
    }

    public function test_transaction_manager_remains_transient_and_stateless(): void
    {
        self::assertNotSame(
            $this->app->make(TransactionManager::class),
            $this->app->make(TransactionManager::class),
        );
    }
}
