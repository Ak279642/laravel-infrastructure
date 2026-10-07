<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache\Events;

final readonly class CacheMiss
{
    public function __construct(
        public string $key,
        public array $tags = [],
    ) {}
}
