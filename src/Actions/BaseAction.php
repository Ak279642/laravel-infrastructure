<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Actions;

use Ak279642\LaravelInfrastructure\Contracts\TransactionManager;

abstract class BaseAction
{
    public function __construct(
        protected readonly TransactionManager $transactions,
    ) {}

    /**
     * Execute the state-changing portion of an application use case.
     *
     * Actions own transaction boundaries. Services and repositories should not
     * start their own transactions for the same use case.
     */
    protected function transactional(
        callable $callback,
        ?int $attempts = null,
    ): mixed {
        return $this->transactions->run(
            $callback,
            max(1, $attempts ?? $this->transactionAttempts()),
        );
    }

    /**
     * Override per action when a use case needs a different deadlock retry count.
     */
    protected function transactionAttempts(): int
    {
        return max(
            1,
            (int) config('laravel-infrastructure.transactions.attempts', 1),
        );
    }
}
