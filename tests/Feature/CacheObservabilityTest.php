<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\Events\CacheHit;
use Ak279642\LaravelInfrastructure\Cache\Events\CacheMiss;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Support\Facades\Event;

final class CacheObservabilityTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    public function test_remember_dispatches_miss_then_hit_without_exposing_value(): void
    {
        Event::fake([CacheHit::class, CacheMiss::class]);

        $cache = $this->app->make(CacheManager::class);
        $calls = 0;

        self::assertSame('secret-value', $cache->remember('observability', 60, function () use (&$calls): string {
            $calls++;

            return 'secret-value';
        }));

        self::assertSame('secret-value', $cache->remember('observability', 60, function () use (&$calls): string {
            $calls++;

            return 'different-value';
        }));

        self::assertSame(1, $calls);

        Event::assertDispatched(CacheMiss::class, fn (CacheMiss $event): bool => $event->key === '_observability');
        Event::assertDispatched(CacheHit::class, fn (CacheHit $event): bool => $event->key === '_observability');
    }
}
