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

    /**
     * Models discovered automatically by repository lookups, even when no
     * explicit validation alias was requested.
     *
     * @var array<class-string<Model>, array<string, Model>>
     */
    protected array $remembered = [];

    public function put(string $key, Model|Collection $value): static
    {
        $this->resolved[$key] = $value;

        // Rebuild rather than incrementally indexing so replacing an alias
        // cannot leave the previous model available to repository reuse.
        $this->rebuildModelIndex();

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

    /**
     * Remember repository-resolved models without requiring a public alias.
     */
    public function remember(Model|Collection $value): static
    {
        $models = $value instanceof Model
            ? [$value]
            : $value->all();

        foreach ($models as $model) {
            if ($model->getKey() === null) {
                continue;
            }

            $this->remembered[$model::class][(string) $model->getKey()] = $model;
        }

        $this->rebuildModelIndex();

        return $this;
    }

    /**
     * Return all currently indexed models of a class.
     */
    public function models(string $class): Collection
    {
        return new Collection(array_values($this->models[$class] ?? []));
    }

    public function forgetModel(Model|string $model, int|string|null $id = null): void
    {
        $class = $model instanceof Model ? $model::class : $model;
        $key = $model instanceof Model ? $model->getKey() : $id;

        if ($key === null) {
            return;
        }

        unset($this->remembered[$class][(string) $key]);

        foreach ($this->resolved as $alias => $value) {
            if (
                $value instanceof Model
                && $value::class === $class
                && (string) $value->getKey() === (string) $key
            ) {
                unset($this->resolved[$alias]);

                continue;
            }

            if ($value instanceof Collection) {
                $remaining = $value->reject(
                    static fn (Model $item): bool => $item::class === $class
                        && (string) $item->getKey() === (string) $key,
                )->values();

                if ($remaining->count() !== $value->count()) {
                    $this->resolved[$alias] = $remaining;
                }
            }
        }

        $this->rebuildModelIndex();
    }

    /**
     * Find one indexed model whose attributes match all supplied conditions.
     *
     * @param  array<string, mixed>  $where
     */
    public function findMatching(
        string $class,
        array $where,
    ): ?Model {
        foreach ($this->models[$class] ?? [] as $model) {
            if ($this->matches($model, $where)) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Find indexed models matching a column/value set and additional
     * conditions.
     *
     * @param  list<mixed>  $values
     * @param  array<string, mixed>  $where
     */
    public function findManyMatching(
        string $class,
        string $column,
        array $values,
        array $where = [],
    ): Collection {
        $wanted = array_map('strval', $values);

        return new Collection(array_values(array_filter(
            $this->models[$class] ?? [],
            function (Model $model) use ($column, $wanted, $where): bool {
                $value = $model->getAttribute($column);

                return $value !== null
                    && in_array((string) $value, $wanted, true)
                    && $this->matches($model, $where);
            },
        )));
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
        $this->remembered = [];
        $this->models = [];
    }

    /**
     * Capture the complete scoped validation identity map so temporary
     * validation work can be rolled back without losing remembered models.
     *
     * @return array{
     *     resolved: array<string, Model|Collection>,
     *     remembered: array<class-string<Model>, array<string, Model>>
     * }
     */
    public function snapshot(): array
    {
        return [
            'resolved' => $this->resolved,
            'remembered' => $this->remembered,
        ];
    }

    /**
     * Restore a previously captured validation context snapshot.
     *
     * @param  array{
     *     resolved?: array<string, Model|Collection>,
     *     remembered?: array<class-string<Model>, array<string, Model>>
     * }  $snapshot
     */
    public function restore(array $snapshot): void
    {
        $this->resolved = $snapshot['resolved'] ?? [];
        $this->remembered = $snapshot['remembered'] ?? [];
        $this->rebuildModelIndex();
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
            if ($model->getKey() === null) {
                continue;
            }

            $this->models[$model::class][(string) $model->getKey()] = $model;
        }
    }

    private function rebuildModelIndex(): void
    {
        $this->models = [];

        foreach ($this->remembered as $models) {
            foreach ($models as $model) {
                $this->indexValue($model);
            }
        }

        foreach ($this->resolved as $value) {
            $this->indexValue($value);
        }
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function matches(Model $model, array $where): bool
    {
        foreach ($where as $column => $expected) {
            if ($column === '') {
                return false;
            }

            $actual = $model->getAttribute($column);

            if ($actual === null || $expected === null) {
                if ($actual !== $expected) {
                    return false;
                }

                continue;
            }

            if ((string) $actual !== (string) $expected) {
                return false;
            }
        }

        return true;
    }
}
