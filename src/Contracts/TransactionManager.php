<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Contracts;

interface TransactionManager
{
    public function run(callable $callback, int $attempts = 1): mixed;
}
