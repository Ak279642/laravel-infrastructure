<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure;

use Ak279642\LaravelInfrastructure\Cache\CacheInvalidator;
use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Console\Commands\DatabaseBackupCommand;
use Ak279642\LaravelInfrastructure\Console\Commands\StorageAuditCommand;
use Ak279642\LaravelInfrastructure\Contracts\TransactionManager;
use Ak279642\LaravelInfrastructure\Database\Schema\SchemaRegistry;
use Ak279642\LaravelInfrastructure\Exceptions\ApiExceptionRenderer;
use Ak279642\LaravelInfrastructure\Files\FileStorage;
use Ak279642\LaravelInfrastructure\Files\PendingFileUploads;
use Ak279642\LaravelInfrastructure\Http\Controllers\AssetsController;
use Ak279642\LaravelInfrastructure\Http\Middleware\RejectSensitivePaths;
use Ak279642\LaravelInfrastructure\Http\Middleware\SecurityHeaders;
use Ak279642\LaravelInfrastructure\Observers\CacheObserver;
use Ak279642\LaravelInfrastructure\Slugs\SlugGenerator;
use Ak279642\LaravelInfrastructure\Transactions\LaravelTransactionManager;
use Ak279642\LaravelInfrastructure\Validation\ValidationContext;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
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
        $this->app->scoped(PendingFileUploads::class, fn ($app): PendingFileUploads => new PendingFileUploads(
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

        $events = $this->app->make(EventDispatcher::class);

        $events->listen(
            TransactionRolledBack::class,
            function (TransactionRolledBack $event): void {
                app(PendingFileUploads::class)->rolledBack(
                    (string) $event->connection->getName(),
                    $event->connection->transactionLevel(),
                );
            },
        );

        $events->listen(
            TransactionCommitted::class,
            function (TransactionCommitted $event): void {
                if ($event->connection->transactionLevel() !== 0) {
                    return;
                }

                app(PendingFileUploads::class)->committed(
                    (string) $event->connection->getName(),
                );
            },
        );

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware(
            'infrastructure.security-headers',
            SecurityHeaders::class,
        );
        $router->aliasMiddleware(
            'infrastructure.reject-sensitive-paths',
            RejectSensitivePaths::class,
        );

        if ((bool) config(
            'laravel-infrastructure.assets.enabled',
            true,
        )) {
            $prefix = trim(
                (string) config(
                    'laravel-infrastructure.assets.prefix',
                    'infrastructure/assets',
                ),
                '/',
            );

            $route = $router
                ->get(
                    $prefix.'/{disk}/{path}',
                    AssetsController::class,
                )
                ->where('path', '.*')
                ->name('laravel-infrastructure.assets.show');

        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                DatabaseBackupCommand::class,
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

        $renderer = $this->app->make(ApiExceptionRenderer::class);

        $handler->reportable(
            function (Throwable $exception) use ($renderer): bool {
                if (! app()->bound('request')) {
                    return true;
                }

                $request = request();

                if (! $renderer->shouldRender($request)) {
                    return true;
                }

                $renderer->report($exception, $request);

                // The package has either logged the exception through the
                // redacted structured logger or intentionally suppressed a
                // low-signal client error.
                return false;
            },
        );

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
