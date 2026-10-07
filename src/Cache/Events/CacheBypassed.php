<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache\Events;

final readonly class CacheBypassed
{
    /** @param list<string> $tags */
    public function __construct(
        public string $key,
        public string $reason,
        public array $tags = [],
    ) {}
}
