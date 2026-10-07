<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Context;

use InvalidArgumentException;

final readonly class OperationContext
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        private array $values,
    ) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key, mixed $default = []): mixed
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Context value [{$key}] is not available.");
        }

        return $this->values[$key] ?? $default;
    }

    public function getOrNull(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}
