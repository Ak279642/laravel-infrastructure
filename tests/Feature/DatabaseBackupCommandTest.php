<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Tests\TestCase;

final class DatabaseBackupCommandTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    public function test_backup_command_is_registered(): void
    {
        self::assertArrayHasKey(
            'infrastructure:database-backup',
            $this->app['artisan']->all(),
        );
    }

    public function test_backup_command_fails_cleanly_for_unsupported_driver(): void
    {
        $this->artisan('infrastructure:database-backup')
            ->expectsOutputToContain(
                'Database backup driver [sqlite] is not supported.',
            )
            ->assertFailed();
    }

    public function test_backup_command_rejects_missing_connection(): void
    {
        $this->artisan(
            'infrastructure:database-backup',
            ['--connection' => 'missing'],
        )
            ->expectsOutputToContain(
                'Database connection [missing] is not configured.',
            )
            ->assertFailed();
    }
}
