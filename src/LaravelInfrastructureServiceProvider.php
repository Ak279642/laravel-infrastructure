<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure;

use Ak279642\LaravelInfrastructure\Cache\CacheInvalidator;
use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Console\Commands\StorageAuditCommand;
use Ak279642\LaravelInfrastructure\Contracts\TransactionManager;
use Ak279642\LaravelInfrastructure\Database\Schema\SchemaRegistry;
use Ak279642\LaravelInfrastructure\Exceptions\ApiExceptionRenderer;
use Ak279642\LaravelInfrastructure\Files\FileStorage;
use Ak279642\LaravelInfrastructure\Observers\CacheObserver;
use Ak279642\LaravelInfrastructure\Slugs\SlugGenerator;
use Ak279642\LaravelInfrastructure\Transactions\LaravelTransactionManager;
use Ak279642\LaravelInfrastructure\Validation\ValidationContext;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Throwable;

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

        $this->app->singleton(SchemaRegistry::class);
        $this->app->singleton(FileStorage::class, fn ($app): FileStorage => new FileStorage(
            $app->make(FilesystemFactory::class),
        ));
        $this->app->singleton(SlugGenerator::class, fn ($app): SlugGenerator => new SlugGenerator(
            $app->make(CacheManager::class),
            $app->make(SchemaRegistry::class),
        ));

        $this->app->scoped(ValidationContext::class, fn (): ValidationContext => new ValidationContext);

        $this->app->bind(TransactionManager::class, LaravelTransactionManager::class);
        $this->app->singleton(ApiExceptionRenderer::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/laravel-infrastructure.php' => config_path('laravel-infrastructure.php'),
        ], 'laravel-infrastructure-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                StorageAuditCommand::class,
            ]);
        }

        if (! (bool) config(
            'laravel-infrastructure.responses.exception_renderer_enabled',
            true,
        )) {
            return;
        }

        $handler = $this->app->make(ExceptionHandlerContract::class);

        if (! method_exists($handler, 'renderable')) {
            return;
        }

        $renderer = $this->app->make(ApiExceptionRenderer::class);

        if (method_exists($handler, 'reportable')) {
            $handler->reportable(
                function (Throwable $exception) use ($renderer): bool {
                    if (! app()->bound('request')) {
                        return true;
                    }

                    $request = request();

                    if (
                        ! $request instanceof Request
                        || ! $renderer->shouldRender($request)
                    ) {
                        return true;
                    }

                    $renderer->report($exception, $request);

                    // The package has either logged the exception through the
                    // redacted structured logger or intentionally suppressed a
                    // low-signal client error.
                    return false;
                },
            );
        }

        $handler->renderable(
            function (Throwable $exception, Request $request) use ($renderer) {
                if (! $renderer->shouldRender($request)) {
                    return null;
                }

                return $renderer->render($exception, $request);
            },
        );
    }
}
