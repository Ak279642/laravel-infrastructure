<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Transactions;

use Ak279642\LaravelInfrastructure\Contracts\TransactionManager;
use Illuminate\Database\DatabaseManager;

final class LaravelTransactionManager implements TransactionManager
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function run(callable $callback, int $attempts = 1): mixed
    {
        return $this->database->transaction($callback, max(1, $attempts));
    }
}
