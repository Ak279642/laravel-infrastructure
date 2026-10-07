<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache\Events;

final readonly class CacheInvalidated
{
    public function __construct(
        public array $tags,
        public bool $successful,
    ) {}
}
