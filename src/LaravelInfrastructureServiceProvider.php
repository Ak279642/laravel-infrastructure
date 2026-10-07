<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure;

use Ak279642\LaravelInfrastructure\Cache\CacheInvalidator;
use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Contracts\TransactionManager;
use Ak279642\LaravelInfrastructure\Observers\CacheObserver;
use Ak279642\LaravelInfrastructure\Transactions\LaravelTransactionManager;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\ServiceProvider;

final class LaravelInfrastructureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-infrastructure.php', 'laravel-infrastructure');

        $this->app->singleton(CacheManager::class, function ($app): CacheManager {
            return new CacheManager(
                $app->make(CacheFactory::class),
                config('laravel-infrastructure.cache.store'),
            );
        });

        $this->app->singleton(CacheInvalidator::class, fn ($app): CacheInvalidator => new CacheInvalidator(
            $app->make(CacheManager::class),
        ));

        $this->app->singleton(CacheObserver::class, fn ($app): CacheObserver => new CacheObserver(
            $app->make(CacheInvalidator::class),
        ));

        $this->app->bind(TransactionManager::class, LaravelTransactionManager::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/laravel-infrastructure.php' => config_path('laravel-infrastructure.php'),
        ], 'laravel-infrastructure-config');
    }
}
