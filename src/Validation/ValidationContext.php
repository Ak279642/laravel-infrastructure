<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Validation;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ValidationContext
{
    /**
     * @var array<class-string<Model>, Model|Collection>
     */
    protected array $resolved = [];

    public function put(string $class, Model|Collection $value): static
    {
        $this->resolved[$class] = $value;

        return $this;
    }

    public function get(string $class): Model|Collection|null
    {
        return $this->resolved[$class] ?? null;
    }

    public function getModel(string $class): ?Model
    {
        $value = $this->get($class);

        return $value instanceof Model ? $value : null;
    }

    public function getCollection(string $class): Collection
    {
        $value = $this->get($class);

        return $value instanceof Collection
            ? $value
            : new Collection;
    }

    public function has(string $class): bool
    {
        return array_key_exists($class, $this->resolved);
    }

    public function forget(string $class): void
    {
        unset($this->resolved[$class]);
    }

    public function clear(): void
    {
        $this->resolved = [];
    }

    public function all(): array
    {
        return $this->resolved;
    }
}
