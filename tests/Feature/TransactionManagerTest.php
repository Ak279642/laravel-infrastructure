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

        Schema::create('transaction_manager_records', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }

    public function test_transaction_returns_null_and_false_without_coercion(): void
    {
        $transactions = $this->app->make(TransactionManager::class);

        self::assertNull($transactions->run(static fn () => null));
        self::assertFalse($transactions->run(static fn () => false));
    }

    public function test_transaction_commits_successful_callback_and_returns_value(): void
    {
        $transactions = $this->app->make(TransactionManager::class);

        $result = $transactions->run(function (): string {
            DB::table('transaction_manager_records')->insert([
                'name' => 'committed',
            ]);

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(
            ['committed'],
            DB::table('transaction_manager_records')->pluck('name')->all(),
        );
    }

    public function test_transaction_rolls_back_and_propagates_exception(): void
    {
        $transactions = $this->app->make(TransactionManager::class);

        try {
            $transactions->run(function (): never {
                DB::table('transaction_manager_records')->insert([
                    'name' => 'rolled-back',
                ]);

                throw new RuntimeException('rollback');
            });

            self::fail('Expected transaction callback exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('rollback', $exception->getMessage());
        }

        self::assertSame(
            [],
            DB::table('transaction_manager_records')->pluck('name')->all(),
        );
    }

    public function test_nested_transactions_preserve_outer_transaction_semantics(): void
    {
        $transactions = $this->app->make(TransactionManager::class);

        $transactions->run(function () use ($transactions): void {
            DB::table('transaction_manager_records')->insert([
                'name' => 'outer',
            ]);

            $transactions->run(function (): void {
                DB::table('transaction_manager_records')->insert([
                    'name' => 'inner',
                ]);
            });
        });

        self::assertSame(
            ['outer', 'inner'],
            DB::table('transaction_manager_records')
                ->orderBy('id')
                ->pluck('name')
                ->all(),
        );
    }
}
