<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Cache\Events\CacheBypassed;
use Ak279642\LaravelInfrastructure\Cache\Events\CacheHit;
use Ak279642\LaravelInfrastructure\Cache\Events\CacheMiss;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use RuntimeException;

final class CacheManagerBehaviorTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set(
            'laravel-infrastructure.cache.events.enabled',
            true,
        );
        $app['config']->set(
            'laravel-infrastructure.cache.lock.enabled',
            true,
        );
        $app['config']->set(
            'laravel-infrastructure.cache.lock.wait_seconds',
            0,
        );
    }

    public function test_cache_hit_and_miss_events_do_not_expose_values(): void
    {
        $events = [];

        $this->app['events']->listen(
            CacheMiss::class,
            function (CacheMiss $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $this->app['events']->listen(
            CacheHit::class,
            function (CacheHit $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $manager = $this->app->make(CacheManager::class);
        $calls = 0;

        self::assertSame(
            'secret-result',
            $manager->remember(
                'observable',
                60,
                function () use (&$calls): string {
                    $calls++;

                    return 'secret-result';
                },
            ),
        );

        self::assertSame(
            'secret-result',
            $manager->remember(
                'observable',
                60,
                function () use (&$calls): string {
                    $calls++;

                    return 'different';
                },
            ),
        );

        self::assertSame(1, $calls);
        self::assertCount(2, $events);
        self::assertInstanceOf(CacheMiss::class, $events[0]);
        self::assertInstanceOf(CacheHit::class, $events[1]);

        foreach ($events as $event) {
            self::assertFalse(
                property_exists($event, 'value'),
            );
        }
    }

    public function test_exception_during_cache_population_releases_the_lock(): void
    {
        $manager = $this->app->make(CacheManager::class);

        try {
            $manager->remember(
                'throws',
                60,
                static function (): never {
                    throw new RuntimeException('boom');
                },
            );

            self::fail('Expected cache callback exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        $normalized = 'laravel-infrastructure:throws';
        $lock = $manager->lock(
            'laravel-infrastructure:remember:'.hash(
                'sha256',
                $normalized,
            ),
            10,
        );

        self::assertNotNull($lock);
        self::assertTrue($lock->get());
        self::assertTrue($lock->release());
    }

    public function test_failed_lock_bypasses_cache_without_leaving_broken_state(): void
    {
        $manager = $this->app->make(CacheManager::class);
        $bypasses = [];

        $this->app['events']->listen(
            CacheBypassed::class,
            function (CacheBypassed $event) use (&$bypasses): void {
                $bypasses[] = $event;
            },
        );

        $normalized = 'laravel-infrastructure:held';
        $lock = $manager->lock(
            'laravel-infrastructure:remember:'.hash(
                'sha256',
                $normalized,
            ),
            10,
        );

        self::assertNotNull($lock);
        self::assertTrue($lock->get());

        try {
            self::assertSame(
                'fresh',
                $manager->remember(
                    'held',
                    60,
                    static fn (): string => 'fresh',
                ),
            );
        } finally {
            $lock->release();
        }

        self::assertNotEmpty($bypasses);
        self::assertSame(
            'lock_unavailable',
            end($bypasses)->reason,
        );

        self::assertSame(
            'cached-after-release',
            $manager->remember(
                'held',
                60,
                static fn (): string => 'cached-after-release',
            ),
        );
    }
}
