<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache;

/**
 * Marker class for unordered arrays.
 *
 * When passed to CacheKey::make(), values inside this wrapper will be
 * deterministically sorted to ensure consistent cache keys regardless
 * of the original order.
 *
 * @internal Use CacheKey::unordered() to create instances.
 */
final readonly class UnorderedArray
{
    /** @param array<array-key, mixed> $values */
    public function __construct(
        public array $values
    ) {}
}
