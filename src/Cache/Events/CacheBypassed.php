<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache\Events;

final readonly class CacheBypassed
{
    public function __construct(
        public string $repository,
        public string $operation,
    ) {}
}
