<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Services;

use Ak279642\LaravelInfrastructure\Database\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Model;

abstract class BaseService
{
    public function __construct(
        protected readonly RepositoryInterface $repository,
    ) {}

    protected function repository(): RepositoryInterface
    {
        return $this->repository;
    }

    protected function findRecord(
        int|string $id,
        array $with = [],
        array $columns = ['*'],
    ): ?Model {
        return $this->repository->find($id, $with, $columns);
    }

    protected function findRecordOrFail(
        int|string $id,
        array $with = [],
        array $columns = ['*'],
    ): Model {
        return $this->repository->findOrFail($id, $with, $columns);
    }

    protected function recordExists(array $filters = []): bool
    {
        return $this->repository->exists($filters);
    }

    protected function createRecord(
        array $data,
        bool $refresh = false,
        array $with = [],
    ): Model {
        $data = $this->beforeCreate($data);

        $model = $this->repository->create($data, $refresh, $with);

        return $this->afterCreate($model, $data);
    }

    protected function updateRecord(
        int|string|Model $id,
        array $data,
        bool $refresh = false,
        array $with = [],
    ): Model {
        $data = $this->beforeUpdate($id, $data);

        $model = $this->repository->update($id, $data, $refresh, $with);

        return $this->afterUpdate($model, $data);
    }

    protected function deleteRecord(int|string|Model $id): bool
    {
        if (! $this->beforeDelete($id)) {
            return false;
        }

        $deleted = $this->repository->delete($id);

        $this->afterDelete($id, $deleted);

        return $deleted;
    }

    protected function beforeCreate(array $data): array
    {
        return $data;
    }

    protected function afterCreate(Model $model, array $data): Model
    {
        return $model;
    }

    protected function beforeUpdate(int|string|Model $id, array $data): array
    {
        return $data;
    }

    protected function afterUpdate(Model $model, array $data): Model
    {
        return $model;
    }

    protected function beforeDelete(int|string|Model $id): bool
    {
        return true;
    }

    protected function afterDelete(int|string|Model $id, bool $deleted): void {}
}
