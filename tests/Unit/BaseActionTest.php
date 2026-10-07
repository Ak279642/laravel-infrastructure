<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Unit;

use Ak279642\LaravelInfrastructure\Actions\BaseAction;
use Ak279642\LaravelInfrastructure\Contracts\TransactionManager;
use PHPUnit\Framework\TestCase;

final class BaseActionTest extends TestCase
{
    public function test_action_passes_configured_retry_attempts_to_transaction_manager(): void
    {
        $transactions = new RecordingTransactionManager();
        $action = new RetryingTestAction($transactions);

        self::assertSame('done', $action->execute());
        self::assertSame(4, $transactions->attempts);
        self::assertSame(1, $transactions->runs);
    }

    public function test_explicit_attempt_override_wins_for_one_transaction(): void
    {
        $transactions = new RecordingTransactionManager();
        $action = new RetryingTestAction($transactions);

        self::assertSame('done', $action->executeWithAttempts(2));
        self::assertSame(2, $transactions->attempts);
    }
}

final class RetryingTestAction extends BaseAction
{
    public function execute(): string
    {
        return $this->transactional(
            static fn (): string => 'done',
        );
    }

    public function executeWithAttempts(int $attempts): string
    {
        return $this->transactional(
            static fn (): string => 'done',
            $attempts,
        );
    }

    protected function transactionAttempts(): int
    {
        return 4;
    }
}

final class RecordingTransactionManager implements TransactionManager
{
    public int $attempts = 0;
    public int $runs = 0;

    public function run(callable $callback, int $attempts = 1): mixed
    {
        $this->attempts = $attempts;
        $this->runs++;

        return $callback();
    }
}
