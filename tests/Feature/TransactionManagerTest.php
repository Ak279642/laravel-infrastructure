<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Contracts\TransactionManager;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class TransactionManagerTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('transaction_records', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }

    public function test_successful_action_boundary_commits(): void
    {
        $manager = $this->app->make(
            TransactionManager::class,
        );

        $manager->run(function (): void {
            DB::table('transaction_records')->insert([
                'name' => 'committed',
            ]);
        });

        self::assertSame(
            1,
            DB::table('transaction_records')->count(),
        );
    }

    public function test_service_exception_rolls_back(): void
    {
        $manager = $this->app->make(
            TransactionManager::class,
        );

        try {
            $manager->run(function (): void {
                DB::table('transaction_records')->insert([
                    'name' => 'rolled-back',
                ]);

                throw new RuntimeException('service failed');
            });

            self::fail('Expected service exception.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'service failed',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            0,
            DB::table('transaction_records')->count(),
        );
    }

    public function test_nested_operations_share_the_transaction_boundary(): void
    {
        $manager = $this->app->make(
            TransactionManager::class,
        );

        $manager->run(function () use ($manager): void {
            DB::table('transaction_records')->insert([
                'name' => 'outer',
            ]);

            $manager->run(function (): void {
                DB::table('transaction_records')->insert([
                    'name' => 'inner',
                ]);
            });
        });

        self::assertSame(
            2,
            DB::table('transaction_records')->count(),
        );
    }
}
