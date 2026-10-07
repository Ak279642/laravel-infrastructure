<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Validation;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ValidationContext
{
    /**
     * Named values resolved during repository-backed validation.
     *
     * @var array<string, Model|Collection>
     */
    protected array $resolved = [];

    /**
     * Model index used by repositories to reuse already-resolved records.
     *
     * @var array<class-string<Model>, array<string, Model>>
     */
    protected array $models = [];

    public function put(string $key, Model|Collection $value): static
    {
        $this->resolved[$key] = $value;
        $this->indexValue($value);

        return $this;
    }

    public function get(string $key): Model|Collection|null
    {
        return $this->resolved[$key] ?? null;
    }

    public function getModel(string $key): ?Model
    {
        $value = $this->get($key);

        if ($value instanceof Model) {
            return $value;
        }

        // Backwards-friendly class lookup: if exactly one model of this class
        // has been resolved under an alias, return it.
        if (isset($this->models[$key]) && count($this->models[$key]) === 1) {
            return array_values($this->models[$key])[0];
        }

        return null;
    }

    public function requireModel(
        string $key,
        ?string $expectedClass = null,
    ): Model {
        $model = $this->getModel($key);

        if (! $model) {
            throw new InvalidArgumentException(
                "Resolved validation model [{$key}] is not available.",
            );
        }

        if ($expectedClass !== null && ! $model instanceof $expectedClass) {
            throw new InvalidArgumentException(
                "Resolved validation model [{$key}] is not an instance of [{$expectedClass}].",
            );
        }

        return $model;
    }

    public function findModel(
        string $class,
        int|string $id,
    ): ?Model {
        return $this->models[$class][(string) $id] ?? null;
    }

    public function getCollection(string $key): Collection
    {
        $value = $this->get($key);

        if ($value instanceof Collection) {
            return $value;
        }

        if (isset($this->models[$key])) {
            return new Collection(array_values($this->models[$key]));
        }

        return new Collection;
    }

    public function requireCollection(
        string $key,
        ?string $expectedClass = null,
    ): Collection {
        if (! $this->has($key) && ! isset($this->models[$key])) {
            throw new InvalidArgumentException(
                "Resolved validation collection [{$key}] is not available.",
            );
        }

        $collection = $this->getCollection($key);

        if ($expectedClass !== null) {
            foreach ($collection as $model) {
                if (! $model instanceof $expectedClass) {
                    throw new InvalidArgumentException(
                        "Resolved validation collection [{$key}] contains an unexpected model type.",
                    );
                }
            }
        }

        return $collection;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->resolved);
    }

    public function forget(string $key): void
    {
        unset($this->resolved[$key]);
        $this->rebuildModelIndex();
    }

    public function clear(): void
    {
        $this->resolved = [];
        $this->models = [];
    }

    /**
     * @return array<string, Model|Collection>
     */
    public function all(): array
    {
        return $this->resolved;
    }

    private function indexValue(Model|Collection $value): void
    {
        $models = $value instanceof Model
            ? [$value]
            : $value->all();

        foreach ($models as $model) {
            if (! $model instanceof Model || $model->getKey() === null) {
                continue;
            }

            $this->models[$model::class][(string) $model->getKey()] = $model;
        }
    }

    private function rebuildModelIndex(): void
    {
        $this->models = [];

        foreach ($this->resolved as $value) {
            $this->indexValue($value);
        }
    }
}
